import type { ReactElement } from 'react'

import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'

import { reviewStatusNeedsAttention } from './ui'

/**
 * A record's review status in a list: the dot alone, so the row keeps its room. Its name says it
 * to a screen reader and a tooltip to a pointer; on a phone the record's own page says it in words.
 */
export function ReviewDot({ status }: { status: Parameters<typeof reviewStatusNeedsAttention>[0] }): ReactElement | null {
  if (!reviewStatusNeedsAttention(status)) return null
  const pending = status === 'pending_review'
  const label = pending ? 'Pending review' : 'Rejected'
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span role="img" aria-label={label} className={cn('block size-2 rounded-full', pending ? 'bg-warning' : 'bg-muted-foreground')} />
      </TooltipTrigger>
      <TooltipContent side="right">{label}</TooltipContent>
    </Tooltip>
  )
}
