import { isRecord, request, requestEnvelope } from '../api'

const API = '/api/v1/administration/units'
const UNIT_CODE = /^[a-z][a-z0-9_]{0,23}$/
const DIMENSIONS = ['MASS', 'VOLUME', 'COUNT'] as const

export type UnitDimension = (typeof DIMENSIONS)[number]
export type AdministrationUnit = {
  id: string
  code: string
  name: string
  symbol: string
  dimension: UnitDimension
  baseUnitCode: string
  toBaseFactor: string
  archivedAt: string | null
  isBase: boolean
  definitionLocked: boolean
  referenceCount: number
  conversions: Array<{
    unitCode: string
    name: string
    symbol: string
    archivedAt: string | null
    factor: string
  }>
}

function parseUnit(value: unknown): AdministrationUnit {
  if (!isRecord(value) || typeof value.id !== 'string'
    || typeof value.code !== 'string' || !UNIT_CODE.test(value.code)
    || typeof value.name !== 'string' || !value.name.trim()
    || typeof value.symbol !== 'string' || !value.symbol.trim()
    || typeof value.dimension !== 'string' || !DIMENSIONS.includes(value.dimension as UnitDimension)
    || typeof value.base_unit_code !== 'string' || !UNIT_CODE.test(value.base_unit_code)
    || typeof value.to_base_factor !== 'string'
    || !/^\d{1,12}(?:\.\d{1,12})?$/.test(value.to_base_factor)
    || !(value.archived_at === null || typeof value.archived_at === 'string'
      && !Number.isNaN(Date.parse(value.archived_at)))
    || typeof value.is_base !== 'boolean'
    || typeof value.definition_locked !== 'boolean'
    || !Number.isInteger(value.reference_count) || Number(value.reference_count) < 0
    || !Array.isArray(value.conversions)) {
    throw new Error('Backend zwrócił nieprawidłową definicję jednostki.')
  }
  const conversions = value.conversions.map((conversion) => {
    if (!isRecord(conversion) || typeof conversion.unit_code !== 'string'
      || typeof conversion.name !== 'string' || typeof conversion.symbol !== 'string'
      || typeof conversion.factor !== 'string'
      || !(conversion.archived_at === null || typeof conversion.archived_at === 'string')) {
      throw new Error('Backend zwrócił nieprawidłowy przelicznik jednostki.')
    }
    return {
      unitCode: conversion.unit_code,
      name: conversion.name,
      symbol: conversion.symbol,
      factor: conversion.factor,
      archivedAt: conversion.archived_at,
    }
  })

  return {
    id: value.id,
    code: value.code,
    name: value.name,
    symbol: value.symbol,
    dimension: value.dimension as UnitDimension,
    baseUnitCode: value.base_unit_code,
    toBaseFactor: value.to_base_factor,
    archivedAt: value.archived_at,
    isBase: value.is_base,
    definitionLocked: value.definition_locked,
    referenceCount: Number(value.reference_count),
    conversions,
  }
}

export async function fetchAdministrationUnits(
  query: { status: 'active' | 'archived' | 'all'; search?: string; dimension?: UnitDimension },
  signal?: AbortSignal,
): Promise<AdministrationUnit[]> {
  const params = new URLSearchParams({ status: query.status })
  if (query.search) params.set('search', query.search)
  if (query.dimension) params.set('dimension', query.dimension)
  const response = await requestEnvelope(`${API}?${params}`, 'GET', undefined, { signal })
  if (!Array.isArray(response.data)) throw new Error('Backend zwrócił nieprawidłowy słownik jednostek.')
  return response.data.map(parseUnit)
}

export async function createAdministrationUnit(input: {
  code: string
  name: string
  symbol: string
  dimension: UnitDimension
  base_unit_code: string
  to_base_factor: string
}): Promise<AdministrationUnit> {
  return parseUnit(await request(API, 'POST', input))
}

export async function updateAdministrationUnit(id: string, input: {
  name: string
  symbol: string
  dimension: UnitDimension
  base_unit_code: string
  to_base_factor: string
}): Promise<AdministrationUnit> {
  return parseUnit(await request(`${API}/${encodeURIComponent(id)}`, 'PATCH', input))
}

export async function archiveAdministrationUnit(id: string): Promise<AdministrationUnit> {
  return parseUnit(await request(`${API}/${encodeURIComponent(id)}/archive`, 'POST', {}))
}

export async function restoreAdministrationUnit(id: string): Promise<AdministrationUnit> {
  return parseUnit(await request(`${API}/${encodeURIComponent(id)}/restore`, 'POST', {}))
}
