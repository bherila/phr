import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
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

export function AllergyFormFields({ form, onChange }: AllergyFormFieldsProps) {
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
