import { Check, CircleAlert, LoaderCircle } from 'lucide-react'
import type { ReactElement, ReactNode } from 'react'
import { useCallback, useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Progress } from '@/components/ui/progress'
import { formatBytes } from '@/lib/utils'

import {
  bindEhiPackage,
  describeEhiError,
  ehiFailureText,
  type EhiPackage,
  type EhiSourceSuggestion,
  type EhiUpload,
  getEhiBindingSuggestions,
  getEhiPackage,
  getEhiUpload,
  pendingProposalCount,
  queueEhiIntake,
  queueEhiPreview,
} from './ehiApi'
import { MAPPING_NOUNS } from './ehiProposalView'
import type { EhiUploadJob } from './EhiUploadProvider'

/** How often the page asks the server for progress while it works. */
export const EHI_POLL_MS = 2000

const DEFAULT_SOURCE_LABEL = 'Epic EHI export'

/** `action`: waiting on the person, not on the server. */
type StageState = 'waiting' | 'active' | 'action' | 'done' | 'failed'

const count = new Intl.NumberFormat('en-US')

function Stage({ number, title, state, children }: { number: number, title: string, state: StageState, children?: ReactNode }): ReactElement {
  const icon = state === 'done'
    ? <Check className="size-4" aria-hidden="true" />
    : state === 'failed'
      ? <CircleAlert className="size-4" aria-hidden="true" />
      : state === 'active'
        ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
        : <span aria-hidden="true">{number}</span>
  const tone = state === 'done'
    ? 'bg-primary text-primary-foreground'
    : state === 'failed'
      ? 'bg-destructive text-white'
      : state === 'active' || state === 'action'
        ? 'border-2 border-primary text-primary'
        : 'border border-border text-muted-foreground'
  const statusWord = { waiting: 'not started', active: 'in progress', action: 'needs you', done: 'done', failed: 'stopped' }[state]

  return (
    <li className="flex gap-3 border-t border-border py-3 first:border-t-0">
      <span className={`flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${tone}`}>{icon}</span>
      <div className="flex min-w-0 flex-1 flex-col gap-1">
        <span className="font-medium text-foreground">{title}<span className="sr-only">, {statusWord}</span></span>
        {children}
      </div>
    </li>
  )
}

/**
 * Where every source row went, in display order. The summary reconciles each table
 * (source rows = unreadable + every disposition), so these always add up to the total.
 */
const DISPOSITIONS: { key: string, label: string, note: string }[] = [
  { key: 'pending_review', label: 'Waiting for your review', note: 'Became proposals' },
  { key: 'projected', label: 'Added to records', note: 'Accepted proposals, with this export as their source' },
  { key: 'corroborating', label: 'Matched records already in PHR', note: 'Added as evidence; the records did not change' },
  { key: 'retained', label: 'Kept as evidence', note: 'Not imported: deleted in the source, or not linked to this patient' },
  { key: 'quarantined', label: 'Set aside', note: 'Missing the identifier needed to track them' },
  { key: 'retained_unmapped', label: 'Kept, not mapped yet', note: 'Stored with the export; later versions of PHR may read them' },
  { key: 'out_of_scope', label: 'Outside PHR’s scope', note: 'Billing and administrative tables, stored but not read' },
]

