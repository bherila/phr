import { Activity, CheckCircle2, ChevronDown, ChevronRight, Pencil, Plus, Trash2 } from 'lucide-react'
import type { FormEvent } from 'react'
import { Fragment, useMemo, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { useClinicalCrud } from '@/phr/clinical/crud'
import { classBadge, codeChip, labelize } from '@/phr/clinical/ui'
import type { PhrListPageProps } from '@/phr/miller'
import { compactPayload, zodErrorMessage } from '@/phr/shared'
import {
  type PhrCondition,
  type PhrConditionFormData,
  PhrConditionFormSchema,
  PhrConditionResponseSchema,
  PhrConditionsResponseSchema,
} from '@/phr/types'

const SELECT_CLASS = 'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm'

const STATUS_CLASS: Record<string, string> = {
  active: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300',
  recurrence: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300',
  relapse: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300',
  inactive: 'bg-muted text-muted-foreground',
  remission: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300',
  resolved: 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-300',
}

const CONDITION_STATUSES = [
  { value: 'active', label: 'Active' },
  { value: 'recurrence', label: 'Recurrence' },
  { value: 'relapse', label: 'Relapse' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'remission', label: 'Remission' },
  { value: 'resolved', label: 'Resolved' },
] as const

const VERIFICATION_STATUSES = [
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'unconfirmed', label: 'Unconfirmed' },
  { value: 'provisional', label: 'Provisional' },
  { value: 'differential', label: 'Differential' },
  { value: 'refuted', label: 'Refuted' },
  { value: 'entered_in_error', label: 'Entered in Error' },
] as const

const SEVERITY_OPTIONS = [
  { value: '', label: 'Not set' },
  { value: 'mild', label: 'Mild' },
  { value: 'moderate', label: 'Moderate' },
  { value: 'severe', label: 'Severe' },
] as const

const EMPTY_FORM: PhrConditionFormData = {
  name: '',
  icd10_code: '',
  snomed_code: '',
  onset_date: '',
  abated_date: '',
  clinical_status: 'active',
  verification_status: 'confirmed',
  severity: '',
  notes: '',
}

interface ConditionFormFieldsProps {
  form: PhrConditionFormData
  onChange: (form: PhrConditionFormData) => void
}

interface AddFormProps {
  busy: boolean
  onSubmit: (form: PhrConditionFormData) => Promise<boolean>
}

interface ConditionsTableProps {
  title: string
  description: string
  conditions: PhrCondition[]
  emptyMessage: string
  canManage: boolean
  editingId: number | null
  deletingId: number | null
  editForm: PhrConditionFormData
  setEditForm: (form: PhrConditionFormData) => void
  onStartEdit: (condition: PhrCondition) => void
  onCancelEdit: () => void
  onSaveEdit: (conditionId: number) => Promise<void>
  onStartDelete: (conditionId: number) => void
  onCancelDelete: () => void
  onConfirmDelete: (conditionId: number) => Promise<void>
  onMarkResolved: (condition: PhrCondition) => Promise<void>
  isMutating: (key: string) => boolean
  onDrill?: PhrListPageProps['onDrill']
}

function conditionFormFromRecord(condition: PhrCondition): PhrConditionFormData {
  return {
    name: condition.name,
    icd10_code: condition.icd10_code ?? '',
    snomed_code: condition.snomed_code ?? '',
    onset_date: condition.onset_date ?? '',
    abated_date: condition.abated_date ?? '',
    clinical_status: PhrConditionFormSchema.shape.clinical_status.safeParse(condition.clinical_status).success
      ? condition.clinical_status as PhrConditionFormData['clinical_status']
      : 'active',
    verification_status: PhrConditionFormSchema.shape.verification_status.safeParse(condition.verification_status).success
      ? condition.verification_status as PhrConditionFormData['verification_status']
      : 'confirmed',
    severity: PhrConditionFormSchema.shape.severity.safeParse(condition.severity ?? '').success
      ? (condition.severity ?? '') as PhrConditionFormData['severity']
      : '',
    notes: condition.notes ?? '',
  }
}

