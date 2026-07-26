import { AlertTriangle, Download, FlaskConical, HeartPulse, ImageIcon, Pill, RefreshCw, Stethoscope, Users } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { patientUrl } from '@/lib/phrRouteBuilder'
import { errorMessage } from '@/phr/shared'
import {
  PhrDicomStudiesResponseSchema,
  type PhrDicomStudy,
  type PhrExport,
  PhrExportResponseSchema,
  PhrExportsResponseSchema,
  type PhrLabResult,
  PhrLabResultsResponseSchema,
  type PhrPatient,
  PhrPatientResponseSchema,
  type PhrVital,
  PhrVitalsResponseSchema,
} from '@/phr/types'

interface TileProps {
  icon: React.ReactNode
  title: string
  href: string
  children: React.ReactNode
}

function Tile({ icon, title, href, children }: TileProps) {
  return (
    <a
      href={href}
      className="group flex flex-col gap-2 rounded-lg border border-border bg-card p-4 transition-colors hover:border-primary/50 hover:bg-accent/40"
    >
      <div className="flex items-center gap-2">
        <span className="text-muted-foreground group-hover:text-primary">{icon}</span>
        <h2 className="text-sm font-semibold text-card-foreground">{title}</h2>
      </div>
      <div className="text-sm text-muted-foreground">{children}</div>
    </a>
  )
}

function abnormalLabs(labs: PhrLabResult[]): PhrLabResult[] {
  return labs.filter((l) => l.abnormal_flag && l.abnormal_flag !== 'N')
}

function mostRecentDate(studies: PhrDicomStudy[]): string | null {
  const dates = studies.map((s) => s.study_date).filter(Boolean)
  if (dates.length === 0) {
    return null
  }
  return dates.sort().reverse()[0] ?? null
}

const EXPORT_FORMATS = [
  { value: 'zip', label: 'ZIP' },
  { value: 'fhir', label: 'FHIR' },
  { value: 'ccda', label: 'CCDA' },
  { value: 'pdf', label: 'PDF' },
]