function AccountingTable({ pkg }: { pkg: EhiPackage }): ReactElement {
  const accounting = pkg.accounting
  const withRows = accounting.tables_by_state['parsed'] ?? 0
  const empty = (accounting.tables_by_state['header_only'] ?? 0) + (accounting.tables_by_state['empty'] ?? 0)
  const proposals = pkg.preview_run?.status === 'completed' ? pkg.preview_run.counts : null
  const known = new Set(DISPOSITIONS.map((disposition) => disposition.key))
  const rows = DISPOSITIONS
    .map((disposition) => ({ ...disposition, value: accounting.dispositions[disposition.key] ?? 0 }))
    .filter((row) => row.value > 0)
  for (const [key, value] of Object.entries(accounting.dispositions)) {
    if (!known.has(key) && value > 0) rows.push({ key, label: key.replaceAll('_', ' '), note: '', value })
  }
  if (accounting.malformed_rows > 0) {
    rows.push({ key: 'malformed', label: 'Could not be read', note: 'Kept with the export', value: accounting.malformed_rows })
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">
        {count.format(accounting.members)} files kept · {count.format(accounting.tables)} tables read ({count.format(withRows)} with rows, {count.format(empty)} empty)
      </p>
      <dl className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 text-sm">
        {rows.map((row) => (
          <div key={row.key} className="contents">
            <dt className="border-t border-border py-2"><span className="text-foreground">{row.label}</span>{row.note && <span className="block text-xs text-muted-foreground">{row.note}</span>}</dt>
            <dd className="border-t border-border py-2 text-right tabular-nums">{count.format(row.value)}</dd>
          </div>
        ))}
        <div className="contents font-semibold">
          <dt className="border-t-2 border-border py-2">Every row accounted for</dt>
          <dd className="border-t-2 border-border py-2 text-right tabular-nums">{count.format(accounting.source_rows)}</dd>
        </div>
      </dl>
      {accounting.unreconciled_tables > 0 && (
        <p role="alert" className="text-sm text-destructive">{accounting.unreconciled_tables} tables did not add up and need attention before review.</p>
      )}
      {proposals && Object.keys(proposals).length > 0 && (
        <p className="text-sm text-muted-foreground">
          Proposed: {Object.entries(proposals).map(([mapping, kinds]) => {
            const total = Object.values(kinds).reduce((sum, value) => sum + value, 0)
            const noun = MAPPING_NOUNS[mapping]
            return `${count.format(total)} ${noun ? (total === 1 ? noun.one : noun.many) : mapping}`
          }).join(', ')}.
        </p>
      )}
    </div>
  )
}

interface BindingPanelProps {
  patientId: number
  pkg: EhiPackage
  canBind: boolean
  onBound: (pkg: EhiPackage) => void
}

function BindingPanel({ patientId, pkg, canBind, onBound }: BindingPanelProps): ReactElement {
  const [suggestions, setSuggestions] = useState<EhiSourceSuggestion[] | null>(null)
  const [choice, setChoice] = useState<number | 'new'>('new')
  const [label, setLabel] = useState(DEFAULT_SOURCE_LABEL)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    getEhiBindingSuggestions(patientId, pkg.id).then((loaded) => {
      if (cancelled) return
      setSuggestions(loaded)
      // A source whose dictionary matches is the likely one, but it is still a choice.
      const likely = loaded.find((suggestion) => suggestion.dictionary_matches)
      if (likely) setChoice(likely.id)
    }).catch((caught: unknown) => {
      if (!cancelled) setError(ehiFailureText(caught))
    })

    return () => {
      cancelled = true
    }
  }, [patientId, pkg.id])

  async function confirm(): Promise<void> {
    setBusy(true)
    setError(null)
    try {
      onBound(await bindEhiPackage(patientId, pkg.id, choice === 'new' ? { new_source_label: label.trim() } : { source_id: choice }))
    } catch (caught) {
      setError(ehiFailureText(caught))
    } finally {
      setBusy(false)
    }
  }

  if (!canBind) {
    return <p className="text-sm text-muted-foreground">The patient’s owner needs to confirm where this export came from before it can be reviewed.</p>
  }

  return (
    <fieldset className="flex flex-col gap-2 rounded-md border border-border p-3 text-sm">
      <legend className="px-1 font-medium text-foreground">Where did this export come from?</legend>
      <p className="text-muted-foreground">
        Records from one place stay tied to it, so a later export from the same place updates them instead of adding them twice.
        {pkg.source_patient_key && <> The export identifies the patient as <span className="font-mono">{pkg.source_patient_key}</span>.</>}
      </p>
      {suggestions?.map((suggestion) => (
        <label key={suggestion.id} className="flex items-center gap-2">
          <input type="radio" name={`ehi-source-${pkg.id}`} checked={choice === suggestion.id} onChange={() => setChoice(suggestion.id)} />
          <span>{suggestion.label}{suggestion.dictionary_matches && <span className="text-muted-foreground"> · same format as before</span>}</span>
        </label>
      ))}
      <div className="flex flex-wrap items-center gap-2">
        <label className="flex items-center gap-2">
          <input type="radio" name={`ehi-source-${pkg.id}`} checked={choice === 'new'} onChange={() => setChoice('new')} />
          <span>A new source named</span>
        </label>
        <Input
          aria-label="New source name"
          className="h-8 w-56"
          maxLength={120}
          value={label}
          onFocus={() => setChoice('new')}
          onChange={(event) => setLabel(event.target.value)}
        />
      </div>
      {error && <p role="alert" className="text-destructive">{error}</p>}
      <div>
        <Button type="button" size="sm" disabled={busy || (choice === 'new' && label.trim() === '')} onClick={() => void confirm()}>
          Confirm source
        </Button>
      </div>
    </fieldset>
  )
}

