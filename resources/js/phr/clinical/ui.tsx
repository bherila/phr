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

const REVIEW_STATUS_TONE: Record<PhrReviewStatus, StatusTone> = {
  pending_review: 'warning',
  confirmed: 'success',
  rejected: 'danger',
}

/** The review status as a dot and a label on one line (the color is on the dot, not the text). */
export function reviewStatusBadge(status: PhrReviewStatus | null | undefined): ReactElement | null {
  if (!status) {
    return null
  }

  return <StatusDot tone={REVIEW_STATUS_TONE[status]} label={labelize(status)} title="Clinical review status" className="capitalize" />
}
