import type { ReactElement } from 'react'

import { cn } from '@/lib/utils'

export type StatusTone = 'success' | 'warning' | 'danger' | 'neutral'

const TONE_CLASS: Record<StatusTone, string> = {
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-destructive',
  neutral: 'bg-muted-foreground',
}

/**
 * A status as a colored dot and a label in the foreground color, on one line. The color only
 * reinforces the label, so it never carries meaning on its own or fails contrast as text.
 */
export function StatusDot({ tone, label, title, className }: { tone: StatusTone, label: string, title?: string | undefined, className?: string | undefined }): ReactElement {
  return (
    <span title={title} data-tone={tone} className={cn('inline-flex items-center gap-1.5 whitespace-nowrap text-xs text-foreground', className)}>
      <span aria-hidden="true" className={cn('size-2 shrink-0 rounded-full', TONE_CLASS[tone])} />
      {label}
    </span>
  )
}