export interface EhiImportFlowProps {
  patientId: number
  /** Whether the viewer owns the patient: only the owner binds a package to a source. */
  canBind: boolean
  /** The browser transfer, while this page's session is still sending bytes. */
  job?: EhiUploadJob
  uploadId?: number
  packageId?: number
  onReview: (pkg: EhiPackage) => void
  /** Something finished that the recent-imports list should reflect. */
  onChanged?: () => void
}

/**
 * One import from upload to review, in three stages, all read from the server: keep the
 * original (upload, then preservation), read and account for every row (accounting,
 * binding, and the queued preview), then review.
 */
export function EhiImportFlow({ patientId, canBind, job, uploadId, packageId, onReview, onChanged }: EhiImportFlowProps): ReactElement {
  const [upload, setUpload] = useState<EhiUpload | null>(null)
  const [pkg, setPkg] = useState<EhiPackage | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const effectiveUploadId = uploadId ?? job?.uploadId ?? undefined
  const transferring = job?.phase === 'uploading'
  const effectivePackageId = packageId ?? upload?.package_id ?? undefined

  const refresh = useCallback(async (): Promise<void> => {
    try {
      if (effectiveUploadId !== undefined && !transferring) {
        setUpload(await getEhiUpload(patientId, effectiveUploadId))
      }
      if (effectivePackageId !== undefined) {
        setPkg(await getEhiPackage(patientId, effectivePackageId))
      }
      setError(null)
    } catch (caught) {
      setError(ehiFailureText(caught))
    }
  }, [patientId, effectiveUploadId, effectivePackageId, transferring])

  const intakeRunning = upload !== null && (upload.status === 'queued' || upload.status === 'processing')
  const previewRunning = pkg?.preview_run?.status === 'queued' || pkg?.preview_run?.status === 'running'
  const serverWorking = transferring || intakeRunning || previewRunning || pkg?.intake_in_progress === true
    || (effectiveUploadId !== undefined && upload === null) || (effectivePackageId !== undefined && pkg === null)

  useEffect(() => {
    void refresh()
  }, [refresh])

  useEffect(() => {
    if (!serverWorking) {
      return undefined
    }
    const timer = setInterval(() => void refresh(), EHI_POLL_MS)

    return () => clearInterval(timer)
  }, [serverWorking, refresh])

  const settled = !serverWorking
  useEffect(() => {
    if (settled) onChanged?.()
  }, [settled, onChanged])

  async function act(action: () => Promise<unknown>): Promise<void> {
    setBusy(true)
    setError(null)
    try {
      await action()
      await refresh()
    } catch (caught) {
      setError(ehiFailureText(caught))
    } finally {
      setBusy(false)
    }
  }

  // ---- stage 1: keep the original
  const preserved = pkg !== null && pkg.preservation_status === 'preserved'
  const rejected = upload?.status === 'rejected'
  const uploadFailed = job?.phase === 'failed' || upload?.status === 'failed'
  // The server still holds an unfinished upload and nothing in this tab is sending it:
  // the transfer stopped, or every byte arrived and only queueing intake failed.
  const stranded = !transferring && upload?.status === 'uploading'
  const allBytesArrived = stranded && upload.uploaded_bytes === upload.file_size_bytes
  const stage1: StageState = preserved ? 'done' : allBytesArrived ? 'action' : rejected || uploadFailed || stranded || job?.phase === 'cancelled' ? 'failed' : 'active'

  // ---- stage 2: read and account, confirm the source, prepare proposals
  const accounted = pkg !== null && pkg.accounting_status === 'accounted'
  const bound = pkg?.binding_status === 'bound'
  const previewFailed = pkg?.preview_run?.status === 'failed'
  const previewComplete = pkg?.preview.state === 'complete' && !previewRunning
  const awaitingPerson = accounted && (!bound || (!previewRunning && !previewFailed && pkg.preview.state !== 'complete'))
  const stage2: StageState = !preserved ? 'waiting' : previewComplete ? 'done' : previewFailed || pkg?.failure_category ? 'failed' : awaitingPerson ? 'action' : 'active'
  const stage3: StageState = previewComplete ? 'action' : 'waiting'

  const pendingTotal = pkg ? pendingProposalCount(pkg) : 0
  const uploadedBytes = job?.bytesSent ?? upload?.uploaded_bytes ?? 0
  const totalBytes = job?.fileSizeBytes ?? upload?.file_size_bytes ?? 0

  return (
    <div className="flex flex-col gap-4">
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <ol className="flex flex-col" aria-label="Import progress">
        <Stage number={1} title="Keep the original" state={stage1}>
          {transferring && (
            <>
              <span className="text-sm text-muted-foreground">Uploading {formatBytes(uploadedBytes)} of {formatBytes(totalBytes)}. Keep this tab open until the upload finishes; you can use the rest of PHR meanwhile.</span>
              <Progress value={totalBytes > 0 ? (uploadedBytes / totalBytes) * 100 : 0} aria-label="Upload progress" />
            </>
          )}
          {job?.phase === 'failed' && <span className="text-sm text-destructive">{job.error}</span>}
          {job?.phase === 'cancelled' && <span className="text-sm text-muted-foreground">Upload cancelled.</span>}
          {allBytesArrived && (
            <span className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
              Every byte arrived, but the import did not start.
              <Button type="button" size="sm" disabled={busy} onClick={() => void act(() => queueEhiIntake(patientId, upload.id))}>Start the import</Button>
            </span>
          )}
          {stranded && !allBytesArrived && job?.phase !== 'cancelled' && (
            <span className="text-sm text-muted-foreground">
              The upload stopped after {formatBytes(upload.uploaded_bytes)} of {formatBytes(upload.file_size_bytes)}. Choose the file again to start over.
            </span>
          )}
          {!transferring && intakeRunning && <span className="text-sm text-muted-foreground">Storing and checking every file in the export…</span>}
          {rejected && <span className="text-sm text-destructive">{describeEhiError(upload.failure_reason)} Nothing was imported.</span>}
          {upload?.status === 'failed' && (
            <span className="flex flex-wrap items-center gap-2 text-sm text-destructive">
              {describeEhiError(upload.failure_reason)}
              <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => void act(() => queueEhiIntake(patientId, upload.id))}>Try again</Button>
            </span>
          )}
          {preserved && (
            <span className="text-sm text-muted-foreground">
              {count.format(pkg.accounting.members)} files kept, each checked.
              {upload?.replayed && ' This export was already imported, so nothing new was stored.'}
            </span>
          )}
        </Stage>

        <Stage number={2} title="Read and account for every row" state={stage2}>
          {preserved && !accounted && <span className="text-sm text-muted-foreground">Reading tables…</span>}
          {accounted && <AccountingTable pkg={pkg} />}
          {accounted && !bound && (
            <BindingPanel
              patientId={patientId}
              pkg={pkg}
              canBind={canBind}
              onBound={(boundPackage) => {
                setPkg(boundPackage)
                void act(() => queueEhiPreview(patientId, boundPackage.id))
              }}
            />
          )}
          {bound && previewRunning && <span className="text-sm text-muted-foreground">Preparing proposals from {pkg.source?.label ?? 'this export'}…</span>}
          {bound && previewFailed && (
            <span className="flex flex-wrap items-center gap-2 text-sm text-destructive">
              {describeEhiError(pkg.preview_run?.failure_reason)}
              <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => void act(() => queueEhiPreview(patientId, pkg.id))}>Try again</Button>
            </span>
          )}
          {bound && !previewRunning && !previewFailed && pkg.preview.state !== 'complete' && (
            <span className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
              {pkg.preview.state === 'incomplete' ? 'Preparing proposals stopped partway.' : 'Ready to prepare proposals.'}
              <Button type="button" size="sm" disabled={busy} onClick={() => void act(() => queueEhiPreview(patientId, pkg.id))}>
                {pkg.preview.state === 'incomplete' ? 'Continue' : 'Prepare review'}
              </Button>
            </span>
          )}
        </Stage>

        <Stage number={3} title="Review" state={stage3}>
          {previewComplete && pkg !== null && (
            <span className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
              {pendingTotal > 0 ? `${count.format(pendingTotal)} proposals to decide. Nothing is added to the record until you do.` : 'Nothing left to decide.'}
              <Button type="button" size="sm" onClick={() => onReview(pkg)}>{pendingTotal > 0 ? `Review ${count.format(pendingTotal)} proposals` : 'View decisions'}</Button>
            </span>
          )}
        </Stage>
      </ol>
    </div>
  )
}
