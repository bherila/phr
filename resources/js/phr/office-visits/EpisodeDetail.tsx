import { CalendarDays } from 'lucide-react'
import { useEffect, useState } from 'react'

import { DetailSkeleton } from '@/components/record-list/Skeleton'
import { Button } from '@/components/ui/button'
import { type PhrReviewDecision, ReviewActions } from '@/phr/clinical/review'
import { recordReviewDecision } from '@/phr/clinical/reviewDecision'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { EPISODE_KIND_LABELS, EPISODE_STATUS_LABELS, episodeEncounterLabel, episodePeriod } from '@/phr/office-visits/episodeLabels'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { PhrRecordNotFound } from '@/phr/shell/PhrRecordNotFound'
import type { PhrNavTarget } from '@/phr/shell/phrRoutes'
import { type PhrEpisode, type PhrEpisodeEncounter, PhrEpisodeResponseSchema } from '@/phr/types'

interface EpisodeDetailProps {
  patientId: number
  recordId: string
  onDrill?: (target: PhrNavTarget) => void
}

/**
 * An episode of care (#415) and the visits grouped under it, opened from a visit or from
 * the Review page. Agents group the visits; a person confirms or rejects the episode here.
 */
export default function EpisodeDetail({ patientId, recordId, onDrill }: EpisodeDetailProps) {
  const [episode, setEpisode] = useState<PhrEpisode | null>(null)
  const [encounters, setEncounters] = useState<PhrEpisodeEncounter[]>([])
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [canManage, setCanManage] = useState(false)
  const [reviewBusy, setReviewBusy] = useState(false)

  useEffect(() => {
    let cancelled = false

    async function load(): Promise<void> {
      setBusy(true)
      setError(null)
      setNotFound(false)
      setEpisode(null)

      try {
        const result = await fetchPhrDetail(`/api/phr/patients/${patientId}/episodes/${recordId}`, PhrEpisodeResponseSchema)
        if (cancelled) return

        setEpisode(result.data?.episode ?? null)
        setEncounters(result.data?.encounters ?? [])
        setCanManage(result.data?.can_manage ?? false)
        setNotFound(result.notFound)
      } catch (caught) {
        if (!cancelled) {
          setError(errorMessage(caught))
          setCanManage(false)
        }
      } finally {
        if (!cancelled) setBusy(false)
      }
    }

    void load()

    return () => {
      cancelled = true
    }
  }, [patientId, recordId])

  async function review(decision: PhrReviewDecision): Promise<void> {
    setReviewBusy(true)
    setError(null)
    try {
      const raw: unknown = await recordReviewDecision(`/api/phr/patients/${patientId}/episodes/${recordId}`, decision)
      setEpisode(PhrEpisodeResponseSchema.parse(raw).episode)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setReviewBusy(false)
    }
  }

  if (notFound) {
    return <PhrRecordNotFound />
  }

  const facts = episode
    ? [
      { label: 'Kind', value: episode.kind ? (EPISODE_KIND_LABELS[episode.kind] ?? episode.kind) : 'Not recorded' },
      { label: 'Status', value: episode.status ? (EPISODE_STATUS_LABELS[episode.status] ?? episode.status) : 'Not recorded' },
      { label: 'Period', value: episodePeriod(episode) ?? 'Not recorded' },
    ]
    : []

  return (
    <div className="space-y-4">
      {error && (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}
      {busy && !episode && <DetailSkeleton label="Loading episode" facts={3} rows={0} />}
      {episode && (
        <>
          <section className="rounded-lg border border-border bg-card p-4">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="text-lg font-semibold text-card-foreground">{episode.title}</h2>
              {reviewStatusBadge(episode.review_status)}
              {canManage && (
                <ReviewActions status={episode.review_status} label={episode.title} busy={reviewBusy} onReview={(decision) => void review(decision)} />
              )}
            </div>
            <p className="mt-1 text-sm text-muted-foreground">Episode of care #{episode.id}</p>
            <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-3">
              {facts.map((fact) => (
                <div key={fact.label}>
                  <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{fact.label}</dt>
                  <dd className="text-card-foreground">{fact.value}</dd>
                </div>
              ))}
            </dl>
            {episode.reason && <p className="mt-4 border-t border-border pt-4 text-sm text-muted-foreground">{episode.reason}</p>}
          </section>
          <section className="rounded-lg border border-border bg-card p-4" aria-label="Visits in this episode">
            <h2 className="flex items-center gap-2 font-semibold text-card-foreground">
              <CalendarDays className="size-4 text-primary" />
              Visits in this episode
            </h2>
            {encounters.length === 0 ? (
              <p className="mt-4 text-sm text-muted-foreground">No visits are grouped under this episode.</p>
            ) : (
              <ul className="mt-4 space-y-2">
                {encounters.map((encounter) => (
                  <li key={encounter.id}>
                    <Button
                      type="button"
                      variant="outline"
                      className="h-auto w-full justify-start px-3 py-2 text-left"
                      onClick={() => onDrill?.({ id: 'office-visit-detail', instance: String(encounter.id) })}
                    >
                      <span className="min-w-0 flex-1 truncate text-sm font-medium">{episodeEncounterLabel(encounter)}</span>
                      {reviewStatusBadge(encounter.review_status)}
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}
    </div>
  )
}
