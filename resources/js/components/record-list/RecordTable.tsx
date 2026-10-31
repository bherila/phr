import { Fragment, type ReactElement, type ReactNode } from 'react'

import { cn } from '@/lib/utils'

import { type RecordNavigationOptions, useRecordNavigation } from './useRecordNavigation'

export interface RecordColumn<Row> {
  id: string
  header: ReactNode
  cell: (row: Row) => ReactNode
  className?: string | ((row: Row) => string) | undefined
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
}

/** Sizes a column to its content on one line. */
export const RECORD_FIT_CLASS = 'w-[1%] whitespace-nowrap'
/** Right-aligned tabular figures, for numeric values only. */
export const RECORD_NUMERIC_CLASS = 'text-right tabular-nums'

export interface RecordSelectionColumn<Row> {
  /** Names the checkbox: "Select {label}". */
  label: (row: Row) => string
  header?: ReactNode | undefined
}

export interface RecordTableProps<Row> extends RecordNavigationOptions<Row> {
  /** The table's accessible name. */
  label: string
  columns: readonly RecordColumn<Row>[]
  /** Shown instead of the table while loading. Nothing by default. */
  loading?: boolean | undefined
  loadingContent?: ReactNode | undefined
  /** Shown instead of the table when there are no rows. */
  empty?: ReactNode | undefined
  /** Extra full-width rows under a row (an inline edit form, a delete confirmation). */
  renderAfterRow?: ((row: Row, colSpan: number) => ReactNode) | undefined
  rowClassName?: ((row: Row) => string) | undefined
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
    typeof column.className === 'function' ? column.className(row) : column.className,
    column.fit && RECORD_FIT_CLASS,
    numeric && RECORD_NUMERIC_CLASS,
  )
}

function headerClass<Row>(column: RecordColumn<Row>): string {
  return cn('text-left', column.headerClassName, column.fit && RECORD_FIT_CLASS, column.numeric === true && 'text-right')
}

export const DEFAULT_LOADING = <p role="status" aria-live="polite" className="text-sm text-muted-foreground">Loading…</p>

/**
 * A table of records with the shared keyboard model ({@link useRecordNavigation}). Native table
 * semantics stay; the active row carries `aria-selected` and the roving tabindex, and the row
 * whose detail is open is highlighted.
 */
export function RecordTable<Row>(props: RecordTableProps<Row>): ReactElement | null {
  const { label, columns, loading = false, loadingContent = DEFAULT_LOADING, empty = null, renderAfterRow, rowClassName, className, tableClassName, headClassName, bodyClassName, selectionColumn, ...options } = props
  const navigation = useRecordNavigation(options)
  const { rows, rowId, selection, onOpen } = options
  if (loading) return <>{loadingContent}</>
  if (rows.length === 0) return <>{empty}</>
  const selectable = selectionColumn !== undefined && selection !== undefined
  const colSpan = columns.length + (selectable ? 1 : 0)

  return (
    <div className={cn('overflow-x-auto', className)}>
      <table aria-label={label} className={tableClassName} {...navigation.containerProps}>
        <thead>
          <tr className={headClassName}>
            {selectable && <th className="w-10 px-3 py-2 font-medium">{selectionColumn.header ?? <span className="sr-only">Selected</span>}</th>}
            {columns.map((column) => (
              <th key={column.id} className={headerClass(column)}>{column.header}</th>
            ))}
          </tr>
        </thead>
        <tbody className={bodyClassName}>
          {rows.map((row) => {
            const id = rowId(row)
            const props = navigation.rowProps(row)
            return (
              <Fragment key={id}>
                <tr
                  {...props}
                  aria-selected={id === navigation.activeId}
                  className={cn(RECORD_ROW_CLASS, onOpen && 'cursor-pointer', rowClassName?.(row))}
                >
                  {selectable && (
                    <td className="w-10 px-3 py-2 align-top">
                      <input
                        type="checkbox"
                        className="size-4"
                        aria-label={`Select ${selectionColumn.label(row)}`}
                        checked={selection.isSelected(id)}
                        onChange={() => selection.onToggle(id)}
                      />
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
