import { z } from 'zod'

import { fetchWrapper } from '@/fetchWrapper'

/**
 * Client for the session EHI import routes (`/api/phr/patients/{id}/ehi/...`). Every
 * response is parsed, so a contract change fails loudly here rather than as a blank
 * screen. Refusals arrive as a fixed reason code; {@link describeEhiError} turns one
 * into a sentence.
 */

/** Laravel serializes an empty associative array as `[]`; read it as an empty map. */
function emptyArrayAsObject(value: unknown): unknown {
  return Array.isArray(value) && value.length === 0 ? {} : value
}

const countMap = z.preprocess(emptyArrayAsObject, z.record(z.string(), z.number()))
const nestedCountMap = z.preprocess(emptyArrayAsObject, z.record(z.string(), countMap))
const looseRecord = z.preprocess(emptyArrayAsObject, z.record(z.string(), z.unknown()))

export const EhiUploadSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  status: z.enum(['uploading', 'queued', 'processing', 'completed', 'rejected', 'failed']),
  failure_reason: z.string().nullable(),
  transport: z.string(),
  file_size_bytes: z.number(),
  uploaded_bytes: z.number(),
  chunk_size_bytes: z.number(),
  package_id: z.number().nullable(),
  replayed: z.boolean().nullable(),
  resumed: z.boolean().nullable(),
  repaired: z.number().nullable(),
  expires_at: z.string(),
  completed_at: z.string().nullable(),
  created_at: z.string().nullable(),
})
export type EhiUpload = z.infer<typeof EhiUploadSchema>

export const EhiPreviewRunSchema = z.object({
  id: z.number(),
  package_id: z.number(),
  status: z.enum(['queued', 'running', 'completed', 'failed']),
  failure_reason: z.string().nullable(),
  counts: nestedCountMap.nullable(),
  statuses: countMap.nullable(),
  findings: countMap.nullable(),
  preview_digest: z.string().nullable(),
  replayed: z.boolean().nullable(),
  requested_at: z.string().nullable(),
  started_at: z.string().nullable(),
  completed_at: z.string().nullable(),
})
export type EhiPreviewRun = z.infer<typeof EhiPreviewRunSchema>

export const EhiPackageSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  preservation_status: z.string(),
  accounting_status: z.string(),
  clinical_status: z.string(),
  binding_status: z.string(),
  failure_category: z.string().nullable(),
  source: z.object({ id: z.number(), label: z.string() }).nullable(),
  source_patient_key: z.string().nullable(),
  intake_count: z.number(),
  last_intake_at: z.string().nullable(),
  created_at: z.string().nullable(),
  intake_in_progress: z.boolean(),
  projection_in_progress: z.boolean(),
  projection_interrupted: z.boolean(),
  accounting: z.object({
    members: z.number(),
    members_by_kind: countMap,
    tables: z.number(),
    tables_by_state: countMap,
    source_rows: z.number(),
    malformed_rows: z.number(),
    dispositions: countMap,
    findings_by_code: countMap,
    unreconciled_tables: z.number(),
  }),
  projection: countMap.nullable(),
  preview: z.object({
    state: z.enum(['not_started', 'complete', 'incomplete']),
    generation: z.number().nullable(),
  }),
  proposals: z.object({
    by_status: countMap,
    pending: nestedCountMap,
    pending_unreviewable: z.number(),
  }),
  preview_run: EhiPreviewRunSchema.nullable(),
})
export type EhiPackage = z.infer<typeof EhiPackageSchema>

export const EhiProposalSchema = z.object({
  id: z.number(),
  package_id: z.number(),
  mapping: z.string(),
  mapping_version: z.string(),
  kind: z.enum(['create', 'update', 'corroborate', 'possible_duplicate', 'retain']),
  status: z.enum(['pending', 'accepted', 'rejected', 'retained', 'linked', 'superseded']),
  reviewable: z.boolean(),
  allowed_decisions: z.array(z.string()),
  target: z.object({ table: z.string(), id: z.number().nullable() }).nullable(),
  candidate_ids: z.array(z.number()),
  candidate_total: z.number(),
  candidates_truncated: z.boolean(),
  proposed: looseRecord.nullable(),
  before: looseRecord.nullable(),
  preview_digest: z.string(),
  decided_at: z.string().nullable(),
})
export type EhiProposal = z.infer<typeof EhiProposalSchema>

