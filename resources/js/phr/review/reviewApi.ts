import { z } from 'zod'

import { fetchWrapper } from '@/fetchWrapper'
import { recordReviewDecision } from '@/phr/clinical/reviewDecision'
import { AiImportResultSchema, decideAiResult } from '@/phr/imports/ai/aiImportsApi'
import { type EhiDecision, ehiFailureText, EhiProposalSchema, reviewEhiProposal } from '@/phr/imports/ehi/ehiApi'
import { isPhrPageId, type PhrPageId } from '@/phr/shell/phrRoutes'

import { reviewStateChanged, ReviewSummarySchema, type ReviewSummaryState } from './reviewSummary'

/**
 * The patient's review queue. Reading it is one request; each decision goes to the route
 * that already owns that kind of proposal, with that route's own checks: a clinical
 * record's review route (conditional on the version listed), the AI import accept and
 * reject routes, and the EHI proposal review route (with its preview digest).
 */
const SectionSchema = z.string().refine(isPhrPageId).transform((value) => value as PhrPageId)

/** One fill as a person compares it with another: the fields that tell two fills apart. */
const FillSummarySchema = z.object({
  id: z.number(),
  filled_on: z.string(),
  name: z.string(),
  strength: z.string().nullable(),
  quantity: z.string().nullable(),
  pharmacy: z.string().nullable(),
  rx_number: z.string().nullable(),
  sources: z.array(z.string()),
  review_status: z.string().optional(),
})
export type FillSummary = z.infer<typeof FillSummarySchema>

const DuplicateSchema = z.object({ fill: FillSummarySchema, candidates: z.array(FillSummarySchema) })
export type FillDuplicate = z.infer<typeof DuplicateSchema>

export const RecordItemSchema = z.object({
  type: z.literal('record'),
  key: z.string(),
  id: z.number(),
  section: SectionSchema,
  review_segment: z.string(),
  title: z.string(),
  detail: z.string().nullable(),
  /** Why a person should look closely, such as a fill printed for another name. */
  flags: z.array(z.object({ code: z.string(), message: z.string() })).optional(),
  /** A fill flagged possible_duplicate, beside the existing fills it may repeat. */
  duplicate: DuplicateSchema.optional(),
  /** Where to open the record when it has no page of its own under its section (an EOB). */
  open_path: z.string().optional(),
  change: z.enum(['new', 'update']),
  version: z.string(),
  updated_at: z.string().nullable(),
})

const AiItemSchema = z.object({
  type: z.literal('ai_result'),
  key: z.string(),
  id: z.number(),
  import_id: z.number(),
  job_type: z.string(),
  result: AiImportResultSchema,
})

const EhiItemSchema = z.object({
  type: z.literal('ehi_proposal'),
  key: z.string(),
  id: z.number(),
  package_id: z.number(),
  section: SectionSchema,
  proposal: EhiProposalSchema,
})

export const ReviewItemSchema = z.discriminatedUnion('type', [RecordItemSchema, AiItemSchema, EhiItemSchema])
export type ReviewItem = z.infer<typeof ReviewItemSchema>

export const ReviewGroupSchema = z.object({
  key: z.string(),
  origin: z.enum(['records', 'ai', 'ehi']),
  label: z.string(),
  name: z.string().nullable(),
  parsed_by_phr: z.boolean(),
  ai_read: z.boolean(),
  execution_mode: z.enum(['api', 'external']).optional(),
  package_id: z.number().optional(),
  document_id: z.number().nullable(),
  date: z.string().nullable(),
  total: z.number(),
  truncated_sections: z.array(z.string()),
  items: z.array(ReviewItemSchema),
})
export type ReviewGroup = z.infer<typeof ReviewGroupSchema>

const QueueSchema = z.object({
  summary: ReviewSummarySchema,
  groups: z.array(ReviewGroupSchema),
  item_limit: z.number(),
  can_manage: z.boolean(),
})

export interface ReviewQueue {
  state: ReviewSummaryState
  groups: ReviewGroup[]
  itemLimit: number
}

