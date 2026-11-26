import { X } from 'lucide-react'
import { type KeyboardEvent, type ReactElement, useId, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import type { PhrPatient, PhrPatientFormData } from '@/phr/types'

export const EMPTY_PATIENT_FORM: PhrPatientFormData = {
  display_name: '',
  real_names: [],
  relationship: '',
  birth_date: '',
  sex_at_birth: '',
  notes: '',
}

export function patientFormFromPatient(patient: PhrPatient): PhrPatientFormData {
  return {
    display_name: patient.display_name ?? '',
    real_names: patient.real_names ?? [],
    relationship: patient.relationship ?? '',
    birth_date: patient.birth_date ?? '',
    sex_at_birth: patient.sex_at_birth ?? '',
    notes: patient.notes ?? '',
  }
}

/** Suggestions only: the field stays free text, as imports and agents write it. */
export const RELATIONSHIP_SUGGESTIONS = ['Self', 'Child', 'Spouse or partner', 'Parent', 'Sibling'] as const

/**
 * The choices offered for sex at birth. The value is stored as the label, which the C-CDA and
 * FHIR exports already map ("Male", "Female"); "M" and "F" from older entries select the same
 * choice without being rewritten.
 */
const SEX_AT_BIRTH_OPTIONS = [
  { value: '', label: 'Not recorded', matches: [''] },
  { value: 'Female', label: 'Female', matches: ['female', 'f'] },
  { value: 'Male', label: 'Male', matches: ['male', 'm'] },
  { value: 'Intersex', label: 'Intersex', matches: ['intersex'] },
] as const

/** The option a stored value selects, or null for a value none of them covers. */
export function sexAtBirthOption(value: string): string | null {
  const normalized = value.trim().toLowerCase()
  return SEX_AT_BIRTH_OPTIONS.find((option) => (option.matches as readonly string[]).includes(normalized))?.value ?? null
}

/** The server accepts up to 20 distinct names. */
const MAX_REAL_NAMES = 20

export type PatientFieldErrors = Partial<Record<keyof PhrPatientFormData, string>>

interface PatientProfileFieldsProps {
  form: PhrPatientFormData
  onChange: (form: PhrPatientFormData) => void
  errors?: PatientFieldErrors
  /** Collapse names on documents, sex at birth and notes under "More details". */
  collapsible?: boolean
  disabled?: boolean
}

export function PatientProfileFields({ form, onChange, errors = {}, collapsible = false, disabled = false }: PatientProfileFieldsProps): ReactElement {
  const id = useId()
  const hasDetails = (form.real_names ?? []).length > 0 || Boolean(form.sex_at_birth) || Boolean(form.notes)
  const [detailsOpen, setDetailsOpen] = useState(!collapsible || hasDetails)
  const set = <K extends keyof PhrPatientFormData>(key: K, value: PhrPatientFormData[K]): void => onChange({ ...form, [key]: value })
  const relationship = (form.relationship ?? '').trim().toLowerCase()

  return (
    <div className="grid gap-4">
      <FieldShell id={`${id}-name`} label="Name *" error={errors.display_name} help="What you call them. Names as printed on documents go under More details.">
        <Input
          id={`${id}-name`}
          value={form.display_name}
          onChange={(event) => set('display_name', event.target.value)}
          maxLength={255}
          autoComplete="off"
          disabled={disabled}
          aria-invalid={errors.display_name ? true : undefined}
          aria-describedby={`${id}-name-help${errors.display_name ? ` ${id}-name-error` : ''}`}
        />
      </FieldShell>

      <div className="grid gap-4 sm:grid-cols-2">
        <FieldShell id={`${id}-birth`} label="Birth date" error={errors.birth_date}>
          <Input id={`${id}-birth`} type="date" value={form.birth_date ?? ''} onChange={(event) => set('birth_date', event.target.value)} disabled={disabled} />
        </FieldShell>
        <FieldShell id={`${id}-relationship`} label="Relationship" error={errors.relationship}>
          <Input
            id={`${id}-relationship`}
            value={form.relationship ?? ''}
            onChange={(event) => set('relationship', event.target.value)}
            maxLength={50}
            autoComplete="off"
            disabled={disabled}
          />
        </FieldShell>
      </div>
      <div role="group" aria-label="Relationship suggestions" className="-mt-2 flex flex-wrap gap-1.5">
        {RELATIONSHIP_SUGGESTIONS.map((suggestion) => {
          const selected = relationship === suggestion.toLowerCase()
          return (
            <button
              key={suggestion}
              type="button"
              aria-pressed={selected}
              disabled={disabled}
              onClick={() => set('relationship', selected ? '' : suggestion)}
              className={cn(
                'min-h-8 rounded-full border px-3 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50',
                selected ? 'border-accent-foreground bg-accent text-accent-foreground' : 'border-border bg-card text-foreground hover:bg-muted',
              )}
            >
              {suggestion}
            </button>
          )
        })}
      </div>

      {collapsible && (
        <button
          type="button"
          aria-expanded={detailsOpen}
          aria-controls={`${id}-details`}
          onClick={() => setDetailsOpen((open) => !open)}
          className="justify-self-start text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          {detailsOpen ? 'Fewer details' : 'More details: names on documents, sex at birth, notes'}
        </button>
      )}

      <div id={`${id}-details`} hidden={!detailsOpen} className="grid gap-4">
        <RealNamesField names={form.real_names ?? []} onChange={(names) => set('real_names', names)} disabled={disabled} error={errors.real_names} />
        <SexAtBirthField value={form.sex_at_birth ?? ''} onChange={(value) => set('sex_at_birth', value)} disabled={disabled} />
        <FieldShell id={`${id}-notes`} label="Notes" error={errors.notes}>
          <textarea
            id={`${id}-notes`}
            className="flex min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:opacity-50"
            value={form.notes ?? ''}
            onChange={(event) => set('notes', event.target.value)}
            maxLength={10000}
            disabled={disabled}
          />
        </FieldShell>
      </div>
    </div>
  )
}

function FieldShell({ id, label, help, error, children }: { id: string, label: string, help?: string, error?: string | undefined, children: ReactElement }): ReactElement {
  return (
    <div className="grid gap-1.5">
      <label htmlFor={id} className="text-sm font-medium text-foreground">{label}</label>
      {children}
      {help && <p id={`${id}-help`} className="text-xs text-muted-foreground">{help}</p>}
      {error && <p id={`${id}-error`} className="text-xs text-destructive">{error}</p>}
    </div>
  )
}

/**
 * Names as printed on documents, one token each. A name is added with Enter or Add, and also when
 * the field loses focus, so a typed name is never left behind by Save.
 */
function RealNamesField({ names, onChange, disabled, error }: { names: string[], onChange: (names: string[]) => void, disabled: boolean, error?: string | undefined }): ReactElement {
  const id = useId()
  const [draft, setDraft] = useState('')
  const full = names.length >= MAX_REAL_NAMES
  const duplicate = draft.trim() !== '' && names.some((name) => name.toLowerCase() === draft.trim().toLowerCase())

  function commit(): void {
    const name = draft.trim()
    if (name === '' || full || duplicate) return
    onChange([...names, name])
    setDraft('')
  }

  function handleKeyDown(event: KeyboardEvent<HTMLInputElement>): void {
    if (event.key === 'Enter') {
      event.preventDefault()
      commit()
    } else if (event.key === 'Backspace' && draft === '' && names.length > 0) {
      onChange(names.slice(0, -1))
    }
  }

  return (
    <div className="grid gap-1.5">
      <label htmlFor={id} className="text-sm font-medium text-foreground">Names on documents</label>
      {names.length > 0 && (
        <ul aria-label="Listed names" className="flex flex-wrap gap-1.5">
          {names.map((name) => (
            <li key={name} className="flex items-center gap-1 rounded-md bg-muted py-0.5 pr-0.5 pl-2 text-sm text-foreground">
              {name}
              <button
                type="button"
                disabled={disabled}
                onClick={() => onChange(names.filter((candidate) => candidate !== name))}
                aria-label={`Remove ${name}`}
                className="flex size-6 items-center justify-center rounded text-muted-foreground hover:bg-background hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <X className="size-3.5" aria-hidden="true" />
              </button>
            </li>
          ))}
        </ul>
      )}
      <div className="flex gap-2">
        <Input
          id={id}
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
          onKeyDown={handleKeyDown}
          onBlur={commit}
          placeholder={full ? 'Up to 20 names' : 'Add a name, e.g. RIVERA, SAMUEL J'}
          maxLength={255}
          autoComplete="off"
          disabled={disabled || full}
          aria-describedby={`${id}-help`}
        />
        <Button type="button" variant="outline" size="sm" className="h-9" disabled={disabled || full || draft.trim() === '' || duplicate} onClick={commit}>Add</Button>
      </div>
      <p id={`${id}-help`} className="text-xs text-muted-foreground">
        {duplicate ? 'That name is already listed.' : 'Used to match imported documents to this patient.'}
      </p>
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  )
}

function SexAtBirthField({ value, onChange, disabled }: { value: string, onChange: (value: string) => void, disabled: boolean }): ReactElement {
  const name = useId()
  const selected = sexAtBirthOption(value)
  // A stored value none of the choices covers stays offered as its own choice after another is
  // picked, so it can be picked back. It is remembered whenever it arrives, since the form may
  // be filled in after this field first renders.
  const [other, setOther] = useState<string | null>(null)
  if (selected === null && value !== '' && other !== value) setOther(value)

  return (
    <fieldset className="grid gap-1.5">
      <legend className="mb-1.5 text-sm font-medium text-foreground">Sex at birth</legend>
      <div className="flex flex-wrap overflow-hidden rounded-md border border-input">
        {[...SEX_AT_BIRTH_OPTIONS.map((option) => ({ value: option.value, label: option.label, checked: selected === option.value })), ...(other ? [{ value: other, label: `Other: ${other}`, checked: selected === null }] : [])].map((option) => (
          <label
            key={option.label}
            className={cn(
              'flex min-h-9 flex-1 cursor-pointer items-center justify-center border-l border-input px-3 text-sm whitespace-nowrap first:border-l-0 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-inset',
              option.checked ? 'bg-accent font-semibold text-accent-foreground' : 'bg-background text-foreground hover:bg-muted',
              disabled && 'cursor-not-allowed opacity-50',
            )}
          >
            <input type="radio" name={name} className="sr-only" value={option.value} checked={option.checked} disabled={disabled} onChange={() => onChange(option.value)} />
            {option.label}
          </label>
        ))}
      </div>
    </fieldset>
  )
}