export type EhiDecision = 'accept' | 'approve_update' | 'link_existing' | 'reject' | 'retain_evidence'

export const EhiSourceSuggestionSchema = z.object({
  id: z.number(),
  label: z.string(),
  dictionary_matches: z.boolean(),
})
export type EhiSourceSuggestion = z.infer<typeof EhiSourceSuggestionSchema>

export const EhiPendingSchema = z.object({
  pending: z.preprocess(emptyArrayAsObject, z.record(z.string(), z.object({
    count: z.number(),
    package_ids: z.array(z.number()),
  }))),
})
export type EhiPending = z.infer<typeof EhiPendingSchema>['pending']

/** Query parameter a record page's banner links with, to open one package's review. */
export const EHI_PACKAGE_QUERY = 'ehi_package'

export function ehiReviewUrl(patientId: number, packageId: number): string {
  return `/phr/imports?patient_id=${patientId}&${EHI_PACKAGE_QUERY}=${packageId}#/imports`
}

function base(patientId: number): string {
  return `/api/phr/patients/${patientId}/ehi`
}

export async function listEhiUploads(patientId: number): Promise<EhiUpload[]> {
  const data: unknown = await fetchWrapper.get(`${base(patientId)}/uploads`)
  return z.object({ uploads: z.array(EhiUploadSchema) }).parse(data).uploads
}

export async function getEhiUpload(patientId: number, uploadId: number): Promise<EhiUpload> {
  const data: unknown = await fetchWrapper.get(`${base(patientId)}/uploads/${uploadId}`)
  return z.object({ upload: EhiUploadSchema }).parse(data).upload
}

export async function startEhiUpload(patientId: number, fileSizeBytes: number): Promise<EhiUpload> {
  const data: unknown = await fetchWrapper.post(`${base(patientId)}/uploads`, { file_size_bytes: fileSizeBytes })
  return z.object({ upload: EhiUploadSchema }).parse(data).upload
}

export async function appendEhiChunk(patientId: number, uploadId: number, chunk: Blob, offset: number, signal?: AbortSignal): Promise<EhiUpload> {
  const form = new FormData()
  form.append('chunk', chunk, 'chunk.bin')
  form.append('offset', String(offset))
  const data: unknown = await fetchWrapper.post(`${base(patientId)}/uploads/${uploadId}/chunks`, form, signal ? { signal } : {})
  return z.object({ upload: EhiUploadSchema }).parse(data).upload
}

export async function queueEhiIntake(patientId: number, uploadId: number): Promise<EhiUpload> {
  const data: unknown = await fetchWrapper.post(`${base(patientId)}/uploads/${uploadId}/intake`, {})
  return z.object({ upload: EhiUploadSchema }).parse(data).upload
}

export async function listEhiPackages(patientId: number): Promise<EhiPackage[]> {
  const data: unknown = await fetchWrapper.get(`${base(patientId)}/packages`)
  return z.object({ packages: z.array(EhiPackageSchema) }).parse(data).packages
}

export async function getEhiPackage(patientId: number, packageId: number): Promise<EhiPackage> {
  const data: unknown = await fetchWrapper.get(`${base(patientId)}/packages/${packageId}`)
  return z.object({ package: EhiPackageSchema }).parse(data).package
}

export async function getEhiBindingSuggestions(patientId: number, packageId: number): Promise<EhiSourceSuggestion[]> {
  const data: unknown = await fetchWrapper.get(`${base(patientId)}/packages/${packageId}/binding`)
  return z.object({ suggestions: z.array(EhiSourceSuggestionSchema) }).parse(data).suggestions
}

export type EhiBindChoice = { source_id: number } | { new_source_label: string }

export async function bindEhiPackage(patientId: number, packageId: number, choice: EhiBindChoice): Promise<EhiPackage> {
  const data: unknown = await fetchWrapper.post(`${base(patientId)}/packages/${packageId}/binding`, choice)
  return z.object({ package: EhiPackageSchema }).parse(data).package
}

export async function queueEhiPreview(patientId: number, packageId: number): Promise<EhiPackage> {
  const data: unknown = await fetchWrapper.post(`${base(patientId)}/packages/${packageId}/preview`, {})
  return z.object({ package: EhiPackageSchema }).parse(data).package
}

const PROPOSAL_PAGE = 500

