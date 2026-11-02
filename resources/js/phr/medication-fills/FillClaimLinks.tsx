import type { ReactElement } from 'react'
import { useCallback, useEffect, useState } from 'react'
import { z } from 'zod'

import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { answerFillEobLink } from '@/phr/review/reviewApi'

import { formatFillDate } from './fillTypes'

const ClaimLinksSchema = z.object({
  claims: z.array(z.object({
    eob_id: z.number(),
    claim_number: z.string().nullable(),
    service_date: z.string().nullable(),
    total_charges: z.string().nullable(),
    provider_name: z.string().nullable(),
    automatic: z.boolean(),
    rule: z.string().nullable(),
  })),
  dismissed: z.boolean(),
})

type ClaimLinks = z.infer<typeof ClaimLinksSchema>

const RULE_REASON: Record<string, string> = {
  sole_pair: 'it was the only fill and the only claim that day',
  known_charge: 'its charge matches earlier fills of this drug',
  last_pair: 'it was the last fill and the last claim left that day',
}

function claimLabel(claim: ClaimLinks['claims'][number]): string {
  return [
    claim.claim_number ? `Claim ${claim.claim_number}` : 'Claim',
    claim.service_date ? formatFillDate(claim.service_date) : null,
    claim.total_charges ? `$${claim.total_charges}` : null,
    claim.provider_name,
  ].filter(Boolean).join(' · ')
}

/**
 * The pharmacy claims a fill is linked to. An automatic link is a reasoned guess from the date and
 * the charge, so a person who can manage the fill may reject it, and undo that.
 */
export default function FillClaimLinks({ patientId, fillId, canManage, refreshKey, onChanged }: {
  patientId: number
  fillId: number
  canManage: boolean
  /** Changes when the fill is saved, because an edit can move its automatic match. */
  refreshKey: string | null
  onChanged: () => void
}): ReactElement | null {
  const [links, setLinks] = useState<ClaimLinks | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async (): Promise<void> => {
    setLinks(ClaimLinksSchema.parse(await fetchWrapper.get(`/api/phr/patients/${patientId}/medication-fills/${fillId}/eob-link`)))
  }, [patientId, fillId])

  useEffect(() => {
    load().catch(() => setError('The linked claims could not be loaded.'))
  }, [load, refreshKey])

  async function answer(request: { action: 'reject', eobId: number } | { action: 'reset' }): Promise<void> {
    setSaving(true)
    setError(null)
    try {
      await answerFillEobLink(patientId, fillId, request)
      await load()
      onChanged()
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'That could not be saved.')
      // A refusal usually means the links moved, so show what they are now.
      await load().catch(() => undefined)
    } finally {
      setSaving(false)
    }
  }

  if (error && !links) return <p role="alert" className="border-t border-border pt-4 text-sm text-destructive">{error}</p>
  if (!links || (links.claims.length === 0 && !links.dismissed)) return null

  return (
    <section aria-label="Pharmacy claim" className="space-y-2 border-t border-border pt-4 text-sm">
      <h3 className="text-sm font-semibold text-card-foreground">Pharmacy claim</h3>
      {error && <p role="alert" className="text-destructive">{error}</p>}
      {links.claims.map((claim) => (
        <div key={claim.eob_id} className="flex flex-wrap items-start justify-between gap-2">
          <div className="min-w-0">
            <p>{claimLabel(claim)}</p>
            {claim.automatic && (
              <p className="text-xs text-muted-foreground">
                Matched automatically: {(claim.rule && RULE_REASON[claim.rule]) ?? 'same date and charge'}.
              </p>
            )}
          </div>
          {claim.automatic && canManage && (
            <Button type="button" variant="outline" size="sm" disabled={saving} className="max-lg:min-h-11" onClick={() => void answer({ action: 'reject', eobId: claim.eob_id })}>
              Not this claim
            </Button>
          )}
        </div>
      ))}
      {links.dismissed && (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-muted-foreground">You said not to match this fill to a claim automatically.</p>
          {canManage && (
            <Button type="button" variant="outline" size="sm" disabled={saving} className="max-lg:min-h-11" onClick={() => void answer({ action: 'reset' })}>
              Match it again
            </Button>
          )}
        </div>
      )}
    </section>
  )
}
