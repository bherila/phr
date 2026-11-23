import { formatDate } from '@/lib/dateFormat'
import type { PhrEpisode, PhrEpisodeEncounter } from '@/phr/types'

/** What an episode is about, as EpisodeDataRules::KINDS names it. */
export const EPISODE_KIND_LABELS: Record<string, string> = {
  illness: 'Illness',
  hospitalization: 'Hospitalization',
  treatment: 'Course of treatment',
  rehabilitation: 'Rehabilitation',
  pregnancy: 'Pregnancy',
  other: 'Other',
}

/** FHIR EpisodeOfCare.status, in words. */
export const EPISODE_STATUS_LABELS: Record<string, string> = {
  planned: 'Planned',
  waitlist: 'Waitlisted',
  active: 'Active',
  onhold: 'On hold',
  finished: 'Finished',
  cancelled: 'Cancelled',
}

function day(value: string | null): string | null {
  return value ? formatDate(value.slice(0, 10)) : null
}

/** "Mar 1, 2026 – Mar 4, 2026", "Since Mar 1, 2026", or null when the episode has no dates. */
export function episodePeriod(episode: Pick<PhrEpisode, 'started_at' | 'ended_at'>): string | null {
  const start = day(episode.started_at)
  const end = day(episode.ended_at)
  if (start && end) return `${start} – ${end}`
  if (start) return `Since ${start}`
  if (end) return `Until ${end}`
  return null
}

/** One line naming a visit grouped under an episode. */
export function episodeEncounterLabel(encounter: PhrEpisodeEncounter): string {
  const started = day(encounter.visit_started_at ?? encounter.visit_date)
  const ended = day(encounter.visit_ended_at)
  const when = started && ended && started !== ended ? `${started} – ${ended}` : started

  return [encounter.visit_type ?? 'Visit', encounter.facility_name, when].filter(Boolean).join(' · ')
}
