import { type FormEvent, type ReactElement, type ReactNode, useEffect, useId, useRef, useState } from 'react'

import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { type FieldErrors, fieldErrorsFrom, focusFirstInvalid } from '@/phr/clinical/fieldErrors'
import { RecordFormDialog } from '@/phr/clinical/RecordFormDialog'
import { compactPayload } from '@/phr/shared'
import {
  type PhrAllergy,
  type PhrAllergyFormData,
  PhrAllergyFormSchema,
} from '@/phr/types'

const SELECT_CLASS = 'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm'

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

export const EMPTY_ALLERGY_FORM: PhrAllergyFormData = {
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
  errors?: FieldErrors<PhrAllergyFormData>
}

export function allergyFormFromRecord(allergy: PhrAllergy): PhrAllergyFormData {
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

export function allergyPayload(form: PhrAllergyFormData): Record<string, unknown> {
  return compactPayload(form)
}

const FIELD_MESSAGES: FieldErrors<PhrAllergyFormData> = {
  substance: 'Substances are at most 255 characters.',
  rxnorm_code: 'RxNorm codes are at most 50 characters.',
  snomed_code: 'SNOMED codes are at most 50 characters.',
  reaction: 'Reactions are at most 255 characters.',
  notes: 'Notes are at most 10000 characters.',
}

function AllergyField({ id, label, error, wide = false, children }: { id: string, label: string, error?: string | undefined, wide?: boolean, children: ReactNode }): ReactElement {
  return (
    <div className={`grid gap-1 ${wide ? 'md:col-span-2' : ''}`}>
      <label htmlFor={id} className="text-sm font-medium">{label}</label>
      {children}
      {error && <p id={`${id}-error`} className="text-xs text-destructive">{error}</p>}
    </div>
  )
}

function Select<T extends string>({ id, value, options, onChange }: { id: string, value: T, options: ReadonlyArray<{ value: string, label: string }>, onChange: (value: T) => void }): ReactElement {
  return (
    <select id={id} value={value} onChange={(event) => onChange(event.target.value as T)} className={SELECT_CLASS}>
      {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
    </select>
  )
}

export function AllergyFormFields({ form, onChange, errors = {} }: AllergyFormFieldsProps) {
  const id = useId()
  const set = <K extends keyof PhrAllergyFormData>(key: K, value: PhrAllergyFormData[K]): void => onChange({ ...form, [key]: value })
  const invalid = (field: keyof PhrAllergyFormData): { 'aria-invalid'?: true, 'aria-describedby'?: string } => (
    errors[field] ? { 'aria-invalid': true, 'aria-describedby': `${id}-${field}-error` } : {}
  )
  return (
    <div className="grid gap-3 md:grid-cols-2">
      <AllergyField id={`${id}-substance`} label="Substance *" error={errors.substance} wide>
        <Input id={`${id}-substance`} value={form.substance} maxLength={255} placeholder="Penicillin" onChange={(event) => set('substance', event.target.value)} {...invalid('substance')} />
      </AllergyField>
      <AllergyField id={`${id}-reaction`} label="Reaction" error={errors.reaction}>
        <Input id={`${id}-reaction`} value={form.reaction} maxLength={255} placeholder="Hives, anaphylaxis" onChange={(event) => set('reaction', event.target.value)} {...invalid('reaction')} />
      </AllergyField>
      <AllergyField id={`${id}-category`} label="Category">
        <Select id={`${id}-category`} value={form.category} options={CATEGORY_OPTIONS} onChange={(value) => set('category', value)} />
      </AllergyField>
      <AllergyField id={`${id}-criticality`} label="Criticality">
        <Select id={`${id}-criticality`} value={form.criticality} options={CRITICALITY_OPTIONS} onChange={(value) => set('criticality', value)} />
      </AllergyField>
      <AllergyField id={`${id}-severity`} label="Severity">
        <Select id={`${id}-severity`} value={form.severity} options={SEVERITY_OPTIONS} onChange={(value) => set('severity', value)} />
      </AllergyField>
      <AllergyField id={`${id}-clinical_status`} label="Clinical Status">
        <Select id={`${id}-clinical_status`} value={form.clinical_status} options={STATUS_OPTIONS} onChange={(value) => set('clinical_status', value)} />
      </AllergyField>
      <AllergyField id={`${id}-verification_status`} label="Verification">
        <Select id={`${id}-verification_status`} value={form.verification_status} options={VERIFICATION_OPTIONS} onChange={(value) => set('verification_status', value)} />
      </AllergyField>
      <AllergyField id={`${id}-rxnorm_code`} label="RxNorm Code" error={errors.rxnorm_code}>
        <Input id={`${id}-rxnorm_code`} value={form.rxnorm_code} maxLength={50} onChange={(event) => set('rxnorm_code', event.target.value)} {...invalid('rxnorm_code')} />
      </AllergyField>
      <AllergyField id={`${id}-snomed_code`} label="SNOMED Code" error={errors.snomed_code}>
        <Input id={`${id}-snomed_code`} value={form.snomed_code} maxLength={50} onChange={(event) => set('snomed_code', event.target.value)} {...invalid('snomed_code')} />
      </AllergyField>
      <AllergyField id={`${id}-notes`} label="Notes" error={errors.notes} wide>
        <Textarea id={`${id}-notes`} value={form.notes} maxLength={10000} onChange={(event) => set('notes', event.target.value)} {...invalid('notes')} />
      </AllergyField>
    </div>
  )
}

/**
 * Adds or edits an allergy over the page, from the list or the allergy's detail, so neither
 * moves. Problems are shown on their fields, and focus goes to the first one.
 */
export function AllergyFormDialog({ allergy, busy, error, onSubmit, onClose }: {
  allergy: PhrAllergy | null
  busy: boolean
  error: string | null
  /** Saves the parsed form; resolves true once the server stored it. */
  onSubmit: (form: PhrAllergyFormData) => Promise<boolean>
  onClose: () => void
}): ReactElement {
  const formRef = useRef<HTMLFormElement>(null)
  const [form, setForm] = useState<PhrAllergyFormData>(() => (allergy ? allergyFormFromRecord(allergy) : EMPTY_ALLERGY_FORM))
  const [errors, setErrors] = useState<FieldErrors<PhrAllergyFormData>>({})

  useEffect(() => {
    focusFirstInvalid(formRef.current)
  }, [errors])

  async function submit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    const parsed = PhrAllergyFormSchema.safeParse(form)
    if (!parsed.success) {
      setErrors(fieldErrorsFrom(parsed.error, {
        ...FIELD_MESSAGES,
        ...(!form.substance.trim() ? { substance: 'Enter the substance.' } : {}),
      }))
      return
    }
    setErrors({})
    await onSubmit(parsed.data)
  }

  return (
    <RecordFormDialog
      title={allergy ? 'Edit allergy' : 'Add allergy'}
      description="The substance, the reaction, how serious it is, its status and notes."
      submitLabel={allergy ? 'Save' : 'Add allergy'}
      busy={busy}
      error={error}
      fieldErrors={errors}
      onSubmit={(event) => void submit(event)}
      onClose={onClose}
      formRef={formRef}
    >
      <AllergyFormFields form={form} onChange={setForm} errors={errors} />
    </RecordFormDialog>
  )
}
