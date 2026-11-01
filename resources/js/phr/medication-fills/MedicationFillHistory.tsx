import { useCallback, useEffect, useState } from 'react'

import { fetchWrapper } from '@/fetchWrapper'
import { errorMessage } from '@/phr/shared'

import { FillForm } from './FillEntryForm'
import { FillsTable } from './FillsTable'
import { type MedicationFill, MedicationFillsResponseSchema } from './fillTypes'

/** A medication's fills, newest first, on the medication detail page. Read-only. */
export function MedicationFillHistory({ patientId, medicationId }: { patientId: number, medicationId: number | string }) {
  const [fills, setFills] = useState<MedicationFill[]>([])
  const [canManage, setCanManage] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<MedicationFill | null>(null)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    let cancelled = false
    fetchWrapper.get(`/api/phr/patients/${patientId}/medications/${medicationId}/fills`).then((raw: unknown) => {
      if (cancelled) return
      const parsed = MedicationFillsResponseSchema.parse(raw)
      setFills(parsed.medication_fills)
      setCanManage(parsed.can_manage)
      setEditing((current) => (current ? parsed.medication_fills.find((fill) => fill.id === current.id) ?? null : null))
    }).catch((caught: unknown) => {
      if (!cancelled) setError(errorMessage(caught))
    }).finally(() => {
      if (!cancelled) setLoading(false)
    })
    return () => {
      cancelled = true
    }
  }, [patientId, medicationId, reloadKey])

  const reload = useCallback(() => setReloadKey((key) => key + 1), [])

  return (
    <section className="rounded-lg border border-border bg-card">
      <div className="border-b border-border px-4 py-3">
        <h2 className="font-semibold text-card-foreground">Fill history</h2>
        <p className="text-sm text-muted-foreground">Dispensings of this medication, newest first.</p>
      </div>
      {error && <div className="px-4 py-3 text-sm text-destructive">{error}</div>}
      {editing && (
        <div className="p-4">
          <FillForm
            key={`${editing.id}:${editing.version ?? ''}:${reloadKey}`}
            patientId={patientId}
            fill={editing}
            onSaved={() => { setEditing(null); reload() }}
            onCancel={() => setEditing(null)}
            onReload={reload}
          />
        </div>
      )}
      <FillsTable
        onEdit={canManage ? setEditing : undefined}
        label="Fill history"
        rows={fills}
        loading={loading}
        empty={<div className="px-4 py-8 text-sm text-muted-foreground">No fills recorded for this medication.</div>}
      />
    </section>
  )
}
