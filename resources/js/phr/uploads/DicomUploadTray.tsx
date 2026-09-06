import { AlertCircle, CheckCircle2, Loader2, UploadCloud, X } from 'lucide-react'
import type { ReactElement } from 'react'

import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Progress } from '@/components/ui/progress'
import { formatBytes } from '@/lib/utils'

import { isActiveUploadPhase, type UploadPhase } from './dicomUpload'
import { type DicomUploadJob, useDicomUploads } from './DicomUploadProvider'

const PHASE_TITLES: Record<UploadPhase, string> = {
  uploading: 'Uploading…',
  finalizing: 'Finalizing…',
  aborting: 'Cancelling…',
  done: 'Upload complete',
  duplicate: 'Duplicate study skipped',
  cancelled: 'Upload cancelled',
  failed: 'Upload failed',
}

function jobTitle(job: DicomUploadJob): string {
  if (job.phase === 'done' && job.summary.errored > 0) {
    return 'Upload finished with errors'
  }
  return PHASE_TITLES[job.phase]
}

/** "142 / 4863 files — CARDIAC_CT", the collapsed line the tray shows while a job runs. */
export function jobProgressLabel(job: DicomUploadJob): string {
  const counts = `${job.filesProcessed} / ${job.totalFiles} files`
  return job.rootName ? `${counts} — ${job.rootName}` : counts
}

function progressPercent(job: DicomUploadJob): number {
  if (job.totalBytes === 0) {
    return 0
  }
  return Math.min(100, Math.round((job.bytesSent / job.totalBytes) * 100))
}

function jobDescription(job: DicomUploadJob): string {
  switch (job.phase) {
    case 'uploading':
    case 'finalizing':
    case 'aborting':
      return `${job.filesProcessed} of ${job.totalFiles} files · ${formatBytes(job.bytesSent)} / ${formatBytes(job.totalBytes)}${job.rootName ? ` · ${job.rootName}` : ''}`
    case 'done':
      return `Stored ${job.summary.stored} · Skipped ${job.summary.skipped}${job.summary.errored > 0 ? ` · Errored ${job.summary.errored}` : ''}`
    case 'duplicate':
      return `No new images were added. Stored ${job.summary.stored} · Skipped ${job.summary.skipped}`
    case 'cancelled':
      return `The upload session was cancelled and stored files were discarded. Processed ${job.filesProcessed} of ${job.totalFiles} files.`
    case 'failed':
      return `The upload session was stopped and stored files were discarded. Processed ${job.filesProcessed} of ${job.totalFiles} files.`
  }
}

function JobIcon({ phase, errored }: { phase: UploadPhase, errored: boolean }): ReactElement {
  if (isActiveUploadPhase(phase)) {
    return <Loader2 className="size-4 shrink-0 animate-spin text-primary" />
  }
  if (phase === 'failed' || errored) {
    return <AlertCircle className="size-4 shrink-0 text-destructive" />
  }
  if (phase === 'cancelled') {
    return <X className="size-4 shrink-0 text-muted-foreground" />
  }
  return <CheckCircle2 className="size-4 shrink-0 text-primary" />
}

/**
 * Collapsed, always-visible view of every upload job. Mounted once by the Miller shell so
 * it outlives column navigation; clicking an entry expands that job's detail modal.
 */
export function DicomUploadTray(): ReactElement | null {
  const { jobs, openJobId, openJob, dismissJob } = useDicomUploads()
  const activeJob = jobs.find((job) => job.id === openJobId) ?? null

  if (jobs.length === 0) {
    return null
  }

  return (
    <>
      <div
        role="region"
        aria-label="DICOM uploads"
        className="pointer-events-none fixed right-4 bottom-4 z-50 flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-2"
      >
        {jobs.map((job) => (
          <div
            key={job.id}
            className="pointer-events-auto rounded-lg border border-border bg-card p-3 shadow-lg"
          >
            <div className="flex items-center gap-2">
              <JobIcon phase={job.phase} errored={job.summary.errored > 0} />
              <button
                type="button"
                className="min-w-0 flex-1 cursor-pointer text-left"
                onClick={() => openJob(job.id)}
              >
                <span className="block truncate text-sm font-medium text-card-foreground">{jobTitle(job)}</span>
                <span className="block truncate text-xs text-muted-foreground">{jobProgressLabel(job)}</span>
              </button>
              {!isActiveUploadPhase(job.phase) && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  aria-label={`Dismiss ${jobTitle(job)}`}
                  onClick={() => dismissJob(job.id)}
                >
                  <X className="size-4" />
                </Button>
              )}
            </div>
            {isActiveUploadPhase(job.phase) && <Progress className="mt-2" value={progressPercent(job)} />}
          </div>
        ))}
      </div>

      <DicomUploadJobDialog job={activeJob} />
    </>
  )
}

