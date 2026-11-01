import type { FormEvent, ReactElement } from 'react'

import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { compactPayload } from '@/phr/shared'
import type { PhrMedication } from '@/phr/types'

export type MedicationStatus = 'active' | 'completed' | 'discontinued' | 'on_hold'

export interface MedicationFormState {
  name: string
  dose: string
  doseUnit: string
  route: string
  frequency: string
  startedOn: string
  endedOn: string
  status: MedicationStatus
  prescriberName: string
  reasonForUse: string
}

const STATUS_OPTIONS: Array<{ value: MedicationStatus, label: string }> = [
  { value: 'active', label: 'Active' },
  { value: 'completed', label: 'Completed' },
  { value: 'discontinued', label: 'Discontinued' },
  { value: 'on_hold', label: 'On hold' },
]

export const EMPTY_MEDICATION_FORM: MedicationFormState = {
  name: '',
  dose: '',
  doseUnit: '',
  route: '',
  frequency: '',
  startedOn: '',
  endedOn: '',
  status: 'active',
  prescriberName: '',
  reasonForUse: '',
}

export function resolveMedicationStatus(value: string): MedicationStatus | null {
  return STATUS_OPTIONS.find((option) => option.value === value)?.value ?? null
}

/** "On hold" for `on_hold`; a status this client does not know is still shown, readably. */
export function medicationStatusLabel(status: string): string {
  const known = STATUS_OPTIONS.find((option) => option.value === status)?.label
  if (known) return known
  const words = status.replaceAll('_', ' ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}

export function medicationFormFromMedication(medication: PhrMedication): MedicationFormState {
  const status = resolveMedicationStatus(medication.status)

  if (!status) {
    console.warn(
      `Unexpected medication status "${medication.status}" for medication ${medication.id}; using "active" in the edit form until the data is corrected.`,
    )
  }

  return {
    name: medication.name,
    dose: medication.dose ?? '',
    doseUnit: medication.dose_unit ?? '',
    route: medication.route ?? '',
    frequency: medication.frequency ?? '',
    startedOn: medication.started_on ?? '',
    endedOn: medication.ended_on ?? '',
    status: status ?? 'active',
    prescriberName: medication.prescriber_name ?? '',
    reasonForUse: medication.reason_for_use ?? '',
  }
}

export function medicationPayload(form: MedicationFormState): Record<string, unknown> {
  return compactPayload({
    name: form.name,
    dose: form.dose,
    dose_unit: form.doseUnit,
    route: form.route,
    frequency: form.frequency,
    started_on: form.startedOn,
    ended_on: form.endedOn,
    status: form.status,
    prescriber_name: form.prescriberName,
    reason_for_use: form.reasonForUse,
  })
}

function localToday(): string {
  const today = new Date()
  const month = String(today.getMonth() + 1).padStart(2, '0')
  const day = String(today.getDate()).padStart(2, '0')
  return `${today.getFullYear()}-${month}-${day}`
}

/**
 * An end date that has arrived settles the status, so the form stops asking. The server applies
 * the same rule to every writer (`PhrMedication::status`). A date still ahead, such as the last
 * day of a course, leaves the medication active until then.
 */
export function endDateHasArrived(endedOn: string): boolean {
  return endedOn !== '' && endedOn <= localToday()
}

function MedicationFormFields({ form, onChange }: { form: MedicationFormState, onChange: (form: MedicationFormState) => void }): ReactElement {
  return (
    <div className="grid gap-3 md:grid-cols-2">
      <label className="grid gap-1 text-sm font-medium md:col-span-2">
        Name *
        <Input value={form.name} onChange={(event) => onChange({ ...form, name: event.target.value })} required />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Dose
        <Input value={form.dose} onChange={(event) => onChange({ ...form, dose: event.target.value })} placeholder="500" />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Dose Unit
        <Input value={form.doseUnit} onChange={(event) => onChange({ ...form, doseUnit: event.target.value })} placeholder="mg" />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Route
        <Input value={form.route} onChange={(event) => onChange({ ...form, route: event.target.value })} placeholder="PO" />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Frequency
        <Input value={form.frequency} onChange={(event) => onChange({ ...form, frequency: event.target.value })} placeholder="BID" />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Started On
        <Input type="date" value={form.startedOn} onChange={(event) => onChange({ ...form, startedOn: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium">
        Ended On
        <Input
          type="date"
          value={form.endedOn}
          // Taking the end date away means it has not ended, whatever the date had made it.
          onChange={(event) => onChange({ ...form, endedOn: event.target.value, status: event.target.value === '' && form.endedOn !== '' ? 'active' : form.status })}
        />
      </label>
      {endDateHasArrived(form.endedOn) ? (
        <div className="grid gap-1 text-sm font-medium">
          Status
          <p className="flex h-9 items-center text-sm font-normal text-muted-foreground">Ended, because it has an end date</p>
        </div>
      ) : (
        <label className="grid gap-1 text-sm font-medium">
          Status
          <select
            value={form.status}
            onChange={(event) => onChange({ ...form, status: event.target.value as MedicationStatus })}
            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm"
          >
            {STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </label>
      )}
      <label className="grid gap-1 text-sm font-medium">
        Prescriber
        <Input value={form.prescriberName} onChange={(event) => onChange({ ...form, prescriberName: event.target.value })} placeholder="Dr. Example" />
      </label>
      <label className="grid gap-1 text-sm font-medium md:col-span-2">
        Reason for Use
        <Textarea value={form.reasonForUse} onChange={(event) => onChange({ ...form, reasonForUse: event.target.value })} placeholder="Why the patient is taking this medication" />
      </label>
    </div>
  )
}

/** Adding or editing a medication, from the list or from its detail. Full height on a phone. */
export function MedicationFormDialog({ title, submitLabel, form, onChange, busy, error, onSubmit, onClose }: {
  title: string
  submitLabel: string
  form: MedicationFormState
  onChange: (form: MedicationFormState) => void
  busy: boolean
  /** Shown inside the dialog: the page behind it is covered. */
  error?: string | null | undefined
  onSubmit: () => void
  onClose: () => void
}): ReactElement {
  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault()
    onSubmit()
  }

  return (
    <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose() }} disablePointerDismissal>
      <DialogContent className="max-h-dvh overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription className="sr-only">The medication's dose, schedule, dates and prescriber.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-3">
          {error && <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</div>}
          <MedicationFormFields form={form} onChange={onChange} />
          <DialogFooter>
            <Button type="button" variant="outline" disabled={busy} onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={busy}>{busy ? 'Saving…' : submitLabel}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
