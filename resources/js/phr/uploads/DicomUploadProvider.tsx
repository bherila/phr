import type { ReactElement, ReactNode } from 'react'
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'

import { fetchWrapper } from '@/fetchWrapper'
import { errorMessage } from '@/phr/shared'
import { PhrDicomUploadFinalizeResponseSchema, PhrDicomUploadResponseSchema } from '@/phr/types'

import {
  appendFailure,
  applyOutcomeToSummary,
  cancelUploadSession,
  type ConcurrencyBudget,
  EMPTY_UPLOAD_SUMMARY,
  filterUploadableFiles,
  globalUploadBudget,
  inferUploadRootName,
  isActiveUploadPhase,
  UploadController,
  type UploadPhase,
  type UploadSummary,
} from './dicomUpload'

export interface DicomUploadJob {
  id: string
  patientId: number
  /** Folder name the user picked, when the browser reported a directory path. */
  rootName: string | null
  phase: UploadPhase
  totalFiles: number
  totalBytes: number
  bytesSent: number
  filesProcessed: number
  currentFileName: string
  summary: UploadSummary
  error: string | null
  startedAt: number
}

export type StartUploadResult = { ok: true, jobId: string } | { ok: false, error: string }

export interface DicomUploadContextValue {
  jobs: DicomUploadJob[]
  activeJobCount: number
  /** Job whose detailed progress modal is open, or null when the tray is collapsed. */
  openJobId: string | null
  startUpload: (patientId: number, files: File[]) => StartUploadResult
  cancelUpload: (jobId: string) => void
  dismissJob: (jobId: string) => void
  openJob: (jobId: string | null) => void
  /** Bumped once per patient each time a job stores or de-duplicates a study. */
  completions: Readonly<Record<number, number>>
}

const DicomUploadContext = createContext<DicomUploadContextValue | null>(null)

let nextJobId = 0

function makeJobId(): string {
  nextJobId += 1
  return `dicom-upload-${nextJobId}`
}

/**
 * `beforeunload` guard body, kept separate so tests can assert the contract without
 * fighting JSDOM's unload handling. Only a *real* unload (tab close, reload, external
 * navigation) reaches this — Miller column navigation is client-side and never fires it.
 */
export function preventUnloadDuringUpload(event: BeforeUnloadEvent): void {
  event.preventDefault()
  event.returnValue = ''
}

export interface DicomUploadProviderProps {
  children: ReactNode
  /**
   * Permit pool gating simultaneous file POSTs. Defaults to the process-wide budget so
   * every job in the app shares one ceiling; tests inject their own to stay isolated.
   */
  budget?: ConcurrencyBudget
}

