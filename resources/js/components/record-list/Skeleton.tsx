import type { ReactElement, ReactNode } from 'react'

import { cn } from '@/lib/utils'

/** A placeholder bar one text line tall. No shimmer when reduced motion is requested. */
export function SkeletonBlock({ className }: { className?: string | undefined }): ReactElement {
  return <span aria-hidden="true" data-skeleton="" className={cn('block h-[1lh] animate-pulse rounded bg-muted motion-reduce:animate-none', className)} />
}

/** The visually hidden live message that goes with `aria-busy` on a loading region. */
export function LoadingMessage({ children }: { children: ReactNode }): ReactElement {
  return <span role="status" aria-live="polite" className="sr-only">{children}</span>
}

/** A detail panel while its record loads: heading, fact lines, then result rows. */
export function DetailSkeleton({ label = 'Loading record', facts = 3, rows = 6 }: { label?: string, facts?: number, rows?: number }): ReactElement {
  return (
    <div aria-busy="true" className="space-y-4" data-testid="detail-skeleton">
      <LoadingMessage>{label}</LoadingMessage>
      <div className="space-y-2">
        <SkeletonBlock className="h-7 w-2/3" />
        <div className="grid gap-1 text-sm">
          {Array.from({ length: facts }, (_, index) => <SkeletonBlock key={index} className="w-1/2" />)}
        </div>
      </div>
      {rows > 0 && (
        <div className="divide-y divide-border rounded-lg border border-border text-sm">
          {Array.from({ length: rows }, (_, index) => (
            <div key={index} aria-hidden="true" data-skeleton-row="" className="grid gap-0.5 px-3 py-2">
              <div className="flex justify-between gap-3"><SkeletonBlock className="w-1/2" /><SkeletonBlock className="w-16" /></div>
              <SkeletonBlock className="w-1/3 text-xs" />
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

/**
 * A page while its code or first data loads: the same header shape as the page (its title when
 * known), a toolbar line, and table rows, so nothing moves when the page arrives.
 */
export function PageSkeleton({ title, label = 'Loading page', rows = 8 }: { title?: ReactNode, label?: string, rows?: number }): ReactElement {
  return (
    <div aria-busy="true" data-testid="page-skeleton">
      <LoadingMessage>{label}</LoadingMessage>
      <div className="mb-6">
        {title ?? <SkeletonBlock className="h-8 w-48 text-2xl" />}
        <SkeletonBlock className="mt-1 w-80 max-w-full text-sm" />
      </div>
      <div className="overflow-hidden rounded-lg border border-border text-sm">
        {Array.from({ length: rows }, (_, index) => (
          <div key={index} aria-hidden="true" className="flex gap-4 border-b border-border px-3 py-2 last:border-0">
            <SkeletonBlock className="flex-1" />
            <SkeletonBlock className="w-20" />
            <SkeletonBlock className="w-24" />
          </div>
        ))}
      </div>
    </div>
  )
}
