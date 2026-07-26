import { Plus, UserRound } from 'lucide-react'
import type { ComponentProps, FormEvent } from 'react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fetchWrapper } from '@/fetchWrapper'
import { compactPayload, errorMessage } from '@/phr/shared'
import {
  type PhrPatient,
  type PhrPatientFormData,
  PhrPatientFormSchema,
  PhrPatientResponseSchema,
} from '@/phr/types'

interface PatientFormProps {
  onCreated: (patient: PhrPatient) => void
  setBusy: (busy: boolean) => void
}

const emptyPatientForm: PhrPatientFormData = {
  display_name: '',
  relationship: '',
  birth_date: '',
  sex_at_birth: '',
  notes: '',
}

export default function PatientForm({ onCreated, setBusy }: PatientFormProps) {
  const [form, setForm] = useState<PhrPatientFormData>(emptyPatientForm)
  const [error, setError] = useState<string | null>(null)

  async function submit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    setError(null)

    const parsed = PhrPatientFormSchema.safeParse(form)
    if (!parsed.success) {
      setError(parsed.error.issues[0]?.message ?? 'Invalid patient profile.')
      return
    }

    setBusy(true)
    try {
      const rawResponse: unknown = await fetchWrapper.post('/api/phr/patients', compactPayload(parsed.data))
      const response = PhrPatientResponseSchema.parse(rawResponse)
      onCreated(response.patient)
      setForm(emptyPatientForm)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="rounded-md border border-border bg-card p-4">
      <div className="mb-3 flex items-center gap-2">
        <UserRound className="size-4 text-primary" />
        <h2 className="text-sm font-semibold text-card-foreground">Patient Profile</h2>
      </div>
      <form className="grid gap-3" onSubmit={(event) => void submit(event)}>
        <LabeledInput label="Name" value={form.display_name} onChange={(value) => setForm({ ...form, display_name: value })} required />
        <LabeledInput label="Relationship" value={form.relationship ?? ''} onChange={(value) => setForm({ ...form, relationship: value })} />
        <LabeledInput label="Birth Date" type="date" value={form.birth_date ?? ''} onChange={(value) => setForm({ ...form, birth_date: value })} />
        <LabeledInput label="Sex At Birth" value={form.sex_at_birth ?? ''} onChange={(value) => setForm({ ...form, sex_at_birth: value })} />
        {error ? <p className="text-sm text-destructive">{error}</p> : null}
        <Button type="submit" size="sm">
          <Plus className="size-4" />
          Add Profile
        </Button>
      </form>
    </section>
  )
}

interface LabeledInputProps extends Omit<ComponentProps<typeof Input>, 'onChange'> {
  label: string
  onChange: (value: string) => void
}

function LabeledInput({ label, onChange, ...props }: LabeledInputProps) {
  return (
    <label className="grid gap-1 text-sm font-medium text-foreground">
      {label}
      <Input {...props} onChange={(event) => onChange(event.target.value)} />
    </label>
  )
}
