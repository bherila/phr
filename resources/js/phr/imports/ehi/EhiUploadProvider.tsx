import type { ReactElement, ReactNode } from 'react'
import { createContext, use, useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { errorMessage } from '@/phr/shared'

import { appendEhiChunk, describeEhiError, getEhiUpload, queueEhiIntake, startEhiUpload } from './ehiApi'

export type EhiUploadPhase = 'uploading' | 'queued' | 'failed' | 'cancelled'

export interface EhiUploadJob {
  id: string
  patientId: number
  fileName: string
  fileSizeBytes: number
  /** The server upload, once opened. */
  uploadId: number | null
  /** Bytes the server has acknowledged. */
  bytesSent: number
  phase: EhiUploadPhase
  error: string | null
}

export interface EhiUploadContextValue {
  jobs: EhiUploadJob[]
  start: (patientId: number, file: File) => string
  cancel: (jobId: string) => void
  dismiss: (jobId: string) => void
}

const EhiUploadContext = createContext<EhiUploadContextValue | null>(null)

/** Attempts per chunk before the upload is reported as failed. */
const CHUNK_ATTEMPTS = 3

let nextJobId = 0

function delay(milliseconds: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, milliseconds))
}

/** The fixed reason code a refusal carries, when it carries one. */
function reasonOf(caught: unknown): string | null {
  return typeof caught === 'string' && /^[a-z_]+$/.test(caught) ? caught : null
}

export interface EhiUploadProviderProps {
  children: ReactNode
  /** Wait between chunk retries; tests pass 0. */
  retryDelayMs?: number
}

/**
 * Runs browser uploads of EHI ZIPs. Mounted by the Miller shell above the column stack,
 * like the DICOM provider, so moving between tabs does not stop an upload. Only the
 * byte transfer lives here: once intake is queued the server owns the import, and the
 * Imports page reads its progress from there.
 */
export function EhiUploadProvider({ children, retryDelayMs = 1000 }: EhiUploadProviderProps): ReactElement {
  const [jobs, setJobs] = useState<EhiUploadJob[]>([])
  const controllersRef = useRef(new Map<string, AbortController>())

  const update = useCallback((jobId: string, patch: Partial<EhiUploadJob>): void => {
    setJobs((previous) => previous.map((job) => (job.id === jobId ? { ...job, ...patch } : job)))
  }, [])

  const run = useCallback(async (jobId: string, patientId: number, file: File, signal: AbortSignal): Promise<void> => {
    try {
      const opened = await startEhiUpload(patientId, file.size)
      update(jobId, { uploadId: opened.id })
      const chunkSize = opened.chunk_size_bytes
      let offset = opened.uploaded_bytes
      let attempts = 0
      while (offset < file.size) {
        if (signal.aborted) {
          return
        }
        try {
          const acknowledged = await appendEhiChunk(patientId, opened.id, file.slice(offset, offset + chunkSize), offset, signal)
          offset = acknowledged.uploaded_bytes
          attempts = 0
          update(jobId, { bytesSent: offset })
        } catch (caught) {
          if (signal.aborted) {
            return
          }
          attempts += 1
          if (attempts >= CHUNK_ATTEMPTS) {
            throw caught
          }
          await delay(retryDelayMs * attempts)
          // The server is the authority on what it kept: continue from there.
          offset = (await getEhiUpload(patientId, opened.id)).uploaded_bytes
          update(jobId, { bytesSent: offset })
        }
      }
      await queueEhiIntake(patientId, opened.id)
      update(jobId, { phase: 'queued' })
    } catch (caught) {
      if (signal.aborted) {
        return
      }
      const reason = reasonOf(caught)
      update(jobId, { phase: 'failed', error: reason ? describeEhiError(reason) : errorMessage(caught) })
    } finally {
      controllersRef.current.delete(jobId)
    }
  }, [retryDelayMs, update])

  const start = useCallback((patientId: number, file: File): string => {
    nextJobId += 1
    const jobId = `ehi-upload-${nextJobId}`
    const controller = new AbortController()
    controllersRef.current.set(jobId, controller)
    setJobs((previous) => [...previous, {
      id: jobId,
      patientId,
      fileName: file.name,
      fileSizeBytes: file.size,
      uploadId: null,
      bytesSent: 0,
      phase: 'uploading',
      error: null,
    }])
    void run(jobId, patientId, file, controller.signal)

    return jobId
  }, [run])

  const cancel = useCallback((jobId: string): void => {
    controllersRef.current.get(jobId)?.abort()
    update(jobId, { phase: 'cancelled', error: null })
  }, [update])

  const dismiss = useCallback((jobId: string): void => {
    controllersRef.current.get(jobId)?.abort()
    setJobs((previous) => previous.filter((job) => job.id !== jobId))
  }, [])

  const uploading = jobs.some((job) => job.phase === 'uploading')
  useEffect(() => {
    if (!uploading) {
      return undefined
    }
    // Only a real unload reaches this; Miller navigation is client-side.
    const guard = (event: BeforeUnloadEvent): void => {
      event.preventDefault()
      event.returnValue = ''
    }
    window.addEventListener('beforeunload', guard)

    return () => window.removeEventListener('beforeunload', guard)
  }, [uploading])

  const value = useMemo(() => ({ jobs, start, cancel, dismiss }), [jobs, start, cancel, dismiss])

  return <EhiUploadContext value={value}>{children}</EhiUploadContext>
}

export function useEhiUploads(): EhiUploadContextValue {
  const context = use(EhiUploadContext)
  if (!context) {
    throw new Error('useEhiUploads must be used inside EhiUploadProvider')
  }

  return context
}
