import { Pencil } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'

import { RowActionsMenu } from '@/components/record-list/RowActionsMenu'
import { DetailSkeleton } from '@/components/record-list/Skeleton'
import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { formatDate } from '@/lib/dateFormat'
import { ConfirmDeleteDialog } from '@/phr/clinical/ConfirmDeleteDialog'
import { recordChanged, useRecordChanges } from '@/phr/clinical/recordChanges'
import { DETAIL_ICON_BUTTON, RecordReviewBar } from '@/phr/clinical/RecordDetailParts'
import type { PhrReviewDecision } from '@/phr/clinical/review'
import { recordReviewDecision } from '@/phr/clinical/reviewDecision'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { administrationStatusLabel, doseAmountLabel, seriesPositionLabel } from '@/phr/immunizations/immunizationDose'
import { reviewStateChanged } from '@/phr/review/reviewSummary'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import type { PhrDetailPageProps } from '@/phr/shell/phrPages'
import { PhrRecordNotFound } from '@/phr/shell/PhrRecordNotFound'
import { type PhrImmunization, type PhrImmunizationFormData, PhrImmunizationResponseSchema } from '@/phr/types'

import { ImmunizationFormDialog, immunizationPayload } from './ImmunizationsPage'

function detailValue(value: string | null | undefined, fallback = 'Not recorded'): string {
  return value && value.trim().length > 0 ? value : fallback
}

