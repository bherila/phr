import { Plus, ShieldCheck } from 'lucide-react'
import type { FormEvent, ReactNode } from 'react'
import { useEffect, useId, useRef, useState } from 'react'

import { RECORD_ONLY_BELOW_MEDIUM, RECORD_ONLY_BELOW_WIDE, RECORD_ONLY_WIDE_BLOCK, type RecordColumn, RecordTable } from '@/components/record-list/RecordTable'
import { RowActionsMenu } from '@/components/record-list/RowActionsMenu'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { formatDate } from '@/lib/dateFormat'
import { ConfirmDeleteDialog } from '@/phr/clinical/ConfirmDeleteDialog'
import { useClinicalCrud } from '@/phr/clinical/crud'
import { type FieldErrors, fieldErrorsFrom, focusFirstInvalid } from '@/phr/clinical/fieldErrors'
import { recordChanged, useRecordChanges } from '@/phr/clinical/recordChanges'
import { RecordFormDialog } from '@/phr/clinical/RecordFormDialog'
import { ReviewActions, ShowRejectedToggle } from '@/phr/clinical/review'
import { ReviewDot } from '@/phr/clinical/ReviewDot'
import { codeChip } from '@/phr/clinical/ui'
import { administrationStatusLabel, doseAmountLabel, seriesPositionLabel } from '@/phr/immunizations/immunizationDose'
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
  vaccine_name: 'Vaccine names are at most 255 characters.',
  cvx_code: 'CVX codes are at most 20 characters.',
  manufacturer: 'Manufacturers are at most 100 characters.',
  lot_number: 'Lot numbers are at most 100 characters.',
  site: 'Sites are at most 100 characters.',
  route: 'Routes are at most 100 characters.',
  administered_by: 'Administered by is at most 255 characters.',
  facility_name: 'Facilities are at most 255 characters.',
  notes: 'Notes are at most 10000 characters.',
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

