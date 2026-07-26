import { AlertCircle, CheckCircle2, Download, ExternalLink, Images, Loader2, RefreshCcw, UploadCloud, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Progress } from '@/components/ui/progress'
import { fetchWrapper } from '@/fetchWrapper'
import { cn, formatBytes } from '@/lib/utils'
import type { PhrListPageProps } from '@/phr/miller'
import { errorMessage } from '@/phr/shared'
import {
  type PhrDicomSignedUploadBatchItem,
  PhrDicomSignedUploadBatchResponseSchema,
  PhrDicomStudiesResponseSchema,
  type PhrDicomStudy,
  PhrDicomUploadFileResponseSchema,
  PhrDicomUploadFinalizeResponseSchema,
  PhrDicomUploadResponseSchema,
} from '@/phr/types'

type FileWithRelativePath = File

interface DirectoryInputAttributes {
  webkitdirectory: string
  directory: string
}

const directoryInputAttributes: DirectoryInputAttributes = {
  webkitdirectory: '',
  directory: '',
}

const UPLOAD_CONCURRENCY = 4
const SIGNED_UPLOAD_BATCH_SIZE = 32

type UploadPhase = 'uploading' | 'done' | 'duplicate' | 'aborting' | 'cancelled' | 'failed'

interface FileFailure {
  path: string
  reason: string
}

interface UploadSummary {
  stored: number
  skipped: number
  errored: number
  failures: FileFailure[]
}

