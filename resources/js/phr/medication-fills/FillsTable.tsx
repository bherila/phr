import type { ReactNode } from 'react'

import { RECORD_ONLY_BELOW_MEDIUM, RECORD_ONLY_WIDE_INLINE, type RecordColumn, RecordTable } from '@/components/record-list/RecordTable'
import { RowActionsMenu } from '@/components/record-list/RowActionsMenu'
import { Button } from '@/components/ui/button'
import type { FillColumn } from '@/lib/displayPreferences'
import { cn } from '@/lib/utils'
import { type PhrReviewDecision, ReviewActions } from '@/phr/clinical/review'
import { reviewStatusBadge, reviewStatusNeedsAttention } from '@/phr/clinical/ui'
import { drillOpener, type PhrListPageProps } from '@/phr/shell/phrPages'

import { FillFlags, fillRouteAndForm, fillStrength } from './FillFlags'
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
  /** Adds Edit to a row's menu. */
  onEdit?: ((fill: MedicationFill) => void) | undefined
  onDrill?: PhrListPageProps['onDrill']
  openRowId?: string | null | undefined
  /** Columns this person has chosen not to see. */
  hiddenColumns?: readonly FillColumn[] | undefined
}

const HEAD = 'px-3 py-2.5 font-medium'
const CELL = 'px-3 py-2.5'

/** A review status where the Review column has no room: the dot alone, named for a screen reader. */
function ReviewDot({ fill }: { fill: MedicationFill }) {
  if (!reviewStatusNeedsAttention(fill.review_status)) return null
  const pending = fill.review_status === 'pending_review'
  return <span role="img" aria-label={pending ? 'Pending review' : 'Rejected'} className={cn('block size-2 rounded-full', pending ? 'bg-warning' : 'bg-muted-foreground')} />
}

function pharmacyName(fill: MedicationFill): string | null {
  return fill.display?.pharmacy_name ?? fill.pharmacy_name
}

/**
 * A patient's fills. Facts about the drug itself (manufacturer, package) repeat on every fill of
 * it, so they live in the fill's detail; the row keeps what differs from one fill to the next.
 * Below 960px the prescriber, the no-insurance price and the row's actions move to the detail, and below 560px each row
 * is two lines that open the record's own page.
 */
export function FillsTable({ label, rows, loading = false, empty, actions, onEdit, onDrill, openRowId, hiddenColumns = [] }: FillsTableProps) {
  const shows = (column: FillColumn): boolean => !hiddenColumns.includes(column)
  const columns: RecordColumn<MedicationFill>[] = [
    {
      id: 'date',
      header: 'Date',
      fit: true,
      showFrom: 'medium',
      headerClassName: HEAD,
      className: cn(CELL, 'tabular-nums'),
      cell: (fill) => formatFillDate(fill.filled_on),
    },
    {
      id: 'medication',
      header: 'Medication',
      // The name column takes the room; the other wrapping columns get what is left.
      headerClassName: cn(HEAD, 'w-[38%]'),
      className: CELL,
      cell: (fill) => {
        const strength = fillStrength(fill)
        const routeAndForm = fillRouteAndForm(fill)
        return (
          <div className="flex gap-2">
            <span className={cn('w-2 shrink-0 pt-1.5', RECORD_ONLY_BELOW_MEDIUM)}><ReviewDot fill={fill} /></span>
            <div className="min-w-0">
              <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span className="font-medium text-card-foreground">{fillDrugName(fill)}</span>
                {strength && (
                  <span className="text-muted-foreground">
                    {strength}
                    {routeAndForm && <span className={RECORD_ONLY_WIDE_INLINE}> · {routeAndForm}</span>}
                  </span>
                )}
                <FillFlags fill={fill} />
              </div>
              <div className={cn('mt-0.5 text-xs text-muted-foreground', RECORD_ONLY_BELOW_MEDIUM)}>
                {[formatFillDate(fill.filled_on), `Qty ${formatQuantity(fill.quantity)}`, shows('pharmacy') ? pharmacyName(fill) : null].filter(Boolean).join(' · ')}
              </div>
            </div>
          </div>
        )
      },
    },
    {
      id: 'quantity',
      header: 'Qty',
      fit: true,
      showFrom: 'medium',
      headerClassName: HEAD,
      numeric: true,
      className: CELL,
      cell: (fill) => formatQuantity(fill.quantity),
    },
    {
      id: 'paid',
      header: 'Paid',
      fit: true,
      numeric: true,
      headerClassName: HEAD,
      className: CELL,
      cell: (fill) => formatCents(fill.paid_cents),
    },
    ...(shows('no_insurance') ? [{
      id: 'no-insurance',
      // Two short words over short prices: the header may wrap, and the room goes to the name.
      header: <span className="inline-block w-16 whitespace-normal">No insurance</span>,
      fit: true,
      numeric: true,
      showFrom: 'wide',
      headerClassName: HEAD,
      className: cn(CELL, 'text-muted-foreground'),
      cell: (fill) => formatCents(fill.retail_cents),
    } satisfies RecordColumn<MedicationFill>] : []),
    ...(shows('prescriber') ? [{
      id: 'prescriber',
      header: 'Prescriber',
      showFrom: 'wide',
      headerClassName: HEAD,
      className: cn(CELL, 'text-muted-foreground'),
      cell: (fill) => <span className="inline-block min-w-24">{(fill.display?.prescriber_name ?? fill.prescriber_name) ?? MISSING}</span>,
    } satisfies RecordColumn<MedicationFill>] : []),
    ...(shows('pharmacy') ? [{
      id: 'pharmacy',
      header: 'Pharmacy',
      showFrom: 'medium',
      headerClassName: cn(HEAD, 'w-[20%]'),
      className: cn(CELL, 'text-muted-foreground'),
      cell: (fill) => pharmacyName(fill) ?? MISSING,
    } satisfies RecordColumn<MedicationFill>] : []),
    {
      id: 'review',
      header: 'Review',
      fit: true,
      showFrom: 'medium',
      showWhenAnyRow: (fill) => reviewStatusNeedsAttention(fill.review_status),
      headerClassName: HEAD,
      className: CELL,
      cell: (fill) => reviewStatusBadge(fill.review_status),
    },
  ]

  if (actions || onEdit) {
    columns.push({
      id: 'actions',
      header: <span className="sr-only">Actions</span>,
      fit: true,
      showFrom: 'wide',
      headerClassName: HEAD,
      className: 'px-2 py-1.5',
      cell: (fill) => {
        const name = `${fillDrugName(fill)} fill ${formatFillDate(fill.filled_on)}`
        const busy = actions ? actions.isMutating(`review:${fill.id}`) || actions.isMutating(`delete:${fill.id}`) : false
        return (
          <div className="flex justify-end gap-1">
            {actions && (
              <ReviewActions
                status={fill.review_status}
                label={name}
                busy={actions.isMutating(`review:${fill.id}`)}
                disabled={busy}
                onReview={(decision) => actions.onReview(fill.id, decision)}
              />
            )}
            <RowActionsMenu
              label={name}
              disabled={busy}
              actions={[
                ...(onEdit ? [{ label: 'Edit', onSelect: () => onEdit(fill) }] : []),
                ...(actions ? [{ label: 'Delete', destructive: true, onSelect: () => actions.onStartDelete(fill.id) }] : []),
              ]}
            />
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
      openRowId={openRowId}
      onOpen={drillOpener(onDrill, (fill: MedicationFill) => ({ id: 'medication-fill-detail', instance: String(fill.id) }))}
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
