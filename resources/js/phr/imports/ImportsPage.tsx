import { FileArchive, FileStack, FolderOpen, UploadCloud } from 'lucide-react'
import type { DragEvent, ReactElement } from 'react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import { usePhrPatients } from '@/phr/patients/usePhrPatients'
import { errorMessage, readPatientIdFromQuery } from '@/phr/shared'
import { useDicomUploads } from '@/phr/uploads'

import { describeEhiError, EHI_PACKAGE_QUERY, type EhiPackage, type EhiUpload, listEhiPackages, listEhiUploads } from './ehi/ehiApi'
import { EhiImportFlow } from './ehi/EhiImportFlow'
import { EhiReview } from './ehi/EhiReview'
import { useEhiUploads } from './ehi/EhiUploadProvider'

const SELECT_CLASS = 'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm'

interface DirectoryInputAttributes {
  webkitdirectory: string
  directory: string
}

// `webkitdirectory` is not in React's typed attribute set but every target browser honours it.
const directoryInputAttributes: DirectoryInputAttributes = {
  webkitdirectory: '',
  directory: '',
}

type View =
  | { kind: 'home' }
  | { kind: 'check', file: File }
  | { kind: 'flow', jobId?: string, uploadId?: number, packageId?: number }
  // The review keeps the patient it was opened for: a deep link may name a patient the
  // viewer can read but not manage, who is never the "Import into" selection.
  | { kind: 'review', patientId: number, packageId: number, sourceLabel: string | null }

function isZip(file: File): boolean {
  return /\.zip$/i.test(file.name) || /zip/i.test(file.type)
}

function formatBytes(bytes: number): string {
  if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`
  if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`
  return `${Math.max(1, Math.round(bytes / 1024))} KB`
}

function formatReceived(value: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(date)
}

function pendingCount(pkg: EhiPackage): number {
  return Object.values(pkg.proposals.pending).reduce((sum, kinds) => sum + Object.values(kinds).reduce((inner, value) => inner + value, 0), 0)
}

/** Where a package stands, in a few words, and whether its review can open. */
function packageStatus(pkg: EhiPackage): { status: string, reviewable: boolean } {
  if (pkg.preservation_status !== 'preserved' || pkg.accounting_status !== 'accounted') {
    return { status: pkg.intake_in_progress ? 'Storing and checking' : 'Stopped', reviewable: false }
  }
  if (pkg.binding_status !== 'bound') return { status: 'Confirm its source', reviewable: false }
  if (pkg.preview_run?.status === 'queued' || pkg.preview_run?.status === 'running') return { status: 'Preparing review', reviewable: false }
  if (pkg.preview.state !== 'complete') return { status: pkg.preview_run?.status === 'failed' ? 'Stopped' : 'Ready to prepare', reviewable: false }
  const pending = pendingCount(pkg)

  return { status: pending > 0 ? `${pending} to review` : 'Reviewed', reviewable: true }
}

interface RecentRow {
  key: string
  title: string
  status: string
  received: string
  upload?: EhiUpload
  pkg?: EhiPackage
}

function uploadStatus(upload: EhiUpload): string {
  switch (upload.status) {
    case 'uploading':
      return 'Upload not finished'
    case 'queued':
    case 'processing':
      return 'Storing and checking'
    case 'rejected':
      return 'Not imported'
    case 'failed':
      return 'Stopped'
    default:
      return 'Imported'
  }
}

/** Uploads first, each with the package it produced, then packages no browser upload produced. */
function recentRows(uploads: EhiUpload[], packages: EhiPackage[]): RecentRow[] {
  const byId = new Map(packages.map((pkg) => [pkg.id, pkg]))
  const covered = new Set<number>()
  const rows: RecentRow[] = []
  for (const upload of uploads) {
    const pkg = upload.package_id !== null ? byId.get(upload.package_id) : undefined
    if (pkg) {
      if (covered.has(pkg.id)) continue
      covered.add(pkg.id)
    }
    rows.push({
      key: `upload-${upload.id}`,
      title: pkg?.source?.label ?? 'Epic EHI export',
      status: pkg ? packageStatus(pkg).status : uploadStatus(upload),
      received: formatReceived(upload.created_at),
      upload,
      ...(pkg ? { pkg } : {}),
    })
  }
  for (const pkg of packages) {
    if (!covered.has(pkg.id)) {
      rows.push({ key: `package-${pkg.id}`, title: pkg.source?.label ?? 'Epic EHI export', status: packageStatus(pkg).status, received: formatReceived(pkg.created_at), pkg })
    }
  }

  return rows
}

