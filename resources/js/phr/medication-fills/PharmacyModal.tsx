import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { patientPageUrl } from '@/phr/shell/phrRoutes'

import { type MedicationFill,MISSING } from './fillTypes'

/** Clicking a fill's Pharmacy opens this: where it was filled, who prescribed it, and the source documents. */
export function PharmacyModal({ patientId, fill, onClose }: { patientId: number, fill: MedicationFill, onClose: () => void }) {
  const pharmacy = fill.display?.pharmacy_name ?? fill.pharmacy_name ?? MISSING
  const documentIds = [...new Set([fill.source_document_id, ...fill.evidence_document_ids].filter((id): id is number => id !== null))]
  const source = documentIds.length === 0
    ? (fill.source_kind === 'manual' ? 'Entered by hand' : MISSING)
    : (
        <span className="flex flex-col gap-1">
          {documentIds.map((id) => (
            <a key={id} href={patientPageUrl(patientId, 'documents', id)} className="text-primary underline-offset-2 hover:underline">Source document {id}</a>
          ))}
        </span>
      )
  const rows: Array<[string, React.ReactNode]> = [
    ['Location', fill.display?.pharmacy_location ?? fill.pharmacy_location ?? MISSING],
    ['Prescriber', fill.display?.prescriber_name ?? fill.prescriber_name ?? MISSING],
    ['Rx number', fill.rx_number ?? MISSING],
    ['Refills remaining', fill.refills_remaining === null ? MISSING : String(fill.refills_remaining)],
    ['Source', source],
  ]
  return (
    <Dialog open onOpenChange={(open) => { if (!open) onClose() }}>
      <DialogContent aria-label={`${pharmacy} details`}>
        <DialogHeader>
          <DialogTitle className="pr-6">{pharmacy}</DialogTitle>
          <DialogDescription>Pharmacy details stored with this fill.</DialogDescription>
        </DialogHeader>
        <dl className="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[9rem_1fr]">
          {rows.map(([term, value]) => (
            <div key={term} className="contents">
              <dt className="text-muted-foreground">{term}</dt>
              <dd className="text-card-foreground">{value}</dd>
            </div>
          ))}
        </dl>
      </DialogContent>
    </Dialog>
  )
}
