import { CheckCircle2, XCircle } from 'lucide-react'
import type { ReactElement } from 'react'

import { Button } from '@/components/ui/button'
import type { PhrReviewStatus } from '@/phr/types'

/** The decisions a reviewer may record. Returning a record to the queue is the server's job. */
export type PhrReviewDecision = 'confirmed' | 'rejected'

/**
 * Confirmed is the resting state — every record predating agent writes is
 * already confirmed — so only records that still need a decision, or that were
 * rejected and may need that undone, carry actions.
 */
export function needsReview(status: PhrReviewStatus | null | undefined): boolean {
  return status === 'pending_review' || status === 'rejected'
}

interface ReviewActionsProps {
  status: PhrReviewStatus | null | undefined
  /** Names the record in the accessible label, e.g. the substance or drug name. */
  label: string
  busy: boolean
  disabled?: boolean
  onReview: (decision: PhrReviewDecision) => void
}

export function ReviewActions({ status, label, busy, disabled = false, onReview }: ReviewActionsProps): ReactElement | null {
  if (!needsReview(status)) {
    return null
  }

  return (
    <span className="inline-flex items-center gap-1">
      <Button
        type="button"
        size="icon-sm"
        variant="ghost"
        className="text-green-700 hover:text-green-800 dark:text-green-400"
        title={`Confirm ${label}`}
        aria-label={`Confirm ${label}`}
        disabled={busy || disabled}
        onClick={() => onReview('confirmed')}
      >
        <CheckCircle2 className="size-4" />
      </Button>
      {status === 'pending_review' && (
        <Button
          type="button"
          size="icon-sm"
          variant="ghost"
          className="text-destructive hover:text-destructive"
          title={`Reject ${label}`}
          aria-label={`Reject ${label}`}
          disabled={busy || disabled}
          onClick={() => onReview('rejected')}
        >
          <XCircle className="size-4" />
        </Button>
      )}
    </span>
  )
}

interface ShowRejectedToggleProps {
  showRejected: boolean
  onChange: (showRejected: boolean) => void
  disabled?: boolean
}

export function ShowRejectedToggle({ showRejected, onChange, disabled = false }: ShowRejectedToggleProps): ReactElement {
  return (
    <Button
      type="button"
      size="sm"
      variant={showRejected ? 'default' : 'outline'}
      aria-pressed={showRejected}
      disabled={disabled}
      onClick={() => onChange(!showRejected)}
    >
      {showRejected ? 'Hide rejected' : 'Show rejected'}
    </Button>
  )
}
