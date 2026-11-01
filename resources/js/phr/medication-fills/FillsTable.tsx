import { Pencil, Trash2 } from 'lucide-react'
import type { ReactNode } from 'react'

import { type RecordColumn, RecordTable } from '@/components/record-list/RecordTable'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { type PhrReviewDecision, ReviewActions } from '@/phr/clinical/review'
import { reviewStatusBadge } from '@/phr/clinical/ui'

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

function Missing() {
  return <span className="text-muted-foreground">{MISSING}</span>
}

export function FillsTable({ label, rows, loading = false, empty, actions, onEdit }: FillsTableProps) {
  const head = 'px-4 py-3 font-medium'
  const cellClass = 'px-4 py-3'
  /** A plain text column, a dash when missing. */
  const textColumn = (id: string, header: string, value: (fill: MedicationFill) => string | null | undefined, extra?: string): RecordColumn<MedicationFill> => ({
    id,
    header,
    headerClassName: head,
    className: cn(cellClass, extra),
    cell: (fill) => {
      const text = value(fill)
      return text ? <span>{text}</span> : <Missing />
    },
  })

  const columns: RecordColumn<MedicationFill>[] = [
    {
      id: 'date',
      header: 'Date',
      fit: true,
      headerClassName: head,
      className: cn(cellClass, 'whitespace-nowrap'),
      cell: (fill) => formatFillDate(fill.filled_on),
    },
    {
      id: 'name',
      header: 'Name',
      headerClassName: head,
      className: cellClass,
      cell: (fill) => (
        <div>
          <div className="font-medium text-card-foreground">{fillDrugName(fill)}</div>
          <div className="mt-1 flex flex-wrap gap-1.5">
            {fill.ndc_status === 'unresolved' && (
              <span className="inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">No FDA match</span>
            )}
            {fill.ndc_status === 'ambiguous' && (
              <span className="inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">Ambiguous NDC</span>
            )}
            {reviewStatusBadge(fill.review_status)}
          </div>
        </div>
      ),
    },
    textColumn('strength', 'Strength', (fill) => fill.drug?.strengths.join('; ')),
    textColumn('dosage_form', 'Dosage form', (fill) => fill.drug?.dosage_form),
    {
      id: 'quantity',
      header: 'Quantity',
      fit: true,
      headerClassName: head,
      className: cn(cellClass, 'whitespace-nowrap'),
      cell: (fill) => formatQuantity(fill.quantity, fill.quantity_unit),
    },
    {
      id: 'paid',
      header: 'Paid',
      fit: true,
      numeric: true,
      headerClassName: head,
      className: cellClass,
      cell: (fill) => formatCents(fill.paid_cents),
    },
    {
      id: 'retail',
      header: 'Retail price',
      fit: true,
      numeric: true,
      headerClassName: head,
      className: cellClass,
      cell: (fill) => formatCents(fill.retail_cents),
    },
    textColumn('prescriber', 'Prescriber', (fill) => fill.display?.prescriber_name ?? fill.prescriber_name),
    textColumn('pharmacy', 'Pharmacy', (fill) => fill.display?.pharmacy_name ?? fill.pharmacy_name),
    textColumn('route', 'Route', (fill) => fill.drug?.route, 'text-muted-foreground'),
    textColumn('manufacturer', 'Manufacturer', (fill) => fill.drug?.labeler_name, 'text-muted-foreground'),
    textColumn('package', 'Package description', (fill) => fill.drug?.package_description, 'text-muted-foreground'),
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
