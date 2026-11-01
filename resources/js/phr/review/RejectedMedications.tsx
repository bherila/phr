import { type ReactElement, useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import { ehiFailureText } from '@/phr/imports/ehi/ehiApi'
import { PHR_PAGES } from '@/phr/shell/phrPages'
import { patientPageUrl } from '@/phr/shell/phrRoutes'

import { decideReviewItems, getRejectedMedications, type RejectedMedications as Rejected } from './reviewApi'

/**
 * Medications and fills someone rejected. Their lists leave them out, so this is the way back:
 * each can be opened, or approved after all through its own review route. Read only when opened,
 * since most visits to this page are about what is still waiting.
 */
export function RejectedMedications({ patientId, refreshKey, onChanged }: { patientId: number, refreshKey: number, onChanged: () => void }): ReactElement {
  const [open, setOpen] = useState(false)
  const [rejected, setRejected] = useState<Rejected | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [reloads, setReloads] = useState(0)
  const [busyKey, setBusyKey] = useState<string | null>(null)
  const [failures, setFailures] = useState<Readonly<Record<string, string>>>({})

  useEffect(() => {
    if (!open) return undefined
    let cancelled = false
    getRejectedMedications(patientId).then((next) => {
      if (cancelled) return
      setRejected(next)
      setLoadError(null)
    }).catch((caught: unknown) => {
      if (!cancelled) setLoadError(ehiFailureText(caught))
    })
    return () => {
      cancelled = true
    }
  }, [open, patientId, refreshKey, reloads])

  async function approve(item: Rejected['items'][number]): Promise<void> {
    setBusyKey(item.key)
    const outcome = await decideReviewItems(patientId, [item], 'approve')
    setFailures((previous) => {
      const next = { ...previous, ...outcome.failed }
      if (!(item.key in outcome.failed)) delete next[item.key]
      return next
    })
    setReloads((count) => count + 1)
    setBusyKey(null)
    onChanged()
  }

  return (
    <details className="rounded-xl border border-border bg-card" onToggle={(event) => setOpen(event.currentTarget.open)}>
      <summary className="flex min-h-11 cursor-pointer items-center px-4 text-sm font-semibold text-foreground md:px-5">Rejected medications and fills</summary>
      <div className="border-t border-border">
        {loadError && <p role="alert" className="px-4 py-3 text-sm text-destructive md:px-5">{loadError}</p>}
        {rejected?.items.length === 0 && <p className="px-4 py-3 text-sm text-muted-foreground md:px-5">None rejected.</p>}
        <ul className="divide-y divide-border">
          {rejected?.items.map((item) => (
            <li key={item.key} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 text-sm md:px-5">
              <div className="min-w-0 flex-[1_1_240px]">
                <span className="block font-medium text-foreground">{item.title}</span>
                <span className="block text-xs text-muted-foreground">{[PHR_PAGES[item.section].label, item.detail].filter(Boolean).join(' · ')}</span>
                {failures[item.key] && <span role="alert" className="mt-1 block text-xs text-destructive">{failures[item.key]}</span>}
              </div>
              <Button asChild size="sm" variant="outline" className="max-md:h-11">
                <a href={patientPageUrl(patientId, item.section, item.id)} aria-label={`Open ${item.title}`}>Open</a>
              </Button>
              {rejected.can_manage && (
                <Button type="button" size="sm" className="max-md:h-11" disabled={busyKey !== null} aria-label={`Approve ${item.title}`} onClick={() => void approve(item)}>Approve</Button>
              )}
            </li>
          ))}
        </ul>
        {rejected?.truncated && <p className="border-t border-border px-4 py-2 text-xs text-muted-foreground md:px-5">Showing the most recent.</p>}
      </div>
    </details>
  )
}
