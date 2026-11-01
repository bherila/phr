import { Pencil } from 'lucide-react'
import { type ReactElement, useCallback, useEffect, useState } from 'react'

import { RowActionsMenu } from '@/components/record-list/RowActionsMenu'
import { DetailSkeleton } from '@/components/record-list/Skeleton'
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { recordChanged, useRecordChanges } from '@/phr/clinical/recordChanges'
import { DETAIL_ICON_BUTTON, DetailFacts, RecordReviewBar } from '@/phr/clinical/RecordDetailParts'
import type { PhrReviewDecision } from '@/phr/clinical/review'
import { recordReviewDecision } from '@/phr/clinical/reviewDecision'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { reviewStateChanged } from '@/phr/review/reviewSummary'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import type { PhrDetailPageProps } from '@/phr/shell/phrPages'
import { PhrRecordNotFound } from '@/phr/shell/PhrRecordNotFound'
import { patientPageUrl } from '@/phr/shell/phrRoutes'

import { DrugDetailPanel } from './DrugDetailPanel'
import { FillFormDialog } from './FillEntryForm'
import { FillFlags, fillStrength } from './FillFlags'
import { fillDrugName, formatCents, formatFillDate, formatQuantity, type MedicationFill, MedicationFillResponseSchema } from './fillTypes'
import { PharmacyModal } from './PharmacyModal'

const RESOURCE = 'medication-fills'
const LINK_BUTTON = 'rounded-sm text-left text-primary underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring'

function present(facts: Array<{ label: string, value: string | null | undefined, wide?: boolean }>) {
  return facts.filter((fact) => fact.value !== null && fact.value !== undefined && fact.value !== '')
}

