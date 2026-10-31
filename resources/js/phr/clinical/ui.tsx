import type { ReactElement } from 'react'

import { StatusDot, type StatusTone } from '@/components/record-list/StatusDot'
import type { PhrReviewStatus } from '@/phr/types'

export function labelize(value: string): string {
  return value.replaceAll('_', ' ')
}

export function codeChip(label: string, value: string | null): ReactElement | null {
  if (!value) {
    return null
  }

  return (
    <span
      title={`${label}: ${value}`}
      className="inline-flex rounded-full border border-border bg-background px-2 py-0.5 text-xs font-medium text-foreground"
    >
      {label} {value}
    </span>
  )
}

export function classBadge(value: string | null, classes: Record<string, string>): ReactElement | null {
  if (!value) {
    return null
  }

  return (
    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${classes[value] ?? 'bg-muted text-muted-foreground'}`}>
      {labelize(value)}
    </span>
  )
}

/**
 * Review status is shown only when it needs attention. Approved (confirmed) is the resting
 * state, so repeating it on every row is noise; a rejected row is muted.
 */
const REVIEW_STATUS_DISPLAY: Partial<Record<PhrReviewStatus, { tone: StatusTone, label: string }>> = {
  pending_review: { tone: 'warning', label: 'Pending review' },
  rejected: { tone: 'neutral', label: 'Rejected' },
}

export function reviewStatusNeedsAttention(status: PhrReviewStatus | null | undefined): boolean {
  return status !== null && status !== undefined && REVIEW_STATUS_DISPLAY[status] !== undefined
}

/** A dot and a label for pending or rejected records; nothing for approved ones. */
export function reviewStatusBadge(status: PhrReviewStatus | null | undefined): ReactElement | null {
  const display = status ? REVIEW_STATUS_DISPLAY[status] : undefined
  if (!display) {
    return null
  }

  return <StatusDot tone={display.tone} label={display.label} title="Clinical review status" />
}
