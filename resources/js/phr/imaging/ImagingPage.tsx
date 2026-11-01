import { Download, ExternalLink, Images, RefreshCcw, UploadCloud } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'

import { RecordList } from '@/components/record-list/RecordList'
import { SkeletonBlock } from '@/components/record-list/Skeleton'
import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { formatDate } from '@/lib/dateFormat'
import { cn, formatBytes } from '@/lib/utils'
import { errorMessage } from '@/phr/shared'
import { drillOpener, type PhrListPageProps } from '@/phr/shell/phrPages'
import { openRecordId } from '@/phr/shell/phrRoutes'
import { PhrDicomStudiesResponseSchema, type PhrDicomStudy } from '@/phr/types'
import { useDicomUploadCompletions } from '@/phr/uploads'

export default function ImagingPage({ patientId, onDrill, openRecord }: PhrListPageProps) {
  const [canManage, setCanManage] = useState(false)
  const [studies, setStudies] = useState<PhrDicomStudy[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // DICOM import lives in the Imports tab now; this just re-reads the study list whenever a
  // background upload for this patient finishes.
  const uploadCompletions = useDicomUploadCompletions(patientId)

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
  }, [loadStudies, uploadCompletions])

  return (
    <div>
      <div className="mb-6 flex items-center justify-between gap-4">
        <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
          <Images className="size-6 text-primary" />
          Imaging
        </h1>
        <div className="flex gap-2">
          {canManage && onDrill && (
            <Button type="button" size="sm" onClick={() => onDrill({ id: 'imports' })}>
              <UploadCloud className="size-4" />
              Import DICOM
            </Button>
          )}
          <Button type="button" variant="outline" size="sm" onClick={() => void loadStudies()} disabled={busy}>
            <RefreshCcw className="size-4" />
            Refresh
          </Button>
        </div>
      </div>

      {error && (
        <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      {!busy && studies.length === 0 && (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          No imaging studies.
        </div>
      )}

      {(studies.length > 0 || (busy && studies.length === 0)) && (
        <RecordList
          label="Imaging studies"
          loading={busy && studies.length === 0}
          skeletonRows={3}
          skeletonItem={<div className="min-w-0 flex-1"><SkeletonBlock className="w-1/2" /><SkeletonBlock className="mt-1 w-2/3 text-xs" /></div>}
          className="flex flex-col gap-3"
          rows={studies}
          rowId={(study) => String(study.id)}
          openRowId={openRecordId(openRecord, 'imaging-study-detail')}
          onOpen={drillOpener(onDrill, (study: PhrDicomStudy) => ({ id: 'imaging-study-detail', instance: String(study.id) }))}
          itemClassName={cn(
            'flex flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between',
            onDrill && 'transition-colors hover:border-primary/50 hover:bg-accent/40',
          )}
          renderItem={(study) => (
            <>
              <div className="min-w-0">
                <p className="break-words font-medium text-card-foreground">{study.description || 'DICOM Study'}</p>
                <p className="mt-1 break-words text-xs text-muted-foreground">
                  {[formatDate(study.study_date), study.modalities, `${study.series_count} series`, `${study.instance_count} images`, formatBytes(study.file_size_bytes)].filter(Boolean).join(' · ')}
                </p>
              </div>
              <div className="flex shrink-0 flex-wrap gap-2">
                <Button type="button" variant="outline" size="sm" aria-label={`Open ${study.description || 'DICOM Study'} in the viewer`} onClick={() => openInOhifViewer(patientId, study.id)}>
                  <ExternalLink className="size-4" />
                  Viewer
                </Button>
                <Button type="button" variant="outline" size="sm" aria-label={`Download ${study.description || 'DICOM Study'} as ZIP`} onClick={() => downloadStudyZip(patientId, study.id)}>
                  <Download className="size-4" />
                  ZIP
                </Button>
              </div>
            </>
          )}
        />
      )}
    </div>
  )
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

function downloadStudyZip(patientId: number, studyId: number): void {
  window.open(`/api/phr/patients/${patientId}/dicom/studies/${studyId}/download`, '_blank', 'noopener,noreferrer')
}

function openInOhifViewer(patientId: number, studyId: number): void {
  const manifestUrl = `/api/phr/patients/${patientId}/dicom/studies/${studyId}/viewer-json`
  const viewerUrl = `/ohif/viewer/dicomjson?url=${encodeURIComponent(manifestUrl)}`
  window.open(viewerUrl, '_blank', 'noopener,noreferrer')
}