export async function getReviewQueue(patientId: number): Promise<ReviewQueue> {
  const parsed = QueueSchema.parse(await fetchWrapper.get(`/api/phr/patients/${patientId}/review`))
  return { state: { summary: parsed.summary, canManage: parsed.can_manage }, groups: parsed.groups, itemLimit: parsed.item_limit }
}

const AI_SECTIONS: Record<string, PhrPageId> = {
  phr_document: 'documents',
  phr_lab_result: 'labs',
  phr_vital: 'vitals',
  phr_medication: 'medications',
  phr_medication_fill: 'medication-fills',
  phr_problem_list: 'conditions',
  phr_procedure: 'procedures',
  phr_immunization: 'immunizations',
  phr_allergy: 'allergies',
  phr_office_visit: 'office-visits',
}

export function sectionOf(item: ReviewItem): PhrPageId {
  return item.type === 'ai_result' ? AI_SECTIONS[item.job_type] ?? 'documents' : item.section
}

/** What approving an EHI proposal means, by its kind: the decision its own review leads with. */
const EHI_APPROVAL: Record<string, EhiDecision> = {
  create: 'accept',
  possible_duplicate: 'accept',
  corroborate: 'accept',
  update: 'approve_update',
  retain: 'retain_evidence',
}

export type ReviewDecision = 'approve' | 'reject'

/** Record one decision through the item's own route. Throws the route's refusal. */
export async function decideReviewItem(patientId: number, item: ReviewItem, decision: ReviewDecision): Promise<void> {
  switch (item.type) {
    case 'record':
      await recordReviewDecision(`/api/phr/patients/${patientId}/${item.review_segment}/${item.id}`, decision === 'approve' ? 'confirmed' : 'rejected', item.version)
      return
    case 'ai_result':
      await decideAiResult(patientId, item.import_id, item.id, decision === 'approve' ? 'accept' : 'reject')
      return
    case 'ehi_proposal':
      await reviewEhiProposal(patientId, item.proposal, decision === 'approve' ? EHI_APPROVAL[item.proposal.kind] ?? 'accept' : 'reject')
  }
}

export interface BatchOutcome {
  done: string[]
  failed: Record<string, string>
}

/**
 * Decide each item in turn, the way the import reviews' "Accept all" does. One refusal
 * (a record changed since the page loaded, a proposal already decided) never stops the
 * rest; it is reported against its own row.
 */
const RejectedSchema = z.object({ items: z.array(RecordItemSchema), truncated: z.boolean(), can_manage: z.boolean() })
export type RejectedMedications = z.infer<typeof RejectedSchema>

/** Rejected medications and fills, which their own lists leave out. */
export async function getRejectedMedications(patientId: number): Promise<RejectedMedications> {
  return RejectedSchema.parse(await fetchWrapper.get(`/api/phr/patients/${patientId}/review/rejected`))
}

export async function decideReviewItems(patientId: number, items: ReviewItem[], decision: ReviewDecision): Promise<BatchOutcome> {
  const outcome: BatchOutcome = { done: [], failed: {} }
  for (const item of items) {
    try {
      await decideReviewItem(patientId, item, decision)
      outcome.done.push(item.key)
    } catch (caught) {
      outcome.failed[item.key] = ehiFailureText(caught)
    }
  }
  return outcome
}

export type DuplicateAction = 'same' | 'different'

/**
 * Answer a fill's possible-duplicate flag: `same` merges it into the existing fill (and
 * retracts it), `different` clears the flag. Conditional on the version listed (a 409
 * otherwise). Throws the route's refusal.
 */
export async function resolveFillDuplicate(patientId: number, fillId: number, action: DuplicateAction, version: string, candidateFillId?: number): Promise<void> {
  try {
    await fetchWrapper.post(`/api/phr/patients/${patientId}/medication-fills/${fillId}/resolve-duplicate`, {
      action,
      version,
      ...(candidateFillId !== undefined ? { candidate_fill_id: candidateFillId } : {}),
    })
  } finally {
    void reviewStateChanged(patientId)
  }
}