/** Every proposal of the package's completed preview, following the pages. */
export async function listAllEhiProposals(patientId: number, packageId: number): Promise<EhiProposal[]> {
  const all: EhiProposal[] = []
  let afterId = 0
  for (;;) {
    const data: unknown = await fetchWrapper.get(`${base(patientId)}/packages/${packageId}/proposals?limit=${PROPOSAL_PAGE}&after_id=${afterId}`)
    const page = z.object({ proposals: z.array(EhiProposalSchema), next_after_id: z.number().nullable() }).parse(data)
    all.push(...page.proposals)
    if (page.next_after_id === null) {
      return all
    }
    afterId = page.next_after_id
  }
}

export interface EhiReviewResult {
  proposal: EhiProposal
  record: { table: string, id: number } | null
}

export async function reviewEhiProposal(patientId: number, proposal: EhiProposal, decision: EhiDecision, linkId?: number): Promise<EhiReviewResult> {
  const data: unknown = await fetchWrapper.post(`${base(patientId)}/proposals/${proposal.id}/review`, {
    decision,
    preview_digest: proposal.preview_digest,
    ...(linkId !== undefined ? { link_id: linkId } : {}),
  })
  return z.object({
    proposal: EhiProposalSchema,
    record: z.object({ table: z.string(), id: z.number() }).nullable(),
  }).parse(data)
}

export async function getEhiPending(patientId: number): Promise<EhiPending> {
  const data: unknown = await fetchWrapper.get(`${base(patientId)}/pending`)
  return EhiPendingSchema.parse(data).pending
}

const ERROR_TEXT: Record<string, string> = {
  patient_archived: 'This patient is archived, so nothing new can be imported into it.',
  package_too_large: 'This file is larger than an EHI export can be.',
  invalid_chunk: 'Part of the file could not be sent. Try again.',
  upload_state_invalid: 'The upload got out of step with the server. Try again.',
  upload_incomplete: 'The file did not finish uploading.',
  upload_expired: 'This upload expired before it was imported. Upload the file again.',
  not_queueable: 'This upload is already being imported, or has finished.',
  storage_failed: 'The file could not be stored. Try again in a moment.',
  queue_failed: 'The import could not be started. Try again.',
  not_ehi: 'This ZIP is not an Epic EHI Tables export.',
  multiple_patients: 'This export holds records for more than one patient, so it was not imported.',
  no_patient: 'This export does not say which patient it belongs to, so it was not imported.',
  encrypted: 'This ZIP is encrypted. Export it again without a password.',
  unreadable: 'This file could not be read as a ZIP.',
  too_many_members: 'This export has more files than PHR can import at once.',
  member_too_large: 'A file inside this export is larger than PHR can import.',
  expansion_ratio: 'This ZIP expands to far more than its size, so it was not imported.',
  path_unsafe: 'This ZIP contains an unsafe file path, so it was not imported.',
  path_collision: 'This ZIP contains two files with the same name, so it was not imported.',
  unscoped_patient_row: 'Some rows in this export do not say which patient they belong to, so it was not imported.',
  access_revoked: 'You no longer have access to import into this patient.',
  lease_lost: 'Another import of this export was running at the same time. Try again.',
  not_ready: 'This export has not finished being stored and checked.',
  not_bound: 'Confirm where this export came from first.',
  label_taken: 'You already have a source with that name. Choose it from the list, or use another name.',
  label_invalid: 'Give the source a short name.',
  already_bound: 'This export is already linked to another source.',
  source_key_mismatch: 'That source holds records for a different patient identifier.',
  stale_preview: 'This proposal changed since the review screen loaded. Reload and review it again.',
  stale_target: 'The record this proposal would change was edited since. Reload and review it again.',
  stale: 'This step stalled and was restarted.',
  preview_incomplete: 'The review is being prepared again. Wait for it to finish.',
  not_pending: 'This proposal was already decided.',
  decision_not_allowed: 'That decision does not apply to this proposal.',
  resource_limit: 'This export is too large to prepare for review in one pass. Try again to continue.',
  unexpected: 'Something went wrong. Try again.',
}

/** A sentence for a fixed reason code, or the text as given when it is not one. */
export function describeEhiError(reason: string | null | undefined): string {
  if (!reason) {
    return 'Something went wrong. Try again.'
  }
  return ERROR_TEXT[reason] ?? reason
}
