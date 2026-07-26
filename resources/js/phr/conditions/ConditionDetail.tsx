import { useEffect, useState } from 'react'

import { PhrNotFoundColumn } from '@/phr/miller'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { type PhrCondition, PhrConditionResponseSchema } from '@/phr/types'

interface ConditionDetailProps {
  patientId: number
  recordId: string
}

function detailValue(value: string | null | undefined, fallback = 'Not recorded'): string {
  return value && value.trim().length > 0 ? value : fallback
}

export default function ConditionDetail({ patientId, recordId }: ConditionDetailProps) {
  const [condition, setCondition] = useState<PhrCondition | null>(null)
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
          `/api/phr/patients/${patientId}/conditions/${recordId}`,
          PhrConditionResponseSchema,
        )

        if (cancelled) {
          return
        }

        setCondition(result.data?.condition ?? null)
        setNotFound(result.notFound)
      } catch (caught) {
        if (!cancelled) {
          setError(errorMessage(caught))
          setCondition(null)
        }
      } finally {
        if (!cancelled) {
          setBusy(false)
        }
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
      {condition && (
        <section className="rounded-lg border border-border bg-card p-4">
          <h2 className="text-lg font-semibold text-card-foreground">{condition.name}</h2>
          <p className="mt-1 text-sm text-muted-foreground">Condition #{condition.id}</p>
          <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Clinical status</dt>
              <dd className="text-card-foreground">{detailValue(condition.clinical_status)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Verification</dt>
              <dd className="text-card-foreground">{detailValue(condition.verification_status)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Severity</dt>
              <dd className="text-card-foreground">{detailValue(condition.severity)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Onset date</dt>
              <dd className="text-card-foreground">{detailValue(condition.onset_date)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Abated date</dt>
              <dd className="text-card-foreground">{detailValue(condition.abated_date)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">ICD-10</dt>
              <dd className="text-card-foreground">{detailValue(condition.icd10_code)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">SNOMED</dt>
              <dd className="text-card-foreground">{detailValue(condition.snomed_code)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Last updated</dt>
              <dd className="text-card-foreground">{detailValue(condition.updated_at)}</dd>
            </div>
          </dl>
          <div className="mt-4 border-t border-border pt-4">
            <h3 className="text-sm font-medium text-card-foreground">Clinical notes</h3>
            <p className="mt-1 text-sm text-muted-foreground">{detailValue(condition.notes, 'No notes recorded.')}</p>
          </div>
        </section>
      )}
    </div>
  )
}
