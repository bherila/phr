import { z } from 'zod'

import { fetchWrapper } from '@/fetchWrapper'
import { type PhrOfficeVisitLinkedRecords, PhrReviewStatusSchema } from '@/phr/types'

/**
 * Attaching existing records to a visit or stay, and removing them (#449). A link is
 * evidence: it changes no record's review status or any section's count, so these writes
 * leave the review counts alone, as the agent's link does.
 */

export const VISIT_LINK_TYPES = ['lab-result', 'vital', 'medication', 'procedure', 'document'] as const
export type VisitLinkType = (typeof VISIT_LINK_TYPES)[number]

export const VISIT_LINK_TYPE_LABELS: Record<VisitLinkType, string> = {
  'lab-result': 'Lab results',
  vital: 'Vitals',
  medication: 'Medications',
  procedure: 'Procedures',
  document: 'Documents and notes',
}

/** The link type of each group the visit page lists. */
export const GROUP_LINK_TYPES: Record<keyof PhrOfficeVisitLinkedRecords, VisitLinkType> = {
  lab_results: 'lab-result',
  vitals: 'vital',
  medications: 'medication',
  procedures: 'procedure',
  documents: 'document',
}

const LinkedVisitSchema = z.object({
  id: z.number(),
  date: z.string().nullable(),
  visit_type: z.string().nullable(),
})
export type LinkedVisit = z.infer<typeof LinkedVisitSchema>

const LinkableRecordSchema = z.object({
  id: z.number(),
  type: z.enum(VISIT_LINK_TYPES),
  name: z.string(),
  date: z.string().nullable(),
  detail: z.string().nullable(),
  review_status: PhrReviewStatusSchema,
  linked_visit: LinkedVisitSchema.nullable(),
})
export type LinkableRecord = z.infer<typeof LinkableRecordSchema>

const LinkableRecordsResponseSchema = z.object({
  office_visit_id: z.number(),
  records: z.array(LinkableRecordSchema),
  pagination: z.object({
    limit: z.number(),
    has_more: z.boolean(),
    next_cursor: z.string().nullable(),
  }),
})
export type LinkableRecordsPage = z.infer<typeof LinkableRecordsResponseSchema>

const LinkResultSchema = z.object({
  type: z.enum(VISIT_LINK_TYPES),
  id: z.number(),
  outcome: z.enum(['linked', 'unchanged', 'refused']),
  reason: z.string().optional(),
  message: z.string().optional(),
  linked_visit: LinkedVisitSchema.nullable().optional(),
})
export type LinkResult = z.infer<typeof LinkResultSchema>

const LinkBatchResponseSchema = z.object({
  office_visit_id: z.number(),
  results: z.array(LinkResultSchema),
  summary: z.object({ linked: z.number(), unchanged: z.number(), refused: z.number() }),
})
export type LinkBatch = z.infer<typeof LinkBatchResponseSchema>

const UnlinkResponseSchema = z.object({ outcome: z.enum(['unlinked', 'unchanged']) })

export interface LinkableRecordsQuery {
  type: VisitLinkType
  q?: string
  from?: string
  to?: string
  cursor?: string | null
}

function visitUrl(patientId: number, visitId: number | string): string {
  return `/api/phr/patients/${patientId}/office-visits/${visitId}`
}

export async function fetchLinkableRecords(patientId: number, visitId: number | string, query: LinkableRecordsQuery): Promise<LinkableRecordsPage> {
  const params = new URLSearchParams({ type: query.type })
  if (query.q?.trim()) params.set('q', query.q.trim())
  if (query.from) params.set('from', query.from)
  if (query.to) params.set('to', query.to)
  if (query.cursor) params.set('cursor', query.cursor)
  const raw: unknown = await fetchWrapper.get(`${visitUrl(patientId, visitId)}/linkable-records?${params.toString()}`)

  return LinkableRecordsResponseSchema.parse(raw)
}

export async function attachRecords(patientId: number, visitId: number | string, links: Array<{ type: VisitLinkType, id: number }>): Promise<LinkBatch> {
  const raw: unknown = await fetchWrapper.post(`${visitUrl(patientId, visitId)}/links`, { links })

  return LinkBatchResponseSchema.parse(raw)
}

export async function detachRecord(patientId: number, visitId: number | string, type: VisitLinkType, id: number): Promise<'unlinked' | 'unchanged'> {
  const raw: unknown = await fetchWrapper.delete(`${visitUrl(patientId, visitId)}/links/${type}/${id}`, {})

  return UnlinkResponseSchema.parse(raw).outcome
}