export default function ImmunizationDetail({ patientId, recordId, onDrill }: PhrDetailPageProps) {
  const [immunization, setImmunization] = useState<PhrImmunization | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [canManage, setCanManage] = useState(false)
  const [mutation, setMutation] = useState<'review' | 'save' | 'delete' | null>(null)
  const [editing, setEditing] = useState(false)
  const [confirmingDelete, setConfirmingDelete] = useState(false)
  const endpoint = `/api/phr/patients/${patientId}/immunizations/${recordId}`

  const load = useCallback(async (isCancelled: () => boolean = () => false): Promise<void> => {
    setBusy(true)
    setError(null)
    setNotFound(false)
    try {
      const result = await fetchPhrDetail(endpoint, PhrImmunizationResponseSchema)
      if (isCancelled()) return
      setImmunization(result.data?.immunization ?? null)
      setCanManage(result.data?.can_manage ?? false)
      setNotFound(result.notFound)
    } catch (caught) {
      if (!isCancelled()) {
        setError(errorMessage(caught))
        setImmunization(null)
        setCanManage(false)
      }
    } finally {
      if (!isCancelled()) setBusy(false)
    }
  }, [endpoint])

  useEffect(() => {
    let cancelled = false
    setEditing(false)
    setConfirmingDelete(false)
    void load(() => cancelled)
    return () => { cancelled = true }
  }, [load])
  useRecordChanges('immunizations', patientId, 'list', (changedId) => {
    if (String(changedId) === recordId) void load()
  })

  async function change(kind: 'save' | 'review', write: () => Promise<unknown>): Promise<boolean> {
    if (!immunization) return false
    setMutation(kind)
    setError(null)
    try {
      setImmunization(PhrImmunizationResponseSchema.parse(await write()).immunization)
      recordChanged({ resource: 'immunizations', patientId, recordId: immunization.id, origin: 'detail' })
      return true
    } catch (caught) {
      setError(errorMessage(caught))
      return false
    } finally {
      setMutation(null)
    }
  }

  async function save(form: PhrImmunizationFormData): Promise<boolean> {
    const saved = await change('save', () => fetchWrapper.patch(endpoint, immunizationPayload(form)))
    if (saved) setEditing(false)
    return saved
  }

  async function remove(): Promise<void> {
    if (!immunization) return
    setMutation('delete')
    setError(null)
    try {
      await fetchWrapper.delete(endpoint, {})
      void reviewStateChanged(patientId)
      recordChanged({ resource: 'immunizations', patientId, recordId: immunization.id, origin: 'detail' })
      setConfirmingDelete(false)
      onDrill?.({ id: 'immunizations' })
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setMutation(null)
    }
  }

  if (notFound) {
    return <PhrRecordNotFound />
  }

  return (
    <div className="flex flex-1 flex-col gap-4">
      {error && !editing && !confirmingDelete && (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}
      {busy && !immunization && <DetailSkeleton label="Loading immunization" facts={4} rows={0} />}
      {immunization && canManage && <RecordReviewBar status={immunization.review_status} label={immunization.vaccine_name} busy={mutation === 'review'} disabled={mutation !== null} onReview={(decision: PhrReviewDecision) => void change('review', () => recordReviewDecision(endpoint, decision))} />}
      {immunization && (
        <section className="rounded-lg border border-border bg-card p-4 [overflow-wrap:anywhere]">
          <div className="flex items-start gap-1">
            <div className="min-w-0 flex-1">
              <h2 className="text-lg font-semibold text-card-foreground">{immunization.vaccine_name}</h2>
              <p className="mt-1 text-sm text-muted-foreground">Immunization #{immunization.id}</p>
              <div className={canManage ? 'mt-1 lg:hidden' : 'mt-1'}>{reviewStatusBadge(immunization.review_status)}</div>
            </div>
            {canManage && <>
              <Button type="button" size="icon-sm" variant="ghost" className={DETAIL_ICON_BUTTON} title="Edit" aria-label={`Edit ${immunization.vaccine_name}`} disabled={mutation !== null} onClick={() => { setError(null); setEditing(true) }}><Pencil className="size-4" aria-hidden="true" /></Button>
              <RowActionsMenu label={immunization.vaccine_name} disabled={mutation !== null} triggerClassName={DETAIL_ICON_BUTTON} actions={[{ label: 'Delete', destructive: true, onSelect: () => { setError(null); setConfirmingDelete(true) } }]} />
            </>}
          </div>
          <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Administered on</dt>
              <dd className="text-card-foreground">{detailValue(formatDate(immunization.administered_on) || null)}</dd>
            </div>
            {administrationStatusLabel(immunization.administration_status) !== null && (
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Administration status</dt>
                <dd className="text-card-foreground">{administrationStatusLabel(immunization.administration_status)}</dd>
              </div>
            )}
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Dose amount</dt>
              <dd className="text-card-foreground">{detailValue(doseAmountLabel(immunization))}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Dose in series</dt>
              <dd className="text-card-foreground">{detailValue(seriesPositionLabel(immunization))}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">CVX code</dt>
              <dd className="text-card-foreground">{detailValue(immunization.cvx_code)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Manufacturer</dt>
              <dd className="text-card-foreground">{detailValue(immunization.manufacturer)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Lot number</dt>
              <dd className="text-card-foreground">{detailValue(immunization.lot_number)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Site / route</dt>
              <dd className="text-card-foreground">{detailValue([immunization.site, immunization.route].filter(Boolean).join(' · '))}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Administered by</dt>
              <dd className="text-card-foreground">{detailValue(immunization.administered_by)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Facility</dt>
              <dd className="text-card-foreground">{detailValue(immunization.facility_name)}</dd>
            </div>
          </dl>
          <div className="mt-4 border-t border-border pt-4">
            <h3 className="text-sm font-medium text-card-foreground">Notes</h3>
            <p className="mt-1 text-sm text-muted-foreground">{detailValue(immunization.notes, 'No notes recorded.')}</p>
          </div>
          {editing && <ImmunizationFormDialog immunization={immunization} busy={mutation === 'save'} error={error} onSubmit={save} onClose={() => { setError(null); setEditing(false) }} />}
          <ConfirmDeleteDialog open={confirmingDelete} title="Delete this immunization?" description={<><strong>{immunization.vaccine_name}</strong> will be removed from this record. You can't undo this.</>} busy={mutation === 'delete'} error={confirmingDelete ? error : null} onConfirm={() => void remove()} onCancel={() => { setError(null); setConfirmingDelete(false) }} />
        </section>
      )}

    </div>
  )
}
