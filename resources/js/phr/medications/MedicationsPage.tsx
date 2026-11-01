import { Plus } from 'lucide-react'
import { useMemo, useState } from 'react'

import { RECORD_ONLY_BELOW_MEDIUM, RECORD_ONLY_BELOW_WIDE, type RecordColumn, RecordTable } from '@/components/record-list/RecordTable'
import { RowActionsMenu } from '@/components/record-list/RowActionsMenu'
import { StatusDot } from '@/components/record-list/StatusDot'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useClinicalCrud } from '@/phr/clinical/crud'
import { recordChanged, useRecordChanges } from '@/phr/clinical/recordChanges'
import { ReviewActions } from '@/phr/clinical/review'
import { reviewStatusNeedsAttention } from '@/phr/clinical/ui'
import { useKnownPatientCanManage } from '@/phr/patients/usePhrPatients'
import { replacePhrLocation } from '@/phr/shell/phrLocation'
import { drillOpener, type PhrListPageProps } from '@/phr/shell/phrPages'
import { openRecordId } from '@/phr/shell/phrRoutes'
import {
  type PhrMedication,
  PhrMedicationResponseSchema,
  PhrMedicationsResponseSchema,
} from '@/phr/types'

import {
  EMPTY_MEDICATION_FORM,
  MedicationFormDialog,
  medicationFormFromMedication,
  type MedicationFormState,
  medicationPayload,
  medicationStatusLabel,
} from './medicationForm'
import {
  initialMedicationStatus,
  matchesMedicationStatus,
  MedicationSectionTabs,
  medicationStatusCounts,
  type MedicationStatusFilter,
  MedicationStatusFilters,
  useFillOverview,
} from './medicationSection'

const EMPTY_TEXT: Record<MedicationStatusFilter, string> = {
  active: 'No active medications.',
  ended: 'No ended medications.',
  all: 'No medications.',
}

const HEAD = 'px-3 py-2.5 font-medium'
const CELL = 'px-3 py-2.5'

function doseText(medication: PhrMedication): string {
  return [medication.dose, medication.dose_unit].filter(Boolean).join(' ')
}

function regimen(medication: PhrMedication): string {
  return [doseText(medication), medication.route, medication.frequency].filter(Boolean).join(' · ')
}

function statusAndReview(medication: PhrMedication): string {
  const status = medicationStatusLabel(medication.status)
  if (medication.review_status === 'pending_review') return `${status} · pending`
  return medication.review_status === 'rejected' ? `${status} · rejected` : status
}

/** A review status where the column has no room: the dot alone, named for a screen reader. */
function ReviewDot({ medication }: { medication: PhrMedication }) {
  if (!reviewStatusNeedsAttention(medication.review_status)) return null
  const pending = medication.review_status === 'pending_review'
  return <span role="img" aria-label={pending ? 'Pending review' : 'Rejected'} className={cn('block size-2 rounded-full', pending ? 'bg-warning' : 'bg-muted-foreground')} />
}

function StatusCell({ medication }: { medication: PhrMedication }) {
  const pending = medication.review_status === 'pending_review'
  const tone = pending ? 'warning' : medication.status === 'active' ? 'success' : 'neutral'
  return <StatusDot tone={tone} label={statusAndReview(medication)} />
}

