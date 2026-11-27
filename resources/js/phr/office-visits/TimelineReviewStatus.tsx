import { reviewStatusBadge } from '@/phr/clinical/ui'
import type { PhrReviewStatus } from '@/phr/types'

/** Compact timelines name the review state without adding another text line on phones. */
export function TimelineReviewStatus({ status }: { status: PhrReviewStatus | null | undefined }) {
  if (status !== 'pending_review' && status !== 'rejected') return null
  const label = status === 'pending_review' ? 'Pending review' : 'Rejected'
  return (
    <span className="inline-flex shrink-0 items-center">
      <span className="hidden @[560px]/record-list:inline-flex">{reviewStatusBadge(status)}</span>
      <span role="img" aria-label={label} className="inline-flex @[560px]/record-list:hidden">
        <span aria-hidden="true" className={`size-2 rounded-full ${status === 'pending_review' ? 'bg-warning' : 'bg-muted-foreground'}`} />
      </span>
    </span>
  )
}
