import { FileArchive, FileStack, FolderOpen, UploadCloud } from 'lucide-react'
import type { DragEvent, ReactElement } from 'react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import { phrSectionUrl } from '@/lib/phrRouteBuilder'
import { formatBytes } from '@/lib/utils'
import { usePhrPatients } from '@/phr/patients/usePhrPatients'
import { errorMessage, readPatientIdFromQuery } from '@/phr/shared'
import { useDicomUploads } from '@/phr/uploads'

import { AiImportReview } from './ai/AiImportReview'
import { type AiImport, aiImportStatus, canKeepAsDocument, DOCUMENT_MAX_BYTES, keepAsDocument, listAiImports, retryAiImport, sendDocumentToAi } from './ai/aiImportsApi'
import { useExecutionMode } from './ai/useExecutionMode'
import { describeEhiError, EHI_PACKAGE_QUERY, ehiFailureText, type EhiPackage, type EhiUpload, importStructuredFile, listEhiPackages, listEhiUploads, pendingProposalCount, STRUCTURED_MAX_BYTES, type StructuredImport } from './ehi/ehiApi'
import { EHI_POLL_MS, EhiImportFlow } from './ehi/EhiImportFlow'
import { EhiReview } from './ehi/EhiReview'
import { useEhiUploads } from './ehi/EhiUploadProvider'
import { ImportSummary } from './ImportSummary'

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
  | { kind: 'summary', fileName: string, imported: StructuredImport }
  | { kind: 'ai-review', patientId: number, importId: number }
  | { kind: 'flow', jobId?: string, uploadId?: number, packageId?: number }
  // The review keeps the patient it was opened for: a deep link may name a patient the
  // viewer can read but not manage, who is never the "Import into" selection.
  | { kind: 'review', patientId: number, packageId: number, sourceLabel: string | null }

/** How often to check on AI imports still with an agent or server-side AI. */
const AI_POLL_MS = 15_000

type FileKind = 'export' | 'structured' | 'other'

/**
 * A first guess from the name and type, only to choose the right check step: the
 * server decides what a file really is from its content, and refuses a wrong guess.
 */
function fileKind(file: File): FileKind {
  if (/\.zip$/i.test(file.name) || /zip/i.test(file.type)) return 'export'
  if (/\.(xml|json|ccd|ccda|cda)$/i.test(file.name) || /(xml|json)/i.test(file.type)) return 'structured'
  return 'other'
}

const STRUCTURED_TITLES: Record<StructuredImport['format'], string> = {
  ccda: 'C-CDA clinical summary',
  fhir: 'FHIR bundle',
}

function formatReceived(value: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(date)
}

/** Where a package stands, in a few words, and whether its review can open. */
function packageStatus(pkg: EhiPackage): { status: string, reviewable: boolean } {
  if (pkg.preservation_status !== 'preserved' || pkg.accounting_status !== 'accounted') {
    return { status: pkg.intake_in_progress ? 'Storing and checking' : 'Stopped', reviewable: false }
  }
  if (pkg.binding_status !== 'bound') return { status: 'Confirm its source', reviewable: false }
  if (pkg.preview_run?.status === 'queued' || pkg.preview_run?.status === 'running') return { status: 'Preparing review', reviewable: false }
  if (pkg.preview.state !== 'complete') return { status: pkg.preview_run?.status === 'failed' ? 'Stopped' : 'Ready to prepare', reviewable: false }
  const pending = pendingProposalCount(pkg)

  return { status: pending > 0 ? `${pending} to review` : 'Reviewed', reviewable: true }
}

interface RecentRow {
  key: string
  title: string
  kind: string
  status: string
  received: string
  /** For newest-first ordering across EHI, MyChart and AI imports. */
  receivedAt: number
  upload?: EhiUpload
  pkg?: EhiPackage
  ai?: AiImport
}

function timeOf(value: string | null): number {
  const parsed = value ? Date.parse(value) : Number.NaN
  return Number.isNaN(parsed) ? 0 : parsed
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
      return upload.import_result ? `Imported · ${upload.import_result.created} added` : 'Imported'
  }
}

/** The row's title and kind line, by what intake found the ZIP to be. */
function uploadLabels(upload: EhiUpload, pkg: EhiPackage | undefined): { title: string, kind: string } {
  if (upload.format === 'mychart') return { title: 'MyChart record', kind: 'MyChart ZIP' }
  if (upload.format === 'ehi' || pkg) return { title: pkg?.source?.label ?? 'Epic EHI export', kind: 'Epic EHI Tables' }
  return { title: 'Export ZIP', kind: 'Not read yet' }
}