export default function ImagingPage({ patientId, onDrill }: PhrListPageProps) {
  const inputRef = useRef<HTMLInputElement | null>(null)
  const [canManage, setCanManage] = useState(false)
  const [studies, setStudies] = useState<PhrDicomStudy[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [dialogOpen, setDialogOpen] = useState(false)
  const [phase, setPhase] = useState<UploadPhase>('uploading')
  const [queuedFiles, setQueuedFiles] = useState<FileWithRelativePath[]>([])
  const [rootName, setRootName] = useState<string | null>(null)
  const [bytesSent, setBytesSent] = useState(0)
  const [filesProcessed, setFilesProcessed] = useState(0)
  const [currentFileName, setCurrentFileName] = useState<string>('')
  const [summary, setSummary] = useState<UploadSummary>({ stored: 0, skipped: 0, errored: 0, failures: [] })

  const controllerRef = useRef<UploadController | null>(null)

  const loadStudies = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      const [rawStudies, rawPatient] = await Promise.all([
        fetchWrapper.get(`/api/phr/patients/${patientId}/dicom/studies`),
        fetchWrapper.get(`/api/phr/patients/${patientId}`),
      ])
      setStudies([...PhrDicomStudiesResponseSchema.parse(rawStudies).studies].sort(compareStudiesNewestFirst))
      const p = (rawPatient as { patient?: { can_manage?: boolean } } | null)?.patient
      setCanManage(Boolean(p?.can_manage))
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }, [patientId])

  useEffect(() => {
    void loadStudies()
  }, [loadStudies])

  const totalBytes = useMemo(() => queuedFiles.reduce((sum, file) => sum + file.size, 0), [queuedFiles])
  const totalFiles = queuedFiles.length
  const progressPercent = totalBytes === 0 ? 0 : Math.min(100, Math.round((bytesSent / totalBytes) * 100))

  function onFolderChosen(files: FileList | null): void {
    const accepted = files ? Array.from(files).filter((file) => !isAuxiliaryUploadPath(relativeFilePath(file))) : []
    if (accepted.length === 0) {
      setError('No DICOM-compatible files were found in the chosen folder.')
      if (inputRef.current) {
        inputRef.current.value = ''
      }
      return
    }

    setError(null)
    const inferredRoot = inferUploadRootName(accepted)
    setQueuedFiles(accepted)
    setRootName(inferredRoot)
    setBytesSent(0)
    setFilesProcessed(0)
    setCurrentFileName('')
    setSummary({ stored: 0, skipped: 0, errored: 0, failures: [] })
    setPhase('uploading')
    setDialogOpen(true)

    if (inputRef.current) {
      inputRef.current.value = ''
    }

    void startUpload(accepted, inferredRoot)
  }

  async function startUpload(files: FileWithRelativePath[], uploadRootName: string | null): Promise<void> {
    setError(null)
    let uploadId: number | null = null

    try {
      const openResponse = await fetchWrapper.post(`/api/phr/patients/${patientId}/dicom/uploads`, uploadRootName ? { root_name: uploadRootName } : {})
      const { upload, limits } = PhrDicomUploadResponseSchema.parse(openResponse)
      const currentUploadId = upload.id
      uploadId = currentUploadId

      controllerRef.current = new UploadController({
        patientId,
        uploadId: currentUploadId,
        files,
        concurrency: UPLOAD_CONCURRENCY,
        maxFileBytes: limits?.max_file_bytes ?? null,
        maxFileSizeLabel: limits?.max_file_size_label ?? null,
        onFileBytesProgress: (bytes) => setBytesSent((prev) => prev + bytes),
        onFileStarted: (name) => setCurrentFileName(name),
        onFileFinished: (outcome) => {
          setFilesProcessed((prev) => prev + 1)
          setSummary((prev) => applyOutcomeToSummary(prev, outcome))
        },
      })

      const outcomes = await controllerRef.current.run()

      if (controllerRef.current.aborted) {
        await cancelUploadSession(patientId, currentUploadId)
        setError('Upload cancelled.')
        setPhase('cancelled')
        return
      }

      const failedOutcomes = outcomes.filter((outcome) => outcome.errorMessage !== null)
      if (failedOutcomes.length > 0) {
        setError(failedOutcomes[0]?.errorMessage ?? 'Upload failed.')
        await cancelUploadSession(patientId, currentUploadId)
        setPhase('failed')
        return
      }

      try {
        const finalizeResponse = PhrDicomUploadFinalizeResponseSchema.parse(await fetchWrapper.post(
          `/api/phr/patients/${patientId}/dicom/uploads/${currentUploadId}/finalize`,
          {},
        ))
        if (finalizeResponse.duplicate_upload === true) {
          setError(null)
          setPhase('duplicate')
          await loadStudies()
          return
        }
      } catch (caught) {
        const message = errorMessage(caught)
        setError(message)
        setSummary((prev) => appendFailure(prev, 'Finalize upload', message))
        await cancelUploadSession(patientId, currentUploadId)
        setPhase('failed')
        return
      }

      setPhase('done')
      await loadStudies()
    } catch (caught) {
      const message = errorMessage(caught)
      setError(message)
      setSummary((prev) => appendFailure(prev, 'Upload session', message))
      if (uploadId !== null) {
        await cancelUploadSession(patientId, uploadId)
      }
      setPhase('failed')
    } finally {
      controllerRef.current = null
    }
  }

  function cancelUpload(): void {
    if (controllerRef.current && !controllerRef.current.aborted) {
      setPhase('aborting')
      controllerRef.current.abort()
    } else {
      closeDialog()
    }
  }

  function closeDialog(): void {
    setDialogOpen(false)
    setQueuedFiles([])
    setCurrentFileName('')
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between gap-4">
        <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
          <Images className="size-6 text-primary" />
          Imaging
        </h1>
        <div className="flex gap-2">
          {canManage && (
            <Button type="button" size="sm" onClick={() => inputRef.current?.click()}>
              <UploadCloud className="size-4" />
              Upload DICOM
            </Button>
          )}
          <Button type="button" variant="outline" size="sm" onClick={() => void loadStudies()} disabled={busy}>
            <RefreshCcw className="size-4" />
            Refresh
          </Button>
        </div>
      </div>

      <input
        ref={inputRef}
        type="file"
        className="hidden"
        multiple
        onChange={(event) => onFolderChosen(event.target.files)}
        {...directoryInputAttributes}
      />

      {error && (
        <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      {busy && studies.length === 0 && <p className="text-sm text-muted-foreground">Loading…</p>}

      {!busy && studies.length === 0 && (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          No imaging studies.
        </div>
      )}

      {studies.length > 0 && (
        <div className="flex flex-col gap-3">
          {studies.map((study) => (
            <div
              key={study.id}
              className={cn(
                'flex flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between',
                onDrill && 'cursor-pointer transition-colors hover:border-primary/50 hover:bg-accent/40',
              )}
              onClick={onDrill ? () => onDrill({ id: 'imaging-study-detail', instance: String(study.id) }) : undefined}
              tabIndex={onDrill ? 0 : undefined}
              onKeyDown={onDrill ? (e) => {
                if (e.target !== e.currentTarget) return
                if (e.key === 'Enter' || e.key === ' ') {
                  e.preventDefault()
                  onDrill({ id: 'imaging-study-detail', instance: String(study.id) })
                }
              } : undefined}
            >
              <div className="min-w-0">
                <p className="break-words font-medium text-card-foreground">{study.description || 'DICOM Study'}</p>
                <p className="mt-1 break-words text-xs text-muted-foreground">
                  {[study.study_date, study.modalities, `${study.series_count} series`, `${study.instance_count} images`, formatBytes(study.file_size_bytes)].filter(Boolean).join(' · ')}
                </p>
              </div>
              <div className="flex shrink-0 flex-wrap gap-2">
                <Button type="button" variant="outline" size="sm" onClick={(e) => { e.stopPropagation(); openInOhifViewer(patientId, study.id) }}>
                  <ExternalLink className="size-4" />
                  Viewer
                </Button>
                <Button type="button" variant="outline" size="sm" onClick={(e) => { e.stopPropagation(); downloadStudyZip(patientId, study.id) }}>
                  <Download className="size-4" />
                  ZIP
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}

      <Dialog
        open={dialogOpen}
        onOpenChange={(open) => {
          if (!open && (phase === 'uploading' || phase === 'aborting')) {
            return
          }
          if (!open) {
            closeDialog()
          }
        }}
      >
        <DialogContent showCloseButton={phase !== 'uploading' && phase !== 'aborting'}>
          <DialogHeader>
            <DialogTitle>
              {phase === 'uploading' && 'Uploading…'}
              {phase === 'aborting' && 'Cancelling…'}
              {phase === 'done' && (summary.errored > 0 ? 'Upload finished with errors' : 'Upload complete')}
              {phase === 'duplicate' && 'Duplicate study skipped'}
              {phase === 'cancelled' && 'Upload cancelled'}
              {phase === 'failed' && 'Upload failed'}
            </DialogTitle>
            <DialogDescription>
              {(phase === 'uploading' || phase === 'aborting') && `${filesProcessed} of ${totalFiles} files · ${formatBytes(bytesSent)} / ${formatBytes(totalBytes)}${rootName ? ` · ${rootName}` : ''}`}
              {phase === 'done' && `Stored ${summary.stored} · Skipped ${summary.skipped}${summary.errored > 0 ? ` · Errored ${summary.errored}` : ''}`}
              {phase === 'duplicate' && `No new images were added. Stored ${summary.stored} · Skipped ${summary.skipped}`}
              {phase === 'cancelled' && `The upload session was cancelled and stored files were discarded. Processed ${filesProcessed} of ${totalFiles} files.`}
              {phase === 'failed' && `The upload session was stopped and stored files were discarded. Processed ${filesProcessed} of ${totalFiles} files.`}
            </DialogDescription>
          </DialogHeader>

          {(phase === 'uploading' || phase === 'aborting') && (
            <div className="flex flex-col gap-2">
              <Progress value={progressPercent} />
              <p className="truncate text-xs text-muted-foreground">
                <Loader2 className="mr-1 inline size-3 animate-spin" />
                {phase === 'aborting' ? 'Stopping in-flight uploads…' : currentFileName || 'Preparing…'}
              </p>
            </div>
          )}

          {(phase === 'done' || phase === 'duplicate' || phase === 'cancelled' || phase === 'failed') && summary.failures.length > 0 && (
            <div className="max-h-40 overflow-y-auto rounded-md border border-border bg-muted/30 p-2 text-xs">
              <p className="mb-1 flex items-center gap-1 font-medium text-foreground">
                <AlertCircle className="size-3" />
                Failed files
              </p>
              <ul className="space-y-0.5 text-muted-foreground">
                {summary.failures.slice(0, 50).map((failure) => (
                  <li key={`${failure.path}:${failure.reason}`} className="break-words">
                    {failure.path} — {failure.reason}
                  </li>
                ))}
                {summary.failures.length > 50 && (
                  <li className="italic">…and {summary.failures.length - 50} more</li>
                )}
              </ul>
            </div>
          )}

          {phase === 'done' && summary.errored === 0 && summary.stored > 0 && (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
              <CheckCircle2 className="size-4 text-primary" />
              Studies are now visible below.
            </p>
          )}

          {phase === 'duplicate' && (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
              <CheckCircle2 className="size-4 text-muted-foreground" />
              This study is already available in the imaging library.
            </p>
          )}

          <DialogFooter>
            {phase === 'uploading' && (
              <Button type="button" variant="outline" onClick={cancelUpload}>
                <X className="size-4" />
                Cancel upload
              </Button>
            )}
            {phase === 'aborting' && (
              <Button type="button" variant="outline" disabled>
                <Loader2 className="size-4 animate-spin" />
                Cancelling…
              </Button>
            )}
            {(phase === 'done' || phase === 'duplicate' || phase === 'cancelled' || phase === 'failed') && (
              <Button type="button" onClick={closeDialog}>
                {phase === 'done' || phase === 'duplicate' ? 'Done' : 'Close'}
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

interface FileOutcome {
  stored: boolean
  skippedReason: string | null
  errorMessage: string | null
  relativePath: string
}

interface SignedUploadBatchRequestFile {
  client_id: string
  filename: string
  relative_path: string
  content_type: string
  file_size: number
}

interface UploadControllerOptions {
  patientId: number
  uploadId: number
  files: FileWithRelativePath[]
  concurrency: number
  maxFileBytes: number | null
  maxFileSizeLabel: string | null
  onFileBytesProgress: (deltaBytes: number) => void
  onFileStarted: (name: string) => void
  onFileFinished: (outcome: FileOutcome) => void
}

class UploadController {
  private readonly options: UploadControllerOptions

  private nextIndex = 0

  private nextSigningIndex = 0

  private readonly signedUploadsByIndex = new Map<number, PhrDicomSignedUploadBatchItem>()

  private signedUploadBatchPromise: Promise<void> | null = null

  private readonly activeRequests = new Set<XMLHttpRequest>()

  private hasHardFailure = false

  aborted = false

  constructor(options: UploadControllerOptions) {
    this.options = options
  }

  abort(): void {
    this.aborted = true
    for (const request of this.activeRequests) {
      request.abort()
    }
  }

  async run(): Promise<FileOutcome[]> {
    const outcomes: FileOutcome[] = []
    const workerCount = Math.min(this.options.concurrency, this.options.files.length)
    const workers: Promise<void>[] = []
    for (let i = 0; i < workerCount; i++) {
      workers.push(this.runWorker(outcomes))
    }
    await Promise.all(workers)

    return outcomes
  }

  private async runWorker(outcomes: FileOutcome[]): Promise<void> {
    while (!this.aborted && !this.hasHardFailure) {
      const index = this.nextIndex++
      if (index >= this.options.files.length) {
        return
      }
      const file = this.options.files[index]
      if (!file) {
        return
      }
      const outcome = await this.uploadOne(index, file)
      outcomes.push(outcome)
      this.options.onFileFinished(outcome)
      if (outcome.errorMessage !== null && !this.aborted) {
        this.hasHardFailure = true
      }
    }
  }

  private async uploadOne(index: number, file: FileWithRelativePath): Promise<FileOutcome> {
    const relativePath = relativeFilePath(file)
    this.options.onFileStarted(relativePath)

    if (this.options.maxFileBytes !== null && file.size > this.options.maxFileBytes) {
      this.options.onFileBytesProgress(file.size)

      return {
        stored: false,
        skippedReason: null,
        errorMessage: `File is ${formatBytes(file.size)}, which exceeds the server upload limit of ${this.options.maxFileSizeLabel ?? formatBytes(this.options.maxFileBytes)}.`,
        relativePath,
      }
    }

    try {
      const signedUpload = await this.signedUploadFor(index)

      if (this.aborted) {
        return { stored: false, skippedReason: null, errorMessage: 'Cancelled.', relativePath }
      }

      await this.putFileToStorage(file, signedUpload.upload_url, signedUpload.headers)

      if (this.aborted) {
        return { stored: false, skippedReason: null, errorMessage: 'Cancelled.', relativePath }
      }

      const completed = PhrDicomUploadFileResponseSchema.parse(await fetchWrapper.post(
        `/api/phr/patients/${this.options.patientId}/dicom/uploads/${this.options.uploadId}/files/complete`,
        {
          r2_key: signedUpload.r2_key,
          relative_path: signedUpload.relative_path,
          original_filename: file.name,
          mime_type: file.type || 'application/dicom',
          file_size_bytes: file.size,
        },
      ))

      return {
        stored: completed.result.stored,
        skippedReason: completed.result.skipped_reason,
        errorMessage: null,
        relativePath: completed.result.relative_path,
      }
    } catch (error) {
      return {
        stored: false,
        skippedReason: null,
        errorMessage: errorMessage(error),
        relativePath,
      }
    }
  }

  private async signedUploadFor(index: number): Promise<PhrDicomSignedUploadBatchItem> {
    while (!this.signedUploadsByIndex.has(index)) {
      if (this.nextSigningIndex >= this.options.files.length && this.signedUploadBatchPromise === null) {
        break
      }

      await this.ensureSignedUploadBatch(index)
    }

    const signedUpload = this.signedUploadsByIndex.get(index)
    if (!signedUpload) {
      const file = this.options.files[index]
      const fileLabel = file ? relativeFilePath(file) : `file ${index + 1}`
      throw new Error(`Unable to reserve DICOM upload URL for ${fileLabel}.`)
    }

    this.signedUploadsByIndex.delete(index)

    return signedUpload
  }

  private async ensureSignedUploadBatch(requiredIndex: number): Promise<void> {
    if (this.signedUploadBatchPromise === null) {
      this.signedUploadBatchPromise = this.requestNextSignedUploadBatch(requiredIndex).finally(() => {
        this.signedUploadBatchPromise = null
      })
    }

    await this.signedUploadBatchPromise
  }

  private async requestNextSignedUploadBatch(requiredIndex: number): Promise<void> {
    if (this.aborted) {
      return
    }

    if (this.nextSigningIndex < requiredIndex) {
      this.nextSigningIndex = requiredIndex
    }

    const files: SignedUploadBatchRequestFile[] = []
    while (this.nextSigningIndex < this.options.files.length && files.length < SIGNED_UPLOAD_BATCH_SIZE) {
      const fileIndex = this.nextSigningIndex
      const file = this.options.files[fileIndex]
      this.nextSigningIndex += 1

      if (!file || !this.shouldRequestSignedUpload(file)) {
        continue
      }

      files.push({
        client_id: String(fileIndex),
        filename: file.name,
        relative_path: relativeFilePath(file),
        content_type: file.type || 'application/dicom',
        file_size: file.size,
      })
    }

    if (files.length === 0) {
      return
    }

    const response = PhrDicomSignedUploadBatchResponseSchema.parse(await fetchWrapper.post(
      `/api/phr/patients/${this.options.patientId}/dicom/uploads/${this.options.uploadId}/signed-urls`,
      { files },
    ))

    for (const signedUpload of response.uploads) {
      const fileIndex = Number.parseInt(signedUpload.client_id, 10)
      if (Number.isInteger(fileIndex)) {
        this.signedUploadsByIndex.set(fileIndex, signedUpload)
      }
    }
  }

  private shouldRequestSignedUpload(file: FileWithRelativePath): boolean {
    return this.options.maxFileBytes === null || file.size <= this.options.maxFileBytes
  }

  private putFileToStorage(file: FileWithRelativePath, uploadUrl: string, signedHeaders: Record<string, string>): Promise<void> {
    return new Promise<void>((resolve, reject) => {
      const xhr = new XMLHttpRequest()
      xhr.open('PUT', uploadUrl)
      for (const [key, value] of Object.entries(signedHeaders)) {
        xhr.setRequestHeader(key, value)
      }
      if (!Object.keys(signedHeaders).some((key) => key.toLowerCase() === 'content-type')) {
        xhr.setRequestHeader('Content-Type', file.type || 'application/dicom')
      }

      let lastLoaded = 0
      xhr.upload.addEventListener('progress', (event) => {
        if (event.lengthComputable) {
          const delta = event.loaded - lastLoaded
          lastLoaded = event.loaded
          this.options.onFileBytesProgress(delta)
        }
      })

      xhr.addEventListener('load', () => {
        this.activeRequests.delete(xhr)
        this.options.onFileBytesProgress(file.size - lastLoaded)

        if (xhr.status >= 200 && xhr.status < 300) {
          resolve()
        } else {
          reject(new Error(`Storage upload failed: ${extractServerError(xhr)}`))
        }
      })

      xhr.addEventListener('error', () => {
        this.activeRequests.delete(xhr)
        reject(new Error('Network error during storage upload.'))
      })

      xhr.addEventListener('abort', () => {
        this.activeRequests.delete(xhr)
        reject(new Error('Cancelled.'))
      })

      this.activeRequests.add(xhr)
      try {
        xhr.send(file)
      } catch (error) {
        this.activeRequests.delete(xhr)
        reject(error instanceof Error ? error : new Error(errorMessage(error)))
      }
    })
  }
}

function applyOutcomeToSummary(summary: UploadSummary, outcome: FileOutcome): UploadSummary {
  if (outcome.stored) {
    return { ...summary, stored: summary.stored + 1 }
  }
  if (outcome.errorMessage !== null) {
    return {
      ...summary,
      errored: summary.errored + 1,
      failures: [...summary.failures, { path: outcome.relativePath, reason: outcome.errorMessage }],
    }
  }
  return {
    ...summary,
    skipped: summary.skipped + 1,
    failures: outcome.skippedReason && outcome.skippedReason !== 'auxiliary_file' && outcome.skippedReason !== 'duplicate_sop_instance'
      ? [...summary.failures, { path: outcome.relativePath, reason: outcome.skippedReason }]
      : summary.failures,
  }
}

function appendFailure(summary: UploadSummary, path: string, reason: string): UploadSummary {
  return {
    ...summary,
    errored: summary.errored + 1,
    failures: [...summary.failures, { path, reason }],
  }
}

async function cancelUploadSession(patientId: number, uploadId: number): Promise<void> {
  await fetchWrapper.post(`/api/phr/patients/${patientId}/dicom/uploads/${uploadId}/cancel`, {}).catch(() => {})
}

function extractServerError(xhr: XMLHttpRequest): string {
  try {
    const parsed = JSON.parse(xhr.responseText)
    if (parsed && typeof parsed === 'object' && 'message' in parsed) {
      return String(parsed.message)
    }
  } catch {
    // body wasn't JSON, fall through
  }
  const status = xhr.statusText || `HTTP ${xhr.status}`
  const snippet = truncate(xhr.responseText, 200)
  return snippet ? `${status} — ${snippet}` : status
}

function truncate(text: string, max: number): string {
  if (!text) {
    return ''
  }
  const oneLine = text.replace(/\s+/g, ' ').trim()
  if (oneLine.length <= max) {
    return oneLine
  }
  return `${oneLine.slice(0, max)}…`
}

function compareStudiesNewestFirst(a: PhrDicomStudy, b: PhrDicomStudy): number {
  const dateComparison = compareNullableStringsDesc(a.study_date, b.study_date)
  if (dateComparison !== 0) {
    return dateComparison
  }

  const timeComparison = compareNullableStringsDesc(a.study_time, b.study_time)
  if (timeComparison !== 0) {
    return timeComparison
  }

  return b.id - a.id
}

function compareNullableStringsDesc(a: string | null, b: string | null): number {
  return (b ?? '').localeCompare(a ?? '')
}

function relativeFilePath(file: FileWithRelativePath): string {
  return file.webkitRelativePath || file.name
}

function inferUploadRootName(files: FileWithRelativePath[]): string | null {
  const firstFile = files[0]
  if (!firstFile) {
    return null
  }

  const firstPath = relativeFilePath(firstFile)
  const segments = firstPath.split('/').filter(Boolean)

  return segments.length > 1 ? (segments[0] ?? null) : null
}

const AUXILIARY_BASENAMES = new Set(['thumbs.db', 'desktop.ini', '.ds_store'])

const AUXILIARY_EXTENSIONS = new Set([
  'bat',
  'bmp',
  'cmd',
  'com',
  'config',
  'css',
  'db',
  'dll',
  'doc',
  'docx',
  'exe',
  'exml',
  'gif',
  'htm',
  'html',
  'ico',
  'inf',
  'ini',
  'jpg',
  'jpeg',
  'js',
  'lnk',
  'msi',
  'pdf',
  'png',
  'rtf',
  'std',
  'txt',
  'url',
  'xml',
])

function isAuxiliaryUploadPath(path: string): boolean {
  const normalizedPath = path.replace(/\\/g, '/')
  const basename = normalizedPath.split('/').pop()?.toLowerCase() ?? ''

  if (basename === 'dicomdir') {
    return false
  }

  if (AUXILIARY_BASENAMES.has(basename)) {
    return true
  }

  const extension = basename.includes('.') ? basename.split('.').pop() ?? '' : ''
  return AUXILIARY_EXTENSIONS.has(extension)
}

function downloadStudyZip(patientId: number, studyId: number): void {
  window.open(`/api/phr/patients/${patientId}/dicom/studies/${studyId}/download`, '_blank', 'noopener,noreferrer')
}

function openInOhifViewer(patientId: number, studyId: number): void {
  const manifestUrl = `/api/phr/patients/${patientId}/dicom/studies/${studyId}/viewer-json`
  const viewerUrl = `/ohif/viewer/dicomjson?url=${encodeURIComponent(manifestUrl)}`
  window.open(viewerUrl, '_blank', 'noopener,noreferrer')
}
