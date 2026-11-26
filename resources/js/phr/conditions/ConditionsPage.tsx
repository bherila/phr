import { Activity, CheckCircle2, ChevronDown, ChevronRight, Pencil, Plus, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'

import { type RecordColumn, RecordTable } from '@/components/record-list/RecordTable'
import { Button } from '@/components/ui/button'
import { formatDate } from '@/lib/dateFormat'
import { ConfirmDeleteDialog } from '@/phr/clinical/ConfirmDeleteDialog'
import { useClinicalCrud } from '@/phr/clinical/crud'
import { recordChanged, useRecordChanges } from '@/phr/clinical/recordChanges'
import { type PhrReviewDecision, ReviewActions, ShowRejectedToggle } from '@/phr/clinical/review'
import { classBadge, codeChip, labelize, reviewStatusBadge } from '@/phr/clinical/ui'
import { useKnownPatientCanManage } from '@/phr/patients/usePhrPatients'
import { compactPayload } from '@/phr/shared'
import { drillOpener, type PhrListPageProps } from '@/phr/shell/phrPages'
import { openRecordId } from '@/phr/shell/phrRoutes'
import {
  type PhrCondition,
  type PhrConditionFormData,
  PhrConditionResponseSchema,
  PhrConditionsResponseSchema,
} from '@/phr/types'

import { ConditionFormDialog, conditionFormFromRecord, conditionPayload, EMPTY_CONDITION_FORM } from './conditionForm'

const STATUS_CLASS: Record<string, string> = {
  active: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300',
  recurrence: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300',
  relapse: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300',
  inactive: 'bg-muted text-muted-foreground',
  remission: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300',
  resolved: 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-300',
}

interface ConditionsTableProps {
  loading?: boolean
  title: string
  description: string
  conditions: PhrCondition[]
  emptyMessage: string
  canManage: boolean
  onStartEdit: (condition: PhrCondition) => void
  onStartDelete: (conditionId: number) => void
  onMarkResolved: (condition: PhrCondition) => Promise<void>
  onReview: (conditionId: number, decision: PhrReviewDecision) => void
  isMutating: (key: string) => boolean
  onDrill?: PhrListPageProps['onDrill']
  openRowId: string | null
}

function sortConditions(conditions: PhrCondition[]): PhrCondition[] {
  return [...conditions].sort((left, right) => {
    const statusCompare = conditionStatusOrder(left) - conditionStatusOrder(right)
    if (statusCompare !== 0) {
      return statusCompare
    }

    const dateCompare = (right.onset_date ?? '').localeCompare(left.onset_date ?? '')
    if (dateCompare !== 0) {
      return dateCompare
    }

    return right.id - left.id
  })
}

function conditionStatusOrder(condition: PhrCondition): number {
  if (['active', 'recurrence', 'relapse'].includes(condition.clinical_status)) {
    return 0
  }

  if (condition.clinical_status === 'remission') {
    return 1
  }

  return 2
}

function todayDateString(): string {
  const today = new Date()
  const year = String(today.getFullYear())
  const month = String(today.getMonth() + 1).padStart(2, '0')
  const day = String(today.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}

function ConditionsTable({
  loading = false,
  title,
  description,
  conditions,
  emptyMessage,
  canManage,
  onStartEdit,
  onStartDelete,
  onMarkResolved,
  onReview,
  isMutating,
  onDrill,
  openRowId,
}: ConditionsTableProps) {
  const busyFor = (condition: PhrCondition): boolean => (
    isMutating(`resolve:${condition.id}`) || isMutating(`save:${condition.id}`) || isMutating(`delete:${condition.id}`)
  )
  const columns: RecordColumn<PhrCondition>[] = [
    {
      id: 'condition',
      header: 'Condition',
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3',
      cell: (condition) => (
        <>
          <div className="font-medium text-card-foreground">{condition.name}</div>
          <div className="mt-2 flex flex-wrap gap-1.5">
            {codeChip('ICD-10', condition.icd10_code)}
            {codeChip('SNOMED', condition.snomed_code)}
            {condition.severity && (
              <span className="inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                {condition.severity}
              </span>
            )}
          </div>
          {condition.notes && <p className="mt-2 text-xs text-muted-foreground">{condition.notes}</p>}
        </>
      ),
    },
    {
      id: 'dates',
      header: 'Dates',
      fit: true,
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3 text-muted-foreground',
      cell: (condition) => (
        <>
          <div>{condition.onset_date ? `Onset ${formatDate(condition.onset_date)}` : 'No onset date'}</div>
          {condition.abated_date && <div>Abated {formatDate(condition.abated_date)}</div>}
        </>
      ),
    },
    {
      id: 'status',
      header: 'Status',
      fit: true,
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3',
      cell: (condition) => (
        <div className="flex items-center gap-2">
          {classBadge(condition.clinical_status, STATUS_CLASS)}
          {reviewStatusBadge(condition.review_status)}
          <span className="text-xs text-muted-foreground">{labelize(condition.verification_status)}</span>
        </div>
      ),
    },
  ]
  if (canManage) {
    columns.push({
      id: 'actions',
      header: 'Actions',
      fit: true,
      headerClassName: 'px-4 py-3 font-medium text-right',
      className: 'px-4 py-3',
      cell: (condition) => {
        const isResolving = isMutating(`resolve:${condition.id}`)
        return (
          <div className="flex justify-end gap-2">
            {condition.clinical_status !== 'resolved' && (
              <Button
                type="button"
                size="sm"
                variant="outline"
                aria-label={`${isResolving ? 'Resolving' : 'Resolve'} ${condition.name}`}
                disabled={busyFor(condition)}
                onClick={() => void onMarkResolved(condition)}
              >
                <CheckCircle2 className="size-4" />
                {isResolving ? 'Resolving...' : 'Resolve'}
              </Button>
            )}
            <ReviewActions
              status={condition.review_status}
              label={condition.name}
              busy={isMutating(`review:${condition.id}`)}
              disabled={busyFor(condition)}
              onReview={(decision) => onReview(condition.id, decision)}
            />
            <Button
              type="button"
              size="icon-sm"
              variant="ghost"
              title={`Edit ${condition.name}`}
              aria-label={`Edit ${condition.name}`}
              disabled={busyFor(condition)}
              onClick={() => onStartEdit(condition)}
            >
              <Pencil className="size-4" />
            </Button>
            <Button
              type="button"
              size="icon-sm"
              variant="ghost"
              className="text-destructive hover:text-destructive"
              title={`Delete ${condition.name}`}
              aria-label={`Delete ${condition.name}`}
              disabled={busyFor(condition)}
              onClick={() => onStartDelete(condition.id)}
            >
              <Trash2 className="size-4" />
            </Button>
          </div>
        )
      },
    })
  }

  return (
    <section className="rounded-lg border border-border bg-card">
      <div className="border-b border-border px-4 py-3">
        <h2 className="font-semibold text-card-foreground">{title}</h2>
        <p className="text-sm text-muted-foreground">{description}</p>
      </div>
      <RecordTable
        label={title}
        loading={loading}
        loadingLabel="Loading conditions"
        rows={conditions}
        rowId={(condition) => String(condition.id)}
        columns={columns}
        openRowId={openRowId}
        onOpen={drillOpener(onDrill, (condition: PhrCondition) => ({ id: 'condition-detail', instance: String(condition.id) }))}
        empty={<div className="px-4 py-8 text-sm text-muted-foreground">{emptyMessage}</div>}
        tableClassName="min-w-full divide-y divide-border text-sm"
        headClassName="text-left text-muted-foreground"
        bodyClassName="divide-y divide-border"
        rowClassName="align-top hover:bg-muted/30"
      />
    </section>
  )
}

export default function ConditionsPage({ patientId, onDrill, openRecord }: PhrListPageProps) {
  const openRowId = openRecordId(openRecord, 'condition-detail')
  const [historicalOpen, setHistoricalOpen] = useState(false)
  // The add or edit dialog: null closed, 'new' adding, a condition editing it.
  const [editing, setEditing] = useState<PhrCondition | 'new' | null>(null)
  const endpoint = `/api/phr/patients/${patientId}/conditions`

  const knownCanManage = useKnownPatientCanManage(patientId)
  const crud = useClinicalCrud<PhrCondition, PhrConditionFormData>({
    endpoint,
    emptyForm: EMPTY_CONDITION_FORM,
    formFromRecord: conditionFormFromRecord,
    parseItem: (raw) => PhrConditionResponseSchema.parse(raw).condition,
    parseList: (raw) => {
      const parsed = PhrConditionsResponseSchema.parse(raw)
      return { records: parsed.conditions, canManage: parsed.can_manage }
    },
    payloadFromForm: conditionPayload,
    initialCanManage: knownCanManage,
    sortRecords: sortConditions,
    onRecordChanged: (recordId) => recordChanged({ resource: 'conditions', patientId, recordId, origin: 'list' }),
  })
  // The detail panel edits and deletes too; the list follows.
  useRecordChanges('conditions', patientId, 'detail', () => void crud.reload())
  // Skeleton rows on the first load only; a refetch keeps the current rows on screen.
  const firstLoad = crud.busy && crud.records.length === 0

  const activeConditions = useMemo(
    () => crud.records.filter((condition) => ['active', 'recurrence', 'relapse'].includes(condition.clinical_status)),
    [crud.records],
  )
  const historicalConditions = useMemo(
    () => crud.records.filter((condition) => !['active', 'recurrence', 'relapse'].includes(condition.clinical_status)),
    [crud.records],
  )

  function openEditor(target: PhrCondition | 'new'): void {
    crud.setError(null)
    setEditing(target)
  }

  function closeEditor(): void {
    crud.setError(null)
    setEditing(null)
  }

  async function saveCondition(form: PhrConditionFormData): Promise<boolean> {
    if (editing === null) return false
    if (editing === 'new') {
      const added = await crud.addRecord(form)
      if (!added) return false
      closeEditor()
      // A new condition opens, so its detail shows what was saved.
      onDrill?.({ id: 'condition-detail', instance: String(added.id) })
      return true
    }
    const updated = await crud.patchRecord(editing.id, conditionPayload(form))
    if (!updated) return false
    closeEditor()
    return true
  }

  const deleting = crud.deletingId === null ? null : crud.records.find((condition) => condition.id === crud.deletingId) ?? null

  async function markResolved(condition: PhrCondition): Promise<void> {
    await crud.patchRecord(
      condition.id,
      compactPayload({
        clinical_status: 'resolved',
        abated_date: condition.abated_date ?? todayDateString(),
      }),
      `resolve:${condition.id}`,
    )
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
            <Activity className="size-6 text-primary" />
            Conditions
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">Track active problems separately from resolved or inactive history.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <ShowRejectedToggle showRejected={crud.showRejected} onChange={crud.setShowRejected} disabled={crud.busy} />
        </div>
      </div>

      {crud.error && editing === null && deleting === null && (
        <div role="alert" className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {crud.error}
        </div>
      )}

      {crud.canManage && (
        <div className="mb-6 flex flex-wrap items-start gap-2">
          <Button type="button" size="sm" variant="outline" onClick={() => openEditor('new')}>
            <Plus className="size-4" aria-hidden="true" />
            Add condition
          </Button>
          <Button type="button" size="sm" variant="outline" onClick={() => onDrill?.({ id: 'documents' })}>
            Import via GenAI
          </Button>
        </div>
      )}

      {!crud.busy && crud.records.length === 0 && (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          No conditions recorded.
        </div>
      )}

      {(firstLoad || crud.records.length > 0) && (
        <div className="space-y-4">
          <ConditionsTable
            loading={firstLoad}
            title="Active Problems"
            description="Active, recurrent, or relapsing conditions."
            conditions={activeConditions}
            emptyMessage="No active problems recorded."
            canManage={crud.canManage}
            onStartEdit={openEditor}
            onStartDelete={(conditionId) => { crud.setError(null); crud.startDelete(conditionId) }}
            onMarkResolved={markResolved}
            onReview={(conditionId, decision) => void crud.reviewRecord(conditionId, decision)}
            isMutating={crud.isMutating}
            onDrill={onDrill}
            openRowId={openRowId}
          />

          {!firstLoad && (
            <section className="rounded-lg border border-border bg-card">
              <button
                type="button"
                className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left"
                onClick={() => setHistoricalOpen((current) => !current)}
                aria-expanded={historicalOpen}
              >
                <div>
                  <h2 className="font-semibold text-card-foreground">Resolved and Inactive</h2>
                  <p className="text-sm text-muted-foreground">Historical problem-list entries retained for context.</p>
                </div>
                <span className="flex items-center gap-2 text-sm text-muted-foreground">
                  {historicalConditions.length}
                  {historicalOpen ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                </span>
              </button>
              {historicalOpen && (
                <div className="border-t border-border">
                  <ConditionsTable
                    title="Historical Conditions"
                    description="Resolved, inactive, or remission conditions."
                    conditions={historicalConditions}
                    emptyMessage="No historical conditions recorded."
                    canManage={crud.canManage}
                    onStartEdit={openEditor}
                    onStartDelete={(conditionId) => { crud.setError(null); crud.startDelete(conditionId) }}
                    onMarkResolved={markResolved}
                    onReview={(conditionId, decision) => void crud.reviewRecord(conditionId, decision)}
                    isMutating={crud.isMutating}
                    onDrill={onDrill}
                    openRowId={openRowId}
                  />
                </div>
              )}
            </section>
          )}
        </div>
      )}
      {editing !== null && (
        <ConditionFormDialog
          condition={editing === 'new' ? null : editing}
          busy={crud.isMutating('add') || (editing !== 'new' && crud.isMutating(`save:${editing.id}`))}
          error={crud.error}
          onSubmit={saveCondition}
          onClose={closeEditor}
        />
      )}
      <ConfirmDeleteDialog
        open={deleting !== null}
        title="Delete this condition?"
        description={<><strong>{deleting?.name}</strong> will be removed from this record. You can't undo this.</>}
        busy={deleting !== null && crud.isMutating(`delete:${deleting.id}`)}
        error={deleting !== null ? crud.error : null}
        onConfirm={() => { if (deleting) void crud.deleteRecord(deleting.id) }}
        onCancel={() => { crud.setError(null); crud.cancelDelete() }}
      />
    </div>
  )
}
