import { Pencil } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'

import { RowActionsMenu } from '@/components/record-list/RowActionsMenu'
import { DetailSkeleton } from '@/components/record-list/Skeleton'
import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { formatDate, formatDateTime } from '@/lib/dateFormat'
import { ConfirmDeleteDialog } from '@/phr/clinical/ConfirmDeleteDialog'
import EncounterEobLinks from '@/phr/clinical/EncounterEobLinks'
import { recordChanged, useRecordChanges } from '@/phr/clinical/recordChanges'
import { DETAIL_ICON_BUTTON, RecordReviewBar } from '@/phr/clinical/RecordDetailParts'
import type { PhrReviewDecision } from '@/phr/clinical/review'
import { recordReviewDecision } from '@/phr/clinical/reviewDecision'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { reviewStateChanged } from '@/phr/review/reviewSummary'
import { errorMessage, fetchPhrDetail, zodErrorMessage } from '@/phr/shared'
import type { PhrDetailPageProps } from '@/phr/shell/phrPages'
import { PhrRecordNotFound } from '@/phr/shell/PhrRecordNotFound'
import { type PhrProcedure, PhrProcedureFormSchema, PhrProcedureResponseSchema } from '@/phr/types'

import { EMPTY_FORM, ProcedureFormFields, procedureFormFromRecord, procedurePayload } from './procedureForm'

function detailValue(value: string | null | undefined, fallback = 'Not recorded'): string {
  return value && value.trim().length > 0 ? value : fallback
}

