import { z } from 'zod'

import { fetchWrapper } from '@/fetchWrapper'

/**
 * Counts for the patient-selection page, for every patient the signed-in person can read,
 * in one request: records, the latest change and what waits for review per patient, and
 * what needs attention across them (imports and AI extractions ready to review, imports
 * that failed). Names and access come from the shared patient list instead.
 */
const OverviewSchema = z.object({
  patients: z.array(z.object({ id: z.number(), records: z.number(), last_updated: z.string().nullable(), pending: z.number() })),
  attention: z.array(z.object({
    kind: z.enum(['ready', 'failed']),
    origin: z.enum(['records', 'ai', 'ehi', 'upload']),
    patient_id: z.number(),
    count: z.number(),
    name: z.string().nullable(),
    date: z.string().nullable(),
  })),
})
export type PatientOverview = z.infer<typeof OverviewSchema>
export type AttentionItem = PatientOverview['attention'][number]

export async function getPatientOverview(): Promise<PatientOverview> {
  return OverviewSchema.parse(await fetchWrapper.get('/api/phr/patients/overview'))
}

function plural(count: number, one: string, many: string): string {
  return `${count} ${count === 1 ? one : many}`
}

/** One line for an attention item, in the words the page uses. */
export function attentionTitle(item: AttentionItem): string {
  if (item.kind === 'failed') {
    return item.origin === 'ai'
      ? `AI extraction failed${item.name ? ` for ${item.name}` : ''}`
      : 'Import failed: the file could not be read'
  }
  switch (item.origin) {
    case 'ai':
      return `AI extraction finished${item.name ? ` for ${item.name}` : ''}: ${plural(item.count, 'proposal', 'proposals')}`
    case 'ehi':
      return `EHI export ready to review: ${plural(item.count, 'proposal', 'proposals')}`
    default:
      return `Records ready to review: ${plural(item.count, 'record', 'records')}`
  }
}
