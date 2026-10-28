import type { ReactElement } from 'react'
import { useEffect, useState } from 'react'

import { ehiImportsUrl, ehiReviewUrl, getEhiPending } from './ehiApi'
import { MAPPING_NOUNS } from './ehiProposalView'

export interface EhiPendingBannerProps {
  patientId: number
  /** The EHI mapping whose proposals this record page would hold. */
  mapping: string
  className?: string
}

/**
 * Tells a record page that an EHI import has proposals of its kind waiting. They are
 * proposals, not records, so the page does not list them; the banner links to their
 * review. Says nothing when there are none, or when the count cannot be loaded.
 */
export function EhiPendingBanner({ patientId, mapping, className = '' }: EhiPendingBannerProps): ReactElement | null {
  const [waiting, setWaiting] = useState<{ count: number, packageIds: number[] } | null>(null)

  useEffect(() => {
    let cancelled = false
    getEhiPending(patientId).then((pending) => {
      const entry = pending[mapping]
      if (!cancelled) setWaiting(entry && entry.count > 0 && entry.package_ids.length > 0 ? { count: entry.count, packageIds: entry.package_ids } : null)
    }).catch(() => {
      if (!cancelled) setWaiting(null)
    })

    return () => {
      cancelled = true
    }
  }, [patientId, mapping])

  if (waiting === null) {
    return null
  }
  const noun = MAPPING_NOUNS[mapping] ?? { one: 'record', many: 'records' }
  const many = waiting.count !== 1
  // One export: straight to its review. Several: the Imports page lists each one,
  // so the count is never larger than what the link leads to.
  const single = waiting.packageIds.length === 1
  const source = single ? 'an EHI export' : `${waiting.packageIds.length} EHI exports`

  return (
    <div role="status" className={`flex flex-wrap items-center gap-x-4 gap-y-2 rounded-md border border-primary/30 bg-primary/5 px-4 py-3 text-sm text-foreground ${className}`}>
      <span className="min-w-[16rem] flex-1">
        <strong className="font-semibold">{waiting.count} {many ? noun.many : noun.one} from {source} {many ? 'are' : 'is'} waiting for review.</strong>{' '}
        {many ? 'They are' : 'It is a'} proposal{many ? 's' : ''}, not {many ? 'records' : 'a record'}, so {many ? 'they are' : 'it is'} not listed below yet.
      </span>
      <a className="font-medium text-primary underline-offset-4 hover:underline" href={single ? ehiReviewUrl(patientId, waiting.packageIds[0]!) : ehiImportsUrl(patientId)}>{single ? 'Review with the import' : 'Review in Imports'}</a>
    </div>
  )
}
