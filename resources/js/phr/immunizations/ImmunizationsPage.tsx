import { Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react'
import type { FormEvent } from 'react'
import { useEffect, useId, useRef, useState } from 'react'

import { type RecordColumn, RecordTable } from '@/components/record-list/RecordTable'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { formatDate } from '@/lib/dateFormat'
import { ConfirmDeleteDialog } from '@/phr/clinical/ConfirmDeleteDialog'
import { useClinicalCrud } from '@/phr/clinical/crud'
import { type FieldErrors, fieldErrorsFrom, focusFirstInvalid } from '@/phr/clinical/fieldErrors'
import { RecordFormDialog } from '@/phr/clinical/RecordFormDialog'
import { ReviewActions, ShowRejectedToggle } from '@/phr/clinical/review'
import { codeChip, reviewStatusBadge } from '@/phr/clinical/ui'
import { doseAmountLabel, seriesPositionLabel } from '@/phr/immunizations/immunizationDose'
import { EhiPendingBanner } from '@/phr/imports/ehi/EhiPendingBanner'
import { useKnownPatientCanManage } from '@/phr/patients/usePhrPatients'
import { numericPayload } from '@/phr/shared'
import { drillOpener, type PhrListPageProps } from '@/phr/shell/phrPages'
import { openRecordId } from '@/phr/shell/phrRoutes'
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
  errors?: FieldErrors<PhrImmunizationFormData>
}

/** The form's own words for what Zod would otherwise report in its own. */
const FIELD_MESSAGES: FieldErrors<PhrImmunizationFormData> = {
  vaccine_name: "Enter the vaccine's name.",
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
  const series = seriesPositionLabel(immunization)
  const amount = doseAmountLabel(immunization)
  const parts = [series === null ? null : `Dose ${series}`, amount].filter((part): part is string => part !== null)

  return parts.length > 0 ? parts.join(' · ') : 'Dose not recorded'
}