export default function ProcedureDetail({ patientId, recordId, onDrill }: PhrDetailPageProps) {
  const [procedure, setProcedure] = useState<PhrProcedure | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [canManage, setCanManage] = useState(false)

  const endpoint = `/api/phr/patients/${patientId}/procedures/${recordId}`
  const selectedEndpoint = useRef(endpoint)
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [confirmingDelete, setConfirmingDelete] = useState(false)
  const [mutating, setMutating] = useState<'save' | 'delete' | 'review' | null>(null)

  const load = useCallback(async (cancelled: () => boolean = () => false): Promise<void> => {
    setBusy(true)
    setError(null)
    try {
      const result = await fetchPhrDetail(endpoint, PhrProcedureResponseSchema)
      if (cancelled() || selectedEndpoint.current !== endpoint) return
      setProcedure(result.data?.procedure ?? null)
      setCanManage(result.data?.can_manage ?? false)
      setNotFound(result.notFound)
    } catch (caught) {
      if (!cancelled() && selectedEndpoint.current === endpoint) setError(errorMessage(caught))
    } finally {
      if (!cancelled() && selectedEndpoint.current === endpoint) setBusy(false)
    }
  }, [endpoint])

  useEffect(() => {
    let cancelled = false
    selectedEndpoint.current = endpoint
    setProcedure(null)
    setCanManage(false)
    setNotFound(false)
    setEditing(false)
    setConfirmingDelete(false)
    setMutating(null)
    void load(() => cancelled)
    return () => { cancelled = true }
  }, [endpoint, load])

  useRecordChanges('procedures', patientId, 'list', (changedId) => {
    if (String(changedId) === recordId) void load()
  })

  async function change(kind: 'save' | 'delete' | 'review', decision?: PhrReviewDecision): Promise<void> {
    if (!procedure || !canManage || mutating !== null) return
    const parsed = kind === 'save' ? PhrProcedureFormSchema.safeParse(form) : null
    if (parsed && !parsed.success) { setError(zodErrorMessage(parsed.error)); return }
    setMutating(kind)
    setError(null)
    try {
      if (kind === 'delete') {
        await fetchWrapper.delete(endpoint, {})
        void reviewStateChanged(patientId)
      } else {
        const raw: unknown = kind === 'review' && decision
          ? await recordReviewDecision(endpoint, decision)
          : await fetchWrapper.patch(endpoint, procedurePayload(parsed?.success ? parsed.data : form))
        if (selectedEndpoint.current === endpoint) {
          setProcedure(PhrProcedureResponseSchema.parse(raw).procedure)
          setEditing(false)
        }
      }
      recordChanged({ resource: 'procedures', patientId, recordId: procedure.id, origin: 'detail' })
      if (kind === 'delete' && selectedEndpoint.current === endpoint) {
        setConfirmingDelete(false)
        setProcedure(null)
        setNotFound(true)
        onDrill?.({ id: 'procedures' })
      }
    } catch (caught) {
      if (selectedEndpoint.current === endpoint) setError(errorMessage(caught))
    } finally {
      if (selectedEndpoint.current === endpoint) setMutating(null)
    }
  }

  if (notFound) {
    return <PhrRecordNotFound />
  }

  return (
    <div className="@container flex min-w-0 flex-col gap-4 wrap-anywhere">
      {error && !confirmingDelete && (
        <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}
      {busy && !procedure && <DetailSkeleton label="Loading procedure" facts={4} rows={0} />}
      {procedure && (
        <>
          <section className="min-w-0 rounded-lg border border-border bg-card p-4">
            {canManage && (
              <div className="mb-2 flex flex-wrap items-center justify-end gap-1">
                <Button type="button" size="icon-sm" variant="ghost" className={DETAIL_ICON_BUTTON} aria-label={`Edit ${procedure.name}`} disabled={busy || mutating !== null} onClick={() => { setForm(procedureFormFromRecord(procedure)); setError(null); setEditing(true) }}>
                  <Pencil className="size-4" aria-hidden="true" />
                </Button>
                <RowActionsMenu label={procedure.name} disabled={busy || mutating !== null} triggerClassName={DETAIL_ICON_BUTTON} actions={[{ label: 'Delete', destructive: true, onSelect: () => { setError(null); setConfirmingDelete(true) } }]} />
              </div>
            )}
            {editing && (
              <form aria-label="Edit procedure" className="mb-4 space-y-3 rounded-lg border border-border p-3" onSubmit={(event) => { event.preventDefault(); void change('save') }}>
                <ProcedureFormFields form={form} onChange={setForm} />
                <div className="flex flex-wrap gap-2">
                  <Button type="submit" disabled={mutating !== null}>{mutating === 'save' ? 'Saving…' : 'Save'}</Button>
                  <Button type="button" variant="outline" disabled={mutating !== null} onClick={() => { setEditing(false); setError(null) }}>Cancel</Button>
                </div>
              </form>
            )}
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="text-lg font-semibold text-card-foreground">{procedure.name}</h2>
              {reviewStatusBadge(procedure.review_status)}
            </div>
            <p className="mt-1 text-sm text-muted-foreground">Procedure #{procedure.id}</p>
            <dl className="mt-4 grid gap-3 text-sm @[560px]:grid-cols-2">
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Performed at</dt>
                <dd className="text-card-foreground">{detailValue(formatDateTime(procedure.performed_at) || null)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Performed on</dt>
                <dd className="text-card-foreground">{detailValue(formatDate(procedure.performed_on) || null)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Provider</dt>
                <dd className="text-card-foreground">{detailValue(procedure.performer_name)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Specialty</dt>
                <dd className="text-card-foreground">{detailValue(procedure.performer_specialty)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Facility</dt>
                <dd className="text-card-foreground">{detailValue(procedure.facility_name)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Status</dt>
                <dd className="text-card-foreground">{detailValue(procedure.status)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">CPT</dt>
                <dd className="text-card-foreground">{detailValue(procedure.cpt_code)}</dd>
              </div>
              <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">SNOMED</dt>
                <dd className="text-card-foreground">{detailValue(procedure.snomed_code)}</dd>
              </div>
            </dl>
            <div className="mt-4 grid gap-3 border-t border-border pt-4 text-sm">
              <div>
                <h3 className="font-medium text-card-foreground">Reason</h3>
                <p className="mt-1 text-muted-foreground">{detailValue(procedure.reason, 'No reason documented.')}</p>
              </div>
              <div>
                <h3 className="font-medium text-card-foreground">Outcome / complications</h3>
                <p className="mt-1 text-muted-foreground">{detailValue(procedure.outcome, 'No complications documented.')}</p>
              </div>
              <div>
                <h3 className="font-medium text-card-foreground">Notes</h3>
                <p className="mt-1 text-muted-foreground">{detailValue(procedure.notes, 'No notes recorded.')}</p>
              </div>
            </div>
          </section>
          {canManage && <RecordReviewBar status={procedure.review_status} label={procedure.name} busy={mutating !== null} disabled={busy} onReview={(decision) => { void change('review', decision) }} />}
          <ConfirmDeleteDialog open={confirmingDelete} title="Delete this procedure?" description={<>Delete <strong>{procedure.name}</strong>? This cannot be undone.</>} busy={mutating === 'delete'} error={error} onConfirm={() => { void change('delete') }} onCancel={() => { setConfirmingDelete(false); setError(null) }} />
          <EncounterEobLinks
            key={`${patientId}-${recordId}`}
            patientId={patientId}
            recordType="procedures"
            recordId={recordId}
            serviceDate={(procedure.performed_at ?? procedure.performed_on)?.slice(0, 10) ?? null}
            eobs={procedure.eobs}
            canManage={canManage}
            onChange={(eobs) => setProcedure((current) => current ? { ...current, eobs } : current)}
          />
        </>
      )}
    </div>
  )
}
