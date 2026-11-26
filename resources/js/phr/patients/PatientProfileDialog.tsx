import { type FormEvent, type ReactElement, useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { fetchWrapper } from '@/fetchWrapper'
import { usePhrPatientListActions } from '@/phr/patients/usePhrPatients'
import { compactPayload, errorMessage } from '@/phr/shared'
import { type PhrPatient, type PhrPatientFormData, PhrPatientFormSchema, PhrPatientResponseSchema } from '@/phr/types'

import { EMPTY_PATIENT_FORM, type PatientFieldErrors, patientFormFromPatient, PatientProfileFields } from './PatientProfileFields'

interface PatientProfileDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The patient to edit; without one the dialog adds a patient. */
  patient?: PhrPatient | null
  /** Called with the saved patient once the server has stored it. */
  onSaved?: (patient: PhrPatient, created: boolean) => void
}

/**
 * Adds a patient or edits one's profile. Adding asks only for what most people know up front and
 * keeps the rest under More details; the saved patient goes into the shared patient list, so the
 * navbar, cards, sidebar and import picker all follow.
 */
export function PatientProfileDialog({ open, onOpenChange, patient = null, onSaved }: PatientProfileDialogProps): ReactElement {
  const patientList = usePhrPatientListActions()
  const editing = patient !== null
  const [form, setForm] = useState<PhrPatientFormData>(EMPTY_PATIENT_FORM)
  const [fieldErrors, setFieldErrors] = useState<PatientFieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!open) return
    setForm(patient ? patientFormFromPatient(patient) : EMPTY_PATIENT_FORM)
    setFieldErrors({})
    setFormError(null)
  }, [open, patient])

  async function submit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    setFormError(null)
    if (form.display_name.trim() === '') {
      setFieldErrors({ display_name: 'Enter a name.' })
      event.currentTarget.querySelector<HTMLInputElement>('[aria-invalid="true"], input')?.focus()
      return
    }
    const parsed = PhrPatientFormSchema.safeParse(form)
    if (!parsed.success) {
      const errors: PatientFieldErrors = {}
      for (const issue of parsed.error.issues) {
        const key = issue.path[0] as keyof PhrPatientFormData | undefined
        if (key && !errors[key]) errors[key] = issue.message
      }
      setFieldErrors(errors)
      return
    }
    setFieldErrors({})
    setBusy(true)
    try {
      const payload = compactPayload(parsed.data)
      const raw: unknown = editing
        ? await fetchWrapper.patch(`/api/phr/patients/${patient.id}`, payload)
        : await fetchWrapper.post('/api/phr/patients', payload)
      const saved = PhrPatientResponseSchema.parse(raw).patient
      patientList?.upsertPatient(saved)
      onOpenChange(false)
      onSaved?.(saved, !editing)
    } catch (caught) {
      setFormError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => { if (!busy) onOpenChange(next) }}>
      <DialogContent className="sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{editing ? 'Edit profile' : 'Add patient'}</DialogTitle>
          <DialogDescription>
            {editing ? 'Changes apply wherever this patient appears.' : 'You can add the rest later from their record.'}
          </DialogDescription>
        </DialogHeader>
        <form noValidate onSubmit={(event) => void submit(event)} className="grid gap-5">
          <PatientProfileFields form={form} onChange={setForm} errors={fieldErrors} collapsible={!editing} disabled={busy} />
          {formError && <p role="alert" className="text-sm text-destructive">{formError}</p>}
          <DialogFooter>
            <Button type="button" variant="outline" disabled={busy} onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button type="submit" disabled={busy}>
              {busy ? 'Saving…' : editing ? 'Save' : 'Add and open record'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