function readDeepLink(): number | undefined {
  if (typeof window === 'undefined') return undefined
  const value = Number.parseInt(new URLSearchParams(window.location.search).get(EHI_PACKAGE_QUERY) ?? '', 10)
  return Number.isFinite(value) ? value : undefined
}

export interface ImportsPageProps {
  /** Patient in context when the tab was opened; used as the default upload target. */
  patientId?: number
}

/**
 * The Imports tab: one drop zone for files PHR can read, the DICOM folder picker, and
 * recent imports. Upload transfers live in providers mounted by the Miller shell, so
 * leaving this column does not stop them; everything after the transfer runs on the
 * server and is read back from it.
 */
export default function ImportsPage({ patientId }: ImportsPageProps): ReactElement {
  const fileInputRef = useRef<HTMLInputElement | null>(null)
  const folderInputRef = useRef<HTMLInputElement | null>(null)
  const { patients, busy: loading, error: loadError, loaded, reloadPatients } = usePhrPatients()
  // A record page's banner links here with the patient in the query, outside any patient path.
  const [preferredPatientId, setPreferredPatientId] = useState<number | undefined>(() => patientId ?? readPatientIdFromQuery() ?? undefined)
  const [error, setError] = useState<string | null>(null)
  const [view, setView] = useState<View>(() => {
    const deepLinked = readDeepLink()
    const linkedPatientId = patientId ?? readPatientIdFromQuery() ?? undefined
    return deepLinked !== undefined && linkedPatientId !== undefined
      ? { kind: 'review', patientId: linkedPatientId, packageId: deepLinked, sourceLabel: null }
      : { kind: 'home' }
  })
  const [dragging, setDragging] = useState(false)
  const [confirmed, setConfirmed] = useState(false)
  const [uploads, setUploads] = useState<EhiUpload[]>([])
  const [packages, setPackages] = useState<EhiPackage[]>([])
  const [recentError, setRecentError] = useState<string | null>(null)
  const { startUpload } = useDicomUploads()
  const ehiUploads = useEhiUploads()

  const manageablePatients = useMemo(
    () => patients.filter((patient) => patient.can_manage && patient.archived_at === null),
    [patients],
  )

  // Keep the requested target while the initial list is loading. Once loaded,
  // retain the resolved fallback until that profile is removed or archived.
  const selectedPatientId = !loaded
    ? preferredPatientId
    : manageablePatients.find((patient) => patient.id === preferredPatientId)?.id ?? manageablePatients[0]?.id

  if (loaded && selectedPatientId !== preferredPatientId) {
    setPreferredPatientId(selectedPatientId)
  }

  const selectedPatient = manageablePatients.find((patient) => patient.id === selectedPatientId)
  const canUpload = selectedPatient !== undefined
  const patientName = selectedPatient?.display_name ?? (selectedPatientId !== undefined ? `Patient ${selectedPatientId}` : '')

  const reloadRecent = useCallback(async (): Promise<void> => {
    if (selectedPatientId === undefined || !canUpload) return
    try {
      const [loadedUploads, loadedPackages] = await Promise.all([listEhiUploads(selectedPatientId), listEhiPackages(selectedPatientId)])
      setUploads(loadedUploads)
      setPackages(loadedPackages)
      setRecentError(null)
    } catch (caught) {
      setRecentError(errorMessage(caught))
    }
  }, [selectedPatientId, canUpload])

  useEffect(() => {
    void reloadRecent()
  }, [reloadRecent])

  function choosePatient(next: number): void {
    setPreferredPatientId(next)
    setView({ kind: 'home' })
    setUploads([])
    setPackages([])
  }

  function chooseFile(file: File | undefined): void {
    if (!file) return
    setError(null)
    setConfirmed(false)
    setView({ kind: 'check', file })
  }

  function onDrop(event: DragEvent<HTMLDivElement>): void {
    event.preventDefault()
    setDragging(false)
    chooseFile(event.dataTransfer.files[0])
  }

  function startEhiImport(file: File): void {
    if (selectedPatientId === undefined) return
    const jobId = ehiUploads.start(selectedPatientId, file)
    setView({ kind: 'flow', jobId })
  }

  function onFolderChosen(files: FileList | null): void {
    // Snapshot before touching the input: `input.value = ''` empties the very `FileList`
    // the change event handed us (it is the element's own live list, not a copy), so
    // clearing first would leave nothing to upload.
    const chosen = files ? Array.from(files) : []

    const input = folderInputRef.current
    if (input) {
      input.value = ''
    }

    if (selectedPatientId === undefined || !canUpload) {
      setError('Choose a patient to import into first.')
      return
    }

    const result = startUpload(selectedPatientId, chosen)
    if (!result.ok) {
      setError(result.error)
      return
    }

    // Deliberately does not open the detail modal: the job announces itself in the tray and
    // the user stays free to navigate. Clicking the tray entry expands it.
    setError(null)
  }

  const activeJobs = ehiUploads.jobs.filter((job) => job.patientId === selectedPatientId && job.phase === 'uploading')
  const flowJob = view.kind === 'flow' && view.jobId !== undefined ? ehiUploads.jobs.find((job) => job.id === view.jobId) : undefined
  const rows = recentRows(uploads, packages)

  return (
    <div className="p-6">
      <div className="flex max-w-3xl flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
            <FileStack className="size-6 text-primary" />
            Imports
          </h1>
          <p className="mt-2 text-sm text-muted-foreground">
            Bring outside records into PHR. Every file is kept as evidence, and nothing in the record changes until you review it.
          </p>
        </div>
        <label className="grid gap-1 text-sm font-medium sm:w-60" htmlFor="import-patient">
          Import into
          <select
            id="import-patient"
            className={SELECT_CLASS}
            value={selectedPatientId === undefined ? '' : String(selectedPatientId)}
            disabled={manageablePatients.length === 0}
            onChange={(event) => choosePatient(Number.parseInt(event.target.value, 10))}
          >
            {manageablePatients.length === 0 && <option value="">No patients available</option>}
            {manageablePatients.map((patient) => (
              <option key={patient.id} value={String(patient.id)}>
                {patient.display_name ?? `Patient ${patient.id}`}
              </option>
            ))}
          </select>
        </label>
      </div>

      {loadError && (
        <div role="alert" className="mt-4 flex max-w-3xl flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          <span>{loadError}</span>
          <Button type="button" variant="outline" size="sm" disabled={loading} onClick={() => void reloadPatients()}>Retry</Button>
        </div>
      )}
      {error && (
        <div role="alert" className="mt-4 max-w-3xl rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}
      {!loading && !loadError && loaded && manageablePatients.length === 0 && (
        <p className="mt-4 text-sm text-muted-foreground">You need manage access to a patient before you can import records.</p>
      )}

      <input
        ref={fileInputRef}
        type="file"
        className="hidden"
        aria-label="File to import"
        onChange={(event) => {
          chooseFile(event.target.files?.[0])
          event.target.value = ''
        }}
      />
      <input
        ref={folderInputRef}
        type="file"
        className="hidden"
        multiple
        aria-label="DICOM folder"
        onChange={(event) => onFolderChosen(event.target.files)}
        {...directoryInputAttributes}
      />

      {view.kind === 'home' && (
        <div className="mt-6 flex max-w-3xl flex-col gap-4">
          <section aria-labelledby="imports-add" className="rounded-lg border border-border bg-card p-4">
            <h2 id="imports-add" className="text-lg font-medium text-card-foreground">Add records</h2>
            <div
              className={`mt-3 flex flex-col items-center gap-2 rounded-md border-2 border-dashed px-4 py-8 text-center ${dragging ? 'border-primary bg-primary/5' : 'border-border'}`}
              onDragOver={(event) => {
                event.preventDefault()
                setDragging(true)
              }}
              onDragLeave={() => setDragging(false)}
              onDrop={onDrop}
            >
              <UploadCloud className="size-8 text-primary" aria-hidden="true" />
              <p className="font-medium text-foreground">Drop a file here</p>
              <p className="max-w-md text-sm text-muted-foreground">
                An Epic EHI Tables export ZIP. PHR checks what it is and shows you before anything is imported.
              </p>
              <Button type="button" disabled={!canUpload} onClick={() => fileInputRef.current?.click()}>Choose file</Button>
            </div>
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
              <div className="text-sm">
                <p className="font-medium text-foreground">Imaging CD or export folder?</p>
                <p className="text-muted-foreground">DICOM needs the whole folder, so it has its own picker. Non-image files are ignored.</p>
              </div>
              <Button type="button" variant="outline" disabled={!canUpload} onClick={() => folderInputRef.current?.click()}>
                <FolderOpen className="size-4" aria-hidden="true" />
                Choose DICOM folder
              </Button>
            </div>
          </section>

          {activeJobs.length > 0 && (
            <section aria-labelledby="imports-uploading" className="rounded-lg border border-border bg-card p-4">
              <h2 id="imports-uploading" className="text-lg font-medium text-card-foreground">Uploading</h2>
              <ul className="mt-2 flex flex-col">
                {activeJobs.map((job) => (
                  <li key={job.id} className="flex flex-wrap items-center justify-between gap-2 border-t border-border py-2 text-sm first:border-t-0">
                    <span>{job.fileName} · {Math.floor((job.bytesSent / Math.max(1, job.fileSizeBytes)) * 100)}%</span>
                    <span className="flex gap-2">
                      <Button type="button" size="sm" variant="outline" onClick={() => setView({ kind: 'flow', jobId: job.id })}>Open</Button>
                      {job.cancellable && <Button type="button" size="sm" variant="ghost" onClick={() => ehiUploads.cancel(job.id)}>Cancel</Button>}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          <section aria-labelledby="imports-recent" className="rounded-lg border border-border bg-card p-4">
            <h2 id="imports-recent" className="text-lg font-medium text-card-foreground">Recent imports</h2>
            {recentError && <p role="alert" className="mt-2 text-sm text-destructive">{recentError}</p>}
            {rows.length === 0 && !recentError && <p className="mt-2 text-sm text-muted-foreground">No EHI exports imported for {patientName || 'this patient'} yet.</p>}
            {rows.length > 0 && (
              <div className="mt-2 overflow-x-auto">
                <table className="w-full min-w-[520px] text-left text-sm">
                  <thead className="text-xs uppercase tracking-wide text-muted-foreground">
                    <tr><th className="py-1 pr-4 font-medium">Source</th><th className="py-1 pr-4 font-medium">Status</th><th className="py-1 pr-4 font-medium">Received</th><th className="py-1"><span className="sr-only">Actions</span></th></tr>
                  </thead>
                  <tbody>
                    {rows.map((row) => {
                      const reviewable = row.pkg ? packageStatus(row.pkg).reviewable : false
                      return (
                        <tr key={row.key} className="border-t border-border">
                          <td className="py-2 pr-4"><span className="font-medium text-foreground">{row.title}</span><span className="block text-xs text-muted-foreground">Epic EHI Tables</span></td>
                          <td className="py-2 pr-4">
                            {row.status}
                            {row.upload && (row.upload.status === 'rejected' || row.upload.status === 'failed') && (
                              <span className="block text-xs text-muted-foreground">{describeEhiError(row.upload.failure_reason)}</span>
                            )}
                          </td>
                          <td className="py-2 pr-4 text-muted-foreground">{row.received}</td>
                          <td className="py-2 text-right">
                            {reviewable && row.pkg ? (
                              <Button type="button" size="sm" variant="outline" onClick={() => setView({ kind: 'review', patientId: row.pkg!.patient_id, packageId: row.pkg!.id, sourceLabel: row.pkg!.source?.label ?? null })}>Review</Button>
                            ) : (
                              <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => setView({ kind: 'flow', ...(row.upload ? { uploadId: row.upload.id } : {}), ...(row.pkg ? { packageId: row.pkg.id } : {}) })}
                              >
                                View
                              </Button>
                            )}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </div>
      )}

      {view.kind === 'check' && (
        <section aria-labelledby="imports-check" className="mt-6 flex max-w-3xl flex-col gap-4 rounded-lg border border-border bg-card p-5">
          {isZip(view.file) ? (
            <>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Check before importing</p>
                <h2 id="imports-check" className="mt-1 flex items-center gap-2 text-lg font-semibold text-card-foreground">
                  <FileArchive className="size-5 text-primary" aria-hidden="true" />
                  Epic EHI Tables export
                </h2>
                <p className="text-sm text-muted-foreground">{view.file.name} · ZIP · {formatBytes(view.file.size)}</p>
              </div>
              <ul className="list-disc space-y-1 pl-5 text-sm text-foreground">
                <li>Every file inside it is stored and checked.</li>
                <li>PHR reads every table and proposes records. Nothing is added or changed yet.</li>
                <li>You decide on each proposal. Likely matches to existing records are shown beside them.</li>
              </ul>
              <p className="text-sm text-muted-foreground">If the ZIP turns out not to be an EHI Tables export, or holds more than one patient, PHR refuses it and nothing is imported.</p>
              <label className="flex items-center gap-2 text-sm font-medium">
                <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />
                This file belongs to {patientName}
              </label>
              <div className="flex flex-wrap gap-2">
                <Button type="button" disabled={!confirmed || !canUpload} onClick={() => startEhiImport(view.file)}>Start import</Button>
                <Button type="button" variant="outline" onClick={() => setView({ kind: 'home' })}>Cancel</Button>
              </div>
            </>
          ) : (
            <>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Not imported</p>
                <h2 id="imports-check" className="mt-1 text-lg font-semibold text-card-foreground">PHR can’t import this file here yet</h2>
                <p className="text-sm text-muted-foreground">{view.file.name} · {formatBytes(view.file.size)}</p>
              </div>
              <p className="text-sm text-foreground">
                This page imports Epic EHI Tables exports. C-CDA, FHIR and MyChart files, PDFs and photos still go through the Documents tab for now.
              </p>
              <div className="flex flex-wrap gap-2">
                <Button type="button" onClick={() => fileInputRef.current?.click()}>Choose another file</Button>
                <Button type="button" variant="outline" onClick={() => setView({ kind: 'home' })}>Back</Button>
              </div>
            </>
          )}
        </section>
      )}

      {view.kind === 'flow' && selectedPatientId !== undefined && (
        <section aria-labelledby="imports-flow" className="mt-6 flex max-w-3xl flex-col gap-3 rounded-lg border border-border bg-card p-5">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <h2 id="imports-flow" className="text-lg font-semibold text-card-foreground">Importing Epic EHI export</h2>
              {flowJob && <p className="text-sm text-muted-foreground">{flowJob.fileName} · {formatBytes(flowJob.fileSizeBytes)}</p>}
            </div>
            <span className="flex gap-2">
              {flowJob?.phase === 'uploading' && flowJob.cancellable && <Button type="button" size="sm" variant="ghost" onClick={() => ehiUploads.cancel(flowJob.id)}>Cancel upload</Button>}
              <Button type="button" size="sm" variant="outline" onClick={() => setView({ kind: 'home' })}>All imports</Button>
            </span>
          </div>
          <EhiImportFlow
            patientId={selectedPatientId}
            canBind={selectedPatient?.access_level === 'owner'}
            {...(flowJob ? { job: flowJob } : {})}
            {...(view.uploadId !== undefined ? { uploadId: view.uploadId } : {})}
            {...(view.packageId !== undefined ? { packageId: view.packageId } : {})}
            onReview={(pkg) => setView({ kind: 'review', patientId: pkg.patient_id, packageId: pkg.id, sourceLabel: pkg.source?.label ?? null })}
            onChanged={reloadRecent}
          />
        </section>
      )}

      {view.kind === 'review' && (
        <div className="mt-6 max-w-3xl">
          <EhiReview
            patientId={view.patientId}
            packageId={view.packageId}
            sourceLabel={view.sourceLabel}
            onBack={() => {
              setView({ kind: 'home' })
              void reloadRecent()
            }}
          />
        </div>
      )}
    </div>
  )
}
