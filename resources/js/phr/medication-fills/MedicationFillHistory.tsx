import { useEffect, useState } from 'react'

import { fetchWrapper } from '@/fetchWrapper'
import { errorMessage } from '@/phr/shared'

import { FillsTable } from './FillsTable'
import { type MedicationFill, MedicationFillsResponseSchema } from './fillTypes'

/** A medication's fills, newest first, on the medication detail page. Read-only. */
export function MedicationFillHistory({ patientId, medicationId }: { patientId: number, medicationId: number | string }) {
  const [fills, setFills] = useState<MedicationFill[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)
    fetchWrapper.get(`/api/phr/patients/${patientId}/medications/${medicationId}/fills`).then((raw: unknown) => {
      if (!cancelled) setFills(MedicationFillsResponseSchema.parse(raw).medication_fills)
    }).catch((caught: unknown) => {
      if (!cancelled) setError(errorMessage(caught))
    }).finally(() => {
      if (!cancelled) setLoading(false)
    })
    return () => {
      cancelled = true
    }
  }, [patientId, medicationId])

  return (
    <section className="rounded-lg border border-border bg-card">
      <div className="border-b border-border px-4 py-3">
        <h2 className="font-semibold text-card-foreground">Fill history</h2>
        <p className="text-sm text-muted-foreground">Dispensings of this medication, newest first.</p>
      </div>
      {error && <div className="px-4 py-3 text-sm text-destructive">{error}</div>}
      <FillsTable
        label="Fill history"
        rows={fills}
        loading={loading}
        empty={<div className="px-4 py-8 text-sm text-muted-foreground">No fills recorded for this medication.</div>}
      />
    </section>
  )
}
