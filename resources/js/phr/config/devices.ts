import { z } from 'zod'

/**
 * Mirrors UserDeviceController@index's `['id', 'device_id', 'name', 'created_at',
 * 'last_used_at', 'expires_at', 'revoked_at']` column list exactly. `.strict()` is
 * deliberate: PhrDeviceKey guards `token_hash` via `$hidden`, and the controller never
 * selects it, but if a future change to either ever let it leak into the response, this
 * schema should fail loudly instead of silently passing it through to the UI.
 */
export const DeviceKeySchema = z.object({
  id: z.number().int().positive(),
  device_id: z.string(),
  name: z.string(),
  created_at: z.string().nullable(),
  last_used_at: z.string().nullable(),
  expires_at: z.string().nullable(),
  revoked_at: z.string().nullable(),
}).strict()

export type DeviceKey = z.infer<typeof DeviceKeySchema>

export const DeviceKeyListSchema = z.array(DeviceKeySchema)

export const DeviceRevokeResponseSchema = z.object({
  success: z.literal(true),
})

export type DeviceStatus = 'active' | 'expired' | 'revoked'

/**
 * A key with no expiry is never "active" (mirrors PhrDeviceKey::isActive() on the
 * backend), but that state is never expected to reach the UI since issueFor() always
 * sets one; it is treated the same as "expired" here so the panel fails closed rather
 * than crashing or claiming a naked key is safe.
 */
export function deviceStatus(device: DeviceKey, now: Date = new Date()): DeviceStatus {
  if (device.revoked_at !== null) return 'revoked'
  if (device.expires_at === null || new Date(device.expires_at).getTime() <= now.getTime()) return 'expired'
  return 'active'
}

export function friendlyDate(value: string | null, fallback = 'Never'): string {
  if (value === null) return fallback
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}
