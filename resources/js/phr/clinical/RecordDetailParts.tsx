import type { ReactElement, ReactNode } from 'react'

import { StatusDot } from '@/components/record-list/StatusDot'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import type { PhrReviewStatus } from '@/phr/types'

import { needsReview, type PhrReviewDecision } from './review'

/** Tap targets are 44px where the detail is its own page; the panel is for a pointer. */
export const DETAIL_ICON_BUTTON = 'max-lg:size-11'

/** Label and value pairs in two columns. Values wrap; a `wide` one takes the whole row. */
export function DetailFacts({ facts }: { facts: ReadonlyArray<{ label: string, value: ReactNode, wide?: boolean }> }): ReactElement | null {
  if (facts.length === 0) return null
  return (
    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
      {facts.map((fact) => (
        <div key={fact.label} className={cn('min-w-0', fact.wide && 'col-span-2')}>
          <dt className="text-xs text-muted-foreground">{fact.label}</dt>
          <dd className="break-words text-card-foreground">{fact.value}</dd>
        </div>
      ))}
    </dl>
  )
}

/**
 * The review decision on a record's detail. In the panel it is a banner above the facts; where
 * the detail is its own page (below `lg`) it is a full-width bar held at the bottom of the screen.
 */
export function RecordReviewBar({ status, label, busy, disabled = false, onReview }: {
  status: PhrReviewStatus | null | undefined
  /** Names the record in the buttons' accessible names. */
  label: string
  busy: boolean
  disabled?: boolean
  onReview: (decision: PhrReviewDecision) => void
}): ReactElement | null {
  if (!needsReview(status)) return null
  const pending = status === 'pending_review'
  return (
    <div
      data-testid="record-review-bar"
      className="sticky bottom-0 z-10 order-last -mx-4 -mb-4 mt-auto flex flex-wrap items-center gap-2 border-t border-border bg-card px-4 py-3 lg:static lg:order-none lg:mx-0 lg:mb-0 lg:mt-0 lg:rounded-lg lg:border-t-0 lg:bg-accent lg:px-3 lg:py-2.5"
    >
      <StatusDot
        tone={pending ? 'warning' : 'neutral'}
        label={pending ? 'Pending review' : 'Rejected'}
        className="hidden min-w-0 flex-1 lg:inline-flex"
      />
      {pending && (
        <Button type="button" size="sm" variant="outline" className="flex-1 max-lg:h-11 lg:flex-none" aria-label={`Reject ${label}`} disabled={busy || disabled} onClick={() => onReview('rejected')}>
          Reject
        </Button>
      )}
      <Button type="button" size="sm" className="flex-1 max-lg:h-11 lg:flex-none" aria-label={`Approve ${label}`} disabled={busy || disabled} onClick={() => onReview('confirmed')}>
        Approve
      </Button>
    </div>
  )
}
