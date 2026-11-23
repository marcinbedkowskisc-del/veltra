import { expect, it } from 'vitest'
import {
  parseRawPermissionData,
  rawDelegablePermissionOptions,
  rawPermissionLabel,
  rawPermissionOptions,
} from './rawPermissionsApi'

it('retains the retired compatibility permission in history without offering it for grants', () => {
  const permission = 'RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE'
  const data = parseRawPermissionData({
    grants: [{
      id: '00000000-0000-4000-8000-000000000501',
      permission,
      source: 'SYSTEM',
      granted_by_name: 'System',
      granted_at: '2026-01-01T00:00:00Z',
      valid_from: '2026-01-01T00:00:00Z',
      valid_until: null,
      revoked_at: null,
      revoked_by_name: null,
      is_effective: false,
      status: 'INACTIVE',
      lineage: [],
    }],
    history: [{
      grant_id: '00000000-0000-4000-8000-000000000501',
      permission,
      action: 'GRANTED',
      actor_name: 'System',
      recipient_name: 'Użytkownik',
      occurred_at: '2026-01-01T00:00:00Z',
    }],
  })

  expect(data.grants[0].permission).toBe(permission)
  expect(data.history[0].permission).toBe(permission)
  expect(rawPermissionLabel(data.grants[0].permission)).toContain('historyczne')
  expect(rawPermissionOptions.some((option) => String(option.value) === permission)).toBe(false)
  expect(rawDelegablePermissionOptions.some((option) => String(option.value) === permission)).toBe(false)
})
