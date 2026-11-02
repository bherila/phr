import type { ReactElement, ReactNode } from 'react'
import { createContext, use, useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { putToStorage } from '@/phr/uploads/directUpload'

import { appendEhiChunk, ehiFailureText, type EhiUpload, type EhiUploadedPart, getEhiUpload, queueEhiIntake, signEhiPart, startEhiUpload } from './ehiApi'

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
  /**
   * False once every byte is acknowledged: intake is then being queued, and a
   * cancel could no longer stop the import it claims to.
   */
  cancellable: boolean
  error: string | null
}

export interface EhiUploadContextValue {
  jobs: EhiUploadJob[]
  start: (patientId: number, file: File) => string
  cancel: (jobId: string) => void
  dismiss: (jobId: string) => void
}

const EhiUploadContext = createContext<EhiUploadContextValue | null>(null)

/** Attempts per chunk or part before the upload is reported as failed. */
const CHUNK_ATTEMPTS = 3

/** A store that hides ETag will never complete: retrying only hides the reason. */
class EtagHiddenError extends Error {
  constructor() {
    super('Storage accepted the file but hid its ETag. The bucket’s CORS rules must expose ETag.')
    this.name = 'EtagHiddenError'
  }
}

let nextJobId = 0

function delay(milliseconds: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, milliseconds))
}

export interface EhiUploadProviderProps {
  children: ReactNode
  /** Wait between chunk retries; tests pass 0. */
  retryDelayMs?: number
}

/**
 * Runs browser uploads of EHI ZIPs. Mounted by the shell above the pages, like the DICOM
 * provider, so moving between pages does not stop an upload. Only the
 * byte transfer lives here: once intake is queued the server owns the import, and the
 * Imports page reads its progress from there.
 */
export function EhiUploadProvider({ children, retryDelayMs = 1000 }: EhiUploadProviderProps): ReactElement {
  const [jobs, setJobs] = useState<EhiUploadJob[]>([])
  const controllersRef = useRef(new Map<string, AbortController>())
  /** Jobs past the point where cancelling could stop them. */
  const committedRef = useRef(new Set<string>())

  const update = useCallback((jobId: string, patch: Partial<EhiUploadJob>): void => {
    setJobs((previous) => previous.map((job) => (job.id === jobId ? { ...job, ...patch } : job)))
  }, [])

  /** Sends the file straight to the store, one signed part at a time. Null when cancelled. */
  const sendParts = useCallback(async (jobId: string, patientId: number, opened: EhiUpload, file: File, signal: AbortSignal): Promise<EhiUploadedPart[] | null> => {
    const partSize = opened.part_size_bytes
    if (!partSize) {
      throw new Error('The server did not say how to divide this file.')
    }
    const parts: EhiUploadedPart[] = []
    const partCount = Math.ceil(file.size / partSize)
    for (let partNumber = 1; partNumber <= partCount; partNumber += 1) {
      const start = (partNumber - 1) * partSize
      const end = Math.min(start + partSize, file.size)
      let attempts = 0
      for (;;) {
        if (signal.aborted) {
          return null
        }
        try {
          // Signed for each attempt: a part retried later must not meet an expired URL. A
          // part sent again replaces the one before it, so a retry needs no other cleanup.
          const signed = await signEhiPart(patientId, opened.id, partNumber, signal)
          const etag = await putToStorage(signed, file.slice(start, end), { signal })
          if (!etag) {
            throw new EtagHiddenError()
          }
          parts.push({ part_number: partNumber, etag })
          update(jobId, { bytesSent: end })
          break
        } catch (caught) {
          if (signal.aborted) {
            return null
          }
          attempts += 1
          if (caught instanceof EtagHiddenError || attempts >= CHUNK_ATTEMPTS) {
            throw caught
          }
          await delay(retryDelayMs * attempts)
        }
      }
    }

    return parts
  }, [retryDelayMs, update])

  const run = useCallback(async (jobId: string, patientId: number, file: File, signal: AbortSignal): Promise<void> => {
    try {
      const opened = await startEhiUpload(patientId, file.size)
      update(jobId, { uploadId: opened.id })
      if (opened.transport === 's3') {
        const parts = await sendParts(jobId, patientId, opened, file, signal)
        if (parts === null || signal.aborted) {
          return
        }
        committedRef.current.add(jobId)
        update(jobId, { cancellable: false })
        await queueEhiIntake(patientId, opened.id, parts)
        update(jobId, { phase: 'queued' })
        return
      }
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
          // The server is the authority on what it kept: continue from there. If
          // asking fails too, retry from the last known offset; a stale offset is
          // refused by the server and spends an attempt like any other failure.
          try {
            offset = (await getEhiUpload(patientId, opened.id)).uploaded_bytes
            update(jobId, { bytesSent: offset })
          } catch {
            // Keep the offset; the attempt limit still bounds the retries.
          }
        }
      }
      if (signal.aborted) {
        return
      }
      committedRef.current.add(jobId)
      update(jobId, { cancellable: false })
      await queueEhiIntake(patientId, opened.id)
      update(jobId, { phase: 'queued' })
    } catch (caught) {
      if (signal.aborted) {
        return
      }
      update(jobId, { phase: 'failed', error: ehiFailureText(caught) })
    } finally {
      controllersRef.current.delete(jobId)
    }
  }, [retryDelayMs, sendParts, update])

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
      cancellable: true,
      error: null,
    }])
    void run(jobId, patientId, file, controller.signal)

    return jobId
  }, [run])

  const cancel = useCallback((jobId: string): void => {
    if (committedRef.current.has(jobId)) {
      return
    }
    controllersRef.current.get(jobId)?.abort()
    update(jobId, { phase: 'cancelled', cancellable: false, error: null })
  }, [update])

  const dismiss = useCallback((jobId: string): void => {
    if (!committedRef.current.has(jobId)) {
      controllersRef.current.get(jobId)?.abort()
    }
    setJobs((previous) => previous.filter((job) => job.id !== jobId))
  }, [])

  const uploading = jobs.some((job) => job.phase === 'uploading')
  useEffect(() => {
    if (!uploading) {
      return undefined
    }
    // Only a real unload reaches this; shell navigation is client-side.
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