function DicomUploadJobDialog({ job }: { job: DicomUploadJob | null }): ReactElement {
  const { openJob, cancelUpload, dismissJob } = useDicomUploads()

  return (
    <Dialog
      open={job !== null}
      onOpenChange={(open) => {
        if (!open) {
          openJob(null)
        }
      }}
    >
      <DialogContent>
        {job && (
          <>
            <DialogHeader>
              <DialogTitle>{jobTitle(job)}</DialogTitle>
              <DialogDescription>{jobDescription(job)}</DialogDescription>
            </DialogHeader>

            {isActiveUploadPhase(job.phase) && (
              <div className="flex min-w-0 flex-col gap-2">
                <Progress value={progressPercent(job)} />
                <p className="min-w-0 truncate text-xs text-muted-foreground">
                  <Loader2 className="mr-1 inline size-3 animate-spin" />
                  {job.phase === 'aborting' && 'Stopping in-flight uploads…'}
                  {job.phase === 'finalizing' && 'Grouping images into studies…'}
                  {job.phase === 'uploading' && (job.currentFileName || 'Preparing…')}
                </p>
              </div>
            )}

            {job.phase === 'failed' && job.error && (
              <p className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm break-words text-destructive">
                {job.error}
              </p>
            )}

            {!isActiveUploadPhase(job.phase) && job.summary.failures.length > 0 && (
              <div className="max-h-40 overflow-y-auto rounded-md border border-border bg-muted/30 p-2 text-xs">
                <p className="mb-1 flex items-center gap-1 font-medium text-foreground">
                  <AlertCircle className="size-3" />
                  Failed files
                </p>
                <ul className="space-y-0.5 text-muted-foreground">
                  {job.summary.failures.slice(0, 50).map((failure) => (
                    <li key={`${failure.path}:${failure.reason}`} className="break-words">
                      {failure.path} — {failure.reason}
                    </li>
                  ))}
                  {job.summary.failures.length > 50 && (
                    <li className="italic">…and {job.summary.failures.length - 50} more</li>
                  )}
                </ul>
              </div>
            )}

            {job.phase === 'done' && job.summary.errored === 0 && job.summary.stored > 0 && (
              <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <CheckCircle2 className="size-4 text-primary" />
                Studies are now visible in the Imaging tab.
              </p>
            )}

            {job.phase === 'duplicate' && (
              <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <CheckCircle2 className="size-4 text-muted-foreground" />
                This study is already available in the imaging library.
              </p>
            )}

            {isActiveUploadPhase(job.phase) && (
              <p className="flex items-center gap-2 text-xs text-muted-foreground">
                <UploadCloud className="size-3" />
                You can keep using the rest of the app — this upload continues in the background.
              </p>
            )}

            <DialogFooter>
              {job.phase === 'uploading' && (
                <>
                  <Button type="button" variant="ghost" onClick={() => openJob(null)}>
                    Run in background
                  </Button>
                  <Button type="button" variant="outline" onClick={() => cancelUpload(job.id)}>
                    <X className="size-4" />
                    Cancel upload
                  </Button>
                </>
              )}
              {job.phase === 'finalizing' && (
                <>
                  <Button type="button" variant="ghost" onClick={() => openJob(null)}>
                    Run in background
                  </Button>
                  <Button type="button" variant="outline" disabled>
                    <Loader2 className="size-4 animate-spin" />
                    Finalizing…
                  </Button>
                </>
              )}
              {job.phase === 'aborting' && (
                <Button type="button" variant="outline" disabled>
                  <Loader2 className="size-4 animate-spin" />
                  Cancelling…
                </Button>
              )}
              {!isActiveUploadPhase(job.phase) && (
                <Button type="button" onClick={() => dismissJob(job.id)}>
                  {job.phase === 'done' || job.phase === 'duplicate' ? 'Done' : 'Close'}
                </Button>
              )}
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
