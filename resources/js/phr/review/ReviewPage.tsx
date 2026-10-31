import { Fragment, type ReactElement, useCallback, useEffect, useMemo, useState } from 'react'

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { AiResultDetails, type AiResultView,aiResultView } from '@/phr/imports/ai/AiImportReview'
import { ehiFailureText, ehiImportsUrl,ehiReviewUrl } from '@/phr/imports/ehi/ehiApi'
import { formatSourceDate, proposalView, retainReason } from '@/phr/imports/ehi/ehiProposalView'
import { PHR_PAGES, type PhrListPageProps } from '@/phr/shell/phrPages'
import { patientPageUrl, type PhrPageId } from '@/phr/shell/phrRoutes'

import { decideReviewItems, getReviewQueue, type ReviewDecision, type ReviewGroup, type ReviewItem, type ReviewQueue, sectionOf } from './reviewApi'
import { setReviewSummary } from './reviewSummary'

type ChangeKind = 'new' | 'update' | 'duplicate' | 'evidence' | 'kept'

const CHANGE: Record<ChangeKind, { label: string, dot: string }> = {
  new: { label: 'New', dot: 'bg-success' },
  update: { label: 'Updates existing', dot: 'bg-info' },
  duplicate: { label: 'Possible duplicate', dot: 'bg-warning' },
  evidence: { label: 'Already in PHR', dot: 'bg-muted-foreground' },
  kept: { label: 'Evidence only', dot: 'bg-muted-foreground' },
}

/** One table row: what the proposal is, where it lands, and what approving it changes. */
export interface ReviewRow {
  item: ReviewItem
  section: PhrPageId
  source: string
  title: string
  detail: string | null
  change: ChangeKind
  changeDetail: string | null
  action: { label: 'Open' | 'Compare', href: string } | null
  /** Possible duplicates start unchecked: approving one adds it as a separate record. */
  checkedByDefault: boolean
  /**
   * An AI proposal is never part of a batch: accepting it imports every value it holds, so
   * it is approved on its own, from the view that shows all of them.
   */
  ai: { view: AiResultView, documentHref: string | null } | null
}

function joined(parts: (string | null | undefined)[]): string | null {
  const text = parts.filter((part): part is string => Boolean(part)).join(' · ')
  return text === '' ? null : text
}

export function reviewRow(item: ReviewItem, group: ReviewGroup, patientId: number): ReviewRow {
  const section = sectionOf(item)
  const source = joined([group.label, group.name]) ?? group.label
  if (item.type === 'record') {
    return {
      item, section, source, title: item.title, detail: item.detail,
      change: item.change,
      changeDetail: item.change === 'new' ? 'Not in the record yet' : 'Changed by its source since it was added',
      action: { label: 'Open', href: patientPageUrl(patientId, section, item.id) },
      checkedByDefault: true,
      ai: null,
    }
  }
  if (item.type === 'ai_result') {
    const view = aiResultView(item.result)
    return {
      item, section, source, title: view.title,
      detail: joined(view.groups.length > 0 ? view.groups.map((nested) => `${nested.records.length} ${nested.label}`) : view.fields.slice(0, 2).map((field) => field.value)),
      change: 'new',
      changeDetail: 'Added to the record when you approve it',
      action: null,
      checkedByDefault: false,
      ai: { view, documentHref: group.document_id !== null ? patientPageUrl(patientId, 'documents', group.document_id) : null },
    }
  }
  const proposal = item.proposal
  const view = proposalView(proposal)
  const change: ChangeKind = proposal.kind === 'update' ? 'update' : proposal.kind === 'possible_duplicate' ? 'duplicate' : proposal.kind === 'corroborate' ? 'evidence' : proposal.kind === 'retain' ? 'kept' : 'new'
  const changeDetail = {
    new: 'Not in the record yet',
    update: view.changes.length > 0 ? view.changes.slice(0, 2).map((field) => `${field.field} ${field.before} → ${field.after}`).join('; ') : 'Changes a record already in PHR',
    duplicate: 'Looks like a record already in PHR; approving adds it as a separate record',
    evidence: 'Matches a record already in PHR; approving adds the export as its evidence',
    kept: retainReason(proposal) ?? 'Kept with the export; nothing is added',
  }[change]
  return {
    item, section, source, title: view.title, detail: joined([view.date, view.detail]),
    change, changeDetail,
    action: { label: change === 'new' || change === 'kept' ? 'Open' : 'Compare', href: ehiReviewUrl(patientId, item.package_id) },
    checkedByDefault: change !== 'duplicate',
    ai: null,
  }
}

