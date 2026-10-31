import { z } from 'zod'

import { fetchWrapper } from '@/fetchWrapper'
import { refreshReviewSummary } from '@/phr/review/reviewSummary'

/**
 * AI imports: documents sent to AI, processed in the person's AI Agent Inbox (by
 * their own connected agent) or by server-side AI with their key, and the records
 * proposed from them. Decisions go through the same review as the agent API.
 */

export const AiImportSchema = z.object({
  id: z.number(),
  document_id: z.number().nullable(),
  job_type: z.string(),
  status: z.string(),
  retry_count: z.number(),
  can_retry: z.boolean(),
  result_count: z.number(),
  pending_result_count: z.number(),
  parsed_at: z.string().nullable(),
  failure_code: z.string().nullable(),
  created_at: z.string().nullable(),
  execution_mode: z.enum(['api', 'external']),
  document_title: z.string().nullable(),
  document_filename: z.string().nullable(),
})
export type AiImport = z.infer<typeof AiImportSchema>

export const AiImportResultSchema = z.object({
  id: z.number(),
  status: z.string(),
  result_index: z.number(),
  data: z.preprocess((value) => (Array.isArray(value) && value.length === 0 ? {} : value), z.record(z.string(), z.unknown())),
})
export type AiImportResult = z.infer<typeof AiImportResultSchema>

export const AiImportDetailSchema = AiImportSchema.extend({ results: z.array(AiImportResultSchema) })
export type AiImportDetail = z.infer<typeof AiImportDetailSchema>

export type ExecutionMode = 'api' | 'external'

function base(patientId: number): string {
  return `/api/phr/patients/${patientId}/ai-imports`
}

export async function listAiImports(patientId: number): Promise<AiImport[]> {
  const data: unknown = await fetchWrapper.get(base(patientId))
  return z.object({ imports: z.array(AiImportSchema) }).parse(data).imports
}

export async function getAiImport(patientId: number, importId: number): Promise<AiImportDetail> {
  const data: unknown = await fetchWrapper.get(`${base(patientId)}/${importId}`)
  return z.object({ import: AiImportDetailSchema }).parse(data).import
}

const ReviewSchema = z.object({ outcome: z.string(), result: z.object({ id: z.number(), status: z.string() }) })

export async function decideAiResult(patientId: number, importId: number, resultId: number, decision: 'accept' | 'reject'): Promise<{ id: number, status: string }> {
  const data: unknown = await fetchWrapper.post(`${base(patientId)}/${importId}/results/${resultId}/${decision}`, {})
  void refreshReviewSummary(patientId)
  return ReviewSchema.parse(data).result
}

export async function retryAiImport(patientId: number, importId: number): Promise<AiImportDetail> {
  const data: unknown = await fetchWrapper.post(`${base(patientId)}/${importId}/retry`, {})
  return z.object({ import: AiImportDetailSchema }).parse(data).import
}

export async function getExecutionMode(): Promise<{ mode: ExecutionMode, mode_chosen: boolean }> {
  const data: unknown = await fetchWrapper.get('/api/user/ai-execution-mode')
  return z.object({ mode: z.enum(['api', 'external']), mode_chosen: z.boolean() }).parse(data)
}

/** File types the Documents store accepts, and its size limit. */
export const DOCUMENT_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'tif', 'tiff', 'txt', 'html', 'htm']
export const DOCUMENT_MAX_BYTES = 51_200 * 1024

export function canKeepAsDocument(file: File): boolean {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
  return DOCUMENT_EXTENSIONS.includes(extension) && file.size <= DOCUMENT_MAX_BYTES
}

/** Store a file as a document, the same as the Documents tab's upload. */
export async function keepAsDocument(patientId: number, file: File): Promise<number> {
  const form = new FormData()
  form.append('file', file, file.name)
  form.append('document_type', 'other')
  form.append('title', file.name)
  const data: unknown = await fetchWrapper.post(`/api/phr/patients/${patientId}/documents`, form)
  return z.object({ document: z.object({ id: z.number() }) }).parse(data).document.id
}

/** Send a stored document to AI: the same request as the Documents tab's button. */
export async function sendDocumentToAi(patientId: number, documentId: number): Promise<{ job_id: number, status: string }> {
  const data: unknown = await fetchWrapper.post(`/api/phr/patients/${patientId}/documents/${documentId}/process`, {})
  return z.object({ job_id: z.number(), status: z.string() }).parse(data)
}

/** Where an AI import stands, in a few words. */
export function aiImportStatus(job: AiImport): { status: string, reviewable: boolean, retryable: boolean } {
  if (job.status === 'failed') return { status: 'Stopped', reviewable: false, retryable: job.can_retry }
  // Finished: parsed or imported, or results already in.
  if (job.status === 'parsed' || job.status === 'imported' || job.result_count > 0) {
    if (job.pending_result_count > 0) return { status: `${job.pending_result_count} to review`, reviewable: true, retryable: false }
    return job.result_count > 0
      ? { status: 'Reviewed', reviewable: true, retryable: false }
      : { status: 'Nothing proposed', reviewable: false, retryable: false }
  }
  return { status: job.execution_mode === 'external' ? 'With your agent' : 'With server-side AI', reviewable: false, retryable: false }
}
