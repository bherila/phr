import { AlertTriangle, ChevronDown, ChevronRight, Info, Pencil, Plus, Trash2 } from 'lucide-react'
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
  PhrAllergiesResponseSchema,
  type PhrAllergy,
  type PhrAllergyFormData,
  PhrAllergyFormSchema,
  PhrAllergyResponseSchema,
} from '@/phr/types'

const SELECT_CLASS = 'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm'

const CRITICALITY_CLASS: Record<string, string> = {
  high: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300',
  low: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300',
  unable_to_assess: 'bg-muted text-muted-foreground',
}

const STATUS_CLASS: Record<string, string> = {
  active: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300',
  inactive: 'bg-muted text-muted-foreground',
  resolved: 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-300',
}

const CATEGORY_OPTIONS = [
  { value: '', label: 'Not set' },
  { value: 'medication', label: 'Medication' },
  { value: 'food', label: 'Food' },
  { value: 'environment', label: 'Environment' },
  { value: 'biologic', label: 'Biologic' },
] as const

const CRITICALITY_OPTIONS = [
  { value: '', label: 'Not set' },
  { value: 'low', label: 'Low' },
  { value: 'high', label: 'High' },
  { value: 'unable_to_assess', label: 'Unable to Assess' },
] as const

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'resolved', label: 'Resolved' },
] as const

const VERIFICATION_OPTIONS = [
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'unconfirmed', label: 'Unconfirmed' },
  { value: 'refuted', label: 'Refuted' },
  { value: 'entered_in_error', label: 'Entered in Error' },
] as const

const SEVERITY_OPTIONS = [
  { value: '', label: 'Not set' },
  { value: 'mild', label: 'Mild' },
  { value: 'moderate', label: 'Moderate' },
  { value: 'severe', label: 'Severe' },
] as const

const EMPTY_FORM: PhrAllergyFormData = {
  substance: '',
  rxnorm_code: '',
  snomed_code: '',
  category: '',
  criticality: 'low',
  clinical_status: 'active',
  verification_status: 'confirmed',
  reaction: '',
  severity: '',
  notes: '',
}

interface AllergyFormFieldsProps {
  form: PhrAllergyFormData
  onChange: (form: PhrAllergyFormData) => void
}

interface AddFormProps {
  busy: boolean
  onSubmit: (form: PhrAllergyFormData) => Promise<boolean>
}

interface AllergiesTableProps {
  title: string
  description: string
  allergies: PhrAllergy[]
  emptyMessage: string
  canManage: boolean
  editingId: number | null
  deletingId: number | null
  editForm: PhrAllergyFormData
  setEditForm: (form: PhrAllergyFormData) => void
  onStartEdit: (allergy: PhrAllergy) => void
  onCancelEdit: () => void
  onSaveEdit: (allergyId: number) => Promise<void>
  onStartDelete: (allergyId: number) => void
  onCancelDelete: () => void
  onConfirmDelete: (allergyId: number) => Promise<void>
  isMutating: (key: string) => boolean
  onDrill?: PhrListPageProps['onDrill']
}

function allergyFormFromRecord(allergy: PhrAllergy): PhrAllergyFormData {
  return {
    substance: allergy.substance,
    rxnorm_code: allergy.rxnorm_code ?? '',
    snomed_code: allergy.snomed_code ?? '',
    category: PhrAllergyFormSchema.shape.category.safeParse(allergy.category ?? '').success
      ? (allergy.category ?? '') as PhrAllergyFormData['category']
      : '',
    criticality: PhrAllergyFormSchema.shape.criticality.safeParse(allergy.criticality ?? '').success
      ? (allergy.criticality ?? '') as PhrAllergyFormData['criticality']
      : '',
    clinical_status: PhrAllergyFormSchema.shape.clinical_status.safeParse(allergy.clinical_status).success
      ? allergy.clinical_status as PhrAllergyFormData['clinical_status']
      : 'active',
    verification_status: PhrAllergyFormSchema.shape.verification_status.safeParse(allergy.verification_status).success
      ? allergy.verification_status as PhrAllergyFormData['verification_status']
      : 'confirmed',
    reaction: allergy.reaction ?? '',
    severity: PhrAllergyFormSchema.shape.severity.safeParse(allergy.severity ?? '').success
      ? (allergy.severity ?? '') as PhrAllergyFormData['severity']
      : '',
    notes: allergy.notes ?? '',
  }
}

