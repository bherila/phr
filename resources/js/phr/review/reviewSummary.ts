import { useEffect, useSyncExternalStore } from 'react'
import { z } from 'zod'

import { fetchWrapper } from '@/fetchWrapper'

/**
 * A patient's review counts, shared by everything that shows them (the sidebar, its
 * small-screen sheet, the Review page): one request per patient, kept until a decision
 * is recorded somewhere, then fetched again so the counts follow without a reload.
 */
const SectionCountSchema = z.object({ pending: z.number(), records: z.number() })

export const ReviewSummarySchema = z.object({
  total: z.number(),
  sections: z.preprocess((value) => (Array.isArray(value) && value.length === 0 ? {} : value), z.record(z.string(), SectionCountSchema)),
  ai_results: z.number(),
})
export type ReviewSummary = z.infer<typeof ReviewSummarySchema>

export interface ReviewSummaryState {
  summary: ReviewSummary
  canManage: boolean
}

const ResponseSchema = z.object({ summary: ReviewSummarySchema, can_manage: z.boolean() })

const entries = new Map<number, ReviewSummaryState | null>()
const inFlight = new Map<number, Promise<void>>()
const listeners = new Set<() => void>()

function emit(): void {
  for (const listener of listeners) listener()
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

function load(patientId: number): Promise<void> {
  const pending = inFlight.get(patientId)
  if (pending) return pending
  const request = (async () => {
    try {
      const parsed = ResponseSchema.parse(await fetchWrapper.get(`/api/phr/patients/${patientId}/review/summary`))
      entries.set(patientId, { summary: parsed.summary, canManage: parsed.can_manage })
    } catch {
      // Counts are a convenience: without them the sidebar shows none, and the
      // next decision or patient view tries again.
      entries.delete(patientId)
    } finally {
      inFlight.delete(patientId)
      emit()
    }
  })()
  inFlight.set(patientId, request)
  return request
}

/** The patient's review counts, fetched once and shared; null until they arrive. */
export function useReviewSummary(patientId: number): ReviewSummaryState | null {
  const state = useSyncExternalStore(subscribe, () => entries.get(patientId) ?? null, () => null)
  useEffect(() => {
    if (!entries.has(patientId)) void load(patientId)
  }, [patientId])
  return state
}

/** Store counts that arrived with another response, so they need no request of their own. */
export function setReviewSummary(patientId: number, state: ReviewSummaryState): void {
  entries.set(patientId, state)
  emit()
}

/** A decision was recorded for this patient: fetch the counts again. */
export function refreshReviewSummary(patientId: number): Promise<void> {
  // A request already running may have read the counts before the decision.
  const running = inFlight.get(patientId)
  return (running ?? Promise.resolve()).then(() => load(patientId))
}

/** For tests: forget every patient's counts. */
export function resetReviewSummaries(): void {
  entries.clear()
  inFlight.clear()
  emit()
}
