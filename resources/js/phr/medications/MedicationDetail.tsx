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
import { MedicationFillHistory } from '@/phr/medication-fills/MedicationFillHistory'
import { reviewStateChanged } from '@/phr/review/reviewSummary'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import type { PhrDetailPageProps } from '@/phr/shell/phrPages'
import { PhrRecordNotFound } from '@/phr/shell/PhrRecordNotFound'
import { type PhrMedication, PhrMedicationResponseSchema } from '@/phr/types'

import { MedicationFormDialog, medicationFormFromMedication, type MedicationFormState, medicationPayload, medicationStatusLabel } from './medicationForm'

const RESOURCE = 'medications'

function filled(value: string | null | undefined): boolean {
  return value !== null && value !== undefined && value.trim().length > 0
}

/** One medication: what is recorded, a line for what is not, and its fills. */
export default function MedicationDetail({ patientId, recordId, onDrill }: PhrDetailPageProps): ReactElement {
  const [medication, setMedication] = useState<PhrMedication | null>(null)
  const [canManage, setCanManage] = useState(false)
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [mutating, setMutating] = useState<'review' | 'save' | 'delete' | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editForm, setEditForm] = useState<MedicationFormState | null>(null)
  const [confirmingDelete, setConfirmingDelete] = useState(false)
  const endpoint = `/api/phr/patients/${patientId}/medications/${recordId}`

  const load = useCallback(async (): Promise<void> => {
    const result = await fetchPhrDetail(endpoint, PhrMedicationResponseSchema)
    setMedication(result.data?.medication ?? null)
    setCanManage(result.data?.can_manage ?? false)
    setNotFound(result.notFound)
  }, [endpoint])

  useEffect(() => {
    let cancelled = false
    setBusy(true)
    setError(null)
    load().catch((caught: unknown) => {
      if (!cancelled) {
        setError(errorMessage(caught))
        setMedication(null)
      }
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

  /** Runs one change to this record: the bar's busy state, its error, and telling the list. */
  async function change(kind: 'review' | 'save', send: () => Promise<unknown>): Promise<boolean> {
    setMutating(kind)
    setError(null)
    try {
      const updated = PhrMedicationResponseSchema.parse(await send()).medication
      setMedication(updated)
      announce(updated.id)
      return true
    } catch (caught) {
      setError(errorMessage(caught))
      return false
    } finally {
      setMutating(null)
    }
  }

  async function saveEdit(form: MedicationFormState): Promise<void> {
    if (!form.name.trim()) {
      setError('Name is required.')
      return
    }
    if (await change('save', () => fetchWrapper.patch(endpoint, medicationPayload(form)))) setEditForm(null)
  }

  async function remove(): Promise<void> {
    if (!medication) return
    setMutating('delete')
    setError(null)
    try {
      await fetchWrapper.delete(endpoint, {})
      void reviewStateChanged(patientId)
      announce(medication.id)
      onDrill?.({ id: RESOURCE })
    } catch (caught) {
      setError(errorMessage(caught))
      setMutating(null)
    }
  }

  if (notFound) return <PhrRecordNotFound />

  const facts = medication === null ? [] : [
    { label: 'Dose', value: [medication.dose, medication.dose_unit].filter(Boolean).join(' ') },
    { label: 'Route', value: medication.route },
    { label: 'Schedule', value: medication.frequency },
    { label: 'Started', value: medication.started_on },
    { label: 'Ended', value: medication.ended_on },
    { label: 'Prescriber', value: medication.prescriber_name },
    { label: 'RxNorm', value: medication.rxnorm_code },
  ]
  // An active medication has no end date to be missing.
  const missing = medication === null ? [] : [
    ...facts.filter((fact) => !filled(fact.value) && !(fact.label === 'Ended' && medication.status === 'active')).map((fact) => (fact.label === 'RxNorm' ? fact.label : fact.label.toLowerCase())),
    ...(filled(medication.reason_for_use) ? [] : ['reason for use']),
  ]

  return (
    <div className="flex flex-1 flex-col gap-4">
      {error && !editForm && <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</div>}
      {busy && !medication && <DetailSkeleton label="Loading medication" facts={4} rows={0} />}
      {medication && (
        <>
          <div className="flex items-start gap-1">
            <div className="min-w-0 flex-1">
              <p className="text-xs text-muted-foreground">{medicationStatusLabel(medication.status)}</p>
              <h2 className="text-lg font-semibold text-card-foreground">{medication.name}</h2>
              <div className="mt-1 flex flex-wrap items-center gap-2 empty:hidden lg:hidden">{reviewStatusBadge(medication.review_status)}</div>
            </div>
            {canManage && (
              <>
                <Button type="button" size="icon-sm" variant="ghost" className={`text-muted-foreground ${DETAIL_ICON_BUTTON}`} title="Edit" aria-label={`Edit ${medication.name}`} disabled={mutating !== null} onClick={() => setEditForm(medicationFormFromMedication(medication))}>
                  <Pencil className="size-4" aria-hidden="true" />
                </Button>
                <RowActionsMenu
                  label={medication.name}
                  disabled={mutating !== null}
                  triggerClassName={DETAIL_ICON_BUTTON}
                  actions={[
                    { label: 'Delete', destructive: true, onSelect: () => setConfirmingDelete(true) },
                  ]}
                />
              </>
            )}
          </div>

          {canManage && (
            <RecordReviewBar
              status={medication.review_status}
              label={medication.name}
              busy={mutating === 'review'}
              disabled={mutating !== null}
              onReview={(decision: PhrReviewDecision) => void change('review', () => recordReviewDecision(endpoint, decision))}
            />
          )}

          <DetailFacts facts={[
            ...facts.filter((fact) => filled(fact.value)).map((fact) => ({ label: fact.label, value: fact.value })),
            ...(filled(medication.reason_for_use) ? [{ label: 'Reason for use', value: medication.reason_for_use, wide: true }] : []),
          ]} />
          {missing.length > 0 && <p className="text-xs text-muted-foreground">Not recorded: {missing.join(', ')}.</p>}

          <MedicationFillHistory patientId={patientId} medicationId={medication.id} onDrill={onDrill} />

          {editForm && (
            <MedicationFormDialog
              title={`Edit ${medication.name}`}
              submitLabel="Save"
              form={editForm}
              onChange={setEditForm}
              busy={mutating === 'save'}
              error={error}
              onSubmit={() => void saveEdit(editForm)}
              onClose={() => { setError(null); setEditForm(null) }}
            />
          )}
          <AlertDialog open={confirmingDelete} onOpenChange={setConfirmingDelete}>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Delete this medication?</AlertDialogTitle>
                <AlertDialogDescription>{medication.name} will be removed. This cannot be undone.</AlertDialogDescription>
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
