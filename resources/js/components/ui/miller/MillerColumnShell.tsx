import { ChevronLeft, X } from 'lucide-react'
import { type ReactNode, useEffect, useRef } from 'react'

import { ScrollArea } from '@/components/ui/scroll-area'
import { cn } from '@/lib/utils'

import type { MillerColumnSize } from './millerRegistry'

const MILLER_COLUMN_SIZE_CLASSES: Record<MillerColumnSize, string> = {
  narrow: 'w-full md:w-[400px]',
  default: 'w-full md:w-[520px]',
  wide: 'w-full md:w-[760px]',
  full: 'w-full md:w-[1040px] xl:w-[1200px]',
  viewport: 'w-screen max-w-screen',
}

function isEditableTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) {
    return false
  }
  if (target.isContentEditable) {
    return true
  }

  return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
}

export interface MillerColumnShellColumn {
  key: string
  id: string
  label: string
  shortLabel: string
  headerActions?: ReactNode | undefined
  size?: MillerColumnSize | undefined
  /** @deprecated Use size instead. */
  wide?: boolean | undefined
  dataAttributes?: Record<`data-${string}`, string | number | boolean | undefined>
  topAccessory?: ReactNode
  children: ReactNode
}

interface MillerColumnShellProps {
  homeView: ReactNode
  columns: MillerColumnShellColumn[]
  onTruncate: (depth: number) => void
  className?: string
  homeColumnClassName?: string
  homeColumnSize?: MillerColumnSize
}

function getMillerColumnSizeClass(column: Pick<MillerColumnShellColumn, 'size' | 'wide'>): string {
  return MILLER_COLUMN_SIZE_CLASSES[column.size ?? (column.wide ? 'wide' : 'default')]
}

export function MillerColumnShell({
  homeView,
  columns,
  onTruncate,
  className = '',
  homeColumnClassName = '',
  homeColumnSize = 'full',
}: MillerColumnShellProps): React.ReactElement {
  const columnDepth = columns.length
  const prevDepthRef = useRef(columnDepth)

  useEffect(() => {
    const prevDepth = prevDepthRef.current
    prevDepthRef.current = columnDepth
    if (columnDepth === 0 || columnDepth < prevDepth) {
      return
    }

    const id = window.requestAnimationFrame(() => {
      const last = document.querySelector<HTMLElement>('section[data-miller-column][data-last="true"]')
      const viewport = last?.closest<HTMLElement>('[data-slot="scroll-area-viewport"]')
      if (viewport) {
        if (typeof viewport.scrollTo === 'function') {
          viewport.scrollTo({ left: viewport.scrollWidth, behavior: 'smooth' })
        } else {
          viewport.scrollLeft = viewport.scrollWidth
        }
        return
      }

      last?.scrollIntoView({ behavior: 'smooth', inline: 'end', block: 'nearest' })
    })

    return () => {
      window.cancelAnimationFrame(id)
    }
  }, [columnDepth])

  useEffect(() => {
    if (typeof window === 'undefined' || columnDepth === 0) {
      return
    }

    const handler = (event: KeyboardEvent): void => {
      if (event.key !== 'Escape' || event.defaultPrevented) {
        return
      }
      if (isEditableTarget(event.target)) {
        return
      }
      if (document.querySelector('[role="dialog"][data-open]')) {
        return
      }
      onTruncate(columnDepth - 1)
    }

    window.addEventListener('keydown', handler)

    return () => {
      window.removeEventListener('keydown', handler)
    }
  }, [columnDepth, onTruncate])

  const hasColumns = columns.length > 0

  return (
    <ScrollArea orientation="horizontal" className={cn('h-full w-full', className)}>
      <div className="flex h-full bg-background">
        <section
          className={cn(
            'relative flex flex-col bg-card',
            hasColumns
              ? ['hidden shrink-0 border-r border-border md:flex', MILLER_COLUMN_SIZE_CLASSES[homeColumnSize]]
              : 'w-full',
            homeColumnClassName,
          )}
        >
          <ScrollArea className="h-full w-full">{homeView}</ScrollArea>
        </section>

        {columns.map((column, depth) => {
          const isLast = depth === columns.length - 1

          return (
            <section
              key={column.key}
              className={cn(
                'relative flex shrink-0 flex-col border-r border-border bg-card motion-safe:animate-in motion-safe:fade-in-0 motion-safe:slide-in-from-right-4 motion-safe:duration-200',
                getMillerColumnSizeClass(column),
                isLast ? '' : 'hidden md:flex',
              )}
              data-column-id={column.id}
              data-depth={depth}
              data-last={isLast ? 'true' : 'false'}
              data-miller-column
              {...column.dataAttributes}
            >
              <header className="flex shrink-0 items-center justify-between gap-2 border-b border-border bg-card px-4 py-3">
                {depth > 0 && (
                  <button
                    type="button"
                    onClick={() => onTruncate(depth)}
                    className="-ml-1 rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:hidden"
                    aria-label="Back to previous column"
                  >
                    <ChevronLeft className="h-4 w-4" aria-hidden="true" />
                  </button>
                )}
                <div className="min-w-0 flex-1">
                  <div className="truncate text-sm font-semibold text-foreground">{column.shortLabel}</div>
                  <div className="truncate text-xs text-muted-foreground">{column.label}</div>
                </div>
                {column.headerActions ? <div className="flex shrink-0 items-center gap-1">{column.headerActions}</div> : null}
                <button
                  type="button"
                  onClick={() => onTruncate(depth)}
                  className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  aria-label={`Close columns after ${column.shortLabel}`}
                >
                  <X className="h-4 w-4" aria-hidden="true" />
                </button>
              </header>
              {column.topAccessory ? <div className="shrink-0">{column.topAccessory}</div> : null}
              <div className="min-h-0 flex-1">
                <ScrollArea className="h-full w-full bg-card">
                  <div className="p-4">{column.children}</div>
                </ScrollArea>
              </div>
            </section>
          )
        })}
      </div>
    </ScrollArea>
  )
}
