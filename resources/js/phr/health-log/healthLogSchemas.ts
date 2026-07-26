import { z } from 'zod'

import type { HealthLogData, HealthLogEntryData } from '@/types/generated/phr'

const nullableString = z.string().nullable()

export const HealthLogKindSchema = z.enum(['meal', 'snack', 'symptom', 'custom'])
export type HealthLogKind = z.infer<typeof HealthLogKindSchema>

export const HealthLogSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  user_id: z.number(),
  created_by_user_id: z.number().nullable(),
  name: z.string(),
  kind: HealthLogKindSchema,
  description: nullableString,
  archived_at: nullableString,
  entries_count: z.number().default(0),
  latest_entry_at: nullableString,
  created_at: nullableString,
  updated_at: nullableString,
}) satisfies z.ZodType<HealthLogData>
export type HealthLog = z.infer<typeof HealthLogSchema>

export const HealthLogEntrySchema = z.object({
  id: z.number(),
  health_log_id: z.number(),
  patient_id: z.number(),
  user_id: z.number(),
  recorded_by_user_id: z.number().nullable(),
  occurred_at: z.string(),
  title: nullableString,
  notes: nullableString,
  intensity: z.number().int().min(0).max(10).nullable(),
  tags: z.array(z.string()).nullable().transform((tags) => tags ?? []),
  details: z.record(z.string(), z.unknown()).nullable(),
  created_at: nullableString,
  updated_at: nullableString,
}) satisfies z.ZodType<HealthLogEntryData>
export type HealthLogEntry = z.infer<typeof HealthLogEntrySchema>

export const HealthLogsResponseSchema = z.object({
  health_logs: z.array(HealthLogSchema),
  can_manage: z.boolean().default(false),
})

export const HealthLogResponseSchema = z.object({
  health_log: HealthLogSchema,
})

export const HealthLogEntriesResponseSchema = z.object({
  entries: z.array(HealthLogEntrySchema),
  can_manage: z.boolean().default(false),
})

export const HealthLogEntryResponseSchema = z.object({
  entry: HealthLogEntrySchema,
})

export const HealthLogFormSchema = z.object({
  name: z.string().trim().min(1, 'Give this log a name.').max(120),
  kind: HealthLogKindSchema,
  description: z.string().trim().max(1000),
})
export type HealthLogFormData = z.infer<typeof HealthLogFormSchema>

function isJsonObject(value: string): boolean {
  if (value.trim() === '') {
    return true
  }

  try {
    const parsed: unknown = JSON.parse(value)
    return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)
  } catch {
    return false
  }
}

export const HealthLogEntryFormSchema = z.object({
  occurred_at: z.string().trim().min(1, 'Choose when this happened.'),
  title: z.string().trim().max(255),
  notes: z.string().trim().max(10000),
  intensity: z.string().trim().refine(
    (value) => value === '' || (/^\d+$/.test(value) && Number(value) >= 0 && Number(value) <= 10),
    'Intensity must be a whole number from 0 to 10.',
  ),
  tags: z.string().max(1000),
  details_json: z.string().max(20000).refine(isJsonObject, 'Details must be a JSON object.'),
}).refine(
  (data) => [data.title, data.notes, data.intensity, data.tags, data.details_json].some((value) => value.trim() !== ''),
  { message: 'Add a title, note, intensity, tag, or detail.' },
)
export type HealthLogEntryFormData = z.infer<typeof HealthLogEntryFormSchema>

export function healthLogPayload(form: HealthLogFormData): Record<string, unknown> {
  return {
    name: form.name,
    kind: form.kind,
    description: form.description || null,
  }
}

export function healthLogEntryPayload(form: HealthLogEntryFormData): Record<string, unknown> {
  return {
    occurred_at: form.occurred_at,
    title: form.title || null,
    notes: form.notes || null,
    intensity: form.intensity === '' ? null : Number(form.intensity),
    tags: form.tags
      .split(',')
      .map((tag) => tag.trim())
      .filter(Boolean),
    details: form.details_json.trim() === '' ? null : JSON.parse(form.details_json),
  }
}

export function localDateTimeInputValue(value: string | null = null): string {
  const date = value ? new Date(value) : new Date()
  if (Number.isNaN(date.getTime())) {
    return ''
  }

  const offsetMilliseconds = date.getTimezoneOffset() * 60_000
  return new Date(date.getTime() - offsetMilliseconds).toISOString().slice(0, 16)
}

export function entryFormFromRecord(entry: HealthLogEntry): HealthLogEntryFormData {
  return {
    occurred_at: localDateTimeInputValue(entry.occurred_at),
    title: entry.title ?? '',
    notes: entry.notes ?? '',
    intensity: entry.intensity === null ? '' : String(entry.intensity),
    tags: entry.tags.join(', '),
    details_json: entry.details ? JSON.stringify(entry.details, null, 2) : '',
  }
}
