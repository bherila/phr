import { Search } from 'lucide-react'
import { type FormEvent, useEffect, useEffectEvent, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { formatDate, formatDateTime } from '@/lib/dateFormat'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { documentTypeLabel } from '@/phr/documents/documentType'
import {
  attachRecords,
  fetchLinkableRecords,
  type LinkableRecord,
  type LinkBatch,
  type LinkResult,
  VISIT_LINK_TYPE_LABELS,
  VISIT_LINK_TYPES,
  type VisitLinkType,
} from '@/phr/office-visits/visitLinksApi'
import { errorMessage } from '@/phr/shared'

/** As many records as one attach may carry (the server's own bound). */
const MAX_SELECTED = 100

const SELECT_CLASS = 'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'

export interface VisitDateRange {
  from: string
  to: string
}

interface AttachRecordsDialogProps {
  patientId: number
  visitId: number | string
  /** The visit's own dates, the range the search starts from. */
  defaultRange: VisitDateRange
  onClose: () => void
  /** Called after an attach, with each record's outcome and the summary to show. */
  onAttached: (batch: LinkBatch, summary: AttachSummary) => void | Promise<void>
}

export interface AttachSummary {
  headline: string
  refusals: string[]
}

interface Selected {
  type: VisitLinkType
  id: number
  name: string
}

/** A record's key across types: its type and id. */
export function recordKey(type: VisitLinkType, id: number): string {
  return `${type}:${id}`
}

/** The visit's dates as a search range: its start through its end, or its one day. An open stay has no end. */
export function visitDateRange(visit: { visit_date: string | null, visit_started_at: string | null, visit_ended_at: string | null }): VisitDateRange {
  const day = (value: string | null): string => value?.slice(0, 10) ?? ''
  const from = day(visit.visit_started_at) || day(visit.visit_date)
  const to = day(visit.visit_ended_at) || (visit.visit_started_at ? '' : day(visit.visit_date))

  return { from, to: to !== '' && to < from ? from : to }
}

export function linkedVisitLabel(visit: NonNullable<LinkableRecord['linked_visit']>): string {
  return visit.date ? `On visit of ${formatDate(visit.date)}` : `On visit #${visit.id}`
}

function refusalText(result: LinkResult, name: string): string {
  switch (result.reason) {
    case 'linked_elsewhere':
      return `${name} is ${result.linked_visit ? linkedVisitLabel(result.linked_visit).replace(/^On/, 'on') : 'on another visit'}. Remove it there first.`
    case 'rejected':
      return `${name} was rejected in review, so it can't be attached.`
    case 'not_found':
      return `${name} is no longer in this record.`
    default:
      return `${name}: ${result.message ?? 'not attached.'}`
  }
}

/** What an attach did, in a line, and each refusal in words. */
export function attachSummary(batch: LinkBatch, names: ReadonlyMap<string, string>): AttachSummary {
  const parts: string[] = []
  const records = (count: number) => `${count} record${count === 1 ? '' : 's'}`
  if (batch.summary.linked > 0) parts.push(`Attached ${records(batch.summary.linked)}.`)
  if (batch.summary.unchanged > 0) parts.push(`${records(batch.summary.unchanged)} already on this visit.`)
  if (batch.summary.refused > 0) parts.push(`${batch.summary.refused} not attached.`)
  const refusals = batch.results
    .filter((result) => result.outcome === 'refused')
    .map((result) => refusalText(result, names.get(recordKey(result.type, result.id)) ?? `Record ${result.id}`))

  return { headline: parts.join(' '), refusals }
}

/** A listed record's date and detail, in a line. */
export function recordDetail(record: LinkableRecord): string {
  const detail = record.type === 'document' ? (record.detail ? documentTypeLabel(record.detail) : null) : record.detail
  const date = record.date ? (record.date.length > 10 ? formatDateTime(record.date) : formatDate(record.date)) : 'No date'

  return [date, detail].filter(Boolean).join(' · ')
}

/**
 * Search the patient's existing records of one type and attach the chosen ones to this visit
 * (#449). The selection is kept across types, so one action can attach labs and a note. A
 * record already on another visit is shown with that visit and can't be chosen: it belongs
 * to one visit, and has to be removed there first.
 */
export default function AttachRecordsDialog({ patientId, visitId, defaultRange, onClose, onAttached }: AttachRecordsDialogProps) {
  const [type, setType] = useState<VisitLinkType>('lab-result')
  const [text, setText] = useState('')
  const [query, setQuery] = useState('')
  const [from, setFrom] = useState(defaultRange.from)
  const [to, setTo] = useState(defaultRange.to)
  const [records, setRecords] = useState<LinkableRecord[]>([])
  const [cursor, setCursor] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [selected, setSelected] = useState<Map<string, Selected>>(() => new Map())
  const request = useRef(0)

  /** Load a page of the current search; the first page replaces the list, a later one extends it. */
  async function loadPage(filters: { type: VisitLinkType, query: string, from: string, to: string }, after: string | null): Promise<void> {
    const ticket = ++request.current
    setLoading(true)
    setError(null)
    try {
      const page = await fetchLinkableRecords(patientId, visitId, { ...filters, q: filters.query, cursor: after })
      if (ticket !== request.current) return
      setRecords((current) => (after ? [...current, ...page.records] : page.records))
      setCursor(page.pagination.has_more ? page.pagination.next_cursor : null)
    } catch (caught) {
      if (ticket === request.current) setError(errorMessage(caught))
    } finally {
      if (ticket === request.current) setLoading(false)
    }
  }
  const loadFirstPage = useEffectEvent((filters: { type: VisitLinkType, query: string, from: string, to: string }) => {
    void loadPage(filters, null)
  })

  // A new type, search or range starts the list over.
  useEffect(() => {
    loadFirstPage({ type, query, from, to })
  }, [patientId, visitId, type, query, from, to])

  function toggle(record: LinkableRecord, checked: boolean): void {
    setSelected((current) => {
      const next = new Map(current)
      if (checked) next.set(recordKey(record.type, record.id), { type: record.type, id: record.id, name: record.name })
      else next.delete(recordKey(record.type, record.id))
      return next
    })
  }

  function search(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault()
    setQuery(text.trim())
  }

  async function attach(): Promise<void> {
    const links = [...selected.values()]
    if (links.length === 0) return
    setSaving(true)
    setError(null)
    try {
      const batch = await attachRecords(patientId, visitId, links.map(({ type: linkType, id }) => ({ type: linkType, id })))
      const names = new Map(links.map((link) => [recordKey(link.type, link.id), link.name]))
      await onAttached(batch, attachSummary(batch, names))
    } catch (caught) {
      setError(errorMessage(caught))
      setSaving(false)
    }
  }

  const count = selected.size
  const typeLabel = VISIT_LINK_TYPE_LABELS[type].toLocaleLowerCase()

  return (
    <Dialog open onOpenChange={(open) => { if (!open && !saving) onClose() }} disablePointerDismissal>
      <DialogContent className="max-h-dvh overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Attach records</DialogTitle>
          <DialogDescription>
            Find records already in this chart and attach them to this visit. A record belongs to one visit at a time.
          </DialogDescription>
        </DialogHeader>
        <form className="grid gap-3 sm:grid-cols-2" onSubmit={search} role="search" aria-label="Find records to attach">
          <label className="grid gap-1 text-sm font-medium">
            Record type
            <select className={SELECT_CLASS} value={type} onChange={(event) => setType(event.target.value as VisitLinkType)}>
              {VISIT_LINK_TYPES.map((option) => (
                <option key={option} value={option}>{VISIT_LINK_TYPE_LABELS[option]}</option>
              ))}
            </select>
          </label>
          <label className="grid gap-1 text-sm font-medium">
            Name contains
            <span className="flex gap-2">
              <Input type="search" value={text} onChange={(event) => setText(event.target.value)} placeholder="e.g. creatinine" />
              <Button type="submit" variant="outline" size="icon" aria-label="Search">
                <Search className="size-4" />
              </Button>
            </span>
          </label>
          <label className="grid gap-1 text-sm font-medium">
            From
            <Input type="date" value={from} max={to || undefined} onChange={(event) => setFrom(event.target.value)} />
          </label>
          <label className="grid gap-1 text-sm font-medium">
            To
            <Input type="date" value={to} min={from || undefined} onChange={(event) => setTo(event.target.value)} />
          </label>
          <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground sm:col-span-2">
            <span>
              {from || to
                ? `Dated ${from ? `from ${formatDate(from)}` : ''}${from && to ? ' ' : ''}${to ? `through ${formatDate(to)}` : ''}.`
                : 'Any date, undated records included.'}
            </span>
            {(from || to) && (
              <Button type="button" size="sm" variant="ghost" onClick={() => { setFrom(''); setTo('') }}>Clear dates</Button>
            )}
            {(from !== defaultRange.from || to !== defaultRange.to) && (defaultRange.from || defaultRange.to) && (
              <Button type="button" size="sm" variant="ghost" onClick={() => { setFrom(defaultRange.from); setTo(defaultRange.to) }}>Use visit dates</Button>
            )}
          </div>
        </form>

        {error && (
          <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>
        )}

        <div aria-busy={loading}>
          {records.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {loading ? 'Searching…' : `No ${typeLabel} match. Try another name or clear the dates.`}
            </p>
          ) : (
            <ul className="divide-y divide-border rounded-md border border-border" aria-label={`${VISIT_LINK_TYPE_LABELS[type]} to attach`}>
              {records.map((record) => {
                const elsewhere = record.linked_visit
                const checked = selected.has(recordKey(record.type, record.id))

                return (
                  <li key={recordKey(record.type, record.id)} className="flex items-start gap-3 px-3 py-2">
                    <input
                      type="checkbox"
                      className="mt-0.5 size-4 shrink-0 accent-primary"
                      aria-label={`Select ${record.name}`}
                      aria-describedby={`attach-${record.type}-${record.id}`}
                      checked={checked}
                      disabled={elsewhere !== null || saving || (!checked && count >= MAX_SELECTED)}
                      onChange={(event) => toggle(record, event.target.checked)}
                    />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-medium text-foreground">{record.name}</span>
                        {reviewStatusBadge(record.review_status)}
                      </div>
                      <p id={`attach-${record.type}-${record.id}`} className="text-xs text-muted-foreground">
                        {recordDetail(record)}
                        {elsewhere && ` · ${linkedVisitLabel(elsewhere)}. Remove it there first.`}
                      </p>
                    </div>
                  </li>
                )
              })}
            </ul>
          )}
          {cursor && (
            <Button type="button" size="sm" variant="outline" className="mt-2" disabled={loading} onClick={() => void loadPage({ type, query, from, to }, cursor)}>
              {loading ? 'Loading…' : 'Show more'}
            </Button>
          )}
        </div>

        <DialogFooter className="items-center sm:justify-between">
          <p className="text-sm text-muted-foreground" aria-live="polite">
            {count === 0 ? 'Nothing selected.' : count >= MAX_SELECTED ? `${count} selected, the most one attach takes.` : `${count} selected.`}
          </p>
          <div className="flex gap-2">
            <Button type="button" variant="outline" disabled={saving} onClick={onClose}>Cancel</Button>
            <Button type="button" disabled={count === 0 || saving} onClick={() => void attach()}>
              {saving ? 'Attaching…' : count === 0 ? 'Attach selected' : `Attach ${count} selected`}
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
