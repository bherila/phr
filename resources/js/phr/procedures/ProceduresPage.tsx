import { Info, Pencil, Plus, Scissors, Trash2 } from 'lucide-react'
import type { FormEvent } from 'react'
import { useState } from 'react'

import { RecordList } from '@/components/record-list/RecordList'
import { RECORD_ONLY_BELOW_WIDE } from '@/components/record-list/RecordTable'
import { SkeletonBlock } from '@/components/record-list/Skeleton'
import type { StatusTone } from '@/components/record-list/StatusDot'
import { Button } from '@/components/ui/button'
import { formatDate, formatDateTime } from '@/lib/dateFormat'
import { useClinicalCrud } from '@/phr/clinical/crud'
import { recordChanged, useRecordChanges } from '@/phr/clinical/recordChanges'
import { ReviewActions, ShowRejectedToggle } from '@/phr/clinical/review'
import { codeChip, labelize, statusBadge } from '@/phr/clinical/ui'
import { TimelineReviewStatus } from '@/phr/office-visits/TimelineReviewStatus'
import { useKnownPatientCanManage } from '@/phr/patients/usePhrPatients'
import { zodErrorMessage } from '@/phr/shared'
import { drillOpener, type PhrListPageProps } from '@/phr/shell/phrPages'
import { openRecordId } from '@/phr/shell/phrRoutes'
import {
  type PhrProcedure,
  type PhrProcedureFormData,
  PhrProcedureFormSchema,
  PhrProcedureResponseSchema,
  PhrProceduresResponseSchema,
} from '@/phr/types'

import { EMPTY_FORM, ProcedureFormFields, procedureFormFromRecord, procedurePayload } from './procedureForm'

const STATUS_TONE: Record<string, StatusTone> = {
  preparation: 'neutral',
  in_progress: 'warning',
  completed: 'success',
  cancelled: 'neutral',
  entered_in_error: 'danger',
}

interface AddFormProps {
  busy: boolean
  onSubmit: (form: PhrProcedureFormData) => Promise<boolean>
}

function sortProcedures(procedures: PhrProcedure[]): PhrProcedure[] {
  return [...procedures].sort((left, right) => {
    const leftDate = left.performed_at ?? left.performed_on ?? ''
    const rightDate = right.performed_at ?? right.performed_on ?? ''
    const dateCompare = rightDate.localeCompare(leftDate)
    if (dateCompare !== 0) {
      return dateCompare
    }

    return right.id - left.id
  })
}

function displayProcedureDate(procedure: PhrProcedure): string {
  if (procedure.performed_at) {
    return formatDateTime(procedure.performed_at)
  }

  return formatDate(procedure.performed_on) || 'Date not recorded'
}

function AddForm({ busy, onSubmit }: AddFormProps) {
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<PhrProcedureFormData>(EMPTY_FORM)

  async function handleSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    const added = await onSubmit(form)
    if (added) {
      setForm(EMPTY_FORM)
      setOpen(false)
    }
  }

  if (!open) {
    return (
      <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
        <Plus className="size-4" />
        Add Procedure
      </Button>
    )
  }

  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <h2 className="mb-3 text-sm font-semibold text-card-foreground">Add Procedure</h2>
      <form onSubmit={(event) => void handleSubmit(event)} className="space-y-3">
        <ProcedureFormFields form={form} onChange={setForm} />
        <div className="flex gap-2">
          <Button type="submit" size="sm" disabled={busy}>{busy ? 'Adding...' : 'Add'}</Button>
          <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => setOpen(false)}>Cancel</Button>
        </div>
      </form>
    </div>
  )
}

