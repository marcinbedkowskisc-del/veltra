import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import BossLocationRestrictions from './BossLocationRestrictions'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

it('shows persisted state and saves location and category restrictions', async () => {
  const kitchen = { id: 'kitchen', name: 'Kuchnia A', type: 'CENTRAL_KITCHEN' } as const
  const restaurant = { id: 'restaurant', name: 'Restauracja A', type: 'RESTAURANT' } as const
  const fetchMock = vi.fn(async (_url: string, init: RequestInit) => {
    const request = init.method === 'PUT' ? JSON.parse(String(init.body)) : null
    const data = request
      ? { ...request, version: request.version + 1 }
      : { version: 0, excluded_location_ids: [], excluded_categories: [], excluded_category_locations: [] }

    return Response.json({ data })
  })
  vi.stubGlobal('fetch', fetchMock)
  const onSaved = vi.fn()
  render(<BossLocationRestrictions userId="boss" locations={[kitchen, restaurant]} onSaved={onSaved} canManage />)

  const checkbox = await screen.findByRole('checkbox', { name: /Wyłącz dostęp/ })
  expect(screen.getAllByRole('checkbox')).toHaveLength(10)
  expect(screen.getByRole('checkbox', { name: 'Ceny zakupu i informacje finansowe' })).toBeTruthy()
  const save = screen.getByRole('button', { name: 'Zapisz ograniczenia' })
  fireEvent.click(checkbox)
  fireEvent.click(screen.getByRole('checkbox', { name: 'Ceny zakupu i informacje finansowe' }))
  fireEvent.click(save)
  expect(await screen.findByText('Zapisano ograniczenia.')).toBeTruthy()
  fireEvent.change(screen.getByLabelText('Wybierz lokalizację'), { target: { value: restaurant.id } })
  expect(screen.getByText('Stan zapisany: Brak ograniczenia BOSS; nie potwierdza to innych uprawnień')).toBeTruthy()
  fireEvent.change(screen.getByLabelText('Wybierz lokalizację'), { target: { value: kitchen.id } })
  fireEvent.click(checkbox)
  fireEvent.click(save)
  expect(await screen.findByText('Zapisano ograniczenia.')).toBeTruthy()
  expect(screen.getByText('Stan zapisany: Brak ograniczenia BOSS; nie potwierdza to innych uprawnień')).toBeTruthy()
  expect(fetchMock.mock.calls.filter(([, request]) => request.method === 'PUT').map(([, request]) => JSON.parse(String(request.body))))
    .toEqual([
      {
        policy_schema_version: 2,
        version: 0,
        excluded_location_ids: [kitchen.id],
        excluded_categories: ['PURCHASE_FINANCIALS'],
        excluded_category_locations: [],
      },
      {
        policy_schema_version: 2,
        version: 1,
        excluded_location_ids: [],
        excluded_categories: ['PURCHASE_FINANCIALS'],
        excluded_category_locations: [],
      },
    ])
  expect(onSaved).toHaveBeenCalledTimes(2)
})
