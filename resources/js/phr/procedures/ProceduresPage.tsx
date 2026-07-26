import { Info, Pencil, Plus, Scissors, Trash2 } from 'lucide-react'
import type { FormEvent } from 'react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { useClinicalCrud } from '@/phr/clinical/crud'
import { classBadge, codeChip } from '@/phr/clinical/ui'
import type { PhrListPageProps } from '@/phr/miller'
import { compactPayload, zodErrorMessage } from '@/phr/shared'
import {
  type PhrProcedure,
  type PhrProcedureFormData,
  PhrProcedureFormSchema,
  PhrProcedureResponseSchema,
  PhrProceduresResponseSchema,
} from '@/phr/types'

const SELECT_CLASS = 'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm'

const STATUS_CLASS: Record<string, string> = {
  preparation: 'bg-blue-100 text-blue-800 dark:bg-blue-900/20 dark:text-blue-300',
  in_progress: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300',
  completed: 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-300',
  cancelled: 'bg-muted text-muted-foreground',
  entered_in_error: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300',
}

const STATUS_OPTIONS = [
  { value: 'completed', label: 'Completed' },
  { value: 'preparation', label: 'Preparation' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'entered_in_error', label: 'Entered in Error' },
] as const

const EMPTY_FORM: PhrProcedureFormData = {
  name: '',
  cpt_code: '',
  snomed_code: '',
  performed_at: '',
  performed_on: '',
  performer_name: '',
  performer_specialty: '',
  facility_name: '',
  status: 'completed',
  reason: '',
  outcome: '',
  notes: '',
}

interface ProcedureFormFieldsProps {
  form: PhrProcedureFormData
  onChange: (form: PhrProcedureFormData) => void
}

interface AddFormProps {
  busy: boolean
  onSubmit: (form: PhrProcedureFormData) => Promise<boolean>
}

function procedureFormFromRecord(procedure: PhrProcedure): PhrProcedureFormData {
  return {
    name: procedure.name,
    cpt_code: procedure.cpt_code ?? '',
    snomed_code: procedure.snomed_code ?? '',
    performed_at: toDatetimeLocal(procedure.performed_at),
    performed_on: procedure.performed_on ?? '',
    performer_name: procedure.performer_name ?? '',
    performer_specialty: procedure.performer_specialty ?? '',
    facility_name: procedure.facility_name ?? '',
    status: PhrProcedureFormSchema.shape.status.safeParse(procedure.status).success
      ? procedure.status as PhrProcedureFormData['status']
      : 'completed',
    reason: procedure.reason ?? '',
    outcome: procedure.outcome ?? '',
    notes: procedure.notes ?? '',
  }
}

