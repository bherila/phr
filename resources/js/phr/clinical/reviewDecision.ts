import { fetchWrapper } from '@/fetchWrapper'
import type { PhrReviewDecision } from '@/phr/clinical/review'
import { reviewStateChanged } from '@/phr/review/reviewSummary'

/**
 * Record a review decision on one clinical record: the only way the browser does. Every
 * section page, detail panel and the Review page come here, so each decision also marks
 * the patient's review counts stale. `version` makes the decision conditional on the record
 * being unchanged since it was read (a 409 otherwise).
 *
 * @param recordUrl the record's session API URL, `/api/phr/patients/{id}/{segment}/{recordId}`
 */
export async function recordReviewDecision(recordUrl: string, decision: PhrReviewDecision, version?: string): Promise<unknown> {
  const patientId = Number(/^\/api\/phr\/patients\/(\d+)\//.exec(recordUrl)?.[1])
  try {
    return await fetchWrapper.patch(`${recordUrl}/review`, { review_status: decision, ...(version ? { version } : {}) })
  } finally {
    // A refusal can mean the record changed elsewhere, so the counts may be stale either way.
    if (patientId > 0) void reviewStateChanged(patientId)
  }
}
