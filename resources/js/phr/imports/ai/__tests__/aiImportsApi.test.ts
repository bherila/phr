import { type AiImport, aiImportStatus } from '../aiImportsApi'

function job(overrides: Partial<AiImport>): AiImport {
  return {
    id: 1, document_id: 2, job_type: 'phr_lab_result', status: 'pending', retry_count: 0, can_retry: false,
    result_count: 0, pending_result_count: 0, parsed_at: null, failure_code: null, created_at: null,
    execution_mode: 'external', document_title: null, document_filename: null, ...overrides,
  }
}

describe('aiImportStatus', () => {
  it('says where unfinished work is', () => {
    expect(aiImportStatus(job({})).status).toBe('With your agent')
    expect(aiImportStatus(job({ execution_mode: 'api', status: 'processing' })).status).toBe('With server-side AI')
  })

  it('treats a finished job that proposed nothing as finished, not as still with the agent', () => {
    expect(aiImportStatus(job({ status: 'imported' }))).toEqual({ status: 'Nothing proposed', reviewable: false, retryable: false })
    expect(aiImportStatus(job({ status: 'parsed' })).status).toBe('Nothing proposed')
  })

  it('offers review while proposals wait, and retry after a failure that allows it', () => {
    expect(aiImportStatus(job({ status: 'parsed', result_count: 3, pending_result_count: 2 }))).toEqual({ status: '2 to review', reviewable: true, retryable: false })
    expect(aiImportStatus(job({ status: 'imported', result_count: 3 })).status).toBe('Reviewed')
    expect(aiImportStatus(job({ status: 'failed', can_retry: true }))).toEqual({ status: 'Stopped', reviewable: false, retryable: true })
  })
})
