import { Plus } from 'lucide-react'
import { useMemo, useState } from 'react'

import { RECORD_ONLY_BELOW_MEDIUM, RECORD_ONLY_BELOW_WIDE, type RecordColumn, RecordTable } from '@/components/record-list/RecordTable'
import { RowActionsMenu } from '@/components/record-list/RowActionsMenu'
import { StatusDot } from '@/components/record-list/StatusDot'
import { Button } from '@/components/ui/button'
import { formatDate } from '@/lib/dateFormat'
import { displayUnits } from '@/lib/measureUnits'
import { cn } from '@/lib/utils'
import { useClinicalCrud } from '@/phr/clinical/crud'
import { recordChanged, useRecordChanges } from '@/phr/clinical/recordChanges'
import { ReviewActions } from '@/phr/clinical/review'
import { ReviewDot } from '@/phr/clinical/ReviewDot'
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
import { medicationDoseText, medicationRegimenText, medicationScheduleText } from './medicationRegimen'
import {
  initialMedicationCategory,
  initialMedicationStatus,
  matchesMedicationCategory,
  matchesMedicationStatus,
  medicationCategoryCounts,
  type MedicationCategoryFilter,
  medicationCategoryFilterOf,
  MedicationCategoryFilters,
  medicationCategoryLabel,
  MedicationSectionHeader,
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

/** Where the list's filters are kept in the address, so a reload or a shared link lands on them. */
function listQuery(status: MedicationStatusFilter, list: MedicationCategoryFilter): string {
  const params = new URLSearchParams()
  if (status !== 'active') params.set('status', status)
  if (list !== 'home') params.set('list', list)
  const query = params.toString()
  return query === '' ? '' : `?${query}`
}

function StatusCell({ medication }: { medication: PhrMedication }) {
  return <StatusDot tone={medication.status === 'active' ? 'success' : 'neutral'} label={medicationStatusLabel(medication.status)} />
}

export default function MedicationsPage({ patientId, onDrill, openRecord }: PhrListPageProps) {
  const openRowId = openRecordId(openRecord, 'medication-detail')
  const [statusFilter, setStatusFilter] = useState<MedicationStatusFilter>(initialMedicationStatus)
  const [categoryFilter, setCategoryFilter] = useState<MedicationCategoryFilter>(initialMedicationCategory)
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
    replacePhrLocation(`${window.location.pathname}${listQuery(next, categoryFilter)}`)
  }

  function selectCategory(next: MedicationCategoryFilter): void {
    setCategoryFilter(next)
    replacePhrLocation(`${window.location.pathname}${listQuery(statusFilter, next)}`)
  }

  // A record opened from a link, Back or the palette may be on another tab or list: show its row.
  const openMedication = openRowId === null ? undefined : crud.records.find((medication) => String(medication.id) === openRowId)
  if (openRowId !== seenOpenRowId && (openRowId === null || openMedication)) {
    setSeenOpenRowId(openRowId)
    if (openMedication && !matchesMedicationCategory(openMedication, categoryFilter)) setCategoryFilter(medicationCategoryFilterOf(openMedication))
    if (openMedication && !matchesMedicationStatus(openMedication, statusFilter)) setStatusFilter(matchesMedicationStatus(openMedication, 'active') ? 'active' : 'ended')
  }

  const listed = useMemo(() => crud.records.filter((medication) => matchesMedicationCategory(medication, categoryFilter)), [crud.records, categoryFilter])
  const rows = useMemo(() => listed.filter((medication) => matchesMedicationStatus(medication, statusFilter)), [listed, statusFilter])
  const counts = useMemo(() => medicationStatusCounts(listed), [listed])
  const categoryCounts = useMemo(() => medicationCategoryCounts(crud.records), [crud.records])

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
          <span className="w-2 shrink-0 pt-1.5"><ReviewDot status={medication.review_status} /></span>
          <div className="min-w-0">
            <div className="font-medium text-card-foreground">
              {displayUnits(medication.name)}
              {medicationCategoryLabel(medication) && (
                <span className="ml-2 inline-block rounded-full border border-border px-2 align-middle text-xs font-normal text-muted-foreground">{medicationCategoryLabel(medication)}</span>
              )}
            </div>
            <div className={cn('mt-0.5 text-xs text-muted-foreground empty:hidden', RECORD_ONLY_BELOW_WIDE)}>{medicationRegimenText(medication)}</div>
            {(showsEnded || lastFillOf(medication)) && (
              <div className={cn('mt-0.5 text-xs text-muted-foreground', RECORD_ONLY_BELOW_MEDIUM)}>
                {[showsEnded ? medicationStatusLabel(medication.status) : null, lastFillOf(medication) ? `Last fill ${formatDate(lastFillOf(medication)!.lastFilledOn)}` : null].filter(Boolean).join(' · ')}
              </div>
            )}
          </div>
        </div>
      ),
    },
    { id: 'route', header: 'Route', showFrom: 'wide', headerClassName: HEAD, className: cn(CELL, 'text-muted-foreground'), cell: (medication) => medication.route ?? '—' },
    { id: 'dose', header: 'Dose', showFrom: 'wide', headerClassName: HEAD, className: CELL, cell: (medication) => <span className="inline-block min-w-22">{medicationDoseText(medication) || '—'}</span> },
    { id: 'schedule', header: 'Schedule', showFrom: 'wide', headerClassName: cn(HEAD, 'w-[22%]'), className: cn(CELL, 'text-muted-foreground'), cell: (medication) => medicationScheduleText(medication) ?? '—' },
    { id: 'started', header: 'Started', fit: true, showFrom: 'medium', headerClassName: HEAD, className: cn(CELL, 'tabular-nums'), cell: (medication) => formatDate(medication.started_on) || '—' },
    ...(showsEnded
      ? [{ id: 'ended', header: 'Ended', fit: true, showFrom: 'medium', headerClassName: HEAD, className: cn(CELL, 'tabular-nums'), cell: (medication) => formatDate(medication.ended_on) || '—' } satisfies RecordColumn<PhrMedication>]
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
            {formatDate(summary.lastFilledOn)}
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
      <MedicationSectionHeader
        patientId={patientId}
        value="medications"
        counts={{ fills: fills?.total ?? null, medications: firstLoad ? null : categoryCounts.home }}
        action={crud.canManage ? (
          <Button aria-label="Add medication" className="max-md:size-11 max-md:px-0" onClick={() => setAddForm(EMPTY_MEDICATION_FORM)}>
            <Plus className="size-4" aria-hidden="true" />
            <span className="max-md:hidden">Add medication</span>
          </Button>
        ) : undefined}
      />

      <MedicationCategoryFilters value={categoryFilter} counts={firstLoad ? null : categoryCounts} onSelect={selectCategory} />
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
