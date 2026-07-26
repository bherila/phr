import type { FormEvent } from 'react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { zodErrorMessage } from '@/phr/shared'

import {
  entryFormFromRecord,
  type HealthLog,
  type HealthLogEntry,
  type HealthLogEntryFormData,
  HealthLogEntryFormSchema,
  healthLogEntryPayload,
  localDateTimeInputValue,
} from './healthLogSchemas'

interface HealthLogEntryFormProps {
  healthLog: HealthLog
  entry?: HealthLogEntry
  busy: boolean
  onSubmit: (payload: Record<string, unknown>) => Promise<boolean>
  onCancel?: () => void
}

function emptyEntryForm(): HealthLogEntryFormData {
  return {
    occurred_at: localDateTimeInputValue(),
    title: '',
    notes: '',
    intensity: '',
    tags: '',
    details_json: '',
  }
}

function titlePlaceholder(healthLog: HealthLog): string {
  if (healthLog.kind === 'meal' || healthLog.kind === 'snack') {
    return 'What did you have?'
  }
  if (healthLog.kind === 'symptom') {
    return 'What changed?'
  }
  return 'Short summary'
}

export function HealthLogEntryForm({ healthLog, entry, busy, onSubmit, onCancel }: HealthLogEntryFormProps) {
  const [form, setForm] = useState<HealthLogEntryFormData>(() => entry ? entryFormFromRecord(entry) : emptyEntryForm())
  const [validationError, setValidationError] = useState<string | null>(null)
  const [showDetails, setShowDetails] = useState(Boolean(entry?.details))

  async function handleSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    setValidationError(null)
    const parsed = HealthLogEntryFormSchema.safeParse(form)
    if (!parsed.success) {
      setValidationError(zodErrorMessage(parsed.error))
      return
    }

    const saved = await onSubmit(healthLogEntryPayload(parsed.data))
    if (saved && !entry) {
      setForm(emptyEntryForm())
      setShowDetails(false)
    }
  }

  return (
    <form className="grid gap-4" onSubmit={(event) => void handleSubmit(event)}>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="grid gap-1 text-sm font-medium text-foreground">
          When
          <Input
            type="datetime-local"
            value={form.occurred_at}
            onChange={(event) => setForm((current) => ({ ...current, occurred_at: event.target.value }))}
            required
          />
        </label>
        <label className="grid gap-1 text-sm font-medium text-foreground">
          Intensity <span className="font-normal text-muted-foreground">(0–10)</span>
          <Input
            type="number"
            min="0"
            max="10"
            step="1"
            value={form.intensity}
            onChange={(event) => setForm((current) => ({ ...current, intensity: event.target.value }))}
            placeholder="Optional"
          />
        </label>
        <label className="grid gap-1 text-sm font-medium text-foreground sm:col-span-2">
          Title
          <Input
            value={form.title}
            onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))}
            placeholder={titlePlaceholder(healthLog)}
          />
        </label>
        <label className="grid gap-1 text-sm font-medium text-foreground sm:col-span-2">
          Notes
          <Textarea
            value={form.notes}
            onChange={(event) => setForm((current) => ({ ...current, notes: event.target.value }))}
            placeholder="What happened? Include foods, symptoms, triggers, or anything else worth remembering."
          />
        </label>
        <label className="grid gap-1 text-sm font-medium text-foreground sm:col-span-2">
          Tags <span className="font-normal text-muted-foreground">(comma separated)</span>
          <Input
            value={form.tags}
            onChange={(event) => setForm((current) => ({ ...current, tags: event.target.value }))}
            placeholder="morning, after exercise, restaurant"
          />
        </label>
      </div>

      {showDetails ? (
        <label className="grid gap-1 text-sm font-medium text-foreground">
          Structured details <span className="font-normal text-muted-foreground">(optional JSON object)</span>
          <Textarea
            className="min-h-28 font-mono text-xs"
            value={form.details_json}
            onChange={(event) => setForm((current) => ({ ...current, details_json: event.target.value }))}
            placeholder={'{\n  "location": "left side",\n  "duration_minutes": 30\n}'}
          />
        </label>
      ) : (
        <Button type="button" size="sm" variant="ghost" className="justify-self-start" onClick={() => setShowDetails(true)}>
          Add structured details
        </Button>
      )}

      {validationError && <p className="text-sm text-destructive">{validationError}</p>}
      <div className="flex flex-wrap gap-2">
        <Button type="submit" size="sm" disabled={busy}>{busy ? 'Saving…' : entry ? 'Save entry' : 'Record entry'}</Button>
        {onCancel && <Button type="button" size="sm" variant="outline" disabled={busy} onClick={onCancel}>Cancel</Button>}
      </div>
    </form>
  )
}
