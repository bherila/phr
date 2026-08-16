import { Film, ReceiptText } from 'lucide-react'
import type { ReactElement } from 'react'
import { useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import type { MillerDrillTarget } from '@/components/ui/miller'
import EncounterEobLinks from '@/phr/clinical/EncounterEobLinks'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { PhrNotFoundColumn } from '@/phr/miller'
import type { PhrModuleId } from '@/phr/miller/phrModuleRegistry'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { type PhrOfficeVisit, type PhrOfficeVisitRelatedService, PhrOfficeVisitResponseSchema } from '@/phr/types'

interface OfficeVisitDetailProps {
  patientId: number
  recordId: string
  onDrill?: (target: MillerDrillTarget<PhrModuleId>) => void
}

function detailValue(value: string | null | undefined, fallback = 'Not recorded'): string {
  return value && value.trim().length > 0 ? value : fallback
}

function renderCodeList(label: string, codes: Array<Record<string, string>> | null): ReactElement {
  return (
    <div>
      <h3 className="font-medium text-card-foreground">{label}</h3>
      {codes && codes.length > 0 ? (
        <ul className="mt-1 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
          {codes.map((code) => (
            <li key={`${label}-${Object.entries(code).flat().join(':')}`}>{Object.values(code).join(' · ')}</li>
          ))}
        </ul>
      ) : (
        <p className="mt-1 text-sm text-muted-foreground">None recorded.</p>
      )}
    </div>
  )
}

function serviceLabel(service: PhrOfficeVisitRelatedService): string {
  return service.description ?? `${service.code_type} ${service.procedure_code}`
}

function serviceDateLabel(service: PhrOfficeVisitRelatedService): string | null {
  if (!service.service_start) return null
  return service.service_end && service.service_end !== service.service_start
    ? `${service.service_start}–${service.service_end}`
    : service.service_start
}

export default function OfficeVisitDetail({ patientId, recordId, onDrill }: OfficeVisitDetailProps) {
  const [visit, setVisit] = useState<PhrOfficeVisit | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [canManage, setCanManage] = useState(false)
  const [refreshNonce, setRefreshNonce] = useState(0)

  useEffect(() => {
    let cancelled = false

    async function load(): Promise<void> {
      setBusy(true)
      setError(null)
      setNotFound(false)
      setVisit(null)
      setCanManage(false)

      try {
        const result = await fetchPhrDetail(
          `/api/phr/patients/${patientId}/office-visits/${recordId}`,
          PhrOfficeVisitResponseSchema,
        )

        if (cancelled) return

        setVisit(result.data?.office_visit ?? null)
        setCanManage(result.data?.can_manage ?? false)
        setNotFound(result.notFound)
      } catch (caught) {
        if (!cancelled) {
          setError(errorMessage(caught))
          setVisit(null)
        }
      } finally {
        if (!cancelled) setBusy(false)
      }
    }

    void load()

    return () => {
      cancelled = true
    }
  }, [patientId, recordId, refreshNonce])

  if (notFound) {
    return <PhrNotFoundColumn />
  }

  return (
    <div className="space-y-4">
      {error && (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}
      {busy && <p className="text-sm text-muted-foreground">Loading...</p>}
      {visit && (
        <>
          <section className="rounded-lg border border-border bg-card p-4">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="text-lg font-semibold text-card-foreground">{detailValue(visit.visit_type, 'Office Visit')}</h2>
              {reviewStatusBadge(visit.review_status)}
            </div>
            <p className="mt-1 text-sm text-muted-foreground">Visit #{visit.id}</p>
            <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Visit date</dt>
                <dd className="text-card-foreground">{detailValue(visit.visit_date)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Start time</dt>
                <dd className="text-card-foreground">{detailValue(visit.visit_started_at)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">End time</dt>
                <dd className="text-card-foreground">{detailValue(visit.visit_ended_at)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Provider</dt>
                <dd className="text-card-foreground">{detailValue(visit.provider_name)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Specialty</dt>
                <dd className="text-card-foreground">{detailValue(visit.provider_specialty)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Facility</dt>
                <dd className="text-card-foreground">{detailValue(visit.facility_name)}</dd>
              </div>
            </dl>
            <div className="mt-4 grid gap-3 border-t border-border pt-4 text-sm">
              <div>
                <h3 className="font-medium text-card-foreground">Chief complaint</h3>
                <p className="mt-1 text-muted-foreground">{detailValue(visit.chief_complaint, 'No complaint recorded.')}</p>
              </div>
              <div>
                <h3 className="font-medium text-card-foreground">Subjective</h3>
                <p className="mt-1 text-muted-foreground">{detailValue(visit.subjective, 'Not recorded.')}</p>
              </div>
              <div>
                <h3 className="font-medium text-card-foreground">Objective</h3>
                <p className="mt-1 text-muted-foreground">{detailValue(visit.objective, 'Not recorded.')}</p>
              </div>
              <div>
                <h3 className="font-medium text-card-foreground">Assessment</h3>
                <p className="mt-1 text-muted-foreground">{detailValue(visit.assessment, 'Not recorded.')}</p>
              </div>
              <div>
                <h3 className="font-medium text-card-foreground">Plan</h3>
                <p className="mt-1 text-muted-foreground">{detailValue(visit.plan, 'Not recorded.')}</p>
              </div>
              {renderCodeList('ICD-10 codes', visit.icd10_codes)}
              {renderCodeList('CPT codes', visit.cpt_codes)}
            </div>
          </section>
          <EncounterEobLinks
            key={`${patientId}-${recordId}`}
            patientId={patientId}
            recordType="office-visits"
            recordId={recordId}
            serviceDate={visit.visit_date}
            eobs={visit.eobs}
            canManage={canManage}
            onChange={(eobs) => {
              setVisit((current) => current ? { ...current, eobs } : current)
              setRefreshNonce((current) => current + 1)
            }}
          />
          <section className="rounded-lg border border-border bg-card p-4">
            <div>
              <h2 className="flex items-center gap-2 font-semibold text-card-foreground">
                <ReceiptText className="size-4 text-primary" />
                Related services
              </h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Procedures and services reported on the EOBs linked to this visit.
              </p>
            </div>
            {visit.related_services.length === 0 ? (
              <p className="mt-4 text-sm text-muted-foreground">No related services are available.</p>
            ) : (
              <ul className="mt-4 divide-y divide-border rounded-md border border-border">
                {visit.related_services.map((service) => {
                  const date = serviceDateLabel(service)

                  return (
                    <li key={service.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                      <div className="min-w-0">
                        <p className="text-sm font-medium text-card-foreground">{serviceLabel(service)}</p>
                        <p className="text-xs text-muted-foreground">{service.code_type} {service.procedure_code}</p>
                      </div>
                      {date && <span className="text-xs text-muted-foreground">{date}</span>}
                    </li>
                  )
                })}
              </ul>
            )}
          </section>
          <section className="rounded-lg border border-border bg-card p-4">
            <div>
              <h2 className="flex items-center gap-2 font-semibold text-card-foreground">
                <Film className="size-4 text-primary" />
                Related imaging studies
              </h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Imaging explicitly associated with this visit.
              </p>
            </div>
            {visit.imaging_studies.length === 0 ? (
              <p className="mt-4 text-sm text-muted-foreground">No imaging studies are linked to this visit.</p>
            ) : (
              <ul className="mt-4 space-y-2">
                {visit.imaging_studies.map((study) => (
                  <li key={study.id}>
                    <Button
                      type="button"
                      variant="outline"
                      className="h-auto w-full justify-start px-3 py-2 text-left"
                      onClick={() => onDrill?.({ id: 'imaging-study-detail', instance: String(study.id) })}
                    >
                      <Film className="size-4 shrink-0 text-primary" />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium">
                          {study.description ?? 'Imaging study'}
                        </span>
                        <span className="block text-xs text-muted-foreground">
                          {[study.study_date, study.modalities, study.accession_number ? `Accession ${study.accession_number}` : null]
                            .filter(Boolean)
                            .join(' · ') || 'Study details available'}
                        </span>
                      </span>
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}
    </div>
  )
}