/** Uploads first, each with the package it produced, then packages no browser upload produced. */
function recentRows(uploads: EhiUpload[], packages: EhiPackage[], aiImports: AiImport[]): RecentRow[] {
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
      ...uploadLabels(upload, pkg),
      status: pkg ? packageStatus(pkg).status : uploadStatus(upload),
      received: formatReceived(upload.created_at),
      receivedAt: timeOf(upload.created_at),
      upload,
      ...(pkg ? { pkg } : {}),
    })
  }
  for (const pkg of packages) {
    if (!covered.has(pkg.id)) {
      rows.push({ key: `package-${pkg.id}`, title: pkg.source?.label ?? 'Epic EHI export', kind: 'Epic EHI Tables', status: packageStatus(pkg).status, received: formatReceived(pkg.created_at), receivedAt: timeOf(pkg.created_at), pkg })
    }
  }
  for (const ai of aiImports) {
    rows.push({
      key: `ai-${ai.id}`,
      title: ai.document_title ?? ai.document_filename ?? 'Document',
      kind: ai.execution_mode === 'external' ? 'AI · AI Agent Inbox' : 'AI · server-side',
      status: aiImportStatus(ai).status,
      received: formatReceived(ai.created_at),
      receivedAt: timeOf(ai.created_at),
      ai,
    })
  }

  return rows.sort((left, right) => right.receivedAt - left.receivedAt)
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
 * recent imports. Upload transfers live in providers mounted by the shell, so
 * leaving this page does not stop them; everything after the transfer runs on the
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
  const [structuredBusy, setStructuredBusy] = useState(false)
  const [structuredError, setStructuredError] = useState<string | null>(null)
  const [uploads, setUploads] = useState<EhiUpload[]>([])
  const [packages, setPackages] = useState<EhiPackage[]>([])
  const [recentError, setRecentError] = useState<string | null>(null)
  const [aiImports, setAiImports] = useState<AiImport[]>([])
  const [aiBusy, setAiBusy] = useState(false)
  const [aiError, setAiError] = useState<string | null>(null)
  // The document a file was already kept as, so a retry after a failed send
  // sends it again instead of storing a second copy.
  const [kept, setKept] = useState<{ file: File, documentId: number } | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const executionMode = useExecutionMode()
  const { startUpload } = useDicomUploads()

  // A deep link opens its review once. Dropping it from the URL keeps a remount or a
  // reload on the Imports home instead of reopening that review every time.
  useEffect(() => {
    const url = new URL(window.location.href)
    if (url.searchParams.has(EHI_PACKAGE_QUERY)) {
      url.searchParams.delete(EHI_PACKAGE_QUERY)
      window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`)
    }
  }, [])
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
      const [loadedUploads, loadedPackages, loadedAi] = await Promise.all([listEhiUploads(selectedPatientId), listEhiPackages(selectedPatientId), listAiImports(selectedPatientId)])
      setUploads(loadedUploads)
      setPackages(loadedPackages)
      setAiImports(loadedAi)
      setRecentError(null)
    } catch (caught) {
      setRecentError(errorMessage(caught))
    }
  }, [selectedPatientId, canUpload])

  useEffect(() => {
    void reloadRecent()
  }, [reloadRecent])

  // Keep Recent imports current while the home view shows work in progress: a
  // transfer this tab is running changed phase, or the server is still storing,
  // reading or preparing one.
  const jobPhases = ehiUploads.jobs.filter((job) => job.patientId === selectedPatientId).map((job) => `${job.id}:${job.phase}`).join(',')
  useEffect(() => {
    if (jobPhases !== '') void reloadRecent()
  }, [jobPhases, reloadRecent])
  // Work with an agent can take hours: check back now and then, not every few seconds.
  const aiWorking = aiImports.some((ai) => ['pending', 'processing', 'queued_tomorrow'].includes(ai.status))
  useEffect(() => {
    if (view.kind !== 'home' || !aiWorking) return undefined
    const timer = setInterval(() => void reloadRecent(), AI_POLL_MS)

    return () => clearInterval(timer)
  }, [view.kind, aiWorking, reloadRecent])
  const serverWorking = uploads.some((upload) => upload.status === 'queued' || upload.status === 'processing')
    || packages.some((pkg) => pkg.intake_in_progress || pkg.preview_run?.status === 'queued' || pkg.preview_run?.status === 'running')
  useEffect(() => {
    if (view.kind !== 'home' || !serverWorking) return undefined
    const timer = setInterval(() => void reloadRecent(), EHI_POLL_MS)

    return () => clearInterval(timer)
  }, [view.kind, serverWorking, reloadRecent])

  function choosePatient(next: number): void {
    setPreferredPatientId(next)
    setView({ kind: 'home' })
    setUploads([])
    setPackages([])
    setAiImports([])
    setNotice(null)
  }

  function chooseFile(file: File | undefined): void {
    if (!file) return
    setError(null)
    setConfirmed(false)
    setStructuredError(null)
    setView({ kind: 'check', file })
  }

  function onDrop(event: DragEvent<HTMLDivElement>): void {
    event.preventDefault()
    setDragging(false)
    chooseFile(event.dataTransfer.files[0])
  }

  /** Keep a file as a document and, optionally, send it to AI: the Documents tab's two requests. */
  async function keepFile(file: File, sendToAi: boolean): Promise<void> {
    if (selectedPatientId === undefined) return
    setAiBusy(true)
    setAiError(null)
    try {
      const documentId = kept?.file === file ? kept.documentId : await keepAsDocument(selectedPatientId, file)
      setKept({ file, documentId })
      if (sendToAi) {
        await sendDocumentToAi(selectedPatientId, documentId)
      }
      setNotice(!sendToAi
        ? `Kept ${file.name} as a document. It is in the Documents tab.`
        : executionMode === 'external'
          ? `Sent ${file.name} to the AI Agent Inbox. Ask the assistant you have connected to PHR to check the inbox; what it proposes appears here for your review.`
          : `Sent ${file.name} to server-side AI. What it proposes appears here for your review.`)
      setView({ kind: 'home' })
      void reloadRecent()
    } catch (caught) {
      setAiError(ehiFailureText(caught))
    } finally {
      setAiBusy(false)
    }
  }

  async function retryAi(ai: AiImport): Promise<void> {
    if (selectedPatientId === undefined) return
    try {
      await retryAiImport(selectedPatientId, ai.id)
      void reloadRecent()
    } catch (caught) {
      setRecentError(ehiFailureText(caught))
    }
  }

  async function importStructured(file: File): Promise<void> {
    if (selectedPatientId === undefined) return
    setStructuredBusy(true)
    setStructuredError(null)
    try {
      const imported = await importStructuredFile(selectedPatientId, file)
      setView({ kind: 'summary', fileName: file.name, imported })
    } catch (caught) {
      setStructuredError(ehiFailureText(caught))
    } finally {
      setStructuredBusy(false)
    }
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
  const rows = recentRows(uploads, packages, aiImports)

  return (
    <div className="p-6">
      <div className="flex max-w-3xl flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
            <FileStack className="size-6 text-primary" />
            Imports
          </h1>
          <p className="mt-2 text-sm text-muted-foreground">
            Bring outside records into PHR. Nothing you import counts as reviewed until you review it.
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
          {notice && (
            <div role="status" className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-primary/30 bg-primary/5 px-3 py-2 text-sm text-foreground">
              <span>{notice}</span>
              <Button type="button" variant="ghost" size="sm" onClick={() => setNotice(null)}>Dismiss</Button>
            </div>
          )}
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
                A C-CDA or FHIR file, a MyChart or Epic EHI export ZIP, or a PDF or photo for AI. PHR checks what it is and shows you before anything is imported.
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
            {rows.length === 0 && !recentError && <p className="mt-2 text-sm text-muted-foreground">Nothing imported for {patientName || 'this patient'} yet.</p>}
            {rows.length > 0 && (
              <div className="mt-2 overflow-x-auto">
                <table className="w-full min-w-[520px] text-left text-sm">
                  <thead className="text-xs uppercase tracking-wide text-muted-foreground">
                    <tr><th className="py-1 pr-4 font-medium">Source</th><th className="py-1 pr-4 font-medium">Status</th><th className="py-1 pr-4 font-medium">Received</th><th className="py-1"><span className="sr-only">Actions</span></th></tr>
                  </thead>
                  <tbody>
                    {rows.map((row) => {
                      const reviewable = row.pkg ? packageStatus(row.pkg).reviewable : false
                      const ai = row.ai ? aiImportStatus(row.ai) : null
                      return (
                        <tr key={row.key} className="border-t border-border">
                          <td className="py-2 pr-4"><span className="font-medium text-foreground">{row.title}</span><span className="block text-xs text-muted-foreground">{row.kind}</span></td>
                          <td className="py-2 pr-4">
                            {row.status}
                            {row.upload && (row.upload.status === 'rejected' || row.upload.status === 'failed') && (
                              <span className="block text-xs text-muted-foreground">{describeEhiError(row.upload.failure_reason)}</span>
                            )}
                          </td>
                          <td className="py-2 pr-4 text-muted-foreground">{row.received}</td>
                          <td className="py-2 text-right">
                            {row.ai && ai ? (
                              ai.reviewable ? (
                                <Button type="button" size="sm" variant="outline" onClick={() => setView({ kind: 'ai-review', patientId: selectedPatientId!, importId: row.ai!.id })}>Review</Button>
                              ) : ai.retryable ? (
                                <Button type="button" size="sm" variant="ghost" onClick={() => void retryAi(row.ai!)}>Try again</Button>
                              ) : null
                            ) : reviewable && row.pkg ? (
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
          {fileKind(view.file) === 'export' && (
            <>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Check before importing</p>
                <h2 id="imports-check" className="mt-1 flex items-center gap-2 text-lg font-semibold text-card-foreground">
                  <FileArchive className="size-5 text-primary" aria-hidden="true" />
                  MyChart or Epic EHI export
                </h2>
                <p className="text-sm text-muted-foreground">{view.file.name} · ZIP · {formatBytes(view.file.size)}</p>
              </div>
              <ul className="list-disc space-y-1 pl-5 text-sm text-foreground">
                <li>PHR works out whether it is an Epic EHI Tables export or a MyChart record ZIP.</li>
                <li>An EHI export: every file is stored and checked, every table is read, and PHR proposes records for you to decide on. Nothing is added until you do.</li>
                <li>A MyChart ZIP: its records are added directly and marked for your review on their own pages.</li>
              </ul>
              <p className="text-sm text-muted-foreground">A ZIP that is neither, or that holds records for more than one person, is refused and nothing is imported.</p>
              <label className="flex items-center gap-2 text-sm font-medium">
                <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />
                This file belongs to {patientName}
              </label>
              <div className="flex flex-wrap gap-2">
                <Button type="button" disabled={!confirmed || !canUpload} onClick={() => startEhiImport(view.file)}>Start import</Button>
                <Button type="button" variant="outline" onClick={() => setView({ kind: 'home' })}>Cancel</Button>
              </div>
            </>
          )}
          {fileKind(view.file) === 'structured' && (
            <>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Check before importing</p>
                <h2 id="imports-check" className="mt-1 text-lg font-semibold text-card-foreground">Clinical summary or FHIR bundle</h2>
                <p className="text-sm text-muted-foreground">{view.file.name} · {formatBytes(view.file.size)}</p>
              </div>
              {view.file.size > STRUCTURED_MAX_BYTES ? (
                <p role="alert" className="text-sm text-destructive">This file is larger than {formatBytes(STRUCTURED_MAX_BYTES)}, the most PHR imports in one file. If it came in a ZIP, import the ZIP instead.</p>
              ) : (
                <>
                  <ul className="list-disc space-y-1 pl-5 text-sm text-foreground">
                    <li>PHR checks that it is a C-CDA document or a FHIR bundle, and refuses anything else.</li>
                    <li>Its records are added directly and marked for your review on their own pages, such as Immunizations.</li>
                    <li>Records already in PHR exactly as the file has them are left as they are, with your earlier review.</li>
                    <li>A file that holds records for more than one person is refused, and nothing is imported.</li>
                  </ul>
                  <label className="flex items-center gap-2 text-sm font-medium">
                    <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />
                    This file belongs to {patientName}
                  </label>
                  {structuredError && <p role="alert" className="text-sm text-destructive">{structuredError}</p>}
                  <div className="flex flex-wrap gap-2">
                    <Button type="button" disabled={!confirmed || !canUpload || structuredBusy} onClick={() => void importStructured(view.file)}>
                      {structuredBusy ? 'Importing…' : 'Import'}
                    </Button>
                    <Button type="button" variant="outline" disabled={structuredBusy} onClick={() => setView({ kind: 'home' })}>Cancel</Button>
                  </div>
                </>
              )}
            </>
          )}
          {fileKind(view.file) === 'other' && (
            canKeepAsDocument(view.file) ? (
              <>
                <div>
                  <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Document or photo</p>
                  <h2 id="imports-check" className="mt-1 text-lg font-semibold text-card-foreground">Extract records with AI</h2>
                  <p className="text-sm text-muted-foreground">{view.file.name} · {formatBytes(view.file.size)}</p>
                </div>
                <p className="text-sm text-foreground">
                  PHR’s own parsers can’t read this file, but AI can.{' '}
                  {executionMode === 'external'
                    ? <>Your settings send AI work to the <strong className="font-semibold">AI Agent Inbox</strong>: the assistant you have connected to PHR picks it up the next time you ask it to check the inbox. Nothing is sent to an AI provider by PHR.</>
                    : <>Your settings send AI work to <strong className="font-semibold">server-side AI</strong>, using the provider key you saved.</>}
                  {' '}What it proposes comes back here for your review; nothing is added until you accept it.
                </p>
                <p className="text-sm text-muted-foreground">
                  The file is kept as a document either way. <a className="font-medium text-primary underline-offset-4 hover:underline" href={phrSectionUrl('config')}>Change AI settings</a>
                </p>
                <label className="flex items-center gap-2 text-sm font-medium">
                  <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />
                  This file belongs to {patientName}
                </label>
                {aiError && <p role="alert" className="text-sm text-destructive">{aiError}</p>}
                <div className="flex flex-wrap gap-2">
                  <Button type="button" disabled={!confirmed || !canUpload || aiBusy} onClick={() => void keepFile(view.file, true)}>
                    {executionMode === 'external' ? 'Keep and send to AI Agent Inbox' : 'Keep and process with AI'}
                  </Button>
                  <Button type="button" variant="outline" disabled={!confirmed || !canUpload || aiBusy} onClick={() => void keepFile(view.file, false)}>Keep as a document only</Button>
                  <Button type="button" variant="ghost" disabled={aiBusy} onClick={() => setView({ kind: 'home' })}>Cancel</Button>
                </div>
              </>
            ) : (
              <>
                <div>
                  <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Not imported</p>
                  <h2 id="imports-check" className="mt-1 text-lg font-semibold text-card-foreground">PHR can’t import this file</h2>
                  <p className="text-sm text-muted-foreground">{view.file.name} · {formatBytes(view.file.size)}</p>
                </div>
                <p className="text-sm text-foreground">
                  PHR imports C-CDA and FHIR files, MyChart and Epic EHI export ZIPs, and PDFs, images and text files up to {formatBytes(DOCUMENT_MAX_BYTES)} for AI.
                </p>
                <div className="flex flex-wrap gap-2">
                  <Button type="button" onClick={() => fileInputRef.current?.click()}>Choose another file</Button>
                  <Button type="button" variant="outline" onClick={() => setView({ kind: 'home' })}>Back</Button>
                </div>
              </>
            )
          )}
        </section>
      )}

      {view.kind === 'summary' && (
        <section aria-labelledby="imports-summary" className="mt-6 flex max-w-3xl flex-col gap-4 rounded-lg border border-border bg-card p-5">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Imported</p>
            <h2 id="imports-summary" className="mt-1 text-lg font-semibold text-card-foreground">{STRUCTURED_TITLES[view.imported.format]}</h2>
            <p className="text-sm text-muted-foreground">{view.fileName}</p>
          </div>
          <ImportSummary result={view.imported.result} />
          <div>
            <Button type="button" onClick={() => setView({ kind: 'home' })}>Done</Button>
          </div>
        </section>
      )}

      {view.kind === 'flow' && selectedPatientId !== undefined && (
        <section aria-labelledby="imports-flow" className="mt-6 flex max-w-3xl flex-col gap-3 rounded-lg border border-border bg-card p-5">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <h2 id="imports-flow" className="text-lg font-semibold text-card-foreground">Import</h2>
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

      {view.kind === 'ai-review' && (
        <div className="mt-6 max-w-3xl">
          <AiImportReview
            patientId={view.patientId}
            importId={view.importId}
            onBack={() => {
              setView({ kind: 'home' })
              void reloadRecent()
            }}
          />
        </div>
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
