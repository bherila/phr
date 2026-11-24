import { CalendarSearch } from 'lucide-react'
import { useEffect, useEffectEvent, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import { formatDate } from '@/lib/dateFormat'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { type AttachSummary, attachSummary, linkedVisitLabel, recordDetail, recordKey } from '@/phr/office-visits/AttachRecordsDialog'
import {
  attachRecords,
  fetchLinkSuggestions,
  type LinkableRecord,
  type LinkBatch,
  type SuggestionList,
  type SuggestionListName,
  type SuggestionWindow,
  VISIT_LINK_TYPE_LABELS,
  VISIT_LINK_TYPES,
  type VisitLinkType,
} from '@/phr/office-visits/visitLinksApi'
import { errorMessage } from '@/phr/shared'

/** As many records as one attach request carries; a larger selection goes in several. */
const MAX_PER_REQUEST = 100

const SELECT_CLASS = 'h-8 rounded-md border border-input bg-background px-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'

/** The margins offered, in days either side of the stay. */
const MARGINS: ReadonlyArray<{ days: number, label: string }> = [
  { days: 0, label: 'Exact dates' },
  { days: 1, label: '1 day either side' },
  { days: 2, label: '2 days either side' },
  { days: 3, label: '3 days either side' },
  { days: 7, label: '7 days either side' },
  { days: 14, label: '14 days either side' },
]

type Lists = Partial<Record<VisitLinkType, Record<SuggestionListName, SuggestionList>>>

const EMPTY_LIST: SuggestionList = { records: [], total: 0, next_cursor: null }

interface LinkSuggestionsPanelProps {
  patientId: number
  visitId: number | string
  /** Changes whenever the visit's links change, so the suggestions are read again. */
  refreshKey: number
  /** Called after an attach, with the summary to show; the visit's links are then read again. */
  onAttached: (summary: AttachSummary) => void | Promise<void>
}

function windowText(range: SuggestionWindow): string {
  return range.open
    ? `Dated from ${formatDate(range.from)} through today, while the stay is under way.`
    : range.from === range.to
      ? `Dated ${formatDate(range.from)}.`
      : `Dated ${formatDate(range.from)} through ${formatDate(range.to)}.`
}

/** Several attach responses as one, in order, so one summary covers them. */
function mergeBatches(batches: LinkBatch[], visitId: number): LinkBatch {
  return {
    office_visit_id: batches[0]?.office_visit_id ?? visitId,
    results: batches.flatMap((batch) => batch.results),
    summary: {
      linked: batches.reduce((sum, batch) => sum + batch.summary.linked, 0),
      unchanged: batches.reduce((sum, batch) => sum + batch.summary.unchanged, 0),
      refused: batches.reduce((sum, batch) => sum + batch.summary.refused, 0),
    },
  }
}

/** The lists without the given records, each list's total less those it held. */
function withoutRecords(lists: Lists, keys: ReadonlySet<string>): Lists {
  const prune = (list: SuggestionList): SuggestionList => {
    const records = list.records.filter((record) => !keys.has(recordKey(record.type, record.id)))
    return { ...list, records, total: Math.max(0, list.total - (list.records.length - records.length)) }
  }
  const next: Lists = {}
  for (const type of VISIT_LINK_TYPES) {
    const group = lists[type]
    if (group) next[type] = { suggested: prune(group.suggested), on_other_visits: prune(group.on_other_visits) }
  }

  return next
}

/**
 * "Suggested from these dates" (#450): the records dated inside the stay that no visit holds
 * yet, grouped by type and all ticked, for the person to confirm in one step. A record another
 * visit holds is shown but can't be chosen, as in the attach dialog. Nothing is linked until
 * the person presses Attach selected. The panel stays out of the way: it shows only when there
 * is something to suggest, or once the person has changed the margin.
 */
export default function LinkSuggestionsPanel({ patientId, visitId, refreshKey, onAttached }: LinkSuggestionsPanelProps) {
  const [marginDays, setMarginDays] = useState(0)
  const [touched, setTouched] = useState(false)
  const [range, setRange] = useState<SuggestionWindow | null>(null)
  const [lists, setLists] = useState<Lists>({})
  const [selected, setSelected] = useState<Map<string, LinkableRecord>>(() => new Map())
  const [loading, setLoading] = useState(false)
  const [paging, setPaging] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const request = useRef(0)

  async function load(margin: number): Promise<void> {
    const ticket = ++request.current
    setLoading(true)
    setError(null)
    try {
      const response = await fetchLinkSuggestions(patientId, visitId, { marginDays: margin })
      if (ticket !== request.current) return
      const next: Lists = {}
      const ticked = new Map<string, LinkableRecord>()
      for (const type of VISIT_LINK_TYPES) {
        const group = response.groups[type]
        if (!group) continue
        next[type] = { suggested: group.suggested ?? EMPTY_LIST, on_other_visits: group.on_other_visits ?? EMPTY_LIST }
        for (const record of group.suggested?.records ?? []) ticked.set(recordKey(record.type, record.id), record)
      }
      setRange(response.window)
      setLists(next)
      setSelected(ticked)
    } catch (caught) {
      if (ticket === request.current) setError(errorMessage(caught))
    } finally {
      if (ticket === request.current) setLoading(false)
    }
  }
  const loadSuggestions = useEffectEvent((margin: number) => {
    void load(margin)
  })

  // Read again when the margin changes, or once the visit's links have.
  useEffect(() => {
    loadSuggestions(marginDays)
  }, [patientId, visitId, marginDays, refreshKey])

  async function showMore(type: VisitLinkType, list: SuggestionListName, cursor: string): Promise<void> {
    setPaging(`${type}:${list}`)
    setError(null)
    try {
      const response = await fetchLinkSuggestions(patientId, visitId, { marginDays, page: { type, list, cursor } })
      const page = response.groups[type]?.[list] ?? EMPTY_LIST
      setLists((current) => {
        const group = current[type] ?? { suggested: EMPTY_LIST, on_other_visits: EMPTY_LIST }
        return { ...current, [type]: { ...group, [list]: { ...page, records: [...group[list].records, ...page.records] } } }
      })
      if (list === 'suggested') {
        setSelected((current) => {
          const next = new Map(current)
          for (const record of page.records) next.set(recordKey(record.type, record.id), record)
          return next
        })
      }
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setPaging(null)
    }
  }

  function toggle(record: LinkableRecord, checked: boolean): void {
    setSelected((current) => {
      const next = new Map(current)
      if (checked) next.set(recordKey(record.type, record.id), record)
      else next.delete(recordKey(record.type, record.id))
      return next
    })
  }

  async function attach(): Promise<void> {
    const records = [...selected.values()]
    if (records.length === 0) return
    setSaving(true)
    setError(null)
    const batches: LinkBatch[] = []
    let failure: string | null = null
    try {
      for (let start = 0; start < records.length; start += MAX_PER_REQUEST) {
        const links = records.slice(start, start + MAX_PER_REQUEST).map(({ type, id }) => ({ type, id }))
        batches.push(await attachRecords(patientId, visitId, links))
      }
    } catch (caught) {
      failure = errorMessage(caught)
    }
    if (batches.length > 0) {
      const batch = mergeBatches(batches, Number(visitId))
      // What is on the visit now leaves the list at once, before the suggestions are read again.
      const placed = new Set(batch.results.filter((result) => result.outcome !== 'refused').map((result) => recordKey(result.type, result.id)))
      setLists((current) => withoutRecords(current, placed))
      setSelected((current) => new Map([...current].filter(([key]) => !placed.has(key))))
      const names = new Map(records.map((record) => [recordKey(record.type, record.id), record.name]))
      try {
        await onAttached(attachSummary(batch, names))
      } catch (caught) {
        failure ??= errorMessage(caught)
      }
    }
    setError(failure)
    setSaving(false)
  }

  const groups = VISIT_LINK_TYPES
    .map((type) => ({ type, suggested: lists[type]?.suggested ?? EMPTY_LIST, others: lists[type]?.on_other_visits ?? EMPTY_LIST }))
    .filter((group) => group.suggested.records.length > 0 || group.others.records.length > 0)
  const suggestedCount = groups.reduce((sum, group) => sum + group.suggested.total, 0)
  if (!touched && suggestedCount === 0) return null

  const count = selected.size

  return (
    <section className="mt-3 rounded-md border border-dashed border-border bg-muted/30 p-3" aria-label="Suggested from these dates">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 className="flex items-center gap-2 text-sm font-medium text-card-foreground">
            <CalendarSearch className="size-4 text-primary" />
            Suggested from these dates
          </h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            {range ? `${windowText(range)} ` : ''}Records on no visit yet. Untick any that don't belong to this stay.
          </p>
        </div>
        <label className="flex items-center gap-2 text-xs text-muted-foreground">
          Include
          <select
            className={SELECT_CLASS}
            value={marginDays}
            disabled={saving}
            onChange={(event) => { setTouched(true); setMarginDays(Number(event.target.value)) }}
          >
            {MARGINS.map((margin) => <option key={margin.days} value={margin.days}>{margin.label}</option>)}
          </select>
        </label>
      </div>

      {error && (
        <p role="alert" className="mt-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>
      )}

      <div className="mt-2 space-y-3" aria-busy={loading}>
        {groups.length === 0 && (
          <p className="text-sm text-muted-foreground">{loading ? 'Looking…' : 'Nothing else is dated within these days.'}</p>
        )}
        {groups.map((group) => (
          <div key={group.type}>
            <h4 className="text-xs font-medium text-card-foreground">
              {VISIT_LINK_TYPE_LABELS[group.type]}
              {group.suggested.total > 0 && <span className="font-normal text-muted-foreground"> · {group.suggested.total} suggested</span>}
            </h4>
            <ul className="mt-1 divide-y divide-border rounded-md border border-border bg-card" aria-label={`Suggested ${VISIT_LINK_TYPE_LABELS[group.type].toLocaleLowerCase()}`}>
              {[...group.suggested.records, ...group.others.records].map((record) => {
                const elsewhere = record.linked_visit
                const id = `suggestion-${record.type}-${record.id}`

                return (
                  <li key={recordKey(record.type, record.id)} className="flex items-start gap-3 px-3 py-1.5">
                    <input
                      type="checkbox"
                      className="mt-0.5 size-4 shrink-0 accent-primary"
                      aria-label={`Select ${record.name}`}
                      aria-describedby={id}
                      checked={elsewhere === null && selected.has(recordKey(record.type, record.id))}
                      disabled={elsewhere !== null || saving}
                      onChange={(event) => toggle(record, event.target.checked)}
                    />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm text-foreground">{record.name}</span>
                        {reviewStatusBadge(record.review_status)}
                      </div>
                      <p id={id} className="text-xs text-muted-foreground">
                        {recordDetail(record)}
                        {elsewhere && ` · ${linkedVisitLabel(elsewhere)}. Remove it there first.`}
                      </p>
                    </div>
                  </li>
                )
              })}
            </ul>
            {(['suggested', 'on_other_visits'] as const).map((list) => {
              const current = list === 'suggested' ? group.suggested : group.others
              if (!current.next_cursor) return null
              const cursor = current.next_cursor

              return (
                <Button
                  key={list}
                  type="button"
                  size="sm"
                  variant="ghost"
                  className="mt-1"
                  disabled={paging !== null || saving}
                  onClick={() => void showMore(group.type, list, cursor)}
                >
                  {paging === `${group.type}:${list}`
                    ? 'Loading…'
                    : `Show more ${list === 'suggested' ? '' : 'on other visits '}(${current.records.length} of ${current.total})`}
                </Button>
              )
            })}
          </div>
        ))}
      </div>

      {suggestedCount > 0 && (
        <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
          <p className="text-xs text-muted-foreground" aria-live="polite">
            {count === 0 ? 'Nothing selected.' : `${count} selected.`}
          </p>
          <Button type="button" size="sm" disabled={count === 0 || saving || loading} onClick={() => void attach()}>
            {saving ? 'Attaching…' : 'Attach selected'}
          </Button>
        </div>
      )}
    </section>
  )
}