export default function MedicationsPage({ patientId, onDrill, openRecord }: PhrListPageProps) {
  const openRowId = openRecordId(openRecord, 'medication-detail')
  const [statusFilter, setStatusFilter] = useState<MedicationStatusFilter>(initialMedicationStatus)
  const [addForm, setAddForm] = useState<MedicationFormState | null>(null)
  const [seenOpenRowId, setSeenOpenRowId] = useState<string | null>(null)

  const crud = useClinicalCrud<PhrMedication, MedicationFormState>({
    endpoint: `/api/phr/patients/${patientId}/medications`,
    emptyForm: EMPTY_MEDICATION_FORM,
    formFromRecord: medicationFormFromMedication,
    parseItem: (raw) => PhrMedicationResponseSchema.parse(raw).medication,
    parseList: (raw) => {
      const parsed = PhrMedicationsResponseSchema.parse(raw)
      return { records: parsed.medications, canManage: parsed.can_manage }
    },
    payloadFromForm: medicationPayload,
    initialCanManage: useKnownPatientCanManage(patientId),
    onRecordChanged: (recordId) => recordChanged({ resource: 'medications', patientId, recordId, origin: 'list' }),
  })
  useRecordChanges('medications', patientId, 'detail', () => void crud.reload())
  // Skeleton rows on the first load only; a refetch keeps the current rows on screen.
  const firstLoad = crud.busy && crud.records.length === 0
  const fills = useFillOverview(patientId)

  function selectStatus(next: MedicationStatusFilter): void {
    setStatusFilter(next)
    // In the address, so a reload or a shared link lands on the same filter; an open record stays open.
    replacePhrLocation(`${window.location.pathname}${next === 'active' ? '' : `?status=${next}`}`)
  }

  // A record opened from a link, Back or the palette may be on another tab: show its row.
  const openMedication = openRowId === null ? undefined : crud.records.find((medication) => String(medication.id) === openRowId)
  if (openRowId !== seenOpenRowId && (openRowId === null || openMedication)) {
    setSeenOpenRowId(openRowId)
    if (openMedication && !matchesMedicationStatus(openMedication, statusFilter)) setStatusFilter(matchesMedicationStatus(openMedication, 'active') ? 'active' : 'ended')
  }

  const rows = useMemo(() => crud.records.filter((medication) => matchesMedicationStatus(medication, statusFilter)), [crud.records, statusFilter])
  const counts = useMemo(() => medicationStatusCounts(crud.records), [crud.records])

  async function addMedication(form: MedicationFormState): Promise<void> {
    if (!form.name.trim()) {
      crud.setError('Name is required.')
      return
    }
    if ((await crud.addRecord(form)) !== null) setAddForm(null)
  }

  async function saveEdit(medicationId: number): Promise<void> {
    if (!crud.editForm.name.trim()) {
      crud.setError('Name is required.')
      return
    }
    await crud.saveEdit(medicationId)
  }

  const busyFor = (medication: PhrMedication): boolean => (
    crud.isMutating(`save:${medication.id}`) || crud.isMutating(`delete:${medication.id}`) || crud.isMutating(`review:${medication.id}`)
  )

  const showsEnded = statusFilter !== 'active'
  const lastFillOf = (medication: PhrMedication) => fills?.byMedication.get(medication.id)
  const columns: RecordColumn<PhrMedication>[] = [
    {
      id: 'medication',
      header: 'Medication',
      headerClassName: cn(HEAD, 'w-[29%]'),
      className: CELL,
      cell: (medication) => (
        <div className="flex gap-2">
          <span className={cn('w-2 shrink-0 pt-1.5', RECORD_ONLY_BELOW_MEDIUM)}><ReviewDot medication={medication} /></span>
          <div className="min-w-0">
            <div className="font-medium text-card-foreground">{medication.name}</div>
            <div className={cn('mt-0.5 text-xs text-muted-foreground empty:hidden', RECORD_ONLY_BELOW_WIDE)}>{regimen(medication)}</div>
            {(showsEnded || lastFillOf(medication)) && (
              <div className={cn('mt-0.5 text-xs text-muted-foreground', RECORD_ONLY_BELOW_MEDIUM)}>
                {[showsEnded ? medicationStatusLabel(medication.status) : null, lastFillOf(medication) ? `Last fill ${lastFillOf(medication)!.lastFilledOn}` : null].filter(Boolean).join(' · ')}
              </div>
            )}
          </div>
        </div>
      ),
    },
    { id: 'dose', header: 'Dose', showFrom: 'wide', headerClassName: HEAD, className: CELL, cell: (medication) => <span className="inline-block min-w-22">{doseText(medication) || '—'}</span> },
    { id: 'route', header: 'Route', showFrom: 'wide', headerClassName: HEAD, className: cn(CELL, 'text-muted-foreground'), cell: (medication) => medication.route ?? '—' },
    { id: 'schedule', header: 'Schedule', showFrom: 'wide', headerClassName: cn(HEAD, 'w-[22%]'), className: cn(CELL, 'text-muted-foreground'), cell: (medication) => medication.frequency ?? '—' },
    { id: 'started', header: 'Started', fit: true, showFrom: 'medium', headerClassName: HEAD, className: cn(CELL, 'tabular-nums'), cell: (medication) => medication.started_on ?? '—' },
    ...(showsEnded
      ? [{ id: 'ended', header: 'Ended', fit: true, showFrom: 'medium', headerClassName: HEAD, className: cn(CELL, 'tabular-nums'), cell: (medication) => medication.ended_on ?? '—' } satisfies RecordColumn<PhrMedication>]
      : []),
    {
      id: 'last-fill',
      header: 'Last fill',
      fit: true,
      showFrom: 'medium',
      headerClassName: HEAD,
      className: cn(CELL, 'tabular-nums'),
      cell: (medication) => {
        const summary = lastFillOf(medication)
        if (!summary) return '—'
        return (
          <>
            {summary.lastFilledOn}
            <span className="block text-xs text-muted-foreground">{summary.count} {summary.count === 1 ? 'fill' : 'fills'}</span>
          </>
        )
      },
    },
    { id: 'prescriber', header: 'Prescriber', showFrom: 'wide', headerClassName: HEAD, className: cn(CELL, 'text-muted-foreground'), cell: (medication) => <span className="inline-block min-w-24">{medication.prescriber_name ?? '—'}</span> },
    { id: 'status', header: 'Status', fit: true, showFrom: 'medium', headerClassName: HEAD, className: CELL, cell: (medication) => <StatusCell medication={medication} /> },
  ]
  if (crud.canManage) {
    columns.push({
      id: 'actions',
      header: <span className="sr-only">Actions</span>,
      fit: true,
      showFrom: 'wide',
      headerClassName: HEAD,
      className: 'px-2 py-1.5',
      cell: (medication) => (
        <div className="flex justify-end gap-1">
          <ReviewActions
            status={medication.review_status}
            label={medication.name}
            busy={crud.isMutating(`review:${medication.id}`)}
            disabled={busyFor(medication)}
            onReview={(decision) => void crud.reviewRecord(medication.id, decision)}
          />
          <RowActionsMenu
            label={medication.name}
            disabled={busyFor(medication)}
            actions={[
              { label: 'Edit', onSelect: () => crud.startEdit(medication) },
              { label: 'Delete', destructive: true, onSelect: () => crud.startDelete(medication.id) },
            ]}
          />
        </div>
      ),
    })
  }

  const editing = crud.editingId === null ? undefined : crud.records.find((medication) => medication.id === crud.editingId)

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <h1 className="text-2xl font-semibold text-foreground">Medications</h1>
        {crud.canManage && (
          <Button aria-label="Add medication" className="ml-auto max-md:size-11 max-md:px-0" onClick={() => setAddForm(EMPTY_MEDICATION_FORM)}>
            <Plus className="size-4" aria-hidden="true" />
            <span className="max-md:hidden">Add medication</span>
          </Button>
        )}
      </div>

      <MedicationSectionTabs patientId={patientId} value="medications" counts={{ fills: fills?.total ?? null, medications: firstLoad ? null : crud.records.length }} />

      <MedicationStatusFilters value={statusFilter} counts={firstLoad ? null : counts} onSelect={selectStatus} />

      {crud.error && !addForm && !editing && (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {crud.error}
        </div>
      )}

      {addForm && (
        <MedicationFormDialog
          title="Add medication"
          submitLabel="Add"
          form={addForm}
          onChange={setAddForm}
          busy={crud.isMutating('add')}
          error={crud.error}
          onSubmit={() => void addMedication(addForm)}
          onClose={() => { crud.setError(null); setAddForm(null) }}
        />
      )}
      {editing && (
        <MedicationFormDialog
          title={`Edit ${editing.name}`}
          submitLabel="Save"
          form={crud.editForm}
          onChange={crud.setEditForm}
          busy={crud.isMutating(`save:${editing.id}`)}
          error={crud.error}
          onSubmit={() => void saveEdit(editing.id)}
          onClose={() => { crud.setError(null); crud.cancelEdit() }}
        />
      )}

      {!crud.busy && crud.records.length === 0 ? (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          No medications recorded.
        </div>
      ) : (
        <section className="rounded-lg border border-border bg-card">
          <RecordTable
            label="Medications"
            loading={firstLoad}
            loadingLabel="Loading medications"
            rows={rows}
            rowId={(medication) => String(medication.id)}
            columns={columns}
            openRowId={openRowId}
            onOpen={drillOpener(onDrill, (medication: PhrMedication) => ({ id: 'medication-detail', instance: String(medication.id) }))}
            empty={<div className="px-4 py-8 text-sm text-muted-foreground">{EMPTY_TEXT[statusFilter]}</div>}
            tableClassName="min-w-full divide-y divide-border text-sm"
            headClassName="text-left text-muted-foreground"
            bodyClassName="divide-y divide-border"
            rowClassName="align-top hover:bg-muted/30"
            renderAfterRow={(medication, colSpan) => {
              if (crud.deletingId !== medication.id) return null
              const deleting = crud.isMutating(`delete:${medication.id}`)
              return (
                <tr>
                  <td colSpan={colSpan} className="bg-destructive/5 px-4 py-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                      <p className="text-sm text-foreground">
                        Delete <strong>{medication.name}</strong>? This cannot be undone.
                      </p>
                      <div className="flex gap-2">
                        <Button variant="destructive" size="sm" disabled={deleting} onClick={() => void crud.deleteRecord(medication.id)}>
                          {deleting ? 'Deleting…' : 'Delete'}
                        </Button>
                        <Button type="button" variant="outline" size="sm" disabled={deleting} onClick={crud.cancelDelete}>
                          Cancel
                        </Button>
                      </div>
                    </div>
                  </td>
                </tr>
              )
            }}
          />
        </section>
      )}
    </div>
  )
}