function conditionPayload(form: PhrConditionFormData): Record<string, unknown> {
  return compactPayload(form)
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

function ConditionFormFields({ form, onChange }: ConditionFormFieldsProps) {
  return (
    <div className="grid gap-3 md:grid-cols-2">
      <label className="grid gap-1 text-sm font-medium md:col-span-2">
        Name *
        <Input value={form.name} onChange={(event) => onChange({ ...form, name: event.target.value })} required />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        ICD-10 Code
        <Input value={form.icd10_code} onChange={(event) => onChange({ ...form, icd10_code: event.target.value })} placeholder="E11.9" />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        SNOMED Code
        <Input value={form.snomed_code} onChange={(event) => onChange({ ...form, snomed_code: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Onset Date
        <Input type="date" value={form.onset_date} onChange={(event) => onChange({ ...form, onset_date: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Abated Date
        <Input type="date" value={form.abated_date} onChange={(event) => onChange({ ...form, abated_date: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Clinical Status
        <select
          value={form.clinical_status}
          onChange={(event) => onChange({ ...form, clinical_status: event.target.value as PhrConditionFormData['clinical_status'] })}
          className={SELECT_CLASS}
        >
          {CONDITION_STATUSES.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Verification
        <select
          value={form.verification_status}
          onChange={(event) => onChange({ ...form, verification_status: event.target.value as PhrConditionFormData['verification_status'] })}
          className={SELECT_CLASS}
        >
          {VERIFICATION_STATUSES.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Severity
        <select
          value={form.severity}
          onChange={(event) => onChange({ ...form, severity: event.target.value as PhrConditionFormData['severity'] })}
          className={SELECT_CLASS}
        >
          {SEVERITY_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>
      <label className="grid gap-1 text-sm font-medium md:col-span-2">
        Notes
        <Textarea value={form.notes} onChange={(event) => onChange({ ...form, notes: event.target.value })} />
      </label>
    </div>
  )
}

function AddForm({ busy, onSubmit }: AddFormProps) {
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<PhrConditionFormData>(EMPTY_FORM)

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
        Add Condition
      </Button>
    )
  }

  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <h2 className="mb-3 text-sm font-semibold text-card-foreground">Add Condition</h2>
      <form onSubmit={(event) => void handleSubmit(event)} className="space-y-3">
        <ConditionFormFields form={form} onChange={setForm} />
        <div className="flex gap-2">
          <Button type="submit" size="sm" disabled={busy}>{busy ? 'Adding...' : 'Add'}</Button>
          <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => setOpen(false)}>Cancel</Button>
        </div>
      </form>
    </div>
  )
}

function ConditionsTable({
  title,
  description,
  conditions,
  emptyMessage,
  canManage,
  editingId,
  deletingId,
  editForm,
  setEditForm,
  onStartEdit,
  onCancelEdit,
  onSaveEdit,
  onStartDelete,
  onCancelDelete,
  onConfirmDelete,
  onMarkResolved,
  isMutating,
  onDrill,
}: ConditionsTableProps) {
  return (
    <section className="rounded-lg border border-border bg-card">
      <div className="border-b border-border px-4 py-3">
        <h2 className="font-semibold text-card-foreground">{title}</h2>
        <p className="text-sm text-muted-foreground">{description}</p>
      </div>
      {conditions.length === 0 ? (
        <div className="px-4 py-8 text-sm text-muted-foreground">{emptyMessage}</div>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead>
              <tr className="text-left text-muted-foreground">
                <th className="px-4 py-3 font-medium">Condition</th>
                <th className="px-4 py-3 font-medium">Dates</th>
                <th className="px-4 py-3 font-medium">Status</th>
                {canManage && <th className="px-4 py-3 font-medium text-right">Actions</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {conditions.map((condition) => {
                const isEditing = editingId === condition.id
                const isDeleting = deletingId === condition.id
                const isSaving = isMutating(`save:${condition.id}`)
                const isDeletingBusy = isMutating(`delete:${condition.id}`)
                const isResolving = isMutating(`resolve:${condition.id}`)
                const isResolved = condition.clinical_status === 'resolved'

                return (
                  <Fragment key={condition.id}>
                    <tr
                      className={`align-top ${onDrill ? 'cursor-pointer hover:bg-muted/30' : ''}`}
                      onClick={() => onDrill?.({ id: 'condition-detail', instance: String(condition.id) })}
                    >
                      <td className="px-4 py-3">
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
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">
                        <div>{condition.onset_date ? `Onset ${condition.onset_date}` : 'No onset date'}</div>
                        {condition.abated_date && <div>Abated {condition.abated_date}</div>}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-col items-start gap-1.5">
                          {classBadge(condition.clinical_status, STATUS_CLASS)}
                          <span className="text-xs text-muted-foreground">{labelize(condition.verification_status)}</span>
                        </div>
                      </td>
                      {canManage && (
                        <td className="px-4 py-3" onClick={(event) => event.stopPropagation()}>
                          <div className="flex justify-end gap-2">
                            {!isResolved && (
                              <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={isResolving || isSaving || isDeletingBusy}
                                onClick={() => void onMarkResolved(condition)}
                              >
                                <CheckCircle2 className="size-4" />
                                {isResolving ? 'Resolving...' : 'Resolve'}
                              </Button>
                            )}
                            <Button
                              type="button"
                              size="icon-sm"
                              variant="ghost"
                              title="Edit condition"
                              disabled={isResolving || isSaving || isDeletingBusy}
                              onClick={() => onStartEdit(condition)}
                            >
                              <Pencil className="size-4" />
                            </Button>
                            <Button
                              type="button"
                              size="icon-sm"
                              variant="ghost"
                              className="text-destructive hover:text-destructive"
                              title="Delete condition"
                              disabled={isResolving || isSaving || isDeletingBusy}
                              onClick={() => onStartDelete(condition.id)}
                            >
                              <Trash2 className="size-4" />
                            </Button>
                          </div>
                        </td>
                      )}
                    </tr>
                    {isEditing && (
                      <tr>
                        <td colSpan={canManage ? 4 : 3} className="bg-muted/20 px-4 py-4">
                          <form
                            className="space-y-3"
                            onSubmit={(event) => {
                              event.preventDefault()
                              void onSaveEdit(condition.id)
                            }}
                          >
                            <ConditionFormFields form={editForm} onChange={setEditForm} />
                            <div className="flex gap-2">
                              <Button type="submit" size="sm" disabled={isSaving}>{isSaving ? 'Saving...' : 'Save'}</Button>
                              <Button type="button" variant="outline" size="sm" disabled={isSaving} onClick={onCancelEdit}>Cancel</Button>
                            </div>
                          </form>
                        </td>
                      </tr>
                    )}
                    {isDeleting && (
                      <tr>
                        <td colSpan={canManage ? 4 : 3} className="bg-destructive/5 px-4 py-4">
                          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm text-foreground">
                              Delete <strong>{condition.name}</strong>? This cannot be undone.
                            </p>
                            <div className="flex gap-2">
                              <Button variant="destructive" size="sm" disabled={isDeletingBusy} onClick={() => void onConfirmDelete(condition.id)}>
                                {isDeletingBusy ? 'Deleting...' : 'Delete'}
                              </Button>
                              <Button type="button" variant="outline" size="sm" disabled={isDeletingBusy} onClick={onCancelDelete}>Cancel</Button>
                            </div>
                          </div>
                        </td>
                      </tr>
                    )}
                  </Fragment>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

export default function ConditionsPage({ patientId, onDrill }: PhrListPageProps) {
  const [historicalOpen, setHistoricalOpen] = useState(false)
  const endpoint = `/api/phr/patients/${patientId}/conditions`

  const crud = useClinicalCrud<PhrCondition, PhrConditionFormData>({
    endpoint,
    emptyForm: EMPTY_FORM,
    formFromRecord: conditionFormFromRecord,
    parseItem: (raw) => PhrConditionResponseSchema.parse(raw).condition,
    parseList: (raw) => {
      const parsed = PhrConditionsResponseSchema.parse(raw)
      return { records: parsed.conditions, canManage: parsed.can_manage }
    },
    payloadFromForm: conditionPayload,
    sortRecords: sortConditions,
  })

  const activeConditions = useMemo(
    () => crud.records.filter((condition) => ['active', 'recurrence', 'relapse'].includes(condition.clinical_status)),
    [crud.records],
  )
  const historicalConditions = useMemo(
    () => crud.records.filter((condition) => !['active', 'recurrence', 'relapse'].includes(condition.clinical_status)),
    [crud.records],
  )

  async function addCondition(form: PhrConditionFormData): Promise<boolean> {
    const parsed = PhrConditionFormSchema.safeParse(form)
    if (!parsed.success) {
      crud.setError(zodErrorMessage(parsed.error))
      return false
    }

    return (await crud.addRecord(parsed.data)) !== null
  }

  async function saveCondition(conditionId: number): Promise<void> {
    const parsed = PhrConditionFormSchema.safeParse(crud.editForm)
    if (!parsed.success) {
      crud.setError(zodErrorMessage(parsed.error))
      return
    }

    const updated = await crud.patchRecord(conditionId, conditionPayload(parsed.data))
    if (updated) {
      crud.cancelEdit()
    }
  }

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
      </div>

      {crud.error && (
        <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {crud.error}
        </div>
      )}

      {crud.canManage && (
        <div className="mb-6 flex flex-wrap items-start gap-2">
          <AddForm busy={crud.isMutating('add')} onSubmit={addCondition} />
          <Button type="button" size="sm" variant="outline" onClick={() => onDrill?.({ id: 'documents' })}>
            Import via GenAI
          </Button>
        </div>
      )}

      {crud.busy && <p className="text-sm text-muted-foreground">Loading...</p>}

      {!crud.busy && crud.records.length === 0 && (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          No conditions recorded.
        </div>
      )}

      {!crud.busy && crud.records.length > 0 && (
        <div className="space-y-4">
          <ConditionsTable
            title="Active Problems"
            description="Active, recurrent, or relapsing conditions."
            conditions={activeConditions}
            emptyMessage="No active problems recorded."
            canManage={crud.canManage}
            editingId={crud.editingId}
            deletingId={crud.deletingId}
            editForm={crud.editForm}
            setEditForm={crud.setEditForm}
            onStartEdit={crud.startEdit}
            onCancelEdit={crud.cancelEdit}
            onSaveEdit={saveCondition}
            onStartDelete={crud.startDelete}
            onCancelDelete={crud.cancelDelete}
            onConfirmDelete={async (conditionId) => { await crud.deleteRecord(conditionId) }}
            onMarkResolved={markResolved}
            isMutating={crud.isMutating}
            onDrill={onDrill}
          />

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
                  editingId={crud.editingId}
                  deletingId={crud.deletingId}
                  editForm={crud.editForm}
                  setEditForm={crud.setEditForm}
                  onStartEdit={crud.startEdit}
                  onCancelEdit={crud.cancelEdit}
                  onSaveEdit={saveCondition}
                  onStartDelete={crud.startDelete}
                  onCancelDelete={crud.cancelDelete}
                  onConfirmDelete={async (conditionId) => { await crud.deleteRecord(conditionId) }}
                   onMarkResolved={markResolved}
                   isMutating={crud.isMutating}
                   onDrill={onDrill}
                 />
              </div>
            )}
          </section>
        </div>
      )}
    </div>
  )
}