function allergyPayload(form: PhrAllergyFormData): Record<string, unknown> {
  return compactPayload(form)
}

function sortAllergies(allergies: PhrAllergy[]): PhrAllergy[] {
  return [...allergies].sort((left, right) => {
    const statusCompare = allergyStatusOrder(left) - allergyStatusOrder(right)
    if (statusCompare !== 0) {
      return statusCompare
    }

    const riskCompare = allergyRiskOrder(left) - allergyRiskOrder(right)
    if (riskCompare !== 0) {
      return riskCompare
    }

    return left.substance.localeCompare(right.substance)
  })
}

function allergyStatusOrder(allergy: PhrAllergy): number {
  return allergy.clinical_status === 'active' ? 0 : 1
}

function allergyRiskOrder(allergy: PhrAllergy): number {
  if (isHighRisk(allergy)) {
    return 0
  }

  if (allergy.criticality === 'low' || allergy.severity === 'moderate') {
    return 1
  }

  return 2
}

function isHighRisk(allergy: PhrAllergy): boolean {
  return allergy.criticality === 'high' || allergy.severity === 'severe'
}

function AllergyFormFields({ form, onChange }: AllergyFormFieldsProps) {
  return (
    <div className="grid gap-3 md:grid-cols-2">
      <label className="grid gap-1 text-sm font-medium md:col-span-2">
        Substance *
        <Input value={form.substance} onChange={(event) => onChange({ ...form, substance: event.target.value })} placeholder="Penicillin" required />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Reaction
        <Input value={form.reaction} onChange={(event) => onChange({ ...form, reaction: event.target.value })} placeholder="Hives, anaphylaxis" />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Category
        <select
          value={form.category}
          onChange={(event) => onChange({ ...form, category: event.target.value as PhrAllergyFormData['category'] })}
          className={SELECT_CLASS}
        >
          {CATEGORY_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Criticality
        <select
          value={form.criticality}
          onChange={(event) => onChange({ ...form, criticality: event.target.value as PhrAllergyFormData['criticality'] })}
          className={SELECT_CLASS}
        >
          {CRITICALITY_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Severity
        <select
          value={form.severity}
          onChange={(event) => onChange({ ...form, severity: event.target.value as PhrAllergyFormData['severity'] })}
          className={SELECT_CLASS}
        >
          {SEVERITY_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Clinical Status
        <select
          value={form.clinical_status}
          onChange={(event) => onChange({ ...form, clinical_status: event.target.value as PhrAllergyFormData['clinical_status'] })}
          className={SELECT_CLASS}
        >
          {STATUS_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Verification
        <select
          value={form.verification_status}
          onChange={(event) => onChange({ ...form, verification_status: event.target.value as PhrAllergyFormData['verification_status'] })}
          className={SELECT_CLASS}
        >
          {VERIFICATION_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>
      <label className="grid gap-1 text-sm font-medium">
        RxNorm Code
        <Input value={form.rxnorm_code} onChange={(event) => onChange({ ...form, rxnorm_code: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        SNOMED Code
        <Input value={form.snomed_code} onChange={(event) => onChange({ ...form, snomed_code: event.target.value })} />
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
  const [form, setForm] = useState<PhrAllergyFormData>(EMPTY_FORM)

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
        Add Allergy
      </Button>
    )
  }

  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <h2 className="mb-3 text-sm font-semibold text-card-foreground">Add Allergy</h2>
      <form onSubmit={(event) => void handleSubmit(event)} className="space-y-3">
        <AllergyFormFields form={form} onChange={setForm} />
        <div className="flex gap-2">
          <Button type="submit" size="sm" disabled={busy}>{busy ? 'Adding...' : 'Add'}</Button>
          <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => setOpen(false)}>Cancel</Button>
        </div>
      </form>
    </div>
  )
}

function AllergiesTable({
  title,
  description,
  allergies,
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
  isMutating,
  onDrill,
}: AllergiesTableProps) {
  return (
    <section className="rounded-lg border border-border bg-card">
      <div className="border-b border-border px-4 py-3">
        <h2 className="font-semibold text-card-foreground">{title}</h2>
        <p className="text-sm text-muted-foreground">{description}</p>
      </div>
      {allergies.length === 0 ? (
        <div className="px-4 py-8 text-sm text-muted-foreground">{emptyMessage}</div>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead>
              <tr className="text-left text-muted-foreground">
                <th className="px-4 py-3 font-medium">Allergy</th>
                <th className="px-4 py-3 font-medium">Reaction</th>
                <th className="px-4 py-3 font-medium">Status</th>
                {canManage && <th className="px-4 py-3 font-medium text-right">Actions</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {allergies.map((allergy) => {
                const isEditing = editingId === allergy.id
                const isDeleting = deletingId === allergy.id
                const isSaving = isMutating(`save:${allergy.id}`)
                const isDeletingBusy = isMutating(`delete:${allergy.id}`)
                const rowClass = isHighRisk(allergy) ? 'bg-destructive/5' : ''

                return (
                  <Fragment key={allergy.id}>
                    <tr
                      className={`align-top ${rowClass} ${onDrill ? 'cursor-pointer hover:bg-muted/30' : ''}`}
                      onClick={() => onDrill?.({ id: 'allergy-detail', instance: String(allergy.id) })}
                    >
                      <td className="px-4 py-3">
                        <div className="font-medium text-card-foreground">{allergy.substance}</div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                          {allergy.category && (
                            <span className="inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                              {allergy.category}
                            </span>
                          )}
                          {codeChip('RxNorm', allergy.rxnorm_code)}
                          {codeChip('SNOMED', allergy.snomed_code)}
                        </div>
                        {allergy.notes && <p className="mt-2 text-xs text-muted-foreground">{allergy.notes}</p>}
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">
                        <div>{allergy.reaction ?? 'Reaction not recorded'}</div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                          {classBadge(allergy.criticality, CRITICALITY_CLASS)}
                          {allergy.severity && (
                            <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${allergy.severity === 'severe' ? 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300' : 'bg-muted text-muted-foreground'}`}>
                              {allergy.severity}
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-col items-start gap-1.5">
                          {classBadge(allergy.clinical_status, STATUS_CLASS)}
                          <span className="text-xs text-muted-foreground">{labelize(allergy.verification_status)}</span>
                        </div>
                      </td>
                      {canManage && (
                        <td className="px-4 py-3" onClick={(event) => event.stopPropagation()}>
                          <div className="flex justify-end gap-2">
                            <Button
                              type="button"
                              size="icon-sm"
                              variant="ghost"
                              title="Edit allergy"
                              disabled={isSaving || isDeletingBusy}
                              onClick={() => onStartEdit(allergy)}
                            >
                              <Pencil className="size-4" />
                            </Button>
                            <Button
                              type="button"
                              size="icon-sm"
                              variant="ghost"
                              className="text-destructive hover:text-destructive"
                              title="Delete allergy"
                              disabled={isSaving || isDeletingBusy}
                              onClick={() => onStartDelete(allergy.id)}
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
                              void onSaveEdit(allergy.id)
                            }}
                          >
                            <AllergyFormFields form={editForm} onChange={setEditForm} />
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
                              Delete <strong>{allergy.substance}</strong>? This cannot be undone.
                            </p>
                            <div className="flex gap-2">
                              <Button variant="destructive" size="sm" disabled={isDeletingBusy} onClick={() => void onConfirmDelete(allergy.id)}>
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

export default function AllergiesPage({ patientId, onDrill }: PhrListPageProps) {
  const [historicalOpen, setHistoricalOpen] = useState(false)
  const endpoint = `/api/phr/patients/${patientId}/allergies`
  const crud = useClinicalCrud<PhrAllergy, PhrAllergyFormData>({
    endpoint,
    emptyForm: EMPTY_FORM,
    formFromRecord: allergyFormFromRecord,
    parseItem: (raw) => PhrAllergyResponseSchema.parse(raw).allergy,
    parseList: (raw) => {
      const parsed = PhrAllergiesResponseSchema.parse(raw)
      return { records: parsed.allergies, canManage: parsed.can_manage }
    },
    payloadFromForm: allergyPayload,
    sortRecords: sortAllergies,
  })

  const activeAllergies = useMemo(
    () => crud.records.filter((allergy) => allergy.clinical_status === 'active'),
    [crud.records],
  )
  const historicalAllergies = useMemo(
    () => crud.records.filter((allergy) => allergy.clinical_status !== 'active'),
    [crud.records],
  )

  async function addAllergy(form: PhrAllergyFormData): Promise<boolean> {
    const parsed = PhrAllergyFormSchema.safeParse(form)
    if (!parsed.success) {
      crud.setError(zodErrorMessage(parsed.error))
      return false
    }

    return (await crud.addRecord(parsed.data)) !== null
  }

  async function saveAllergy(allergyId: number): Promise<void> {
    const parsed = PhrAllergyFormSchema.safeParse(crud.editForm)
    if (!parsed.success) {
      crud.setError(zodErrorMessage(parsed.error))
      return
    }

    const updated = await crud.patchRecord(allergyId, allergyPayload(parsed.data))
    if (updated) {
      crud.cancelEdit()
    }
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
            <AlertTriangle className="size-6 text-primary" />
            Allergies
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">Track active allergies and historical resolved or inactive reactions.</p>
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
            <AddForm busy={crud.isMutating('add')} onSubmit={addAllergy} />
          </div>
          <div className="flex items-start gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
            <Info className="mt-0.5 size-4 shrink-0" />
            Allergies are imported from CCDA/FHIR data or extracted as part of office-visit review.
          </div>
        </div>
      )}

      {crud.busy && <p className="text-sm text-muted-foreground">Loading...</p>}

      {!crud.busy && crud.records.length === 0 && (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          No allergies recorded.
        </div>
      )}

      {!crud.busy && crud.records.length > 0 && (
        <div className="space-y-4">
          <AllergiesTable
            title="Active Allergies"
            description="High criticality and severe reactions are highlighted."
            allergies={activeAllergies}
            emptyMessage="No active allergies recorded."
            canManage={crud.canManage}
            editingId={crud.editingId}
            deletingId={crud.deletingId}
            editForm={crud.editForm}
            setEditForm={crud.setEditForm}
            onStartEdit={crud.startEdit}
            onCancelEdit={crud.cancelEdit}
            onSaveEdit={saveAllergy}
            onStartDelete={crud.startDelete}
            onCancelDelete={crud.cancelDelete}
            onConfirmDelete={async (allergyId) => { await crud.deleteRecord(allergyId) }}
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
                <p className="text-sm text-muted-foreground">Historical allergies retained for clinical context.</p>
              </div>
              <span className="flex items-center gap-2 text-sm text-muted-foreground">
                {historicalAllergies.length}
                {historicalOpen ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
              </span>
            </button>
            {historicalOpen && (
              <div className="border-t border-border">
                <AllergiesTable
                  title="Historical Allergies"
                  description="Resolved or inactive allergy records."
                  allergies={historicalAllergies}
                  emptyMessage="No historical allergies recorded."
                  canManage={crud.canManage}
                  editingId={crud.editingId}
                  deletingId={crud.deletingId}
                  editForm={crud.editForm}
                  setEditForm={crud.setEditForm}
                  onStartEdit={crud.startEdit}
                  onCancelEdit={crud.cancelEdit}
                  onSaveEdit={saveAllergy}
                  onStartDelete={crud.startDelete}
                  onCancelDelete={crud.cancelDelete}
                   onConfirmDelete={async (allergyId) => { await crud.deleteRecord(allergyId) }}
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
