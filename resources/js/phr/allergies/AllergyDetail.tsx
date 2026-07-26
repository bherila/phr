import { useEffect, useState } from 'react'

import { PhrNotFoundColumn } from '@/phr/miller'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { type PhrAllergy, PhrAllergyResponseSchema } from '@/phr/types'

interface AllergyDetailProps {
  patientId: number
  recordId: string
}

function detailValue(value: string | null | undefined, fallback = 'Not recorded'): string {
  return value && value.trim().length > 0 ? value : fallback
}

export default function AllergyDetail({ patientId, recordId }: AllergyDetailProps) {
  const [allergy, setAllergy] = useState<PhrAllergy | null>(null)
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
          `/api/phr/patients/${patientId}/allergies/${recordId}`,
          PhrAllergyResponseSchema,
        )

        if (cancelled) return

        setAllergy(result.data?.allergy ?? null)
        setNotFound(result.notFound)
      } catch (caught) {
        if (!cancelled) {
          setError(errorMessage(caught))
          setAllergy(null)
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
      {allergy && (
        <section className="rounded-lg border border-border bg-card p-4">
          <h2 className="text-lg font-semibold text-card-foreground">{allergy.substance}</h2>
          <p className="mt-1 text-sm text-muted-foreground">Allergy #{allergy.id}</p>
          <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Reaction</dt>
              <dd className="text-card-foreground">{detailValue(allergy.reaction)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Severity</dt>
              <dd className="text-card-foreground">{detailValue(allergy.severity)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Criticality</dt>
              <dd className="text-card-foreground">{detailValue(allergy.criticality)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Category</dt>
              <dd className="text-card-foreground">{detailValue(allergy.category)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Clinical status</dt>
              <dd className="text-card-foreground">{detailValue(allergy.clinical_status)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Verification</dt>
              <dd className="text-card-foreground">{detailValue(allergy.verification_status)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">RxNorm</dt>
              <dd className="text-card-foreground">{detailValue(allergy.rxnorm_code)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">SNOMED</dt>
              <dd className="text-card-foreground">{detailValue(allergy.snomed_code)}</dd>
            </div>
          </dl>
          <div className="mt-4 border-t border-border pt-4">
            <h3 className="text-sm font-medium text-card-foreground">Notes</h3>
            <p className="mt-1 text-sm text-muted-foreground">{detailValue(allergy.notes, 'No notes recorded.')}</p>
          </div>
        </section>
      )}
    </div>
  )
}