/** A plural noun for a section, for chips such as "3 conditions". */
function sectionNoun(section: PhrPageId, count: number): string {
  const label = PHR_PAGES[section].label.toLowerCase()
  if (count !== 1) return label
  return { labs: 'lab result', vitals: 'vital', 'office-visits': 'visit', medications: 'medication', conditions: 'condition', procedures: 'procedure', immunizations: 'immunization', allergies: 'allergy', documents: 'document' }[section as string] ?? label
}

function sectionChips(rows: ReviewRow[]): string[] {
  const counts = new Map<PhrPageId, number>()
  for (const row of rows) counts.set(row.section, (counts.get(row.section) ?? 0) + 1)
  return [...counts.entries()].map(([section, count]) => `${count} ${sectionNoun(section, count)}`)
}

function formatWhen(iso: string | null): string | null {
  return iso === null ? null : formatSourceDate(iso)
}

function groupMeta(group: ReviewGroup): string | null {
  const when = formatWhen(group.date)
  if (group.origin === 'ai') {
    const where = group.execution_mode === 'external' ? 'Processed from the AI Agent Inbox' : 'Processed by server-side AI'
    return joined([where, when])
  }
  if (group.origin === 'ehi') return joined([when ? `Imported ${when}` : null, 'parsed by PHR, no AI'])
  return joined([when ? `Updated ${when}` : null, group.parsed_by_phr ? 'parsed by PHR, no AI' : null])
}

interface ViewGroup {
  key: string
  title: string
  meta: string | null
  aiRead: boolean
  rows: ReviewRow[]
  note: string | null
  moreHref: string | null
}

function viewGroups(queue: ReviewQueue, patientId: number, bySection: boolean): ViewGroup[] {
  const sourceGroups = queue.groups.map((group) => {
    const rows = group.items.map((item) => reviewRow(item, group, patientId))
    const unlisted = group.total - group.items.length
    const note = unlisted > 0
      ? `Showing the first ${group.items.length} of ${group.total}.`
      : group.truncated_sections.length > 0
        ? `More than ${queue.itemLimit} records wait in ${group.truncated_sections.map((section) => (PHR_PAGES[section as PhrPageId]?.label ?? section)).join(', ')}; the rest are on those pages.`
        : null
    return {
      key: group.key,
      title: joined([group.label, group.name]) ?? group.label,
      meta: groupMeta(group),
      aiRead: group.ai_read,
      rows,
      note,
      moreHref: unlisted > 0 && group.origin === 'ehi' && group.package_id !== undefined ? ehiReviewUrl(patientId, group.package_id) : unlisted > 0 ? ehiImportsUrl(patientId) : null,
    }
  })
  if (!bySection) return sourceGroups

  const sections = new Map<PhrPageId, ReviewRow[]>()
  for (const row of sourceGroups.flatMap((group) => group.rows)) {
    sections.set(row.section, [...(sections.get(row.section) ?? []), row])
  }
  return [...sections.entries()].map(([section, rows]) => ({
    key: `section:${section}`,
    title: PHR_PAGES[section].label,
    meta: null,
    aiRead: rows.some((row) => row.item.type === 'ai_result'),
    rows,
    note: null,
    moreHref: null,
  }))
}

interface PendingBatch {
  group: ViewGroup
  decision: ReviewDecision
  rows: ReviewRow[]
}

