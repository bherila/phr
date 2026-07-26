import { ArrowRight, FileText, Pin } from 'lucide-react'
import type { ReactElement, ReactNode } from 'react'

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { cn } from '@/lib/utils'

export interface MillerDockTileAmount {
  label: string
  value: string
  className?: string
  valueClassName?: string
}

export interface MillerDockTileEntry<Id extends string> {
  id: Id
  label: string
  shortLabel: string
  amounts?: MillerDockTileAmount[] | null
  badge?: ReactNode
  canPin?: boolean
  inactive?: boolean
  pinLabel?: string
}

export interface MillerDockSectionProps<Id extends string> {
  title: string
  entries: MillerDockTileEntry<Id>[]
  onOpen: (id: Id) => void
  action?: ReactNode
  className?: string
  titleClassName?: string
  isPinned?: (id: Id) => boolean
  onTogglePin?: (id: Id) => void
}

export interface MillerDockClearButtonProps {
  onClear: () => void
  label?: string
}

export function MillerDockClearButton({ onClear, label = 'Clear' }: MillerDockClearButtonProps): ReactElement {
  return (
    <button
      type="button"
      onClick={onClear}
      className="rounded px-2 py-0.5 text-[11px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      {label}
    </button>
  )
}

export function MillerDockSection<Id extends string>({
  title,
  entries,
  onOpen,
  action,
  className,
  titleClassName,
  isPinned,
  onTogglePin,
}: MillerDockSectionProps<Id>): ReactElement {
  return (
    <Card className={className}>
      <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0">
        <CardTitle className={cn('text-sm font-semibold', titleClassName)}>{title}</CardTitle>
        {action}
      </CardHeader>
      <CardContent>
        <MillerDockTileGrid
          entries={entries}
          onOpen={onOpen}
          {...(isPinned ? { isPinned } : {})}
          {...(onTogglePin ? { onTogglePin } : {})}
        />
      </CardContent>
    </Card>
  )
}

interface MillerDockTileGridProps<Id extends string> {
  entries: MillerDockTileEntry<Id>[]
  onOpen: (id: Id) => void
  isPinned?: (id: Id) => boolean
  onTogglePin?: (id: Id) => void
}

export function MillerDockTileGrid<Id extends string>({
  entries,
  onOpen,
  isPinned,
  onTogglePin,
}: MillerDockTileGridProps<Id>): ReactElement {
  return (
    <div className="grid grid-cols-[repeat(auto-fit,minmax(min(14rem,100%),1fr))] gap-2">
      {entries.map((entry) => (
        <MillerDockTile
          key={entry.id}
          entry={entry}
          onOpen={onOpen}
          pinned={isPinned?.(entry.id) ?? false}
          {...(onTogglePin ? { onTogglePin: () => onTogglePin(entry.id) } : {})}
        />
      ))}
    </div>
  )
}

interface MillerDockTileProps<Id extends string> {
  entry: MillerDockTileEntry<Id>
  onOpen: (id: Id) => void
  pinned: boolean
  onTogglePin?: () => void
}

function MillerDockTile<Id extends string>({
  entry,
  onOpen,
  pinned,
  onTogglePin,
}: MillerDockTileProps<Id>): ReactElement {
  const pinLabel = entry.pinLabel ?? entry.shortLabel
  const canPin = onTogglePin !== undefined && entry.canPin !== false

  return (
    <div
      className={cn(
        'group relative flex min-h-[3.25rem] items-stretch overflow-hidden rounded-md border border-border bg-card transition-colors hover:border-primary/40 hover:bg-accent/30',
        entry.inactive ? 'opacity-50' : null,
      )}
    >
      <button
        type="button"
        onClick={() => onOpen(entry.id)}
        className="flex min-w-0 flex-1 items-center gap-2 px-3 py-2 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
      >
        <FileText className="h-4 w-4 shrink-0 text-info" aria-hidden="true" />
        <span className="flex min-w-0 flex-col">
          {entry.label !== entry.shortLabel && <span className="sr-only">{entry.label}</span>}
          <span className="truncate text-sm font-medium text-foreground">{entry.shortLabel}</span>
          {entry.amounts && entry.amounts.length > 0 && (
            <span className="mt-0.5 flex flex-wrap gap-x-2 gap-y-0.5">
              {entry.amounts.map((amount) => (
                <span
                  key={amount.label}
                  className={cn('inline-flex items-baseline gap-1 text-[10px] tabular-nums', amount.className)}
                >
                  <span className="text-muted-foreground">{amount.label}</span>
                  <span className={cn('font-medium text-foreground', amount.valueClassName)}>{amount.value}</span>
                </span>
              ))}
            </span>
          )}
        </span>
      </button>
      <span className="flex shrink-0 items-center gap-2 pr-3">
        {entry.badge}
        {canPin && (
          <button
            type="button"
            onClick={onTogglePin}
            aria-label={pinned ? `Unpin ${pinLabel}` : `Pin ${pinLabel}`}
            aria-pressed={pinned}
            className={cn(
              'rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              pinned ? 'opacity-100 text-foreground' : 'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100',
            )}
          >
            <Pin className={cn('h-3.5 w-3.5', pinned ? 'fill-current' : null)} aria-hidden="true" />
          </button>
        )}
        <ArrowRight className="h-4 w-4 shrink-0 self-center text-muted-foreground" aria-hidden="true" />
      </span>
    </div>
  )
}
