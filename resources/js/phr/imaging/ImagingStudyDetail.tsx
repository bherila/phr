import { Box, ExternalLink, Film, Loader2 } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import { PhrNotFoundColumn } from '@/phr/miller/PhrNotFoundColumn'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
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

export default function ImagingStudyDetail({ patientId, recordId }: ImagingStudyDetailProps) {
  const [study, setStudy] = useState<PhrDicomStudy | null>(null)
  const [series, setSeries] = useState<PhrDicomViewerSeries[]>([])
  const [busy, setBusy] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setBusy(true)
    setNotFound(false)
    setError(null)
    try {
      const [studyResult, viewerResult] = await Promise.all([
        fetchPhrDetail(
          `/api/phr/patients/${patientId}/dicom/studies/${recordId}`,
          PhrDicomStudyResponseSchema,
        ),
        fetchPhrDetail(
          `/api/phr/patients/${patientId}/dicom/studies/${recordId}/viewer-json`,
          PhrDicomViewerResponseSchema,
        ),
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
    return <PhrNotFoundColumn />
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
    { label: 'Study Date', value: study.study_date },
    { label: 'Accession #', value: study.accession_number },
    { label: 'Series', value: String(study.series_count) },
    { label: 'Images', value: String(study.instance_count) },
  ]

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-base font-semibold text-foreground">{study.description || 'DICOM Study'}</h2>
        <Button type="button" variant="outline" size="sm" onClick={() => openInOhifViewer(patientId, recordId)}>
          <ExternalLink className="size-4" />
          Open Viewer
        </Button>
      </div>

      <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
        {metaRows.map(({ label, value }) =>
          value ? (
            <div key={label}>
              <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
              <dd className="mt-0.5 text-foreground">{value}</dd>
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
                  <p className="truncate text-sm font-medium text-foreground">
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
