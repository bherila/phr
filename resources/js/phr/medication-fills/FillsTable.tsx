import { Pencil, Trash2 } from 'lucide-react'
import type { ReactNode } from 'react'

import { type RecordColumn, RecordTable } from '@/components/record-list/RecordTable'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { type PhrReviewDecision, ReviewActions } from '@/phr/clinical/review'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { useLowercaseClass } from '@/phr/display/DisplayPreferences'

import { fillDrugName, formatCents, formatFillDate, formatQuantity, type MedicationFill, MISSING } from './fillTypes'

export interface FillActions {
  isMutating: (key: string) => boolean
  onReview: (fillId: number, decision: PhrReviewDecision) => void
  deletingId: number | null
  onStartDelete: (fillId: number) => void
  onCancelDelete: () => void
  onConfirmDelete: (fillId: number) => void
}

interface FillsTableProps {
  label: string
  rows: MedicationFill[]
  loading?: boolean
  empty: ReactNode
  /** Review and delete controls; omitted for a read-only list. */
  actions?: FillActions | undefined
  /** Adds an edit button per row. */
  onEdit?: ((fill: MedicationFill) => void) | undefined
}

function strengthAndForm(fill: MedicationFill): string {
  return [fill.drug?.strengths.join(' / '), fill.drug?.dosage_form].filter(Boolean).join(' · ')
}

function Missing() {
  return <span className="text-muted-foreground">{MISSING}</span>
}

export function FillsTable({ label, rows, loading = false, empty, actions, onEdit }: FillsTableProps) {
  const lc = useLowercaseClass()
  const textClass = (extra?: string) => cn(lc, extra)

  const columns: RecordColumn<MedicationFill>[] = [
    {
      id: 'date',
      header: 'Date',
      fit: true,
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3',
      cell: (fill) => formatFillDate(fill.filled_on),
    },
    {
      id: 'drug',
      header: 'Drug',
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3',
      cell: (fill) => {
        const strength = strengthAndForm(fill)
        return (
          <div className={textClass()}>
            <div className="font-medium text-card-foreground">
              {fillDrugName(fill)}
              {fill.drug?.brand_name ? ` (${fill.drug.brand_name})` : ''}
            </div>
            {strength && <div className="text-xs text-muted-foreground">{strength}</div>}
            {fill.directions && <p className="mt-1 text-xs text-muted-foreground">{fill.directions}</p>}
            <div className="mt-1 flex flex-wrap gap-1.5">
              {fill.ndc_status === 'unresolved' && (
                <span className="inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">No FDA match</span>
              )}
              {reviewStatusBadge(fill.review_status)}
            </div>
          </div>
        )
      },
    },
    {
      id: 'quantity',
      header: 'Quantity',
      fit: true,
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3',
      cell: (fill) => <span className={lc}>{formatQuantity(fill.quantity, fill.quantity_unit)}</span>,
    },
    {
      id: 'paid',
      header: 'Paid',
      fit: true,
      numeric: true,
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3',
      cell: (fill) => formatCents(fill.paid_cents),
    },
    {
      id: 'retail',
      header: 'Retail',
      fit: true,
      numeric: true,
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3',
      cell: (fill) => formatCents(fill.retail_cents),
    },
    {
      id: 'pharmacy',
      header: 'Pharmacy',
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3',
      cell: (fill) => (
        fill.pharmacy_name || fill.pharmacy_location || fill.prescriber_name ? (
          <div className={textClass()}>
            <div>{fill.pharmacy_name ?? MISSING}</div>
            {fill.pharmacy_location && <div className="text-xs text-muted-foreground">{fill.pharmacy_location}</div>}
            {fill.prescriber_name && <div className="text-xs text-muted-foreground">{fill.prescriber_name}</div>}
          </div>
        ) : <Missing />
      ),
    },
    {
      id: 'package',
      header: 'Route / manufacturer / package',
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3 text-muted-foreground',
      cell: (fill) => {
        const parts = [fill.drug?.route, fill.drug?.labeler_name, fill.drug?.package_description].filter(Boolean)
        return parts.length === 0
          ? <Missing />
          : <div className={textClass()}>{parts.map((part) => <div key={part}>{part}</div>)}</div>
      },
    },
  ]

  if (actions || onEdit) {
    columns.push({
      id: 'actions',
      header: 'Actions',
      fit: true,
      headerClassName: 'px-4 py-3 font-medium text-right',
      className: 'px-4 py-3',
      cell: (fill) => {
        const name = `${fillDrugName(fill)} fill ${formatFillDate(fill.filled_on)}`
        const busy = actions ? actions.isMutating(`review:${fill.id}`) || actions.isMutating(`delete:${fill.id}`) : false
        return (
          <div className="flex justify-end gap-2">
            {actions && <ReviewActions
              status={fill.review_status}
              label={name}
              busy={actions.isMutating(`review:${fill.id}`)}
              disabled={busy}
              onReview={(decision) => actions.onReview(fill.id, decision)}
            />}
            {onEdit && (
              <Button size="icon-sm" variant="ghost" title={`Edit ${name}`} aria-label={`Edit ${name}`} disabled={busy} onClick={() => onEdit(fill)}>
                <Pencil className="size-4" />
              </Button>
            )}
            {actions && (
              <Button
                size="icon-sm"
                variant="ghost"
                className="text-destructive hover:text-destructive"
                title={`Delete ${name}`}
                aria-label={`Delete ${name}`}
                disabled={busy}
                onClick={() => actions.onStartDelete(fill.id)}
              >
                <Trash2 className="size-4" />
              </Button>
            )}
          </div>
        )
      },
    })
  }

  return (
    <RecordTable
      label={label}
      loading={loading}
      loadingLabel="Loading fills"
      rows={rows}
      rowId={(fill) => String(fill.id)}
      columns={columns}
      empty={empty}
      tableClassName="min-w-full divide-y divide-border text-sm"
      headClassName="text-left text-muted-foreground"
      bodyClassName="divide-y divide-border"
      rowClassName="align-top hover:bg-muted/30"
      renderAfterRow={actions ? (fill, colSpan) => (
        actions.deletingId === fill.id ? (
          <tr>
            <td colSpan={colSpan} className="bg-destructive/5 px-4 py-4">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-sm text-foreground">
                  Delete the <strong>{formatFillDate(fill.filled_on)}</strong> fill of <strong>{fillDrugName(fill)}</strong>? This cannot be undone.
                </p>
                <div className="flex gap-2">
                  <Button variant="destructive" size="sm" disabled={actions.isMutating(`delete:${fill.id}`)} onClick={() => actions.onConfirmDelete(fill.id)}>
                    {actions.isMutating(`delete:${fill.id}`) ? 'Deleting…' : 'Delete'}
                  </Button>
                  <Button type="button" variant="outline" size="sm" onClick={actions.onCancelDelete}>Cancel</Button>
                </div>
              </div>
            </td>
          </tr>
        ) : null
      ) : undefined}
    />
  )
}