function procedurePayload(form: PhrProcedureFormData): Record<string, unknown> {
  return compactPayload(form)
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

function toDatetimeLocal(value: string | null): string {
  return value ? value.replace(' ', 'T').slice(0, 16) : ''
}

function displayProcedureDate(procedure: PhrProcedure): string {
  if (procedure.performed_at) {
    return procedure.performed_at.slice(0, 16)
  }

  return procedure.performed_on ?? 'Date not recorded'
}

function ProcedureFormFields({ form, onChange }: ProcedureFormFieldsProps) {
  return (
    <div className="grid gap-3 md:grid-cols-2">
      <label className="grid gap-1 text-sm font-medium md:col-span-2">
        Name *
        <Input value={form.name} onChange={(event) => onChange({ ...form, name: event.target.value })} required />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Performed At
        <Input
          type="datetime-local"
          value={form.performed_at}
          onChange={(event) => onChange({ ...form, performed_at: event.target.value })}
        />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Performed On
        <Input type="date" value={form.performed_on} onChange={(event) => onChange({ ...form, performed_on: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        CPT Code
        <Input value={form.cpt_code} onChange={(event) => onChange({ ...form, cpt_code: event.target.value })} placeholder="99213" />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        SNOMED Code
        <Input value={form.snomed_code} onChange={(event) => onChange({ ...form, snomed_code: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Performer
        <Input value={form.performer_name} onChange={(event) => onChange({ ...form, performer_name: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Specialty
        <Input value={form.performer_specialty} onChange={(event) => onChange({ ...form, performer_specialty: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Facility
        <Input value={form.facility_name} onChange={(event) => onChange({ ...form, facility_name: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Status
        <select
          value={form.status}
          onChange={(event) => onChange({ ...form, status: event.target.value as PhrProcedureFormData['status'] })}
          className={SELECT_CLASS}
        >
          {STATUS_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>
      <label className="grid gap-1 text-sm font-medium md:col-span-2">
        Reason
        <Textarea value={form.reason} onChange={(event) => onChange({ ...form, reason: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium md:col-span-2">
        Outcome
        <Textarea value={form.outcome} onChange={(event) => onChange({ ...form, outcome: event.target.value })} />
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

export default function ProceduresPage({ patientId, onDrill }: PhrListPageProps) {
  const endpoint = `/api/phr/patients/${patientId}/procedures`
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
    sortRecords: sortProcedures,
  })

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

      {crud.busy && <p className="text-sm text-muted-foreground">Loading...</p>}

      {!crud.busy && crud.records.length === 0 && (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          No procedures recorded.
        </div>
      )}

      {!crud.busy && crud.records.length > 0 && (
        <ol className="relative space-y-4 border-l border-border pl-5">
          {crud.records.map((procedure) => {
            const isEditing = crud.editingId === procedure.id
            const isDeleting = crud.deletingId === procedure.id
            const isSaving = crud.isMutating(`save:${procedure.id}`)
            const isDeletingBusy = crud.isMutating(`delete:${procedure.id}`)

            return (
              <li key={procedure.id} className="relative">
                <span className="absolute -left-[1.65rem] top-4 size-3 rounded-full border-2 border-background bg-primary" />
                <div
                  className={`rounded-lg border border-border bg-card ${onDrill ? 'cursor-pointer transition-colors hover:bg-muted/30' : ''}`}
                  onClick={() => onDrill?.({ id: 'procedure-detail', instance: String(procedure.id) })}
                >
                  <div className="grid gap-3 px-4 py-3 md:grid-cols-[150px_minmax(0,1fr)_auto] md:items-start">
                    <div className="text-sm font-medium text-muted-foreground">{displayProcedureDate(procedure)}</div>
                    <div className="min-w-0">
                      <div className="font-medium text-card-foreground">{procedure.name}</div>
                      <div className="mt-2 flex flex-wrap gap-1.5">
                        {codeChip('CPT', procedure.cpt_code)}
                        {codeChip('SNOMED', procedure.snomed_code)}
                        {classBadge(procedure.status, STATUS_CLASS)}
                      </div>
                      <div className="mt-2 text-sm text-muted-foreground">
                        {[
                          procedure.performer_name,
                          procedure.performer_specialty,
                          procedure.facility_name,
                        ].filter(Boolean).join(' · ') || 'No performer or facility recorded'}
                      </div>
                      {(procedure.reason || procedure.outcome || procedure.notes) && (
                        <div className="mt-2 grid gap-1 text-xs text-muted-foreground">
                          {procedure.reason && <p><span className="font-medium text-foreground">Reason:</span> {procedure.reason}</p>}
                          {procedure.outcome && <p><span className="font-medium text-foreground">Outcome:</span> {procedure.outcome}</p>}
                          {procedure.notes && <p>{procedure.notes}</p>}
                        </div>
                      )}
                    </div>
                    {crud.canManage && (
                       <div className="flex justify-end gap-2" onClick={(event) => event.stopPropagation()}>
                        <Button
                          type="button"
                          size="icon-sm"
                          variant="ghost"
                          title="Edit procedure"
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
                          title="Delete procedure"
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
              </li>
            )
          })}
        </ol>
      )}
    </div>
  )
}
