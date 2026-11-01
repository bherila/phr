import { Fragment, type ReactElement, type ReactNode } from 'react'

import { cn } from '@/lib/utils'

import { LoadingMessage, SkeletonBlock } from './Skeleton'
import { type RecordNavigationOptions, useRecordNavigation } from './useRecordNavigation'

export interface RecordColumn<Row> {
  id: string
  header: ReactNode
  cell: (row: Row) => ReactNode
  /** Cell classes. A string, so loading rows get the same padding as real ones. */
  className?: string | undefined
  headerClassName?: string | undefined
  /**
   * Size the column to its content and keep it on one line (values, units, dates, flags,
   * statuses, actions). Columns without it share the remaining width: the primary name column.
   */
  fit?: boolean | undefined
  /**
   * Right-align with tabular figures. Only for numbers: text values stay left-aligned, so a
   * function can decide per row (a lab value is a number or a word).
   */
  numeric?: boolean | ((row: Row) => boolean) | undefined
  /**
   * Show the column only when at least one row satisfies this (a Review column that only
   * matters while something waits for review). Rows that do not satisfy it get an empty cell.
   * Skeleton rows have no data, so the column is hidden while loading.
   */
  showWhenAnyRow?: ((row: Row) => boolean) | undefined
  /**
   * Show the column only when the list itself is at least this wide. The list is a size
   * container, so a detail panel narrowing it counts the same as a smaller window. Whatever a
   * dropped column held must still be reachable: in a line under the name, or in the detail.
   */
  showFrom?: RecordColumnTier | undefined
}

/** The widths a list changes shape at: medium is 560px, wide is 960px. */
export type RecordColumnTier = 'medium' | 'wide'

const COLUMN_TIER_CLASS: Record<RecordColumnTier, string> = {
  medium: 'hidden @[560px]/record-list:table-cell',
  wide: 'hidden @[960px]/record-list:table-cell',
}

/** For a cell's content that stands in for columns dropped at this width. */
export const RECORD_ONLY_BELOW_MEDIUM = '@[560px]/record-list:hidden'
export const RECORD_ONLY_BELOW_WIDE = '@[960px]/record-list:hidden'
export const RECORD_ONLY_WIDE_INLINE = 'hidden @[960px]/record-list:inline'
export const RECORD_ONLY_WIDE_BLOCK = 'hidden @[960px]/record-list:block'

/** Sizes a column to its content on one line. */
export const RECORD_FIT_CLASS = 'w-[1%] whitespace-nowrap'
/** Right-aligned tabular figures, for numeric values only. */
export const RECORD_NUMERIC_CLASS = 'text-right tabular-nums'

export interface RecordSelectionColumn<Row> {
  /** Names the checkbox: "Select {label}". */
  label: (row: Row) => string
  header?: ReactNode | undefined
  /** Classes for the checkbox column's header and cells (padding). */
  className?: string | undefined
}

export interface RecordTableProps<Row> extends RecordNavigationOptions<Row> {
  /** The table's accessible name. */
  label: string
  columns: readonly RecordColumn<Row>[]
  /**
   * The first load: the table renders skeleton rows in its real columns, with `aria-busy` and
   * a hidden live message. Pass it only while there are no rows yet; a refetch keeps the rows.
   */
  loading?: boolean | undefined
  /** How many skeleton rows to show: the expected count when known. */
  skeletonRows?: number | undefined
  /** The hidden live message while loading. Defaults to "Loading {label}". */
  loadingLabel?: string | undefined
  /** Shown instead of the table when there are no rows. */
  empty?: ReactNode | undefined
  /** Extra full-width rows under a row (an inline edit form, a delete confirmation). */
  renderAfterRow?: ((row: Row, colSpan: number) => ReactNode) | undefined
  /** A string also applies to skeleton rows; a function only to real ones. */
  rowClassName?: string | ((row: Row) => string) | undefined
  /** The scroll box around the table: wide tables scroll inside it, not the page. */
  className?: string | undefined
  tableClassName?: string | undefined
  headClassName?: string | undefined
  bodyClassName?: string | undefined
  /** Adds a checkbox column. Requires `selection`; Space on a row toggles it. */
  selectionColumn?: RecordSelectionColumn<Row> | undefined
}

export const RECORD_ROW_CLASS = 'outline-none focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring data-[open]:bg-accent/60'