export default function ProceduresPage({ patientId, onDrill, openRecord }: PhrListPageProps) {
  const endpoint = `/api/phr/patients/${patientId}/procedures`
  const knownCanManage = useKnownPatientCanManage(patientId)
  const crud = useClinicalCrud<PhrProcedure, PhrProcedureFormData>({
    endpoint,
    emptyForm: EMPTY_FORM,
    formFromRecord: procedureFormFromRecord,
    parseItem: (raw) => PhrProcedureResponseSchema.parse(raw).procedure,
    parseList: (raw) => {
      const parsed = PhrProceduresResponseSchema.parse(raw)
      return { records: parsed.procedures, canManage: parsed.can_manage }
    },
    payloadFromForm: procedurePayload,
    initialCanManage: knownCanManage,
    sortRecords: sortProcedures,
    onRecordChanged: (recordId) => recordChanged({ resource: 'procedures', patientId, recordId, origin: 'list' }),
  })
  useRecordChanges('procedures', patientId, 'detail', () => { void crud.reload() })
  // Skeleton rows on the first load only; a refetch keeps the current rows on screen.
  const firstLoad = crud.busy && crud.records.length === 0

  async function addProcedure(form: PhrProcedureFormData): Promise<boolean> {
    const parsed = PhrProcedureFormSchema.safeParse(form)
    if (!parsed.success) {
      crud.setError(zodErrorMessage(parsed.error))
      return false
    }

    return (await crud.addRecord(parsed.data)) !== null
  }

  async function saveProcedure(procedureId: number): Promise<void> {
    const parsed = PhrProcedureFormSchema.safeParse(crud.editForm)
    if (!parsed.success) {
      crud.setError(zodErrorMessage(parsed.error))
      return
    }

    const updated = await crud.patchRecord(procedureId, procedurePayload(parsed.data))
    if (updated) {
      crud.cancelEdit()
    }
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
            <Scissors className="size-6 text-primary" />
            Procedures
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">Timeline of procedures, operations, and office procedures.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <ShowRejectedToggle showRejected={crud.showRejected} onChange={crud.setShowRejected} disabled={crud.busy} />
        </div>
      </div>

      {crud.error && (
        <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {crud.error}
        </div>
      )}

      {crud.canManage && (
        <div className="mb-6 grid gap-3">
          <div className="flex flex-wrap items-start gap-2">
            <AddForm busy={crud.isMutating('add')} onSubmit={addProcedure} />
          </div>
          <div className="flex items-start gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
            <Info className="mt-0.5 size-4 shrink-0" />
            Procedures are typically imported through CCDA or FHIR record imports. Use Documents for source files that need review.
          </div>
        </div>
      )}

      {!crud.busy && crud.records.length === 0 && (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          No procedures recorded.
        </div>
      )}

      {(firstLoad || crud.records.length > 0) && (
        <RecordList
          loading={firstLoad}
          skeletonRows={5}
          skeletonItem={<div className="rounded-lg border border-border bg-card px-4 py-3"><SkeletonBlock className="w-1/3 text-sm" /><SkeletonBlock className="mt-1 w-2/3" /></div>}
          label="Procedures"
          as="ol"
          className="@container/record-list relative min-w-0 space-y-3 border-l border-border pl-5"
          itemClassName="relative"
          rows={crud.records}
          rowId={(procedure) => String(procedure.id)}
          openRowId={openRecordId(openRecord, 'procedure-detail')}
          onOpen={drillOpener(onDrill, (procedure: PhrProcedure) => ({ id: 'procedure-detail', instance: String(procedure.id) }))}
          renderItem={(procedure) => {
            const isEditing = crud.editingId === procedure.id
            const isDeleting = crud.deletingId === procedure.id
            const isSaving = crud.isMutating(`save:${procedure.id}`)
            const isDeletingBusy = crud.isMutating(`delete:${procedure.id}`)

            return (
              <>
                <span className="absolute -left-[1.65rem] top-4 size-3 rounded-full border-2 border-background bg-primary" />
                <div className="min-w-0 rounded-lg border border-border bg-card wrap-anywhere transition-colors hover:bg-muted/30 in-data-[open]:bg-accent/60">
                  <div className="grid min-w-0 grid-cols-1 gap-1 px-3 py-3 @[560px]/record-list:px-4 @[960px]/record-list:grid-cols-[150px_minmax(0,1fr)_auto] @[960px]/record-list:items-start @[960px]/record-list:gap-3">
                    <div className="hidden text-sm font-medium text-muted-foreground @[960px]/record-list:block">{displayProcedureDate(procedure)}</div>
                    <div className="min-w-0">
                      <div className="flex min-w-0 items-start justify-between gap-2"><span className="min-w-0 font-medium text-card-foreground">{procedure.name}</span><TimelineReviewStatus status={procedure.review_status} /></div>
                      <p className={`mt-1 text-sm text-muted-foreground ${RECORD_ONLY_BELOW_WIDE}`}>
                        {[displayProcedureDate(procedure), labelize(procedure.status), procedure.performer_name].filter(Boolean).join(' · ')}<span className="hidden @[560px]/record-list:inline">{procedure.facility_name && ` · ${procedure.facility_name}`}</span>
                      </p>
                      <div className="mt-1 hidden flex-wrap items-center gap-1.5 @[960px]/record-list:flex">
                        <span className="hidden @[960px]/record-list:inline">{codeChip('CPT', procedure.cpt_code)}</span>
                        <span className="hidden @[960px]/record-list:inline">{codeChip('SNOMED', procedure.snomed_code)}</span>
                        {statusBadge(procedure.status, STATUS_TONE)}

                      </div>
                      <div className="mt-2 hidden text-sm text-muted-foreground @[960px]/record-list:block">
                        {[
                          procedure.performer_name,
                          procedure.performer_specialty,
                          procedure.facility_name,
                        ].filter(Boolean).join(' · ') || 'No performer or facility recorded'}
                      </div>
                      {(procedure.reason || procedure.outcome || procedure.notes) && (
                        <div className="mt-2 hidden gap-1 text-xs text-muted-foreground @[960px]/record-list:grid">
                          {procedure.reason && <p><span className="font-medium text-foreground">Reason:</span> {procedure.reason}</p>}
                          {procedure.outcome && <p><span className="font-medium text-foreground">Outcome:</span> {procedure.outcome}</p>}
                          {procedure.notes && <p>{procedure.notes}</p>}
                        </div>
                      )}
                    </div>
                    {crud.canManage && (
                      <div className="hidden justify-end gap-2 @[960px]/record-list:flex">
                        <ReviewActions
                          status={procedure.review_status}
                          label={procedure.name}
                          busy={crud.isMutating(`review:${procedure.id}`)}
                          disabled={isSaving || isDeletingBusy}
                          onReview={(decision) => void crud.reviewRecord(procedure.id, decision)}
                        />
                        <Button
                          type="button"
                          size="icon-sm"
                          variant="ghost"
                          title={`Edit ${procedure.name}`}
                          aria-label={`Edit ${procedure.name}`}
                          disabled={isSaving || isDeletingBusy}
                          onClick={() => crud.startEdit(procedure)}
                        >
                          <Pencil className="size-4" />
                        </Button>
                        <Button
                          type="button"
                          size="icon-sm"
                          variant="ghost"
                          className="text-destructive hover:text-destructive"
                          title={`Delete ${procedure.name}`}
                          aria-label={`Delete ${procedure.name}`}
                          disabled={isSaving || isDeletingBusy}
                          onClick={() => crud.startDelete(procedure.id)}
                        >
                          <Trash2 className="size-4" />
                        </Button>
                      </div>
                    )}
                  </div>
                  {isEditing && (
                    <div className="border-t border-border bg-muted/20 px-4 py-4">
                      <form
                        className="space-y-3"
                        onSubmit={(event) => {
                          event.preventDefault()
                          void saveProcedure(procedure.id)
                        }}
                      >
                        <ProcedureFormFields form={crud.editForm} onChange={crud.setEditForm} />
                        <div className="flex gap-2">
                          <Button type="submit" size="sm" disabled={isSaving}>{isSaving ? 'Saving...' : 'Save'}</Button>
                          <Button type="button" variant="outline" size="sm" disabled={isSaving} onClick={crud.cancelEdit}>Cancel</Button>
                        </div>
                      </form>
                    </div>
                  )}
                  {isDeleting && (
                    <div className="border-t border-border bg-destructive/5 px-4 py-4">
                      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-foreground">
                          Delete <strong>{procedure.name}</strong>? This cannot be undone.
                        </p>
                        <div className="flex gap-2">
                          <Button variant="destructive" size="sm" disabled={isDeletingBusy} onClick={() => void crud.deleteRecord(procedure.id)}>
                            {isDeletingBusy ? 'Deleting...' : 'Delete'}
                          </Button>
                          <Button type="button" variant="outline" size="sm" disabled={isDeletingBusy} onClick={crud.cancelDelete}>Cancel</Button>
                        </div>
                      </div>
                    </div>
                  )}
                </div>
              </>
            )
          }}
        />
      )}
    </div>
  )
}
