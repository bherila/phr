import type { FormEvent } from 'react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { zodErrorMessage } from '@/phr/shared'

import {
  type HealthLog,
  type HealthLogFormData,
  HealthLogFormSchema,
  type HealthLogKind,
  healthLogPayload,
} from './healthLogSchemas'

const KIND_OPTIONS: { value: HealthLogKind; label: string; description: string }[] = [
  { value: 'meal', label: 'Meal', description: 'Breakfasts, lunches, dinners, and drinks' },
  { value: 'snack', label: 'Snack', description: 'Quick bites between meals' },
  { value: 'symptom', label: 'Symptom', description: 'Symptoms, triggers, and changes over time' },
  { value: 'custom', label: 'Custom', description: 'Anything else you want to observe' },
]

const SELECT_CLASS = 'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'

interface HealthLogFormProps {
  healthLog?: HealthLog
  busy: boolean
  onSubmit: (payload: Record<string, unknown>) => Promise<boolean>
  onCancel: () => void
}

export function HealthLogForm({ healthLog, busy, onSubmit, onCancel }: HealthLogFormProps) {
  const [form, setForm] = useState<HealthLogFormData>({
    name: healthLog?.name ?? '',
    kind: healthLog?.kind ?? 'custom',
    description: healthLog?.description ?? '',
  })
  const [validationError, setValidationError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    setValidationError(null)
    const parsed = HealthLogFormSchema.safeParse(form)
    if (!parsed.success) {
      setValidationError(zodErrorMessage(parsed.error))
      return
    }

    await onSubmit(healthLogPayload(parsed.data))
  }

  return (
    <form className="grid gap-3 rounded-lg border border-border bg-muted/20 p-3" onSubmit={(event) => void handleSubmit(event)}>
      <label className="grid gap-1 text-sm font-medium text-foreground">
        Log name
        <Input
          value={form.name}
          onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
          placeholder="Sinus congestion"
          autoFocus
          required
        />
      </label>
      <label className="grid gap-1 text-sm font-medium text-foreground">
        Type
        <select
          aria-label="Type"
          value={form.kind}
          className={SELECT_CLASS}
          onChange={(event) => setForm((current) => ({ ...current, kind: event.target.value as HealthLogKind }))}
        >
          {KIND_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
        <span className="text-xs font-normal text-muted-foreground">
          {KIND_OPTIONS.find((option) => option.value === form.kind)?.description}
        </span>
      </label>
      <label className="grid gap-1 text-sm font-medium text-foreground">
        Description
        <Textarea
          value={form.description}
          onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))}
          placeholder="What do you want to learn from this log?"
        />
      </label>
      {validationError && <p className="text-sm text-destructive">{validationError}</p>}
      <div className="flex flex-wrap gap-2">
        <Button type="submit" size="sm" disabled={busy}>{busy ? 'Saving…' : healthLog ? 'Save log' : 'Create log'}</Button>
        <Button type="button" size="sm" variant="outline" disabled={busy} onClick={onCancel}>Cancel</Button>
      </div>
    </form>
  )
}