/** One fill: what was dispensed that day, the drug it is, and the documents that show it. */
export default function MedicationFillDetail({ patientId, recordId, onDrill }: PhrDetailPageProps): ReactElement {
  const [fill, setFill] = useState<MedicationFill | null>(null)
  const [canManage, setCanManage] = useState(false)
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [mutating, setMutating] = useState<'review' | 'delete' | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [dialog, setDialog] = useState<'drug' | 'pharmacy' | 'edit' | 'delete' | null>(null)
  const [editKey, setEditKey] = useState(0)
  const endpoint = `/api/phr/patients/${patientId}/medication-fills/${recordId}`

  const load = useCallback(async (): Promise<MedicationFill | null> => {
    const result = await fetchPhrDetail(endpoint, MedicationFillResponseSchema)
    setFill(result.data?.medication_fill ?? null)
    setCanManage(result.data?.can_manage ?? false)
    setNotFound(result.notFound)
    return result.data?.medication_fill ?? null
  }, [endpoint])

  useEffect(() => {
    let cancelled = false
    setBusy(true)
    setError(null)
    load().catch((caught: unknown) => {
      if (!cancelled) setError(errorMessage(caught))
    }).finally(() => {
      if (!cancelled) setBusy(false)
    })
    return () => {
      cancelled = true
    }
  }, [load])

  // A decision on the row beside this panel changes the record it shows.
  useRecordChanges(RESOURCE, patientId, 'list', (changedId) => {
    if (String(changedId) !== recordId) return
    load().catch((caught: unknown) => setError(errorMessage(caught)))
  })

  function announce(id: number): void {
    recordChanged({ resource: RESOURCE, patientId, recordId: id, origin: 'detail' })
  }

  async function review(decision: PhrReviewDecision): Promise<void> {
    if (!fill) return
    setMutating('review')
    setError(null)
    try {
      const raw: unknown = await recordReviewDecision(endpoint, decision)
      setFill(MedicationFillResponseSchema.parse(raw).medication_fill)
      announce(fill.id)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setMutating(null)
    }
  }

  async function remove(): Promise<void> {
    if (!fill) return
    setMutating('delete')
    setError(null)
    try {
      await fetchWrapper.delete(endpoint, {})
      void reviewStateChanged(patientId)
      announce(fill.id)
      onDrill?.({ id: RESOURCE })
    } catch (caught) {
      setError(errorMessage(caught))
      setMutating(null)
    }
  }

  async function reloadForEdit(): Promise<void> {
    try {
      await load()
      setEditKey((key) => key + 1)
    } catch (caught) {
      setError(errorMessage(caught))
    }
  }

  if (notFound) return <PhrRecordNotFound />

  const name = fill ? fillDrugName(fill) : ''
  const pharmacy = fill ? fill.display?.pharmacy_name ?? fill.pharmacy_name : null
  const documentIds = fill ? [...new Set([fill.source_document_id, ...fill.evidence_document_ids].filter((id): id is number => id !== null))] : []
  const resolved = fill?.ndc_status === 'resolved' && fill.ndc_10 !== null

  return (
    <div className="flex flex-1 flex-col gap-4">
      {error && <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</div>}
      {busy && !fill && <DetailSkeleton label="Loading fill" facts={6} rows={0} />}
      {fill && (
        <>
          <div className="flex items-start gap-1">
            <div className="min-w-0 flex-1">
              <p className="text-xs text-muted-foreground">Filled {formatFillDate(fill.filled_on)}</p>
              <h2 className="text-lg font-semibold text-card-foreground">
                {name} {fillStrength(fill) && <span className="font-normal text-muted-foreground">{fillStrength(fill)}</span>}
              </h2>
              <div className="mt-1 flex flex-wrap items-center gap-2 lg:hidden">{reviewStatusBadge(fill.review_status)}</div>
              <div className="mt-1 flex flex-wrap items-center gap-2 empty:hidden"><FillFlags fill={fill} /></div>
            </div>
            {canManage && (
              <>
                <Button type="button" size="icon-sm" variant="ghost" className={`text-muted-foreground ${DETAIL_ICON_BUTTON}`} title="Edit" aria-label={`Edit ${name} fill ${formatFillDate(fill.filled_on)}`} disabled={mutating !== null} onClick={() => setDialog('edit')}>
                  <Pencil className="size-4" aria-hidden="true" />
                </Button>
                <RowActionsMenu
                  label={`${name} fill ${formatFillDate(fill.filled_on)}`}
                  disabled={mutating !== null}
                  triggerClassName={DETAIL_ICON_BUTTON}
                  actions={[{ label: 'Delete', destructive: true, onSelect: () => setDialog('delete') }]}
                />
              </>
            )}
          </div>

          {canManage && <RecordReviewBar status={fill.review_status} label={`${name} fill ${formatFillDate(fill.filled_on)}`} busy={mutating === 'review'} disabled={mutating !== null} onReview={(decision) => void review(decision)} />}

          <DetailFacts facts={present([
            { label: 'Quantity', value: fill.quantity === null ? null : formatQuantity(fill.quantity, fill.quantity_unit) },
            { label: 'Paid', value: fill.paid_cents === null ? null : formatCents(fill.paid_cents) },
            { label: 'Retail price', value: fill.retail_cents === null ? null : formatCents(fill.retail_cents) },
            { label: 'Days supply', value: fill.days_supply === null ? null : String(fill.days_supply) },
            { label: 'Refills left', value: fill.refills_remaining === null ? null : String(fill.refills_remaining) },
            { label: 'Rx number', value: fill.rx_number },
            { label: 'Prescriber', value: fill.display?.prescriber_name ?? fill.prescriber_name },
            { label: 'Directions', value: fill.display?.directions ?? fill.directions, wide: true },
          ])} />

          {pharmacy && (
            <div className="text-sm">
              <p className="text-xs text-muted-foreground">Pharmacy</p>
              <button type="button" className={`${LINK_BUTTON} max-lg:min-h-11`} aria-haspopup="dialog" onClick={() => setDialog('pharmacy')}>{pharmacy}</button>
            </div>
          )}

          <section aria-label="Drug" className="space-y-3 border-t border-border pt-4">
            <div className="flex items-baseline justify-between gap-2">
              <h3 className="text-sm font-semibold text-card-foreground">Drug</h3>
              <button type="button" className={`${LINK_BUTTON} text-sm max-lg:min-h-11`} aria-haspopup="dialog" onClick={() => setDialog('drug')}>Label and links</button>
            </div>
            {fill.drug ? (
              <DetailFacts facts={present([
                { label: 'Form', value: fill.drug.dosage_form },
                { label: 'Route', value: fill.drug.route },
                { label: 'Brand', value: fill.drug.brand_name },
                { label: 'Manufacturer', value: fill.drug.labeler_name },
                { label: 'Package', value: fill.drug.package_description, wide: true },
                { label: 'NDC', value: fill.ndc_10 },
              ])} />
            ) : (
              <p className="text-sm text-muted-foreground">
                {resolved ? 'No drug details are stored for this fill.' : `No FDA listing is stored for this fill${fill.ndc_as_captured ? ` (NDC as captured ${fill.ndc_as_captured})` : ''}.`}
              </p>
            )}
            {fill.medication && (
              <p className="text-sm">
                <span className="text-xs text-muted-foreground">On the medication list as </span>
                <a href={patientPageUrl(patientId, 'medications', fill.medication.id)} className="text-primary underline-offset-2 hover:underline">{fill.medication.name}</a>
              </p>
            )}
          </section>

          <section aria-label="Evidence" className="space-y-1.5 border-t border-border pt-4">
            <h3 className="text-sm font-semibold text-card-foreground">Evidence</h3>
            {documentIds.length === 0
              ? <p className="text-sm text-muted-foreground">{fill.source_kind === 'manual' ? 'Entered by hand.' : 'No source document.'}</p>
              : documentIds.map((id) => (
                  <a key={id} href={patientPageUrl(patientId, 'documents', id)} className="block text-sm text-primary underline-offset-2 hover:underline max-lg:py-2.5">Source document {id}</a>
                ))}
          </section>

          {dialog === 'drug' && <DrugDetailPanel patientId={patientId} fill={fill} onClose={() => setDialog(null)} />}
          {dialog === 'pharmacy' && <PharmacyModal patientId={patientId} fill={fill} onClose={() => setDialog(null)} />}
          {dialog === 'edit' && (
            <FillFormDialog
              patientId={patientId}
              fill={fill}
              formKey={`${fill.id}:${editKey}`}
              onSaved={(saved) => { setFill(saved); setDialog(null); announce(saved.id) }}
              onClose={() => setDialog(null)}
              onReload={() => void reloadForEdit()}
            />
          )}
          <AlertDialog open={dialog === 'delete'} onOpenChange={(open) => { if (!open) setDialog(null) }}>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Delete this fill?</AlertDialogTitle>
                <AlertDialogDescription>The {formatFillDate(fill.filled_on)} fill of {name} will be removed. This cannot be undone.</AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction disabled={mutating !== null} onClick={() => void remove()}>{mutating === 'delete' ? 'Deleting…' : 'Delete'}</AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </>
      )}
    </div>
  )
}
