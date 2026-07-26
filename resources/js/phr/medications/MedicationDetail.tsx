import { useEffect, useState } from 'react'

import { PhrNotFoundColumn } from '@/phr/miller'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { type PhrMedication, PhrMedicationResponseSchema } from '@/phr/types'

interface MedicationDetailProps {
  patientId: number
  recordId: string
}

function detailValue(value: string | null | undefined, fallback = 'Not recorded'): string {
  return value && value.trim().length > 0 ? value : fallback
}

export default function MedicationDetail({ patientId, recordId }: MedicationDetailProps) {
  const [medication, setMedication] = useState<PhrMedication | null>(null)
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
          `/api/phr/patients/${patientId}/medications/${recordId}`,
          PhrMedicationResponseSchema,
        )

        if (cancelled) return

        setMedication(result.data?.medication ?? null)
        setNotFound(result.notFound)
      } catch (caught) {
        if (!cancelled) {
          setError(errorMessage(caught))
          setMedication(null)
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
      {medication && (
        <section className="rounded-lg border border-border bg-card p-4">
          <h2 className="text-lg font-semibold text-card-foreground">{medication.name}</h2>
          <p className="mt-1 text-sm text-muted-foreground">Medication #{medication.id}</p>
          <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Dose</dt>
              <dd className="text-card-foreground">{detailValue([medication.dose, medication.dose_unit].filter(Boolean).join(' '))}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Route</dt>
              <dd className="text-card-foreground">{detailValue(medication.route)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Schedule</dt>
              <dd className="text-card-foreground">{detailValue(medication.frequency)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Status</dt>
              <dd className="text-card-foreground">{detailValue(medication.status)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Started on</dt>
              <dd className="text-card-foreground">{detailValue(medication.started_on)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Ended on</dt>
              <dd className="text-card-foreground">{detailValue(medication.ended_on)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Prescriber</dt>
              <dd className="text-card-foreground">{detailValue(medication.prescriber_name)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">RxNorm</dt>
              <dd className="text-card-foreground">{detailValue(medication.rxnorm_code)}</dd>
            </div>
          </dl>
          <div className="mt-4 border-t border-border pt-4">
            <h3 className="text-sm font-medium text-card-foreground">Reason for use</h3>
            <p className="mt-1 text-sm text-muted-foreground">{detailValue(medication.reason_for_use, 'No reason documented.')}</p>
          </div>
        </section>
      )}
    </div>
  )
}
