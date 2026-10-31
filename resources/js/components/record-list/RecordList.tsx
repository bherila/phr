import type { ReactElement, ReactNode } from 'react'

import { cn } from '@/lib/utils'

import { DEFAULT_SKELETON_ROWS, defaultLoadingLabel, RECORD_ROW_CLASS } from './RecordTable'
import { LoadingMessage, SkeletonBlock } from './Skeleton'
import { type RecordNavigationOptions, useRecordNavigation } from './useRecordNavigation'

export interface RecordListProps<Row> extends RecordNavigationOptions<Row> {
  /** The list's accessible name. */
  label: string
  renderItem: (row: Row) => ReactNode
  /** `ol` for a timeline or anything ordered. */
  as?: 'ul' | 'ol' | undefined
  /** The first load: skeleton items, `aria-busy` and a hidden live message, as in the table. */
  loading?: boolean | undefined
  skeletonRows?: number | undefined
  loadingLabel?: string | undefined
  /** One skeleton item's content. Two text lines by default. */
  skeletonItem?: ReactNode | undefined
  empty?: ReactNode | undefined
  className?: string | undefined
  itemClassName?: string | ((row: Row) => string) | undefined
}

/**
 * The list form of the record table, for content that is not a table (a timeline, a stack of
 * cards). Same keyboard model; the active item is marked with `aria-current`, because
 * `aria-selected` is not allowed on a list item.
 */
export function RecordList<Row>(props: RecordListProps<Row>): ReactElement | null {
  const { label, renderItem, as: List = 'ul', loading = false, skeletonRows = DEFAULT_SKELETON_ROWS, loadingLabel, skeletonItem, empty = null, className, itemClassName, ...options } = props
  const navigation = useRecordNavigation(options)
  const { rows, rowId, onOpen } = options
  if (!loading && rows.length === 0) return <>{empty}</>

  return (
    <div aria-busy={loading || undefined}>
      {loading && <LoadingMessage>{loadingLabel ?? defaultLoadingLabel(label)}</LoadingMessage>}
      <List aria-label={label} className={className} {...navigation.containerProps}>
        {loading && Array.from({ length: skeletonRows }, (_, index) => (
          <li key={index} aria-hidden="true" data-skeleton-row="" className={cn('rounded-lg', typeof itemClassName === 'string' ? itemClassName : undefined)}>
            {skeletonItem ?? <div className="grid gap-1 text-sm"><SkeletonBlock className="w-1/2" /><SkeletonBlock className="w-1/3" /></div>}
          </li>
        ))}
        {!loading && rows.map((row) => {
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
    </div>
  )
}
