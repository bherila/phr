import { useEffect, useState } from 'react'

import { fetchWrapper } from '@/fetchWrapper'
import { type PhrReviewDecision, ReviewActions } from '@/phr/clinical/review'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { PhrNotFoundColumn } from '@/phr/miller'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { type PhrImmunization, PhrImmunizationResponseSchema } from '@/phr/types'

interface ImmunizationDetailProps {
  patientId: number
  recordId: string
}

function detailValue(value: string | null | undefined, fallback = 'Not recorded'): string {
  return value && value.trim().length > 0 ? value : fallback
}

export default function ImmunizationDetail({ patientId, recordId }: ImmunizationDetailProps) {
  const [immunization, setImmunization] = useState<PhrImmunization | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [canManage, setCanManage] = useState(false)
  const [reviewBusy, setReviewBusy] = useState(false)

  useEffect(() => {
    let cancelled = false

    async function load(): Promise<void> {
      setBusy(true)
      setError(null)
      setNotFound(false)

      try {
        const result = await fetchPhrDetail(
          `/api/phr/patients/${patientId}/immunizations/${recordId}`,
          PhrImmunizationResponseSchema,
        )

        if (cancelled) return

        setImmunization(result.data?.immunization ?? null)
        setCanManage(result.data?.can_manage ?? false)
        setNotFound(result.notFound)
      } catch (caught) {
        if (!cancelled) {
          setError(errorMessage(caught))
          setImmunization(null)
          setCanManage(false)
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

  async function reviewImmunization(decision: PhrReviewDecision): Promise<void> {
    if (!immunization) return

    setReviewBusy(true)
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.patch(
        `/api/phr/patients/${patientId}/immunizations/${recordId}/review`,
        { review_status: decision },
      )
      const updated = PhrImmunizationResponseSchema.parse(raw).immunization
      setImmunization(updated)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setReviewBusy(false)
    }
  }

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
      {immunization && (
        <section className="rounded-lg border border-border bg-card p-4">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-lg font-semibold text-card-foreground">{immunization.vaccine_name}</h2>
            {reviewStatusBadge(immunization.review_status)}
            {canManage && (
              <ReviewActions
                status={immunization.review_status}
                label={immunization.vaccine_name}
                busy={reviewBusy}
                onReview={(decision) => void reviewImmunization(decision)}
              />
            )}
          </div>
          <p className="mt-1 text-sm text-muted-foreground">Immunization #{immunization.id}</p>
          <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Administered on</dt>
              <dd className="text-card-foreground">{detailValue(immunization.administered_on)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Dose</dt>
              <dd className="text-card-foreground">
                {immunization.dose_number === null
                  ? 'Not recorded'
                  : `${immunization.dose_number}${immunization.series_doses === null ? '' : ` / ${immunization.series_doses}`}`}
              </dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">CVX code</dt>
              <dd className="text-card-foreground">{detailValue(immunization.cvx_code)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Manufacturer</dt>
              <dd className="text-card-foreground">{detailValue(immunization.manufacturer)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Lot number</dt>
              <dd className="text-card-foreground">{detailValue(immunization.lot_number)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Site / route</dt>
              <dd className="text-card-foreground">{detailValue([immunization.site, immunization.route].filter(Boolean).join(' · '))}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Administered by</dt>
              <dd className="text-card-foreground">{detailValue(immunization.administered_by)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Facility</dt>
              <dd className="text-card-foreground">{detailValue(immunization.facility_name)}</dd>
            </div>
          </dl>
          <div className="mt-4 border-t border-border pt-4">
            <h3 className="text-sm font-medium text-card-foreground">Notes</h3>
            <p className="mt-1 text-sm text-muted-foreground">{detailValue(immunization.notes, 'No notes recorded.')}</p>
          </div>
        </section>
      )}
    </div>
  )
}
