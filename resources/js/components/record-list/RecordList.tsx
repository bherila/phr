import type { ReactElement, ReactNode } from 'react'

import { cn } from '@/lib/utils'

import { DEFAULT_LOADING, RECORD_ROW_CLASS } from './RecordTable'
import { type RecordNavigationOptions, useRecordNavigation } from './useRecordNavigation'

export interface RecordListProps<Row> extends RecordNavigationOptions<Row> {
  /** The list's accessible name. */
  label: string
  renderItem: (row: Row) => ReactNode
  /** `ol` for a timeline or anything ordered. */
  as?: 'ul' | 'ol'
  loading?: boolean
  loadingContent?: ReactNode
  empty?: ReactNode
  className?: string
  itemClassName?: string | ((row: Row) => string)
}

/**
 * The list form of the record table, for content that is not a table (a timeline, a stack of
 * cards). Same keyboard model; the active item is marked with `aria-current`, because
 * `aria-selected` is not allowed on a list item.
 */
export function RecordList<Row>(props: RecordListProps<Row>): ReactElement | null {
  const { label, renderItem, as: List = 'ul', loading = false, loadingContent = DEFAULT_LOADING, empty = null, className, itemClassName, ...options } = props
  const navigation = useRecordNavigation(options)
  const { rows, rowId, onOpen } = options
  if (loading) return <>{loadingContent}</>
  if (rows.length === 0) return <>{empty}</>

  return (
    <List aria-label={label} className={className} {...navigation.containerProps}>
      {rows.map((row) => {
        const id = rowId(row)
        return (
          <li
            key={id}
            {...navigation.rowProps(row)}
            aria-current={id === navigation.activeId ? 'true' : undefined}
            className={cn(RECORD_ROW_CLASS, 'rounded-lg', onOpen && 'cursor-pointer', typeof itemClassName === 'function' ? itemClassName(row) : itemClassName)}
          >
            {renderItem(row)}
          </li>
        )
      })}
    </List>
  )
}
