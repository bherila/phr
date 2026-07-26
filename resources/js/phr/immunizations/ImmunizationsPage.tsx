import { Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react'
import type { FormEvent } from 'react'
import { Fragment, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { useClinicalCrud } from '@/phr/clinical/crud'
import { codeChip } from '@/phr/clinical/ui'
import type { PhrListPageProps } from '@/phr/miller'
import { numericPayload, zodErrorMessage } from '@/phr/shared'
import {
  type PhrImmunization,
  type PhrImmunizationFormData,
  PhrImmunizationFormSchema,
  PhrImmunizationResponseSchema,
  PhrImmunizationsResponseSchema,
} from '@/phr/types'

const EMPTY_FORM: PhrImmunizationFormData = {
  vaccine_name: '',
  cvx_code: '',
  manufacturer: '',
  lot_number: '',
  administered_on: '',
  dose_number: '',
  series_doses: '',
  site: '',
  route: '',
  administered_by: '',
  facility_name: '',
  notes: '',
}

interface ImmunizationFormFieldsProps {
  form: PhrImmunizationFormData
  onChange: (form: PhrImmunizationFormData) => void
}

interface AddFormProps {
  busy: boolean
  onSubmit: (form: PhrImmunizationFormData) => Promise<boolean>
}

function immunizationFormFromRecord(immunization: PhrImmunization): PhrImmunizationFormData {
  return {
    vaccine_name: immunization.vaccine_name,
    cvx_code: immunization.cvx_code ?? '',
    manufacturer: immunization.manufacturer ?? '',
    lot_number: immunization.lot_number ?? '',
    administered_on: immunization.administered_on ?? '',
    dose_number: immunization.dose_number === null ? '' : String(immunization.dose_number),
    series_doses: immunization.series_doses === null ? '' : String(immunization.series_doses),
    site: immunization.site ?? '',
    route: immunization.route ?? '',
    administered_by: immunization.administered_by ?? '',
    facility_name: immunization.facility_name ?? '',
    notes: immunization.notes ?? '',
  }
}

function immunizationPayload(form: PhrImmunizationFormData): Record<string, unknown> {
  return numericPayload(form, ['dose_number', 'series_doses'])
}

function sortImmunizations(immunizations: PhrImmunization[]): PhrImmunization[] {
  return [...immunizations].sort((left, right) => {
    const dateCompare = (right.administered_on ?? '').localeCompare(left.administered_on ?? '')
    if (dateCompare !== 0) {
      return dateCompare
    }

    return right.id - left.id
  })
}

function doseLabel(immunization: PhrImmunization): string {
  if (immunization.dose_number !== null && immunization.series_doses !== null) {
    return `Dose ${immunization.dose_number}/${immunization.series_doses}`
  }

  if (immunization.dose_number !== null) {
    return `Dose ${immunization.dose_number}`
  }

  return 'Dose not recorded'
}

function ImmunizationFormFields({ form, onChange }: ImmunizationFormFieldsProps) {
  return (
    <div className="grid gap-3 md:grid-cols-2">
      <label className="grid gap-1 text-sm font-medium md:col-span-2">
        Vaccine Name *
        <Input value={form.vaccine_name} onChange={(event) => onChange({ ...form, vaccine_name: event.target.value })} required />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Administered On
        <Input type="date" value={form.administered_on} onChange={(event) => onChange({ ...form, administered_on: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        CVX Code
        <Input value={form.cvx_code} onChange={(event) => onChange({ ...form, cvx_code: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Manufacturer
        <Input value={form.manufacturer} onChange={(event) => onChange({ ...form, manufacturer: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Lot Number
        <Input value={form.lot_number} onChange={(event) => onChange({ ...form, lot_number: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Dose Number
        <Input inputMode="numeric" value={form.dose_number} onChange={(event) => onChange({ ...form, dose_number: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Series Doses
        <Input inputMode="numeric" value={form.series_doses} onChange={(event) => onChange({ ...form, series_doses: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Site
        <Input value={form.site} onChange={(event) => onChange({ ...form, site: event.target.value })} placeholder="Left deltoid" />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Route
        <Input value={form.route} onChange={(event) => onChange({ ...form, route: event.target.value })} placeholder="IM" />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Administered By
        <Input value={form.administered_by} onChange={(event) => onChange({ ...form, administered_by: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Facility
        <Input value={form.facility_name} onChange={(event) => onChange({ ...form, facility_name: event.target.value })} />
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
  const [form, setForm] = useState<PhrImmunizationFormData>(EMPTY_FORM)

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
        Add Immunization
      </Button>
    )
  }

  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <h2 className="mb-3 text-sm font-semibold text-card-foreground">Add Immunization</h2>
      <form onSubmit={(event) => void handleSubmit(event)} className="space-y-3">
        <ImmunizationFormFields form={form} onChange={setForm} />
        <div className="flex gap-2">
          <Button type="submit" size="sm" disabled={busy}>{busy ? 'Adding...' : 'Add'}</Button>
          <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => setOpen(false)}>Cancel</Button>
        </div>
      </form>
    </div>
  )
}

export default function ImmunizationsPage({ patientId, onDrill }: PhrListPageProps) {
  const endpoint = `/api/phr/patients/${patientId}/immunizations`
  const crud = useClinicalCrud<PhrImmunization, PhrImmunizationFormData>({
    endpoint,
    emptyForm: EMPTY_FORM,
    formFromRecord: immunizationFormFromRecord,
    parseItem: (raw) => PhrImmunizationResponseSchema.parse(raw).immunization,
    parseList: (raw) => {
      const parsed = PhrImmunizationsResponseSchema.parse(raw)
      return { records: parsed.immunizations, canManage: parsed.can_manage }
    },
    payloadFromForm: immunizationPayload,
    sortRecords: sortImmunizations,
  })

  async function addImmunization(form: PhrImmunizationFormData): Promise<boolean> {
    const parsed = PhrImmunizationFormSchema.safeParse(form)
    if (!parsed.success) {
      crud.setError(zodErrorMessage(parsed.error))
      return false
    }

    return (await crud.addRecord(parsed.data)) !== null
  }

  async function saveImmunization(immunizationId: number): Promise<void> {
    const parsed = PhrImmunizationFormSchema.safeParse(crud.editForm)
    if (!parsed.success) {
      crud.setError(zodErrorMessage(parsed.error))
      return
    }

    const updated = await crud.patchRecord(immunizationId, immunizationPayload(parsed.data))
    if (updated) {
      crud.cancelEdit()
    }
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
            <ShieldCheck className="size-6 text-primary" />
            Immunizations
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">Review vaccine history by administration date.</p>
        </div>
      </div>

      {crud.error && (
        <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {crud.error}
        </div>
      )}

      {crud.canManage && (
        <div className="mb-6 flex flex-wrap items-start gap-2">
          <AddForm busy={crud.isMutating('add')} onSubmit={addImmunization} />
          <Button type="button" size="sm" variant="outline" onClick={() => onDrill?.({ id: 'documents' })}>
            Import via GenAI
          </Button>
        </div>
      )}

      {crud.busy && <p className="text-sm text-muted-foreground">Loading...</p>}

      {!crud.busy && crud.records.length === 0 && (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          No immunizations recorded.
        </div>
      )}

      {!crud.busy && crud.records.length > 0 && (
        <section className="rounded-lg border border-border bg-card">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-border text-sm">
              <thead>
                <tr className="text-left text-muted-foreground">
                  <th className="px-4 py-3 font-medium">Vaccine</th>
                  <th className="px-4 py-3 font-medium">Administered</th>
                  <th className="px-4 py-3 font-medium">Dose</th>
                  <th className="px-4 py-3 font-medium">Facility</th>
                  {crud.canManage && <th className="px-4 py-3 font-medium text-right">Actions</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {crud.records.map((immunization) => {
                  const isEditing = crud.editingId === immunization.id
                  const isDeleting = crud.deletingId === immunization.id
                  const isSaving = crud.isMutating(`save:${immunization.id}`)
                  const isDeletingBusy = crud.isMutating(`delete:${immunization.id}`)

                  return (
                    <Fragment key={immunization.id}>
                      <tr
                        className={`align-top ${onDrill ? 'cursor-pointer hover:bg-muted/30' : ''}`}
                        onClick={() => onDrill?.({ id: 'immunization-detail', instance: String(immunization.id) })}
                      >
                        <td className="px-4 py-3">
                          <div className="font-medium text-card-foreground">{immunization.vaccine_name}</div>
                          <div className="mt-2 flex flex-wrap gap-1.5">
                            {codeChip('CVX', immunization.cvx_code)}
                            {immunization.manufacturer && (
                              <span className="inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                {immunization.manufacturer}
                              </span>
                            )}
                            {immunization.lot_number && (
                              <span className="inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                Lot {immunization.lot_number}
                              </span>
                            )}
                          </div>
                          {immunization.notes && <p className="mt-2 text-xs text-muted-foreground">{immunization.notes}</p>}
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">{immunization.administered_on ?? 'Not recorded'}</td>
                        <td className="px-4 py-3 text-muted-foreground">
                          <div>{doseLabel(immunization)}</div>
                          <div>{[immunization.site, immunization.route].filter(Boolean).join(' · ')}</div>
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">
                          <div>{immunization.facility_name ?? 'Not recorded'}</div>
                          {immunization.administered_by && <div>{immunization.administered_by}</div>}
                        </td>
                        {crud.canManage && (
                          <td className="px-4 py-3" onClick={(event) => event.stopPropagation()}>
                            <div className="flex justify-end gap-2">
                              <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                title="Edit immunization"
                                disabled={isSaving || isDeletingBusy}
                                onClick={() => crud.startEdit(immunization)}
                              >
                                <Pencil className="size-4" />
                              </Button>
                              <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                className="text-destructive hover:text-destructive"
                                title="Delete immunization"
                                disabled={isSaving || isDeletingBusy}
                                onClick={() => crud.startDelete(immunization.id)}
                              >
                                <Trash2 className="size-4" />
                              </Button>
                            </div>
                          </td>
                        )}
                      </tr>
                      {isEditing && (
                        <tr>
                          <td colSpan={crud.canManage ? 5 : 4} className="bg-muted/20 px-4 py-4">
                            <form
                              className="space-y-3"
                              onSubmit={(event) => {
                                event.preventDefault()
                                void saveImmunization(immunization.id)
                              }}
                            >
                              <ImmunizationFormFields form={crud.editForm} onChange={crud.setEditForm} />
                              <div className="flex gap-2">
                                <Button type="submit" size="sm" disabled={isSaving}>{isSaving ? 'Saving...' : 'Save'}</Button>
                                <Button type="button" variant="outline" size="sm" disabled={isSaving} onClick={crud.cancelEdit}>Cancel</Button>
                              </div>
                            </form>
                          </td>
                        </tr>
                      )}
                      {isDeleting && (
                        <tr>
                          <td colSpan={crud.canManage ? 5 : 4} className="bg-destructive/5 px-4 py-4">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                              <p className="text-sm text-foreground">
                                Delete <strong>{immunization.vaccine_name}</strong>? This cannot be undone.
                              </p>
                              <div className="flex gap-2">
                                <Button variant="destructive" size="sm" disabled={isDeletingBusy} onClick={() => void crud.deleteRecord(immunization.id)}>
                                  {isDeletingBusy ? 'Deleting...' : 'Delete'}
                                </Button>
                                <Button type="button" variant="outline" size="sm" disabled={isDeletingBusy} onClick={crud.cancelDelete}>Cancel</Button>
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
        </section>
      )}
    </div>
  )
}
