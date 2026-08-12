import { DeviceKeySchema, deviceStatus, friendlyDate } from './devices'

function apiShape(overrides: Partial<Record<string, unknown>> = {}): Record<string, unknown> {
  return {
    id: 1,
    device_id: 'AB12-CD34-EF56',
    name: "Ben's MacBook Pro",
    created_at: '2026-06-01T12:00:00.000000Z',
    last_used_at: '2026-08-01T09:30:00.000000Z',
    expires_at: '2026-09-01T00:00:00.000000Z',
    revoked_at: null,
    ...overrides,
  }
}

describe('DeviceKeySchema', () => {
  it('accepts the shape UserDeviceController@index returns', () => {
    const parsed = DeviceKeySchema.parse(apiShape())
    expect(parsed).toEqual(apiShape())
  })

  it('accepts revoked and never-used devices with null timestamps', () => {
    const parsed = DeviceKeySchema.parse(apiShape({ last_used_at: null, revoked_at: '2026-08-05T00:00:00.000000Z' }))
    expect(parsed.last_used_at).toBeNull()
    expect(parsed.revoked_at).toBe('2026-08-05T00:00:00.000000Z')
  })

  it('rejects a shape that leaks token_hash', () => {
    expect(() => DeviceKeySchema.parse(apiShape({ token_hash: 'sha256:should-never-appear' }))).toThrow()
  })

  it('rejects a shape missing a required field', () => {
    const { device_id: _deviceId, ...withoutDeviceId } = apiShape()
    expect(() => DeviceKeySchema.parse(withoutDeviceId)).toThrow()
  })
})

describe('deviceStatus', () => {
  const now = new Date('2026-08-11T00:00:00.000000Z')

  it('is active when unrevoked with a future expiry', () => {
    expect(deviceStatus(DeviceKeySchema.parse(apiShape()), now)).toBe('active')
  })

  it('is revoked when revoked_at is set, even if not yet expired', () => {
    const device = DeviceKeySchema.parse(apiShape({ revoked_at: '2026-08-05T00:00:00.000000Z' }))
    expect(deviceStatus(device, now)).toBe('revoked')
  })

  it('is expired once expires_at has passed', () => {
    const device = DeviceKeySchema.parse(apiShape({ expires_at: '2026-08-01T00:00:00.000000Z' }))
    expect(deviceStatus(device, now)).toBe('expired')
  })

  it('is expired (not active) when expires_at is null, mirroring PhrDeviceKey::isActive()', () => {
    const device = DeviceKeySchema.parse(apiShape({ expires_at: null }))
    expect(deviceStatus(device, now)).toBe('expired')
  })

  it('prefers revoked over expired when both apply', () => {
    const device = DeviceKeySchema.parse(apiShape({
      expires_at: '2026-08-01T00:00:00.000000Z',
      revoked_at: '2026-08-02T00:00:00.000000Z',
    }))
    expect(deviceStatus(device, now)).toBe('revoked')
  })
})

describe('friendlyDate', () => {
  it('returns the fallback for null', () => {
    expect(friendlyDate(null)).toBe('Never')
    expect(friendlyDate(null, 'No expiry')).toBe('No expiry')
  })

  it('formats a present timestamp as a human-readable date', () => {
    const formatted = friendlyDate('2026-06-01T12:00:00.000000Z')
    expect(formatted).not.toBe('Never')
    expect(formatted.length).toBeGreaterThan(0)
  })
})
