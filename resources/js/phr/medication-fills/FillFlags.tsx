import type { ReactElement } from 'react'

import { DiscontinuedBadge } from './DiscontinuedBadge'
import type { MedicationFill } from './fillTypes'

const CHIP = 'inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground'

/** What a person should know about a fill's identity: no FDA match, discontinued, a possible duplicate. */
export function FillFlags({ fill }: { fill: MedicationFill }): ReactElement | null {
  const duplicate = fill.review_flags.some((flag) => flag.code === 'possible_duplicate')
  const unresolved = fill.ndc_status === 'unresolved'
  const ambiguous = fill.ndc_status === 'ambiguous'
  const discontinued = fill.ndc_status === 'resolved' && fill.discontinued
  if (!duplicate && !unresolved && !ambiguous && !discontinued) return null
  return (
    <span className="inline-flex flex-wrap gap-1.5">
      {unresolved && <span className={CHIP}>No FDA match</span>}
      {discontinued && <DiscontinuedBadge />}
      {ambiguous && <span className={CHIP}>Ambiguous NDC</span>}
      {duplicate && <span className={CHIP} title="This may be the same fill as another one. Decide on the Review page.">Possible duplicate</span>}
    </span>
  )
}

export function fillStrength(fill: MedicationFill): string {
  return fill.drug?.strengths.join('; ') ?? ''
}

export function fillRouteAndForm(fill: MedicationFill): string {
  return [fill.drug?.route, fill.drug?.dosage_form].filter(Boolean).join(' ')
}