export default function SummaryPage({ patientId }: { patientId: number }) {
  const [patient, setPatient] = useState<PhrPatient | null>(null)
  const [labs, setLabs] = useState<PhrLabResult[]>([])
  const [vitals, setVitals] = useState<PhrVital[]>([])
  const [studies, setStudies] = useState<PhrDicomStudy[]>([])
  const [exports, setExports] = useState<PhrExport[]>([])
  const [exportFormats, setExportFormats] = useState<string[]>(['zip'])
  const [exportBusy, setExportBusy] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      const [rawPatient, rawLabs, rawVitals, rawStudies] = await Promise.all([
        fetchWrapper.get(`/api/phr/patients/${patientId}`),
        fetchWrapper.get(`/api/phr/patients/${patientId}/lab-results`),
        fetchWrapper.get(`/api/phr/patients/${patientId}/vitals`),
        fetchWrapper.get(`/api/phr/patients/${patientId}/dicom/studies`),
      ])
      setPatient(PhrPatientResponseSchema.parse(rawPatient).patient)
      setLabs(PhrLabResultsResponseSchema.parse(rawLabs).lab_results)
      setVitals(PhrVitalsResponseSchema.parse(rawVitals).vitals)
      setStudies(PhrDicomStudiesResponseSchema.parse(rawStudies).studies)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }, [patientId])

  useEffect(() => {
    void load()
  }, [load])

  const loadExports = useCallback(async () => {
    if (!patient?.can_share) return
    try {
      const raw = await fetchWrapper.get(`/api/phr/patients/${patientId}/exports`)
      setExports(PhrExportsResponseSchema.parse(raw).exports)
    } catch {
      setExports([])
    }
  }, [patient?.can_share, patientId])

  useEffect(() => {
    void loadExports()
  }, [loadExports])

  async function generateExport(): Promise<void> {
    setExportBusy(true)
    setError(null)
    try {
      const raw = await fetchWrapper.post(`/api/phr/patients/${patientId}/exports`, { formats: exportFormats })
      const created = PhrExportResponseSchema.parse(raw).export
      setExports((current) => [created, ...current])
      window.setTimeout(() => void loadExports(), 1500)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setExportBusy(false)
    }
  }

  function toggleExportFormat(format: string): void {
    setExportFormats((current) => {
      if (format === 'zip') {
        return ['zip']
      }
      const withoutZip = current.filter((item) => item !== 'zip')
      const next = withoutZip.includes(format)
        ? withoutZip.filter((item) => item !== format)
        : [...withoutZip, format]
      return next.length === 0 ? ['zip'] : next
    })
  }

  if (busy) {
    return <p className="text-sm text-muted-foreground">Loading…</p>
  }

  if (error) {
    return (
      <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
        {error}
      </div>
    )
  }

  const allAbnormalLabs = abnormalLabs(labs)
  const recentStudyDate = mostRecentDate(studies)

  const vitalNames = Array.from(new Set(vitals.map((v) => v.vital_name).filter(Boolean)))
  const recentLabs = labs.slice(0, 5)

  const accessGrants = patient?.access_grants ?? []

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-foreground">{patient?.display_name ?? 'Summary'}</h1>
        {patient?.relationship && (
          <p className="mt-1 text-sm text-muted-foreground">{patient.relationship}</p>
        )}
      </div>

      {allAbnormalLabs.length > 0 && (
        <div className="mb-4 flex items-center gap-2 rounded-md border border-yellow-400/50 bg-yellow-50 px-3 py-2 text-sm text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300">
          <AlertTriangle className="size-4 shrink-0" />
          <span>
            {allAbnormalLabs.length} abnormal lab result{allAbnormalLabs.length === 1 ? '' : 's'} — check the{' '}
            <a href={patientUrl(patientId) + '#/labs'} className="underline">
              Labs tab
            </a>
            .
          </span>
        </div>
      )}

      {patient?.can_share && (
        <div className="mb-6 rounded-lg border border-border bg-card p-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="text-sm font-semibold text-card-foreground">Export Record</h2>
              <p className="mt-1 text-sm text-muted-foreground">Generate portable FHIR, CCDA, PDF, and ZIP files.</p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              {EXPORT_FORMATS.map((format) => (
                <label key={format.value} className="flex h-9 items-center gap-2 rounded-md border border-border px-3 text-sm">
                  <input
                    type="checkbox"
                    checked={exportFormats.includes(format.value)}
                    onChange={() => toggleExportFormat(format.value)}
                  />
                  {format.label}
                </label>
              ))}
              <Button size="sm" onClick={() => void generateExport()} disabled={exportBusy}>
                <Download className="size-4" />
                {exportBusy ? 'Generating...' : 'Generate'}
              </Button>
              <Button size="sm" variant="outline" onClick={() => void loadExports()}>
                <RefreshCw className="size-4" />
              </Button>
            </div>
          </div>
          {exports.length > 0 && (
            <div className="mt-3 divide-y divide-border rounded-md border border-border">
              {exports.slice(0, 3).map((item) => (
                <div key={item.id} className="flex flex-wrap items-center justify-between gap-3 px-3 py-2 text-sm">
                  <div className="min-w-0">
                    <span className="font-medium text-foreground">{item.filename ?? `Export ${item.id}`}</span>
                    <span className="ml-2 text-muted-foreground">{item.status}</span>
                    {item.error_message && <span className="ml-2 text-destructive">{item.error_message}</span>}
                  </div>
                  {item.download_url ? (
                    <a className="inline-flex items-center gap-1 text-primary hover:underline" href={item.download_url}>
                      <Download className="size-4" />
                      Download
                    </a>
                  ) : null}
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Tile icon={<FlaskConical className="size-4" />} title="Labs" href={patientUrl(patientId) + '#/labs'}>
          {labs.length === 0 ? (
            'No lab results recorded.'
          ) : (
            <>
              <span className="font-medium text-foreground">{labs.length}</span> result{labs.length === 1 ? '' : 's'}
              {allAbnormalLabs.length > 0 && (
                <span className="ml-2 font-medium text-yellow-700 dark:text-yellow-400">
                  ({allAbnormalLabs.length} abnormal)
                </span>
              )}
              {recentLabs[0]?.collection_datetime && (
                <p className="mt-1 text-xs">Most recent: {recentLabs[0].collection_datetime.slice(0, 10)}</p>
              )}
            </>
          )}
        </Tile>

        <Tile icon={<HeartPulse className="size-4" />} title="Vitals" href={patientUrl(patientId) + '#/vitals'}>
          {vitals.length === 0 ? (
            'No vitals recorded.'
          ) : (
            <>
              <span className="font-medium text-foreground">{vitals.length}</span> reading{vitals.length === 1 ? '' : 's'}
              {vitalNames.length > 0 && (
                <p className="mt-1 text-xs truncate">{vitalNames.slice(0, 4).join(' · ')}</p>
              )}
            </>
          )}
        </Tile>

        <Tile icon={<ImageIcon className="size-4" />} title="Imaging" href={patientUrl(patientId) + '#/imaging'}>
          {studies.length === 0 ? (
            'No imaging studies recorded.'
          ) : (
            <>
              <span className="font-medium text-foreground">{studies.length}</span> stud{studies.length === 1 ? 'y' : 'ies'}
              {recentStudyDate && (
                <p className="mt-1 text-xs">Most recent: {recentStudyDate}</p>
              )}
            </>
          )}
        </Tile>

        <Tile icon={<Stethoscope className="size-4" />} title="Conditions" href={patientUrl(patientId) + '#/conditions'}>
          Coming soon.
        </Tile>

        <Tile icon={<Pill className="size-4" />} title="Medications" href={patientUrl(patientId) + '#/medications'}>
          Coming soon.
        </Tile>

        <Tile icon={<Users className="size-4" />} title="Access" href={patientUrl(patientId) + '#/access'}>
          {patient?.can_share === false ? (
            'Shared with you.'
          ) : accessGrants.length <= 1 ? (
            'Only you have access.'
          ) : (
            <>
              Shared with{' '}
              <span className="font-medium text-foreground">{accessGrants.length - 1}</span>{' '}
              other{accessGrants.length - 1 === 1 ? '' : 's'}.
            </>
          )}
        </Tile>
      </div>
    </div>
  )
}
