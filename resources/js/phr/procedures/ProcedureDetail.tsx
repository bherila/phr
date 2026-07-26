import { useEffect, useState } from 'react'

import { PhrNotFoundColumn } from '@/phr/miller'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { type PhrProcedure, PhrProcedureResponseSchema } from '@/phr/types'

interface ProcedureDetailProps {
  patientId: number
  recordId: string
}

function detailValue(value: string | null | undefined, fallback = 'Not recorded'): string {
  return value && value.trim().length > 0 ? value : fallback
}

export default function ProcedureDetail({ patientId, recordId }: ProcedureDetailProps) {
  const [procedure, setProcedure] = useState<PhrProcedure | null>(null)
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
          `/api/phr/patients/${patientId}/procedures/${recordId}`,
          PhrProcedureResponseSchema,
        )

        if (cancelled) return

        setProcedure(result.data?.procedure ?? null)
        setNotFound(result.notFound)
      } catch (caught) {
        if (!cancelled) {
          setError(errorMessage(caught))
          setProcedure(null)
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
      {procedure && (
        <section className="rounded-lg border border-border bg-card p-4">
          <h2 className="text-lg font-semibold text-card-foreground">{procedure.name}</h2>
          <p className="mt-1 text-sm text-muted-foreground">Procedure #{procedure.id}</p>
          <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Performed at</dt>
              <dd className="text-card-foreground">{detailValue(procedure.performed_at)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Performed on</dt>
              <dd className="text-card-foreground">{detailValue(procedure.performed_on)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Provider</dt>
              <dd className="text-card-foreground">{detailValue(procedure.performer_name)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Specialty</dt>
              <dd className="text-card-foreground">{detailValue(procedure.performer_specialty)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Facility</dt>
              <dd className="text-card-foreground">{detailValue(procedure.facility_name)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Status</dt>
              <dd className="text-card-foreground">{detailValue(procedure.status)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">CPT</dt>
              <dd className="text-card-foreground">{detailValue(procedure.cpt_code)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">SNOMED</dt>
              <dd className="text-card-foreground">{detailValue(procedure.snomed_code)}</dd>
            </div>
          </dl>
          <div className="mt-4 grid gap-3 border-t border-border pt-4 text-sm">
            <div>
              <h3 className="font-medium text-card-foreground">Reason</h3>
              <p className="mt-1 text-muted-foreground">{detailValue(procedure.reason, 'No reason documented.')}</p>
            </div>
            <div>
              <h3 className="font-medium text-card-foreground">Outcome / complications</h3>
              <p className="mt-1 text-muted-foreground">{detailValue(procedure.outcome, 'No complications documented.')}</p>
            </div>
            <div>
              <h3 className="font-medium text-card-foreground">Notes</h3>
              <p className="mt-1 text-muted-foreground">{detailValue(procedure.notes, 'No notes recorded.')}</p>
            </div>
          </div>
        </section>
      )}
    </div>
  )
}