export function immunizationPayload(form: PhrImmunizationFormData): Record<string, unknown> {
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

function ImmunizationField({ id, label, error, children, wide = false }: {
  id: string
  label: string
  error?: string | undefined
  children: ReactNode
  wide?: boolean
}) {
  return (
    <div className={`grid gap-1 ${wide ? 'md:col-span-2' : ''}`}>
      <label htmlFor={id} className="text-sm font-medium">{label}</label>
      {children}
      {error && <p id={`${id}-error`} className="text-xs text-destructive">{error}</p>}
    </div>
  )
}

function ImmunizationFormFields({ form, onChange, errors = {} }: ImmunizationFormFieldsProps) {
  const id = useId()
  const invalid = (field: keyof PhrImmunizationFormData): { 'aria-invalid'?: true, 'aria-describedby'?: string } => (
    errors[field] ? { 'aria-invalid': true, 'aria-describedby': `${id}-${field}-error` } : {}
  )
  return (
    <div className="grid gap-3 md:grid-cols-2">
      <ImmunizationField id={`${id}-vaccine_name`} label="Vaccine Name *" error={errors.vaccine_name} wide>
        <Input id={`${id}-vaccine_name`} value={form.vaccine_name} maxLength={255} onChange={(event) => onChange({ ...form, vaccine_name: event.target.value })} {...invalid('vaccine_name')} />
      </ImmunizationField>
      <ImmunizationField id={`${id}-administered_on`} label="Administered On" error={errors.administered_on}>
        <Input id={`${id}-administered_on`} value={form.administered_on} type="date" onChange={(event) => onChange({ ...form, administered_on: event.target.value })} {...invalid('administered_on')} />
      </ImmunizationField>
      <ImmunizationField id={`${id}-cvx_code`} label="CVX Code" error={errors.cvx_code}>
        <Input id={`${id}-cvx_code`} value={form.cvx_code} maxLength={20} onChange={(event) => onChange({ ...form, cvx_code: event.target.value })} {...invalid('cvx_code')} />
      </ImmunizationField>
      <ImmunizationField id={`${id}-manufacturer`} label="Manufacturer" error={errors.manufacturer}>
        <Input id={`${id}-manufacturer`} value={form.manufacturer} maxLength={100} onChange={(event) => onChange({ ...form, manufacturer: event.target.value })} {...invalid('manufacturer')} />
      </ImmunizationField>
      <ImmunizationField id={`${id}-lot_number`} label="Lot Number" error={errors.lot_number}>
        <Input id={`${id}-lot_number`} value={form.lot_number} maxLength={100} onChange={(event) => onChange({ ...form, lot_number: event.target.value })} {...invalid('lot_number')} />
      </ImmunizationField>
      <ImmunizationField id={`${id}-dose_number`} label="Dose Number" error={errors.dose_number}>
        <Input id={`${id}-dose_number`} value={form.dose_number} inputMode="numeric" onChange={(event) => onChange({ ...form, dose_number: event.target.value })} {...invalid('dose_number')} />
      </ImmunizationField>
      <ImmunizationField id={`${id}-series_doses`} label="Series Doses" error={errors.series_doses}>
        <Input id={`${id}-series_doses`} value={form.series_doses} inputMode="numeric" onChange={(event) => onChange({ ...form, series_doses: event.target.value })} {...invalid('series_doses')} />
      </ImmunizationField>
      <ImmunizationField id={`${id}-site`} label="Site" error={errors.site}>
        <Input id={`${id}-site`} value={form.site} maxLength={100} placeholder="Left deltoid" onChange={(event) => onChange({ ...form, site: event.target.value })} {...invalid('site')} />
      </ImmunizationField>
      <ImmunizationField id={`${id}-route`} label="Route" error={errors.route}>
        <Input id={`${id}-route`} value={form.route} maxLength={100} placeholder="IM" onChange={(event) => onChange({ ...form, route: event.target.value })} {...invalid('route')} />
      </ImmunizationField>
      <ImmunizationField id={`${id}-administered_by`} label="Administered By" error={errors.administered_by}>
        <Input id={`${id}-administered_by`} value={form.administered_by} maxLength={255} onChange={(event) => onChange({ ...form, administered_by: event.target.value })} {...invalid('administered_by')} />
      </ImmunizationField>
      <ImmunizationField id={`${id}-facility_name`} label="Facility" error={errors.facility_name}>
        <Input id={`${id}-facility_name`} value={form.facility_name} maxLength={255} onChange={(event) => onChange({ ...form, facility_name: event.target.value })} {...invalid('facility_name')} />
      </ImmunizationField>
      <ImmunizationField id={`${id}-notes`} label="Notes" error={errors.notes} wide>
        <Textarea id={`${id}-notes`} value={form.notes} maxLength={10000} onChange={(event) => onChange({ ...form, notes: event.target.value })} {...invalid('notes')} />
      </ImmunizationField>
    </div>
  )
}

export function ImmunizationFormDialog({ immunization, busy, error, onSubmit, onClose }: {
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
      setErrors(fieldErrorsFrom(parsed.error, {
        ...FIELD_MESSAGES,
        ...(!form.vaccine_name.trim() ? { vaccine_name: "Enter the vaccine's name." } : {}),
      }))
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
      fieldErrors={errors}
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
    onRecordChanged: (recordId) => recordChanged({ resource: 'immunizations', patientId, recordId, origin: 'list' }),
  })
  useRecordChanges('immunizations', patientId, 'detail', () => void crud.reload())
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
          <div className="flex items-start gap-2">
            <span className="shrink-0 pt-1.5"><ReviewDot status={immunization.review_status} /></span>
            <span className="font-medium text-card-foreground [overflow-wrap:anywhere]">{immunization.vaccine_name}</span>
          </div>
          <div className={`${RECORD_ONLY_BELOW_WIDE} mt-1 text-xs text-muted-foreground [overflow-wrap:anywhere]`}>
            <span className={RECORD_ONLY_BELOW_MEDIUM}>{formatDate(immunization.administered_on) || 'Date not recorded'} · </span>
            {doseLabel(immunization)}
            {administrationStatusLabel(immunization.administration_status) && <> · {administrationStatusLabel(immunization.administration_status)}</>}
          </div>
          <div className={RECORD_ONLY_WIDE_BLOCK}>
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
            {immunization.notes && <p className="mt-2 text-xs text-muted-foreground [overflow-wrap:anywhere]">{immunization.notes}</p>}
          </div>
        </>
      ),
    },
    { id: 'administered', header: 'Administered', fit: true, showFrom: 'medium', headerClassName: 'px-4 py-3 font-medium', className: 'px-4 py-3 text-muted-foreground [overflow-wrap:anywhere]', cell: (immunization) => formatDate(immunization.administered_on) || 'Not recorded' },
    {
      id: 'dose',
      header: 'Dose',
      showFrom: 'wide',
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3 text-muted-foreground [overflow-wrap:anywhere]',
      cell: (immunization) => (
        <>
          <div>{doseLabel(immunization)}</div>
          <div>{[immunization.site, immunization.route].filter(Boolean).join(' · ')}</div>
        </>
      ),
    },
    {
      id: 'facility',
      showFrom: 'wide',
      header: 'Facility',
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3 text-muted-foreground [overflow-wrap:anywhere]',
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
      showFrom: 'wide',
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
          <RowActionsMenu
            label={immunization.vaccine_name}
            disabled={busyFor(immunization)}
            actions={[
              { label: 'Edit', onSelect: () => openEditor(immunization) },
              { label: 'Delete', destructive: true, onSelect: () => { crud.setError(null); crud.startDelete(immunization.id) } },
            ]}
          />
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
