import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { compactPayload } from '@/phr/shared'
import { type PhrProcedure, type PhrProcedureFormData, PhrProcedureFormSchema } from '@/phr/types'

const SELECT_CLASS = 'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm'

const STATUS_OPTIONS = [
  { value: 'completed', label: 'Completed' },
  { value: 'preparation', label: 'Preparation' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'entered_in_error', label: 'Entered in Error' },
] as const

export const EMPTY_FORM: PhrProcedureFormData = {
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

export function procedureFormFromRecord(procedure: PhrProcedure): PhrProcedureFormData {
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

export function procedurePayload(form: PhrProcedureFormData): Record<string, unknown> {
  return compactPayload(form)
}

function toDatetimeLocal(value: string | null): string {
  return value ? value.replace(' ', 'T').slice(0, 16) : ''
}

export function ProcedureFormFields({ form, onChange }: ProcedureFormFieldsProps) {
  return (
    <div className="grid min-w-0 grid-cols-1 gap-3 @[560px]:grid-cols-2">
      <label className="grid gap-1 text-sm font-medium @[560px]:col-span-2">
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
      <label className="grid gap-1 text-sm font-medium @[560px]:col-span-2">
        Reason
        <Textarea value={form.reason} onChange={(event) => onChange({ ...form, reason: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium @[560px]:col-span-2">
        Outcome
        <Textarea value={form.outcome} onChange={(event) => onChange({ ...form, outcome: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium @[560px]:col-span-2">
        Notes
        <Textarea value={form.notes} onChange={(event) => onChange({ ...form, notes: event.target.value })} />
      </label>
    </div>
  )
}
