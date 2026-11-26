import { Pencil } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'

import { RowActionsMenu } from '@/components/record-list/RowActionsMenu'
import { DetailSkeleton } from '@/components/record-list/Skeleton'
import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { formatDate, formatDateTime } from '@/lib/dateFormat'
import { ConfirmDeleteDialog } from '@/phr/clinical/ConfirmDeleteDialog'
import { recordChanged, useRecordChanges } from '@/phr/clinical/recordChanges'
import { DETAIL_ICON_BUTTON } from '@/phr/clinical/RecordDetailParts'
import { useKnownPatientCanManage } from '@/phr/patients/usePhrPatients'
import { reviewStateChanged } from '@/phr/review/reviewSummary'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import type { PhrDetailPageProps } from '@/phr/shell/phrPages'
import { PhrRecordNotFound } from '@/phr/shell/PhrRecordNotFound'
import { type PhrCondition, type PhrConditionFormData, PhrConditionResponseSchema } from '@/phr/types'

import { ConditionFormDialog, conditionPayload } from './conditionForm'

const RESOURCE = 'conditions'

function detailValue(value: string | null | undefined, fallback = 'Not recorded'): string {
  return value && value.trim().length > 0 ? value : fallback
}

export default function ConditionDetail({ patientId, recordId, onDrill }: PhrDetailPageProps) {
  const endpoint = `/api/phr/patients/${patientId}/conditions/${recordId}`
  const canManage = useKnownPatientCanManage(patientId)
  const [condition, setCondition] = useState<PhrCondition | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [mutating, setMutating] = useState<'save' | 'delete' | null>(null)
  const [editing, setEditing] = useState(false)
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const load = useCallback(async (isCancelled: () => boolean = () => false): Promise<void> => {
    setBusy(true)
    setError(null)
    setNotFound(false)
    try {
      const result = await fetchPhrDetail(endpoint, PhrConditionResponseSchema)
      if (isCancelled()) return
      setCondition(result.data?.condition ?? null)
      setNotFound(result.notFound)
    } catch (caught) {
      if (!isCancelled()) {
        setError(errorMessage(caught))
        setCondition(null)
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
    return () => {
      cancelled = true
    }
  }, [load])

  // The list beside the panel edits, resolves and reviews too; show what it saved.
  useRecordChanges(RESOURCE, patientId, 'list', (changedId) => {
    if (String(changedId) === recordId) void load()
  })

  async function save(form: PhrConditionFormData): Promise<boolean> {
    if (!condition) return false
    setMutating('save')
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.patch(endpoint, conditionPayload(form))
      setCondition(PhrConditionResponseSchema.parse(raw).condition)
      setEditing(false)
      recordChanged({ resource: RESOURCE, patientId, recordId: condition.id, origin: 'detail' })
      return true
    } catch (caught) {
      setError(errorMessage(caught))
      return false
    } finally {
      setMutating(null)
    }
  }

  async function remove(): Promise<void> {
    if (!condition) return
    setMutating('delete')
    setError(null)
    try {
      await fetchWrapper.delete(endpoint, {})
      void reviewStateChanged(patientId)
      recordChanged({ resource: RESOURCE, patientId, recordId: condition.id, origin: 'detail' })
      setConfirmingDelete(false)
      onDrill?.({ id: RESOURCE })
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setMutating(null)
    }
  }

  if (notFound) {
    return <PhrRecordNotFound />
  }

  const dialogOpen = editing || confirmingDelete

  return (
    <div className="space-y-4">
      {error && !dialogOpen && (
        <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}
      {busy && !condition && <DetailSkeleton label="Loading condition" facts={4} rows={0} />}
      {condition && (
        <section className="rounded-lg border border-border bg-card p-4">
          <div className="flex items-start gap-1">
            <div className="min-w-0 flex-1">
              <h2 className="text-lg font-semibold text-card-foreground">{condition.name}</h2>
              <p className="mt-1 text-sm text-muted-foreground">Condition #{condition.id}</p>
            </div>
            {canManage && (
              <>
                <Button type="button" size="icon-sm" variant="ghost" className={`text-muted-foreground ${DETAIL_ICON_BUTTON}`} title="Edit" aria-label={`Edit ${condition.name}`} disabled={mutating !== null} onClick={() => { setError(null); setEditing(true) }}>
                  <Pencil className="size-4" aria-hidden="true" />
                </Button>
                <RowActionsMenu
                  label={condition.name}
                  disabled={mutating !== null}
                  triggerClassName={DETAIL_ICON_BUTTON}
                  actions={[{ label: 'Delete', destructive: true, onSelect: () => { setError(null); setConfirmingDelete(true) } }]}
                />
              </>
            )}
          </div>
          <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Clinical status</dt>
              <dd className="text-card-foreground">{detailValue(condition.clinical_status)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Verification</dt>
              <dd className="text-card-foreground">{detailValue(condition.verification_status)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Severity</dt>
              <dd className="text-card-foreground">{detailValue(condition.severity)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Onset date</dt>
              <dd className="text-card-foreground">{detailValue(formatDate(condition.onset_date) || null)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Abated date</dt>
              <dd className="text-card-foreground">{detailValue(formatDate(condition.abated_date) || null)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">ICD-10</dt>
              <dd className="text-card-foreground">{detailValue(condition.icd10_code)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">SNOMED</dt>
              <dd className="text-card-foreground">{detailValue(condition.snomed_code)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Last updated</dt>
              <dd className="text-card-foreground">{detailValue(formatDateTime(condition.updated_at) || null)}</dd>
            </div>
          </dl>
          <div className="mt-4 border-t border-border pt-4">
            <h3 className="text-sm font-medium text-card-foreground">Clinical notes</h3>
            <p className="mt-1 text-sm text-muted-foreground">{detailValue(condition.notes, 'No notes recorded.')}</p>
          </div>

          {editing && (
            <ConditionFormDialog
              condition={condition}
              busy={mutating === 'save'}
              error={error}
              onSubmit={save}
              onClose={() => { setError(null); setEditing(false) }}
            />
          )}
          <ConfirmDeleteDialog
            open={confirmingDelete}
            title="Delete this condition?"
            description={<><strong>{condition.name}</strong> will be removed from this record. You can't undo this.</>}
            busy={mutating === 'delete'}
            error={confirmingDelete ? error : null}
            onConfirm={() => void remove()}
            onCancel={() => { setError(null); setConfirmingDelete(false) }}
          />
        </section>
      )}
    </div>
  )
}