function cellClass<Row>(column: RecordColumn<Row>, row: Row): string {
  const numeric = typeof column.numeric === 'function' ? column.numeric(row) : column.numeric === true
  return cn(
    'text-left',
    column.className,
    column.fit && RECORD_FIT_CLASS,
    numeric && RECORD_NUMERIC_CLASS,
    column.showFrom && COLUMN_TIER_CLASS[column.showFrom],
  )
}

function headerClass<Row>(column: RecordColumn<Row>): string {
  return cn('text-left', column.headerClassName, column.fit && RECORD_FIT_CLASS, column.numeric === true && 'text-right', column.showFrom && COLUMN_TIER_CLASS[column.showFrom])
}

export const DEFAULT_SKELETON_ROWS = 8

export function defaultLoadingLabel(label: string): string {
  return `Loading ${label.toLowerCase()}`
}

/**
 * A table of records with the shared keyboard model ({@link useRecordNavigation}). Native table
 * semantics stay; the active row carries `aria-selected` and the roving tabindex, and the row
 * whose detail is open is highlighted.
 */
export function RecordTable<Row>(props: RecordTableProps<Row>): ReactElement | null {
  const { label, columns: allColumns, loading = false, skeletonRows = DEFAULT_SKELETON_ROWS, loadingLabel, empty = null, renderAfterRow, rowClassName, className, tableClassName, headClassName, bodyClassName, selectionColumn, ...options } = props
  const navigation = useRecordNavigation(options)
  const { rows, rowId, selection, onOpen } = options
  const columns = allColumns.filter((column) => column.showWhenAnyRow === undefined || (!loading && rows.some(column.showWhenAnyRow)))
  if (!loading && rows.length === 0) return <>{empty}</>
  const selectable = selectionColumn !== undefined && selection !== undefined
  // Only lists that change shape become containers: containment stops the box taking its width
  // from the table, which a list in a shrink-to-fit parent relies on.
  const responsive = allColumns.some((column) => column.showFrom !== undefined)
  const colSpan = columns.length + (selectable ? 1 : 0)
  const rowClass = (row: Row | null): string | undefined => (
    typeof rowClassName === 'function' ? (row === null ? undefined : rowClassName(row)) : rowClassName
  )

  return (
    <div className={cn('overflow-x-auto', responsive && '@container/record-list', className)} aria-busy={loading || undefined}>
      {loading && <LoadingMessage>{loadingLabel ?? defaultLoadingLabel(label)}</LoadingMessage>}
      <table aria-label={label} className={tableClassName} {...navigation.containerProps}>
        <thead>
          <tr className={headClassName}>
            {selectable && <th scope="col" className={cn('w-10 px-3 py-2 font-medium', selectionColumn.className)}>{selectionColumn.header ?? <span className="sr-only">Selected</span>}</th>}
            {columns.map((column) => (
              <th key={column.id} className={headerClass(column)}>{column.header}</th>
            ))}
          </tr>
        </thead>
        <tbody className={bodyClassName}>
          {loading && Array.from({ length: skeletonRows }, (_, index) => (
            <tr key={index} aria-hidden="true" data-skeleton-row="" className={rowClass(null)}>
              {selectable && <td className={cn('w-10 px-3 py-2', selectionColumn.className)} />}
              {columns.map((column) => (
                <td key={column.id} className={cn('text-left', column.className, column.fit && RECORD_FIT_CLASS, column.showFrom && COLUMN_TIER_CLASS[column.showFrom])}>
                  <SkeletonBlock className={column.fit ? 'w-14' : 'w-3/4 max-w-64'} />
                </td>
              ))}
            </tr>
          ))}
          {!loading && rows.map((row) => {
            const id = rowId(row)
            const props = navigation.rowProps(row)
            return (
              <Fragment key={id}>
                <tr
                  {...props}
                  aria-selected={id === navigation.activeId}
                  className={cn(RECORD_ROW_CLASS, onOpen && 'cursor-pointer', rowClass(row))}
                >
                  {selectable && (
                    <td className={cn('w-10 px-3 py-2 align-top', selectionColumn.className)}>
                      {selection.isSelectable?.(id) !== false && (
                        <input
                          type="checkbox"
                          className="size-4 accent-primary"
                          aria-label={`Select ${selectionColumn.label(row)}`}
                          checked={selection.isSelected(id)}
                          disabled={selection.disabled === true}
                          onChange={() => selection.onToggle(id)}
                        />
                      )}
                    </td>
                  )}
                  {columns.map((column) => (
                    <td key={column.id} className={cellClass(column, row)}>{column.cell(row)}</td>
                  ))}
                </tr>
                {renderAfterRow?.(row, colSpan)}
              </Fragment>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
