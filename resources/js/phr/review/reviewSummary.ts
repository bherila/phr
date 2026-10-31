import { useEffect, useSyncExternalStore } from 'react'
import { z } from 'zod'

import { clearPhrPreload } from '@/fetchPreload'
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

/** Counts a view may reuse without asking again; older ones are fetched on the next view. */
const FRESH_FOR_MS = 30_000

const entries = new Map<number, { state: ReviewSummaryState, fetchedAt: number }>()
/** Patients whose counts a decision or import may have changed since they were read. */
const stale = new Set<number>()
/** How many mounted views show each patient's counts. */
const mounted = new Map<number, number>()
const inFlight = new Map<number, Promise<void>>()
const queued = new Map<number, Promise<void>>()
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
      entries.set(patientId, { state: { summary: parsed.summary, canManage: parsed.can_manage }, fetchedAt: Date.now() })
      stale.delete(patientId)
    } catch {
      // Counts are a convenience: on failure the last ones (if any) stay, still marked
      // stale, and the next view of the patient asks again.
    } finally {
      inFlight.delete(patientId)
      emit()
    }
  })()
  inFlight.set(patientId, request)
  return request
}

function needsFetch(patientId: number): boolean {
  const entry = entries.get(patientId)
  return entry === undefined || stale.has(patientId) || Date.now() - entry.fetchedAt > FRESH_FOR_MS
}

/** The patient's review counts, shared by every view; null until they first arrive. */
export function useReviewSummary(patientId: number): ReviewSummaryState | null {
  const state = useSyncExternalStore(subscribe, () => entries.get(patientId)?.state ?? null, () => null)
  useEffect(() => {
    mounted.set(patientId, (mounted.get(patientId) ?? 0) + 1)
    if (needsFetch(patientId)) void load(patientId)
    return () => {
      mounted.set(patientId, (mounted.get(patientId) ?? 1) - 1)
    }
  }, [patientId])
  return state
}

/** Store counts that arrived with another response, so they need no request of their own. */
export function setReviewSummary(patientId: number, state: ReviewSummaryState): void {
  entries.set(patientId, { state, fetchedAt: Date.now() })
  stale.delete(patientId)
  emit()
}

/**
 * The one call every path makes after it may have changed what waits for review for a
 * patient (a decision, an import, a record added or deleted). The cached counts become
 * stale, so whichever view shows them next fetches them; a view showing them now fetches
 * them at once. A request already running may have read them before the change, so the
 * new one starts after it, and any number of changes made meanwhile share that request.
 */
export function reviewStateChanged(patientId: number): Promise<void> {
  stale.add(patientId)
  // Counts (or lists) the page was loaded with may no longer hold: never serve them now.
  clearPhrPreload()
  if ((mounted.get(patientId) ?? 0) === 0) return Promise.resolve()
  const waiting = queued.get(patientId)
  if (waiting) return waiting
  const next = (inFlight.get(patientId) ?? Promise.resolve()).then(() => {
    queued.delete(patientId)
    return load(patientId)
  })
  queued.set(patientId, next)
  return next
}

/** For tests: forget every patient's counts. */
export function resetReviewSummaries(): void {
  entries.clear()
  stale.clear()
  mounted.clear()
  inFlight.clear()
  queued.clear()
  emit()
}