export function DicomUploadProvider({ children, budget = globalUploadBudget }: DicomUploadProviderProps): ReactElement {
  const [jobs, setJobs] = useState<DicomUploadJob[]>([])
  const [openJobId, setOpenJobId] = useState<string | null>(null)
  const [completions, setCompletions] = useState<Record<number, number>>({})
  const controllersRef = useRef(new Map<string, UploadController>())

  const updateJob = useCallback((jobId: string, updater: (job: DicomUploadJob) => DicomUploadJob): void => {
    setJobs((previous) => previous.map((job) => (job.id === jobId ? updater(job) : job)))
  }, [])

  const runJob = useCallback(async (jobId: string, patientId: number, files: File[], rootName: string | null): Promise<void> => {
    let uploadId: number | null = null

    try {
      const openResponse = await fetchWrapper.post(
        `/api/phr/patients/${patientId}/dicom/uploads`,
        rootName ? { root_name: rootName } : {},
      )
      const { upload, limits } = PhrDicomUploadResponseSchema.parse(openResponse)
      uploadId = upload.id

      const controller = new UploadController({
        patientId,
        uploadId: upload.id,
        files,
        budget,
        maxFileBytes: limits?.max_file_bytes ?? null,
        maxFileSizeLabel: limits?.max_file_size_label ?? null,
        onFileBytesProgress: (bytes) => updateJob(jobId, (job) => ({ ...job, bytesSent: job.bytesSent + bytes })),
        onFileStarted: (name) => updateJob(jobId, (job) => ({ ...job, currentFileName: name })),
        onFileFinished: (outcome) => updateJob(jobId, (job) => ({
          ...job,
          filesProcessed: job.filesProcessed + 1,
          summary: applyOutcomeToSummary(job.summary, outcome),
        })),
      })
      controllersRef.current.set(jobId, controller)

      const outcomes = await controller.run()

      if (controller.aborted) {
        await cancelUploadSession(patientId, upload.id)
        updateJob(jobId, (job) => ({ ...job, phase: 'cancelled', error: 'Upload cancelled.', currentFileName: '' }))
        return
      }

      const failedOutcomes = outcomes.filter((outcome) => outcome.errorMessage !== null)
      if (failedOutcomes.length > 0) {
        await cancelUploadSession(patientId, upload.id)
        updateJob(jobId, (job) => ({
          ...job,
          phase: 'failed',
          error: failedOutcomes[0]?.errorMessage ?? 'Upload failed.',
          currentFileName: '',
        }))
        return
      }

      try {
        const finalizeResponse = PhrDicomUploadFinalizeResponseSchema.parse(await fetchWrapper.post(
          `/api/phr/patients/${patientId}/dicom/uploads/${upload.id}/finalize`,
          {},
        ))
        if (finalizeResponse.duplicate_upload === true) {
          updateJob(jobId, (job) => ({ ...job, phase: 'duplicate', error: null, currentFileName: '' }))
          setCompletions((previous) => ({ ...previous, [patientId]: (previous[patientId] ?? 0) + 1 }))
          return
        }
      } catch (caught) {
        const message = errorMessage(caught)
        await cancelUploadSession(patientId, upload.id)
        updateJob(jobId, (job) => ({
          ...job,
          phase: 'failed',
          error: message,
          currentFileName: '',
          summary: appendFailure(job.summary, 'Finalize upload', message),
        }))
        return
      }

      updateJob(jobId, (job) => ({ ...job, phase: 'done', error: null, currentFileName: '' }))
      setCompletions((previous) => ({ ...previous, [patientId]: (previous[patientId] ?? 0) + 1 }))
    } catch (caught) {
      const message = errorMessage(caught)
      if (uploadId !== null) {
        await cancelUploadSession(patientId, uploadId)
      }
      updateJob(jobId, (job) => ({
        ...job,
        phase: 'failed',
        error: message,
        currentFileName: '',
        summary: appendFailure(job.summary, 'Upload session', message),
      }))
    } finally {
      controllersRef.current.delete(jobId)
    }
  }, [budget, updateJob])

  const startUpload = useCallback((patientId: number, files: File[]): StartUploadResult => {
    const accepted = filterUploadableFiles(files)
    if (accepted.length === 0) {
      return { ok: false, error: 'No DICOM-compatible files were found in the chosen folder.' }
    }

    const jobId = makeJobId()
    const job: DicomUploadJob = {
      id: jobId,
      patientId,
      rootName: inferUploadRootName(accepted),
      phase: 'uploading',
      totalFiles: accepted.length,
      totalBytes: accepted.reduce((sum, file) => sum + file.size, 0),
      bytesSent: 0,
      filesProcessed: 0,
      currentFileName: '',
      summary: EMPTY_UPLOAD_SUMMARY,
      error: null,
      startedAt: Date.now(),
    }

    setJobs((previous) => [...previous, job])
    void runJob(jobId, patientId, accepted, job.rootName)

    return { ok: true, jobId }
  }, [runJob])

  const cancelUpload = useCallback((jobId: string): void => {
    const controller = controllersRef.current.get(jobId)
    if (!controller || controller.aborted) {
      return
    }
    updateJob(jobId, (job) => ({ ...job, phase: 'aborting' }))
    controller.abort()
  }, [updateJob])

  const dismissJob = useCallback((jobId: string): void => {
    setJobs((previous) => previous.filter((job) => job.id !== jobId))
    setOpenJobId((previous) => (previous === jobId ? null : previous))
  }, [])

  const openJob = useCallback((jobId: string | null): void => {
    setOpenJobId(jobId)
  }, [])

  const activeJobCount = useMemo(() => jobs.filter((job) => isActiveUploadPhase(job.phase)).length, [jobs])

  useEffect(() => {
    if (activeJobCount === 0) {
      return
    }
    window.addEventListener('beforeunload', preventUnloadDuringUpload)
    return () => {
      window.removeEventListener('beforeunload', preventUnloadDuringUpload)
    }
  }, [activeJobCount])

  const value = useMemo<DicomUploadContextValue>(() => ({
    jobs,
    activeJobCount,
    openJobId,
    startUpload,
    cancelUpload,
    dismissJob,
    openJob,
    completions,
  }), [jobs, activeJobCount, openJobId, startUpload, cancelUpload, dismissJob, openJob, completions])

  return <DicomUploadContext.Provider value={value}>{children}</DicomUploadContext.Provider>
}

export function useDicomUploads(): DicomUploadContextValue {
  const value = useContext(DicomUploadContext)
  if (!value) {
    throw new Error('useDicomUploads must be used inside a <DicomUploadProvider>.')
  }
  return value
}

/**
 * Number of uploads that have finished for `patientId` since mount. Views that list
 * imported data (the Imaging tab) watch this to refresh themselves without owning any
 * upload state. Returns 0 outside a provider so those views still render standalone.
 */
export function useDicomUploadCompletions(patientId: number | undefined): number {
  const value = useContext(DicomUploadContext)
  if (!value || patientId === undefined) {
    return 0
  }
  return value.completions[patientId] ?? 0
}
