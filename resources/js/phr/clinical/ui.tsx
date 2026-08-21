import type { ReactElement } from 'react'

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

const REVIEW_STATUS_CLASS: Record<PhrReviewStatus, string> = {
  pending_review: 'bg-amber-100 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200',
  confirmed: 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-300',
}

export function reviewStatusBadge(status: PhrReviewStatus | null | undefined): ReactElement | null {
  if (!status) {
    return null
  }

  return (
    <span
      title="Clinical review status"
      className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize ${REVIEW_STATUS_CLASS[status]}`}
    >
      {labelize(status)}
    </span>
  )
}
