import { fetchWrapper, getCsrfToken } from '@/fetchWrapper'
import { formatBytes } from '@/lib/utils'
import { errorMessage } from '@/phr/shared'
import { type PhrDicomUploadFileResponse, PhrDicomUploadFileResponseSchema } from '@/phr/types'

/**
 * Ceiling on file-upload requests in flight at any moment, shared by *every* job.
 * Three concurrent imports must not mean twelve simultaneous multi-MB POSTs, so the
 * budget below is global rather than per-job (each job still runs up to this many
 * workers; the budget is what actually gates the network).
 */
export const UPLOAD_CONCURRENCY = 4

export type UploadPhase = 'uploading' | 'done' | 'duplicate' | 'aborting' | 'cancelled' | 'failed'

export const ACTIVE_UPLOAD_PHASES: readonly UploadPhase[] = ['uploading', 'aborting']

export function isActiveUploadPhase(phase: UploadPhase): boolean {
  return ACTIVE_UPLOAD_PHASES.includes(phase)
}

export interface FileFailure {
  path: string
  reason: string
}

export interface UploadSummary {
  stored: number
  skipped: number
  errored: number
  failures: FileFailure[]
}

export const EMPTY_UPLOAD_SUMMARY: UploadSummary = { stored: 0, skipped: 0, errored: 0, failures: [] }

export interface FileOutcome {
  stored: boolean
  skippedReason: string | null
  errorMessage: string | null
  relativePath: string
}

/**
 * FIFO permit pool. `acquire()` resolves immediately while permits remain and otherwise
 * queues; `release()` hands the permit straight to the longest-waiting caller so the
 * total number of holders never exceeds the configured ceiling.
 */
export class ConcurrencyBudget {
  private available: number

  private readonly waiters: (() => void)[] = []

  constructor(permits: number) {
    this.available = permits
  }

  acquire(): Promise<void> {
    if (this.available > 0) {
      this.available -= 1
      return Promise.resolve()
    }

    return new Promise<void>((resolve) => {
      this.waiters.push(resolve)
    })
  }

  release(): void {
    const next = this.waiters.shift()
    if (next) {
      next()
      return
    }
    this.available += 1
  }

  /** Permits currently free. Exposed for tests; production code just acquires/releases. */
  get availablePermits(): number {
    return this.available
  }
}

export const globalUploadBudget = new ConcurrencyBudget(UPLOAD_CONCURRENCY)

export interface UploadControllerOptions {
  patientId: number
  uploadId: number
  files: File[]
  budget: ConcurrencyBudget
  maxFileBytes: number | null
  maxFileSizeLabel: string | null
  onFileBytesProgress: (deltaBytes: number) => void
  onFileStarted: (name: string) => void
  onFileFinished: (outcome: FileOutcome) => void
}

export class UploadController {
  private readonly options: UploadControllerOptions

  private nextIndex = 0

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
    const workerCount = Math.min(UPLOAD_CONCURRENCY, this.options.files.length)
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

      // The permit is held only for the request itself, so a job stalled behind other
      // jobs' uploads never occupies budget it is not using.
      await this.options.budget.acquire()
      let outcome: FileOutcome
      try {
        outcome = await this.uploadOne(file)
      } finally {
        this.options.budget.release()
      }

      outcomes.push(outcome)
      this.options.onFileFinished(outcome)
      if (outcome.errorMessage !== null && !this.aborted) {
        this.hasHardFailure = true
      }
    }
  }

  private async uploadOne(file: File): Promise<FileOutcome> {
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

    if (this.aborted) {
      return { stored: false, skippedReason: null, errorMessage: 'Cancelled.', relativePath }
    }

    try {
      const completed = await this.postFile(file, relativePath)

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

  private postFile(file: File, relativePath: string): Promise<PhrDicomUploadFileResponse> {
    return new Promise<PhrDicomUploadFileResponse>((resolve, reject) => {
      const xhr = new XMLHttpRequest()
      xhr.open('POST', `/api/phr/patients/${this.options.patientId}/dicom/uploads/${this.options.uploadId}/files`)
      xhr.setRequestHeader('Accept', 'application/json')
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest')
      const csrfToken = getCsrfToken()
      if (csrfToken) {
        xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken)
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
          try {
            resolve(PhrDicomUploadFileResponseSchema.parse(JSON.parse(xhr.responseText)))
          } catch (error) {
            reject(error instanceof Error ? error : new Error(errorMessage(error)))
          }
        } else {
          reject(new Error(extractServerError(xhr)))
        }
      })

      xhr.addEventListener('error', () => {
        this.activeRequests.delete(xhr)
        reject(new Error('Network error during upload.'))
      })

      xhr.addEventListener('abort', () => {
        this.activeRequests.delete(xhr)
        reject(new Error('Cancelled.'))
      })

      const formData = new FormData()
      formData.append('file', file)
      formData.append('relative_path', relativePath)

      this.activeRequests.add(xhr)
      try {
        xhr.send(formData)
      } catch (error) {
        this.activeRequests.delete(xhr)
        reject(error instanceof Error ? error : new Error(errorMessage(error)))
      }
    })
  }
}

export function applyOutcomeToSummary(summary: UploadSummary, outcome: FileOutcome): UploadSummary {
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

export function appendFailure(summary: UploadSummary, path: string, reason: string): UploadSummary {
  return {
    ...summary,
    errored: summary.errored + 1,
    failures: [...summary.failures, { path, reason }],
  }
}

export async function cancelUploadSession(patientId: number, uploadId: number): Promise<void> {
  await fetchWrapper.post(`/api/phr/patients/${patientId}/dicom/uploads/${uploadId}/cancel`, {}).catch(() => {})
}

export function extractServerError(xhr: XMLHttpRequest): string {
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

export function relativeFilePath(file: File): string {
  return file.webkitRelativePath || file.name
}

export function inferUploadRootName(files: File[]): string | null {
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

export function isAuxiliaryUploadPath(path: string): boolean {
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

/** Drops the non-DICOM debris a burned imaging CD carries alongside the study. */
export function filterUploadableFiles(files: File[]): File[] {
  return files.filter((file) => !isAuxiliaryUploadPath(relativeFilePath(file)))
}
