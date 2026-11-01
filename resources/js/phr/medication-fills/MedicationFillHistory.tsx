import { useCallback, useEffect, useState } from 'react'

import { RecordTable } from '@/components/record-list/RecordTable'
import { fetchWrapper } from '@/fetchWrapper'
import { useRecordChanges } from '@/phr/clinical/recordChanges'
import { errorMessage } from '@/phr/shared'
import type { PhrNavTarget } from '@/phr/shell/phrRoutes'
import { patientPageUrl } from '@/phr/shell/phrRoutes'

import { fillDrugName, formatCents, formatFillDate, formatQuantity, type MedicationFill, MedicationFillsResponseSchema, MISSING } from './fillTypes'

/** How many fills the medication's detail lists; the rest are in Fills. */
const SHOWN_FILLS = 5

const HEAD = 'py-1.5 pr-3 text-xs font-medium'
const CELL = 'py-2 pr-3'

/**
 * A medication's fills on its detail, newest first. The detail is narrow and the drug is already
 * known, so this is only what differs from fill to fill; everything else is one click away on
 * the fill itself.
 */
export function MedicationFillHistory({ patientId, medicationId, onDrill }: {
  patientId: number
  medicationId: number | string
  onDrill?: ((target: PhrNavTarget) => void) | undefined
}) {
  const [fills, setFills] = useState<MedicationFill[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    let cancelled = false
    fetchWrapper.get(`/api/phr/patients/${patientId}/medications/${medicationId}/fills`).then((raw: unknown) => {
      if (cancelled) return
      setFills(MedicationFillsResponseSchema.parse(raw).medication_fills)
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
  useRecordChanges('medication-fills', patientId, 'detail', reload)
  useRecordChanges('medication-fills', patientId, 'list', reload)

  const waiting = fills.filter((fill) => fill.review_status === 'pending_review').length
  const summary = fills.length === 0
    ? null
    : [
        `${fills.length} ${fills.length === 1 ? 'fill' : 'fills'}`,
        `last ${formatFillDate(fills[0]!.filled_on)}`,
        ...(waiting > 0 ? [`${waiting} waiting for review`] : []),
      ].join(' · ')
  const open = onDrill
    ? (fill: MedicationFill) => onDrill({ id: 'medication-fill-detail', instance: String(fill.id) })
    : undefined

  return (
    <section aria-label="Fill history" className="space-y-2 border-t border-border pt-4">
      <div className="flex flex-wrap items-baseline gap-x-2">
        <h3 className="text-sm font-semibold text-card-foreground">Fill history</h3>
        {summary && <span className="text-xs text-muted-foreground">{summary}</span>}
      </div>
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <RecordTable
        label="Fill history"
        rows={fills.slice(0, SHOWN_FILLS)}
        loading={loading}
        skeletonRows={3}
        rowId={(fill) => String(fill.id)}
        onOpen={open}
        empty={<p className="text-sm text-muted-foreground">No fills recorded for this medication.</p>}
        tableClassName="min-w-full divide-y divide-border text-sm"
        headClassName="text-left text-muted-foreground"
        bodyClassName="divide-y divide-border"
        rowClassName="align-top hover:bg-muted/30"
        columns={[
          {
            id: 'date',
            header: 'Date',
            fit: true,
            headerClassName: HEAD,
            className: `${CELL} tabular-nums`,
            cell: (fill) => (
              <span className="inline-flex items-center gap-1.5">
                <a
                  href={patientPageUrl(patientId, 'medication-fills', fill.id)}
                  aria-label={`${fillDrugName(fill)} fill ${formatFillDate(fill.filled_on)}`}
                  className="text-primary underline-offset-2 hover:underline"
                >
                  {formatFillDate(fill.filled_on)}
                </a>
                {fill.review_status === 'pending_review' && <span role="img" aria-label="Pending review" className="size-2 rounded-full bg-warning" />}
              </span>
            ),
          },
          { id: 'quantity', header: 'Qty', fit: true, headerClassName: HEAD, className: CELL, cell: (fill) => formatQuantity(fill.quantity, fill.quantity_unit) },
          { id: 'paid', header: 'Paid', fit: true, numeric: true, headerClassName: HEAD, className: CELL, cell: (fill) => formatCents(fill.paid_cents) },
          { id: 'pharmacy', header: 'Pharmacy', headerClassName: 'py-1.5 text-xs font-medium', className: 'py-2 text-muted-foreground', cell: (fill) => (fill.display?.pharmacy_name ?? fill.pharmacy_name) ?? MISSING },
        ]}
      />
      {fills.length > 0 && (
        <a href={`${patientPageUrl(patientId, 'medication-fills')}?medication_id=${medicationId}`} className="inline-block text-sm text-primary underline-offset-2 hover:underline max-lg:py-2.5">
          Open in Fills
        </a>
      )}
    </section>
  )
}
