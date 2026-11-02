import type { ReactElement } from 'react'

import { displayUnits } from '@/lib/measureUnits'

import type { MedicationFill } from './fillTypes'

const CHIP = 'inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground'

/** What a person should know about a fill: no FDA match, a possible duplicate, a claim to choose. */
export function FillFlags({ fill }: { fill: MedicationFill }): ReactElement | null {
  const duplicate = fill.review_flags.some((flag) => flag.code === 'possible_duplicate')
  const claimToMatch = fill.review_flags.some((flag) => flag.code === 'eob_link_open')
  const unresolved = fill.ndc_status === 'unresolved'
  const ambiguous = fill.ndc_status === 'ambiguous'
  if (!duplicate && !claimToMatch && !unresolved && !ambiguous) return null
  return (
    <span className="inline-flex flex-wrap gap-1.5">
      {unresolved && <span className={CHIP}>No FDA match</span>}
      {ambiguous && <span className={CHIP}>Ambiguous NDC</span>}
      {duplicate && <span className={CHIP} title="This may be the same fill as another one. Decide on the Review page.">Possible duplicate</span>}
      {claimToMatch && <span className={CHIP} title="A pharmacy claim on this date may belong to this fill. Decide on the Review page.">Claim to match</span>}
    </span>
  )
}

export function fillStrength(fill: MedicationFill): string {
  return displayUnits(fill.drug?.strengths.join('; ') ?? '')
}

