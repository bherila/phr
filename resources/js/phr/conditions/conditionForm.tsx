import { type FormEvent, type ReactElement, type ReactNode, useEffect, useId, useRef, useState } from 'react'

import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { type FieldErrors, fieldErrorsFrom, focusFirstInvalid } from '@/phr/clinical/fieldErrors'
import { RecordFormDialog } from '@/phr/clinical/RecordFormDialog'
import { compactPayload } from '@/phr/shared'
import { type PhrCondition, type PhrConditionFormData, PhrConditionFormSchema } from '@/phr/types'

const SELECT_CLASS = 'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm'

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

export const EMPTY_CONDITION_FORM: PhrConditionFormData = {
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

/** The form's own words for what Zod would otherwise report in its own. */
const FIELD_MESSAGES: FieldErrors<PhrConditionFormData> = {
  name: "Enter the condition's name.",
  icd10_code: 'ICD-10 codes are at most 20 characters.',
  snomed_code: 'SNOMED codes are at most 50 characters.',
}

export function conditionFormFromRecord(condition: PhrCondition): PhrConditionFormData {
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

export function conditionPayload(form: PhrConditionFormData): Record<string, unknown> {
  return compactPayload(form)
}

function Field({ id, label, error, className, children }: { id: string, label: string, error?: string | undefined, className?: string, children: ReactNode }): ReactElement {
  return (
    <div className={`grid gap-1 ${className ?? ''}`}>
      <label htmlFor={id} className="text-sm font-medium">{label}</label>
      {children}
      {error && <p id={`${id}-error`} className="text-xs text-destructive">{error}</p>}
    </div>
  )
}

export function ConditionFormFields({ form, onChange, errors = {} }: {
  form: PhrConditionFormData
  onChange: (form: PhrConditionFormData) => void
  errors?: FieldErrors<PhrConditionFormData>
}): ReactElement {
  const id = useId()
  const invalid = (field: keyof PhrConditionFormData): { 'aria-invalid'?: true, 'aria-describedby'?: string } => (
    errors[field] ? { 'aria-invalid': true, 'aria-describedby': `${id}-${field}-error` } : {}
  )

  return (
    <div className="grid gap-3 md:grid-cols-2">
      <Field id={`${id}-name`} label="Name *" error={errors.name} className="md:col-span-2">
        <Input id={`${id}-name`} value={form.name} maxLength={255} onChange={(event) => onChange({ ...form, name: event.target.value })} {...invalid('name')} />
      </Field>
      <Field id={`${id}-icd10_code`} label="ICD-10 Code" error={errors.icd10_code}>
        <Input id={`${id}-icd10_code`} value={form.icd10_code} maxLength={20} onChange={(event) => onChange({ ...form, icd10_code: event.target.value })} placeholder="E11.9" {...invalid('icd10_code')} />
      </Field>
      <Field id={`${id}-snomed_code`} label="SNOMED Code" error={errors.snomed_code}>
        <Input id={`${id}-snomed_code`} value={form.snomed_code} maxLength={50} onChange={(event) => onChange({ ...form, snomed_code: event.target.value })} {...invalid('snomed_code')} />
      </Field>
      <Field id={`${id}-onset_date`} label="Onset Date" error={errors.onset_date}>
        <Input id={`${id}-onset_date`} type="date" value={form.onset_date} onChange={(event) => onChange({ ...form, onset_date: event.target.value })} {...invalid('onset_date')} />
      </Field>
      <Field id={`${id}-abated_date`} label="Abated Date" error={errors.abated_date}>
        <Input id={`${id}-abated_date`} type="date" value={form.abated_date} onChange={(event) => onChange({ ...form, abated_date: event.target.value })} {...invalid('abated_date')} />
      </Field>
      <Field id={`${id}-clinical_status`} label="Clinical Status">
        <select
          id={`${id}-clinical_status`}
          value={form.clinical_status}
          onChange={(event) => onChange({ ...form, clinical_status: event.target.value as PhrConditionFormData['clinical_status'] })}
          className={SELECT_CLASS}
        >
          {CONDITION_STATUSES.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </Field>
      <Field id={`${id}-verification_status`} label="Verification">
        <select
          id={`${id}-verification_status`}
          value={form.verification_status}
          onChange={(event) => onChange({ ...form, verification_status: event.target.value as PhrConditionFormData['verification_status'] })}
          className={SELECT_CLASS}
        >
          {VERIFICATION_STATUSES.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </Field>
      <Field id={`${id}-severity`} label="Severity">
        <select
          id={`${id}-severity`}
          value={form.severity}
          onChange={(event) => onChange({ ...form, severity: event.target.value as PhrConditionFormData['severity'] })}
          className={SELECT_CLASS}
        >
          {SEVERITY_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </Field>
      <Field id={`${id}-notes`} label="Notes" error={errors.notes} className="md:col-span-2">
        <Textarea id={`${id}-notes`} value={form.notes} maxLength={10000} onChange={(event) => onChange({ ...form, notes: event.target.value })} {...invalid('notes')} />
      </Field>
    </div>
  )
}

interface ConditionFormDialogProps {
  /** The condition to edit; without one the dialog adds a condition. */
  condition?: PhrCondition | null
  busy: boolean
  /** A refused save, shown inside the dialog: the page behind it is covered. */
  error?: string | null
  /** Saves the parsed form; resolves true once the server stored it, and the dialog closes. */
  onSubmit: (form: PhrConditionFormData) => Promise<boolean>
  onClose: () => void
}

/**
 * Adds or edits a condition over the page, so the list beside it never moves. Problems are shown
 * on their fields, and focus goes to the first one.
 */
export function ConditionFormDialog({ condition = null, busy, error = null, onSubmit, onClose }: ConditionFormDialogProps): ReactElement {
  const formRef = useRef<HTMLFormElement>(null)
  const [form, setForm] = useState<PhrConditionFormData>(() => (condition ? conditionFormFromRecord(condition) : EMPTY_CONDITION_FORM))
  const [errors, setErrors] = useState<FieldErrors<PhrConditionFormData>>({})

  useEffect(() => {
    focusFirstInvalid(formRef.current)
  }, [errors])

  async function submit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    const parsed = PhrConditionFormSchema.safeParse(form)
    if (!parsed.success) {
      setErrors(fieldErrorsFrom(parsed.error, FIELD_MESSAGES))
      return
    }
    setErrors({})
    await onSubmit(parsed.data)
  }

  return (
    <RecordFormDialog
      title={condition ? 'Edit condition' : 'Add condition'}
      description="The condition's name, codes, dates, status and notes."
      submitLabel={condition ? 'Save' : 'Add condition'}
      busy={busy}
      error={error}
      onSubmit={(event) => void submit(event)}
      onClose={onClose}
      formRef={formRef}
    >
      <ConditionFormFields form={form} onChange={setForm} errors={errors} />
    </RecordFormDialog>
  )
}