function ImmunizationFormFields({ form, onChange, errors = {} }: ImmunizationFormFieldsProps) {
  const errorId = useId()
  return (
    <div className="grid gap-3 md:grid-cols-2">
      <label className="grid gap-1 text-sm font-medium md:col-span-2">
        Vaccine Name *
        <Input
          value={form.vaccine_name}
          maxLength={255}
          onChange={(event) => onChange({ ...form, vaccine_name: event.target.value })}
          {...(errors.vaccine_name ? { 'aria-invalid': true, 'aria-describedby': errorId } : {})}
        />
        {errors.vaccine_name && <span id={errorId} className="text-xs font-normal text-destructive">{errors.vaccine_name}</span>}
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

function ImmunizationFormDialog({ immunization, busy, error, onSubmit, onClose }: {
  immunization: PhrImmunization | null
  busy: boolean
  error: string | null
  onSubmit: (form: PhrImmunizationFormData) => Promise<boolean>
  onClose: () => void
}) {
  const formRef = useRef<HTMLFormElement>(null)
  const [form, setForm] = useState<PhrImmunizationFormData>(() => (immunization ? immunizationFormFromRecord(immunization) : EMPTY_FORM))
  const [errors, setErrors] = useState<FieldErrors<PhrImmunizationFormData>>({})

  useEffect(() => {
    focusFirstInvalid(formRef.current)
  }, [errors])

  async function submit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    const parsed = PhrImmunizationFormSchema.safeParse(form)
    if (!parsed.success) {
      setErrors(fieldErrorsFrom(parsed.error, FIELD_MESSAGES))
      return
    }
    setErrors({})
    await onSubmit(parsed.data)
  }

  return (
    <RecordFormDialog
      title={immunization ? 'Edit immunization' : 'Add immunization'}
      description="The vaccine, when and where it was given, the dose and notes."
      submitLabel={immunization ? 'Save' : 'Add immunization'}
      busy={busy}
      error={error}
      onSubmit={(event) => void submit(event)}
      onClose={onClose}
      formRef={formRef}
    >
      <ImmunizationFormFields form={form} onChange={setForm} errors={errors} />
    </RecordFormDialog>
  )
}

export default function ImmunizationsPage({ patientId, onDrill, openRecord }: PhrListPageProps) {
  const endpoint = `/api/phr/patients/${patientId}/immunizations`
  const knownCanManage = useKnownPatientCanManage(patientId)
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
    initialCanManage: knownCanManage,
    sortRecords: sortImmunizations,
  })
  // Skeleton rows on the first load only; a refetch keeps the current rows on screen.
  const firstLoad = crud.busy && crud.records.length === 0
  // The add or edit dialog: null closed, 'new' adding, an immunization editing it.
  const [editing, setEditing] = useState<PhrImmunization | 'new' | null>(null)

  function openEditor(target: PhrImmunization | 'new'): void {
    crud.setError(null)
    setEditing(target)
  }

  function closeEditor(): void {
    crud.setError(null)
    setEditing(null)
  }

  async function saveImmunization(form: PhrImmunizationFormData): Promise<boolean> {
    if (editing === null) return false
    if (editing === 'new') {
      const added = await crud.addRecord(form)
      if (!added) return false
      closeEditor()
      onDrill?.({ id: 'immunization-detail', instance: String(added.id) })
      return true
    }
    const updated = await crud.patchRecord(editing.id, immunizationPayload(form))
    if (!updated) return false
    closeEditor()
    return true
  }

  const deleting = crud.deletingId === null ? null : crud.records.find((immunization) => immunization.id === crud.deletingId) ?? null

  const busyFor = (immunization: PhrImmunization): boolean => crud.isMutating(`save:${immunization.id}`) || crud.isMutating(`delete:${immunization.id}`)
  const columns: RecordColumn<PhrImmunization>[] = [
    {
      id: 'vaccine',
      header: 'Vaccine',
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3',
      cell: (immunization) => (
        <>
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span className="font-medium text-card-foreground">{immunization.vaccine_name}</span>
            {reviewStatusBadge(immunization.review_status)}
          </div>
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
        </>
      ),
    },
    { id: 'administered', header: 'Administered', fit: true, headerClassName: 'px-4 py-3 font-medium', className: 'px-4 py-3 text-muted-foreground', cell: (immunization) => formatDate(immunization.administered_on) || 'Not recorded' },
    {
      id: 'dose',
      header: 'Dose',
      fit: true,
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3 text-muted-foreground',
      cell: (immunization) => (
        <>
          <div>{doseLabel(immunization)}</div>
          <div>{[immunization.site, immunization.route].filter(Boolean).join(' · ')}</div>
        </>
      ),
    },
    {
      id: 'facility',
      header: 'Facility',
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3 text-muted-foreground',
      cell: (immunization) => (
        <>
          <div>{immunization.facility_name ?? 'Not recorded'}</div>
          {immunization.administered_by && <div>{immunization.administered_by}</div>}
        </>
      ),
    },
  ]
  if (crud.canManage) {
    columns.push({
      id: 'actions',
      header: 'Actions',
      fit: true,
      headerClassName: 'px-4 py-3 font-medium text-right',
      className: 'px-4 py-3',
      cell: (immunization) => (
        <div className="flex justify-end gap-2">
          <ReviewActions
            status={immunization.review_status}
            label={immunization.vaccine_name}
            busy={crud.isMutating(`review:${immunization.id}`)}
            disabled={busyFor(immunization)}
            onReview={(decision) => void crud.reviewRecord(immunization.id, decision)}
          />
          <Button
            type="button"
            size="icon-sm"
            variant="ghost"
            title={`Edit ${immunization.vaccine_name}`}
            aria-label={`Edit ${immunization.vaccine_name}`}
            disabled={busyFor(immunization)}
            onClick={() => openEditor(immunization)}
          >
            <Pencil className="size-4" />
          </Button>
          <Button
            type="button"
            size="icon-sm"
            variant="ghost"
            className="text-destructive hover:text-destructive"
            title={`Delete ${immunization.vaccine_name}`}
            aria-label={`Delete ${immunization.vaccine_name}`}
            disabled={busyFor(immunization)}
            onClick={() => { crud.setError(null); crud.startDelete(immunization.id) }}
          >
            <Trash2 className="size-4" />
          </Button>
        </div>
      ),
    })
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
        <div className="flex flex-wrap gap-2">
          <ShowRejectedToggle showRejected={crud.showRejected} onChange={crud.setShowRejected} disabled={crud.busy} />
        </div>
      </div>

      <EhiPendingBanner patientId={patientId} mapping="immunization" className="mb-4" />

      {crud.error && editing === null && deleting === null && (
        <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {crud.error}
        </div>
      )}

      {crud.canManage && (
        <div className="mb-6 flex flex-wrap items-start gap-2">
          <Button type="button" size="sm" variant="outline" onClick={() => openEditor('new')}>
            <Plus className="size-4" aria-hidden="true" />
            Add immunization
          </Button>
          <Button type="button" size="sm" variant="outline" onClick={() => onDrill?.({ id: 'documents' })}>
            Import via GenAI
          </Button>
        </div>
      )}

      {!crud.busy && crud.records.length === 0 && (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          No immunizations recorded.
        </div>
      )}

      {(firstLoad || crud.records.length > 0) && (
        <section className="rounded-lg border border-border bg-card">
          <RecordTable
            loading={firstLoad}
            label="Immunizations"
            rows={crud.records}
            rowId={(immunization) => String(immunization.id)}
            columns={columns}
            openRowId={openRecordId(openRecord, 'immunization-detail')}
            onOpen={drillOpener(onDrill, (immunization: PhrImmunization) => ({ id: 'immunization-detail', instance: String(immunization.id) }))}
            tableClassName="min-w-full divide-y divide-border text-sm"
            headClassName="text-left text-muted-foreground"
            bodyClassName="divide-y divide-border"
            rowClassName="align-top hover:bg-muted/30"
          />
        </section>
      )}
      {editing !== null && (
        <ImmunizationFormDialog
          immunization={editing === 'new' ? null : editing}
          busy={crud.isMutating('add') || (editing !== 'new' && crud.isMutating(`save:${editing.id}`))}
          error={crud.error}
          onSubmit={saveImmunization}
          onClose={closeEditor}
        />
      )}
      <ConfirmDeleteDialog
        open={deleting !== null}
        title="Delete this immunization?"
        description={<><strong>{deleting?.vaccine_name}</strong> will be removed from this record. You can't undo this.</>}
        busy={deleting !== null && crud.isMutating(`delete:${deleting.id}`)}
        error={deleting !== null ? crud.error : null}
        onConfirm={() => { if (deleting) void crud.deleteRecord(deleting.id) }}
        onCancel={() => { crud.setError(null); crud.cancelDelete() }}
      />
    </div>
  )
}
