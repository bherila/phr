import { Layers } from 'lucide-react'
import { useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { EPISODE_KIND_LABELS, episodePeriod } from '@/phr/office-visits/episodeLabels'
import type { PhrNavTarget } from '@/phr/shell/phrRoutes'
import { PhrEpisodesResponseSchema } from '@/phr/types'

interface OfficeVisitEpisodesProps {
  patientId: number
  visitId: string
  onDrill?: ((target: PhrNavTarget) => void) | undefined
}

type Episodes = ReturnType<typeof PhrEpisodesResponseSchema.parse>['episodes']

/**
 * The episodes of care a visit is grouped under (#415), such as an earlier admission and the
 * stay it led to. Shown only when there is one; each opens with the other visits it groups.
 */
export default function OfficeVisitEpisodes({ patientId, visitId, onDrill }: OfficeVisitEpisodesProps) {
  const [episodes, setEpisodes] = useState<Episodes>([])
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let cancelled = false
    setEpisodes([])
    setFailed(false)
    fetchWrapper.get(`/api/phr/patients/${patientId}/episodes?office_visit_id=${encodeURIComponent(visitId)}`)
      .then((raw: unknown) => {
        if (!cancelled) setEpisodes(PhrEpisodesResponseSchema.parse(raw).episodes)
      })
      .catch(() => {
        if (!cancelled) setFailed(true)
      })

    return () => {
      cancelled = true
    }
  }, [patientId, visitId])

  if (failed) {
    return <p className="text-sm text-muted-foreground">Episodes of care could not be loaded.</p>
  }
  if (episodes.length === 0) {
    return null
  }

  return (
    <section className="rounded-lg border border-border bg-card p-4" aria-label="Episodes of care">
      <h2 className="flex items-center gap-2 font-semibold text-card-foreground">
        <Layers className="size-4 text-primary" />
        Episodes of care
      </h2>
      <p className="mt-1 text-sm text-muted-foreground">Stories this visit is part of, with the other visits in each.</p>
      <ul className="mt-4 space-y-2">
        {episodes.map((episode) => {
          const others = episode.encounters.filter((encounter) => String(encounter.id) !== visitId).length

          return (
            <li key={episode.id}>
              <Button
                type="button"
                variant="outline"
                className="h-auto w-full justify-start px-3 py-2 text-left"
                onClick={() => onDrill?.({ id: 'episode-detail', instance: String(episode.id) })}
              >
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium">{episode.title}</span>
                  <span className="block text-xs text-muted-foreground">
                    {[
                      episode.kind ? (EPISODE_KIND_LABELS[episode.kind] ?? episode.kind) : null,
                      episodePeriod(episode),
                      others === 1 ? '1 other visit' : `${others} other visits`,
                    ].filter(Boolean).join(' · ')}
                  </span>
                </span>
                {reviewStatusBadge(episode.review_status)}
              </Button>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
