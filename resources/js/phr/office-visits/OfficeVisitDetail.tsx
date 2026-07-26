import type { ReactElement } from 'react'
import { useEffect, useState } from 'react'

import { PhrNotFoundColumn } from '@/phr/miller'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { type PhrOfficeVisit, PhrOfficeVisitResponseSchema } from '@/phr/types'

interface OfficeVisitDetailProps {
  patientId: number
  recordId: string
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

export default function OfficeVisitDetail({ patientId, recordId }: OfficeVisitDetailProps) {
  const [visit, setVisit] = useState<PhrOfficeVisit | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    async function load(): Promise<void> {
      setBusy(true)
      setError(null)
      setNotFound(false)

      try {
        const result = await fetchPhrDetail(
          `/api/phr/patients/${patientId}/office-visits/${recordId}`,
          PhrOfficeVisitResponseSchema,
        )

        if (cancelled) return

        setVisit(result.data?.office_visit ?? null)
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
  }, [patientId, recordId])

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
        <section className="rounded-lg border border-border bg-card p-4">
          <h2 className="text-lg font-semibold text-card-foreground">{detailValue(visit.visit_type, 'Office Visit')}</h2>
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
      )}
    </div>
  )
}