/**
 * Everything proposed for this patient's record, grouped by where it came from (or by
 * section), approved or rejected in batches. Nothing changes the record until a person who
 * manages it confirms a batch; each item still goes through its own review route.
 */
export default function ReviewPage({ patientId }: PhrListPageProps): ReactElement {
  const [queue, setQueue] = useState<ReviewQueue | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [bySection, setBySection] = useState(false)
  const [checked, setChecked] = useState<ReadonlyMap<string, boolean>>(() => new Map())
  const [failures, setFailures] = useState<Readonly<Record<string, string>>>({})
  const [status, setStatus] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [confirming, setConfirming] = useState<PendingBatch | null>(null)
  const [expanded, setExpanded] = useState<ReadonlySet<string>>(() => new Set())
  const toggleExpanded = (key: string): void => {
    setExpanded((previous) => {
      const next = new Set(previous)
      if (!next.delete(key)) next.add(key)
      return next
    })
  }

  const load = useCallback(async (): Promise<void> => {
    setLoadError(null)
    try {
      const next = await getReviewQueue(patientId)
      setQueue(next)
      // The listing carries the sidebar's counts too, so they need no request of their own.
      setReviewSummary(patientId, next.state)
    } catch (caught) {
      setLoadError(ehiFailureText(caught))
    }
  }, [patientId])

  useEffect(() => {
    void load()
  }, [load])

  const groups = useMemo(() => (queue === null ? [] : viewGroups(queue, patientId, bySection)), [queue, patientId, bySection])
  const canManage = queue?.state.canManage ?? false
  const isChecked = (row: ReviewRow): boolean => checked.get(row.item.key) ?? row.checkedByDefault
  const setRowChecked = (keys: string[], value: boolean): void => {
    setChecked((previous) => {
      const next = new Map(previous)
      for (const key of keys) next.set(key, value)
      return next
    })
  }

  async function run(batch: PendingBatch): Promise<void> {
    setConfirming(null)
    setBusy(true)
    setStatus(null)
    const outcome = await decideReviewItems(patientId, batch.rows.map((row) => row.item), batch.decision)
    setFailures((previous) => {
      const next = { ...previous }
      for (const key of outcome.done) delete next[key]
      return { ...next, ...outcome.failed }
    })
    const failed = Object.keys(outcome.failed).length
    const verb = batch.decision === 'approve' ? 'Approved' : 'Rejected'
    setStatus(`${verb} ${outcome.done.length}.${failed > 0 ? ` ${failed} could not be ${batch.decision === 'approve' ? 'approved' : 'rejected'}; each says why below.` : ''}`)
    await load()
    setBusy(false)
  }

  const total = queue?.state.summary.total ?? 0
  const sources = queue?.groups.length ?? 0

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-end gap-4">
        <div className="flex min-w-0 flex-[1_1_320px] flex-col gap-1">
          <h1 className="text-2xl font-semibold text-foreground">Waiting for review</h1>
          <p className="text-sm text-muted-foreground">
            {queue === null
              ? 'Loading…'
              : total === 0
                ? 'Nothing here changes the record until you approve it.'
                : `${total} proposed ${total === 1 ? 'change' : 'changes'} from ${sources} ${sources === 1 ? 'source' : 'sources'}. Nothing here changes the record until you approve it.`}
          </p>
        </div>
        <div role="radiogroup" aria-label="Group by" className="flex rounded-lg bg-secondary p-0.5">
          {[{ value: false, label: 'By source' }, { value: true, label: 'By section' }].map((option) => (
            <button
              key={option.label}
              type="button"
              role="radio"
              aria-checked={bySection === option.value}
              onClick={() => setBySection(option.value)}
              className={cn(
                'h-8 rounded-md px-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                bySection === option.value ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
              )}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      {queue !== null && !canManage && total > 0 && (
        <p role="note" className="rounded-md border border-dashed border-border px-3 py-2 text-sm text-muted-foreground">
          View only: you can see what waits for review, but only someone who manages this record can approve or reject it.
        </p>
      )}
      {status && <p role="status" className="text-sm text-muted-foreground">{status}</p>}
      {loadError && (
        <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          <span>{loadError}</span>
          <Button type="button" variant="outline" size="sm" onClick={() => void load()}>Retry</Button>
        </div>
      )}

      {queue !== null && groups.length === 0 && (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border py-16 text-center">
          <h2 className="text-base font-semibold text-foreground">Nothing waiting for review</h2>
          <p className="max-w-md text-sm text-muted-foreground">Records you import, and what AI reads from documents you send it, appear here until you approve or reject them.</p>
        </div>
      )}

      {groups.map((group) => {
        const batchable = group.rows.filter((row) => row.ai === null)
        const selected = batchable.filter(isChecked)
        const headingId = `review-group-${group.key.replaceAll(/[^a-z0-9]+/gi, '-')}`
        const allChecked = batchable.length > 0 && selected.length === batchable.length
        return (
          <section key={group.key} aria-labelledby={headingId} className="overflow-hidden rounded-xl border border-border bg-card">
            <div className="flex flex-wrap items-center gap-3 border-b border-border px-4 py-4 md:px-5">
              <div className="flex min-w-0 flex-[1_1_320px] flex-col gap-1.5">
                <h2 id={headingId} className="text-base font-semibold text-foreground">{group.title}</h2>
                <div className="flex flex-wrap items-center gap-1.5 text-xs">
                  {group.meta && <span className="text-muted-foreground">{group.meta}</span>}
                  {!bySection && sectionChips(group.rows).map((chip) => (
                    <span key={chip} className="rounded-full border border-border px-2 py-px text-foreground">{chip}</span>
                  ))}
                </div>
              </div>
              {group.aiRead && (
                <span className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                  <span aria-hidden="true" className="size-[7px] rounded-full bg-info" />
                  <span>AI-read: check against the document</span>
                  <span className="sr-only">.</span>
                  <span>Open each one and approve it on its own.</span>
                </span>
              )}
              <Button type="button" variant="outline" disabled={!canManage || busy} onClick={() => setConfirming({ group, decision: 'reject', rows: group.rows })}>
                Reject all
              </Button>
              {batchable.length > 0 && (
                <Button type="button" disabled={!canManage || busy || selected.length === 0} onClick={() => setConfirming({ group, decision: 'approve', rows: selected })}>
                  Approve {selected.length} selected
                </Button>
              )}
            </div>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] border-collapse text-sm">
                <thead>
                  <tr className="text-left text-xs font-semibold text-muted-foreground">
                    <th scope="col" className="w-11 py-2.5 pr-0 pl-4 md:pl-5">
                      {batchable.length > 0 ? (
                        <input
                          type="checkbox"
                          aria-label={`Select all in ${group.title}`}
                          checked={allChecked}
                          disabled={!canManage || busy}
                          onChange={(event) => setRowChecked(batchable.map((row) => row.item.key), event.target.checked)}
                          className="size-4 accent-primary"
                        />
                      ) : <span className="sr-only">Select</span>}
                    </th>
                    <th scope="col" className="px-3 py-2.5">Proposed</th>
                    <th scope="col" className="px-3 py-2.5">{bySection ? 'Source' : 'Section'}</th>
                    <th scope="col" className="px-3 py-2.5">Change</th>
                    <th scope="col" className="px-4 py-2.5 md:pr-5"><span className="sr-only">Actions</span></th>
                  </tr>
                </thead>
                <tbody>
                  {group.rows.map((row) => {
                    const change = CHANGE[row.change]
                    const failure = failures[row.item.key]
                    const open = expanded.has(row.item.key)
                    const detailsId = `review-details-${row.item.key.replaceAll(/[^a-z0-9]+/gi, '-')}`
                    return (
                      <Fragment key={row.item.key}>
                      <tr className="border-t border-border align-top">
                        <td className="py-3 pr-0 pl-4 md:pl-5">
                          {row.ai === null && (
                            <input
                              type="checkbox"
                              aria-label={`Select ${row.title}`}
                              checked={isChecked(row)}
                              disabled={!canManage || busy}
                              onChange={(event) => setRowChecked([row.item.key], event.target.checked)}
                              className="size-4 accent-primary"
                            />
                          )}
                        </td>
                        <td className="px-3 py-3">
                          <span className="block font-semibold text-foreground">{row.title}</span>
                          {row.detail && <span className="mt-0.5 block text-xs text-muted-foreground">{row.detail}</span>}
                          {failure && <span role="alert" className="mt-1 block text-xs text-destructive">{failure}</span>}
                        </td>
                        <td className="px-3 py-3 text-muted-foreground">{bySection ? row.source : PHR_PAGES[row.section].label}</td>
                        <td className="px-3 py-3">
                          <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-foreground">
                            <span aria-hidden="true" className={cn('size-[7px] rounded-full', change.dot)} />
                            {change.label}
                          </span>
                          {row.changeDetail && <span className="mt-0.5 block text-xs text-muted-foreground">{row.changeDetail}</span>}
                        </td>
                        <td className="px-4 py-2 text-right whitespace-nowrap md:pr-5">
                          {row.action && (
                            <Button asChild size="sm" variant="outline">
                              <a href={row.action.href} aria-label={`${row.action.label} ${row.title}`}>{row.action.label}</a>
                            </Button>
                          )}
                          {row.ai && (
                            <Button type="button" size="sm" variant="outline" aria-expanded={open} aria-controls={detailsId} aria-label={`Review ${row.title}`} onClick={() => toggleExpanded(row.item.key)}>
                              {open ? 'Hide' : 'Review'}
                            </Button>
                          )}
                        </td>
                      </tr>
                      {row.ai && open && (
                        <tr id={detailsId} className="bg-muted/20">
                          <td colSpan={5} className="px-4 pt-1 pb-4 md:px-5">
                            <div className="flex flex-col gap-2 pl-8">
                              <p className="text-sm text-muted-foreground">
                                Approving adds everything below to the record. Check it against the document first.
                                {row.ai.documentHref && <> <a href={row.ai.documentHref} className="font-medium text-primary hover:underline">Open the document</a></>}
                              </p>
                              <AiResultDetails view={row.ai.view} />
                              <div className="flex flex-wrap gap-2">
                                <Button type="button" size="sm" disabled={!canManage || busy} aria-label={`Approve ${row.title}`} onClick={() => void run({ group, decision: 'approve', rows: [row] })}>Approve</Button>
                                <Button type="button" size="sm" variant="outline" disabled={!canManage || busy} aria-label={`Reject ${row.title}`} onClick={() => void run({ group, decision: 'reject', rows: [row] })}>Reject</Button>
                              </div>
                            </div>
                          </td>
                        </tr>
                      )}
                      </Fragment>
                    )
                  })}
                </tbody>
              </table>
            </div>
            {group.note && (
              <p className="border-t border-border px-4 py-2 text-xs text-muted-foreground md:px-5">
                {group.note}
                {group.moreHref && <> <a href={group.moreHref} className="font-medium text-primary hover:underline">Review the rest</a></>}
              </p>
            )}
          </section>
        )
      })}

      <AlertDialog open={confirming !== null} onOpenChange={(open) => { if (!open) setConfirming(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {confirming && `${confirming.decision === 'approve' ? 'Approve' : 'Reject'} ${confirming.rows.length} ${confirming.rows.length === 1 ? 'item' : 'items'}?`}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {confirming?.decision === 'approve'
                ? 'They become part of this patient’s record as shown. Anything that changed since this page loaded is left for you to look at again.'
                : 'Rejected records stay out of the record; a rejected record can still be found on its section’s page.'}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => { if (confirming) void run(confirming) }}>
              {confirming?.decision === 'approve' ? `Approve ${confirming.rows.length}` : `Reject ${confirming?.rows.length ?? 0}`}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
