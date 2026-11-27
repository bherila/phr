import { Box, Download, ExternalLink, Film, Loader2 } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import { formatDate } from '@/lib/dateFormat'
import { formatBytes } from '@/lib/utils'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { PhrRecordNotFound } from '@/phr/shell/PhrRecordNotFound'
import {
  type PhrDicomStudy,
  PhrDicomStudyResponseSchema,
  PhrDicomViewerResponseSchema,
  type PhrDicomViewerSeries,
} from '@/phr/types'

interface ImagingStudyDetailProps {
  patientId: number
  recordId: string
}

/**
 * Client-side heuristic only — the volume-manifest endpoint (loaded by the
 * standalone 3D page) is the authoritative eligibility gate.
 */
function mayBeVolumetric(series: PhrDicomViewerSeries): boolean {
  return (series.Modality === 'CT' || series.Modality === 'MR') && series.instances.length >= 20
}

function openInOhifViewer(patientId: number, studyId: string): void {
  const manifestUrl = `/api/phr/patients/${patientId}/dicom/studies/${studyId}/viewer-json`
  const viewerUrl = `/ohif/viewer/dicomjson?url=${encodeURIComponent(manifestUrl)}`
  window.open(viewerUrl, '_blank', 'noopener,noreferrer')
}

function openExplore3d(patientId: number, seriesId: number): void {
  window.open(`/phr/patient/${patientId}/imaging/series/${seriesId}/explore-3d`, '_blank', 'noopener,noreferrer')
}

export default function ImagingStudyDetail(props: ImagingStudyDetailProps) {
  return <ImagingStudyRecord key={`${props.patientId}:${props.recordId}`} {...props} />
}

function ImagingStudyRecord({ patientId, recordId }: ImagingStudyDetailProps) {
  const [study, setStudy] = useState<PhrDicomStudy | null>(null)
  const [series, setSeries] = useState<PhrDicomViewerSeries[]>([])
  const [busy, setBusy] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [viewerError, setViewerError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setBusy(true)
    setNotFound(false)
    setError(null)
    setViewerError(null)
    try {
      const [studyResult, viewerResult] = await Promise.all([
        fetchPhrDetail(
          `/api/phr/patients/${patientId}/dicom/studies/${recordId}`,
          PhrDicomStudyResponseSchema,
        ),
        fetchPhrDetail(
          `/api/phr/patients/${patientId}/dicom/studies/${recordId}/viewer-json`,
          PhrDicomViewerResponseSchema,
        ).catch((caught: unknown) => {
          setViewerError(errorMessage(caught))
          return { data: null, notFound: false }
        }),
      ])
      if (studyResult.notFound || !studyResult.data) {
        setNotFound(true)
        return
      }
      setStudy(studyResult.data.study)
      setSeries(viewerResult.data?.studies[0]?.series ?? [])
    } catch (caught: unknown) {
      setError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }, [patientId, recordId])

  useEffect(() => {
    void load()
  }, [load])

  if (busy) {
    return (
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 className="size-4 animate-spin" />
        Loading…
      </div>
    )
  }

  if (notFound) {
    return <PhrRecordNotFound />
  }

  if (error) {
    return (
      <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
        {error}
      </div>
    )
  }

  if (!study) {
    return null
  }

  const metaRows: { label: string; value: string | null | undefined }[] = [
    { label: 'Description', value: study.description },
    { label: 'Modality', value: study.modalities },
    { label: 'Study Date', value: formatDate(study.study_date) },
    { label: 'Accession #', value: study.accession_number },
    { label: 'Series', value: viewerError ? 'Unavailable' : String(series.length) },
    { label: 'Images', value: viewerError ? 'Unavailable' : String(series.reduce((sum, s) => sum + s.instances.length, 0)) },
    { label: 'Stored study', value: `${study.series_count} series · ${study.instance_count} images` },
    { label: 'Size', value: formatBytes(study.file_size_bytes) },
  ]

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="min-w-0 break-words [overflow-wrap:anywhere] text-base font-semibold text-foreground">{study.description || 'DICOM Study'}</h2>
        <div className="flex flex-wrap gap-2">
        <Button type="button" variant="outline" size="sm" onClick={() => openInOhifViewer(patientId, recordId)}>
          <ExternalLink className="size-4" />
          Open Viewer
        </Button>
        <Button asChild variant="outline" size="sm"><a href={`/api/phr/patients/${patientId}/dicom/studies/${recordId}/download`} target="_blank" rel="noopener noreferrer"><Download className="size-4" />Download ZIP</a></Button>
        </div>
      </div>

      {viewerError && <p role="status" className="text-sm text-muted-foreground">Viewer data could not be loaded: {viewerError}</p>}
      <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
        {metaRows.map(({ label, value }) =>
          value ? (
            <div key={label} className="min-w-0">
              <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
              <dd className="mt-0.5 break-words [overflow-wrap:anywhere] text-foreground">{value}</dd>
            </div>
          ) : null,
        )}
      </dl>

      {series.length > 0 && (
        <div className="space-y-2">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Series</h3>
          <div className="flex flex-col gap-2">
            {series.map((s) => (
              <div
                key={s.SeriesInstanceUID}
                className="flex items-center gap-3 rounded-md border border-border bg-card p-3"
              >
                <Film className="size-8 shrink-0 text-muted-foreground" aria-hidden="true" />
                <div className="min-w-0 flex-1">
                  <p className="break-words [overflow-wrap:anywhere] text-sm font-medium text-foreground">
                    {s.SeriesDescription || s.Modality || `Series ${s.SeriesNumber ?? ''}`}
                  </p>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {[s.Modality, `${s.instances.length} image${s.instances.length === 1 ? '' : 's'}`]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                </div>
                {mayBeVolumetric(s) && (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => openExplore3d(patientId, s.id)}
                  >
                    <Box className="size-4" />
                    Explore in 3D
                  </Button>
                )}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
