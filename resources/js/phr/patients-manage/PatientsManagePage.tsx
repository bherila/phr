import { Pencil, Plus, Trash2, UserRound } from 'lucide-react'
import type { FormEvent } from 'react'
import { useCallback, useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fetchWrapper } from '@/fetchWrapper'
import { compactPayload, errorMessage } from '@/phr/shared'
import {
  type PhrPatient,
  type PhrPatientFormData,
  PhrPatientFormSchema,
  PhrPatientListResponseSchema,
  PhrPatientResponseSchema,
} from '@/phr/types'

const emptyForm: PhrPatientFormData = {
  display_name: '',
  relationship: '',
  birth_date: '',
  sex_at_birth: '',
  notes: '',
}

function patientToForm(patient: PhrPatient): PhrPatientFormData {
  return {
    display_name: patient.display_name ?? '',
    relationship: patient.relationship ?? '',
    birth_date: patient.birth_date ?? '',
    sex_at_birth: patient.sex_at_birth ?? '',
    notes: patient.notes ?? '',
  }
}

interface PatientFieldsProps {
  form: PhrPatientFormData
  onChange: (form: PhrPatientFormData) => void
}

function PatientFields({ form, onChange }: PatientFieldsProps) {
  return (
    <div className="grid gap-3">
      <label className="grid gap-1 text-sm font-medium text-foreground">
        Name *
        <Input
          value={form.display_name}
          onChange={(e) => onChange({ ...form, display_name: e.target.value })}
          required
        />
      </label>
      <label className="grid gap-1 text-sm font-medium text-foreground">
        Relationship
        <Input
          value={form.relationship ?? ''}
          onChange={(e) => onChange({ ...form, relationship: e.target.value })}
        />
      </label>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="grid gap-1 text-sm font-medium text-foreground">
          Birth Date
          <Input
            type="date"
            value={form.birth_date ?? ''}
            onChange={(e) => onChange({ ...form, birth_date: e.target.value })}
          />
        </label>
        <label className="grid gap-1 text-sm font-medium text-foreground">
          Sex at Birth
          <Input
            value={form.sex_at_birth ?? ''}
            onChange={(e) => onChange({ ...form, sex_at_birth: e.target.value })}
          />
        </label>
      </div>
      <label className="grid gap-1 text-sm font-medium text-foreground">
        Notes
        <textarea
          className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
          value={form.notes ?? ''}
          onChange={(e) => onChange({ ...form, notes: e.target.value })}
          maxLength={10000}
        />
      </label>
    </div>
  )
}

export default function PatientsManagePage() {
  const [patients, setPatients] = useState<PhrPatient[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [createForm, setCreateForm] = useState<PhrPatientFormData>(emptyForm)
  const [createBusy, setCreateBusy] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)

  const [editingId, setEditingId] = useState<number | null>(null)
  const [editForm, setEditForm] = useState<PhrPatientFormData>(emptyForm)
  const [editBusy, setEditBusy] = useState(false)
  const [editError, setEditError] = useState<string | null>(null)

  const [deletingId, setDeletingId] = useState<number | null>(null)
  const [deleteBusy, setDeleteBusy] = useState(false)

  const loadPatients = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.get('/api/phr/patients')
      const response = PhrPatientListResponseSchema.parse(raw)
      setPatients(response.patients)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }, [])

  useEffect(() => {
    void loadPatients()
  }, [loadPatients])

  async function handleCreate(e: FormEvent<HTMLFormElement>): Promise<void> {
    e.preventDefault()
    setCreateError(null)
    const parsed = PhrPatientFormSchema.safeParse(createForm)
    if (!parsed.success) {
      setCreateError(parsed.error.issues[0]?.message ?? 'Invalid input.')
      return
    }
    setCreateBusy(true)
    try {
      const raw: unknown = await fetchWrapper.post('/api/phr/patients', compactPayload(parsed.data))
      const response = PhrPatientResponseSchema.parse(raw)
      setPatients((prev) => [...prev, response.patient])
      setCreateForm(emptyForm)
    } catch (err) {
      setCreateError(errorMessage(err))
    } finally {
      setCreateBusy(false)
    }
  }

  function startEdit(patient: PhrPatient): void {
    setEditingId(patient.id)
    setEditForm(patientToForm(patient))
    setEditError(null)
  }

  function cancelEdit(): void {
    setEditingId(null)
    setEditError(null)
  }

  async function handleUpdate(e: FormEvent<HTMLFormElement>): Promise<void> {
    e.preventDefault()
    if (editingId === null) {
      return
    }
    setEditError(null)
    const parsed = PhrPatientFormSchema.safeParse(editForm)
    if (!parsed.success) {
      setEditError(parsed.error.issues[0]?.message ?? 'Invalid input.')
      return
    }
    setEditBusy(true)
    try {
      const raw: unknown = await fetchWrapper.patch(`/api/phr/patients/${editingId}`, compactPayload(parsed.data))
      const response = PhrPatientResponseSchema.parse(raw)
      setPatients((prev) => prev.map((p) => (p.id === editingId ? response.patient : p)))
      setEditingId(null)
    } catch (err) {
      setEditError(errorMessage(err))
    } finally {
      setEditBusy(false)
    }
  }

  async function handleDelete(patientId: number): Promise<void> {
    setDeleteBusy(true)
    try {
      await fetchWrapper.delete(`/api/phr/patients/${patientId}`, {})
      setPatients((prev) => prev.filter((p) => p.id !== patientId))
      setDeletingId(null)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setDeleteBusy(false)
    }
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-foreground">Manage Patients</h1>
        <p className="mt-1 text-sm text-muted-foreground">Create and edit patient profiles.</p>
      </div>

      {error && (
        <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      <div className="mb-8 rounded-lg border border-border bg-card p-4">
        <div className="mb-3 flex items-center gap-2">
          <Plus className="size-4 text-primary" />
          <h2 className="text-sm font-semibold text-card-foreground">Add Patient Profile</h2>
        </div>
        <form onSubmit={(e) => void handleCreate(e)}>
          <PatientFields form={createForm} onChange={setCreateForm} />
          {createError && <p className="mt-2 text-sm text-destructive">{createError}</p>}
          <div className="mt-4">
            <Button type="submit" size="sm" disabled={createBusy}>
              <Plus className="size-4" />
              {createBusy ? 'Adding…' : 'Add Profile'}
            </Button>
          </div>
        </form>
      </div>

      {busy && <p className="text-sm text-muted-foreground">Loading…</p>}

      {!busy && patients.length === 0 && (
        <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-border py-12 text-center">
          <UserRound className="h-8 w-8 text-muted-foreground/50" />
          <p className="text-sm text-muted-foreground">No patient profiles yet. Add one above.</p>
        </div>
      )}

      <div className="flex flex-col gap-4">
        {patients.map((patient) => {
          if (editingId === patient.id) {
            return (
              <div key={patient.id} className="rounded-lg border border-primary/40 bg-card p-4">
                <h3 className="mb-3 text-sm font-semibold text-card-foreground">Edit Profile</h3>
                <form onSubmit={(e) => void handleUpdate(e)}>
                  <PatientFields form={editForm} onChange={setEditForm} />
                  {editError && <p className="mt-2 text-sm text-destructive">{editError}</p>}
                  <div className="mt-4 flex gap-2">
                    <Button type="submit" size="sm" disabled={editBusy}>
                      {editBusy ? 'Saving…' : 'Save'}
                    </Button>
                    <Button type="button" variant="outline" size="sm" onClick={cancelEdit} disabled={editBusy}>
                      Cancel
                    </Button>
                  </div>
                </form>
              </div>
            )
          }

          if (deletingId === patient.id) {
            return (
              <div key={patient.id} className="rounded-lg border border-destructive/40 bg-card p-4">
                <p className="mb-3 text-sm text-foreground">
                  Delete <strong>{patient.display_name}</strong>? This cannot be undone.
                </p>
                <div className="flex gap-2">
                  <Button
                    variant="destructive"
                    size="sm"
                    disabled={deleteBusy}
                    onClick={() => void handleDelete(patient.id)}
                  >
                    {deleteBusy ? 'Deleting…' : 'Delete'}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={deleteBusy}
                    onClick={() => setDeletingId(null)}
                  >
                    Cancel
                  </Button>
                </div>
              </div>
            )
          }

          return (
            <div key={patient.id} className="flex items-start justify-between gap-4 rounded-lg border border-border bg-card p-4">
              <div className="flex items-center gap-3">
                <UserRound className="size-5 shrink-0 text-muted-foreground" />
                <div>
                  <p className="font-medium text-card-foreground">{patient.display_name ?? `Patient ${patient.id}`}</p>
                  {patient.relationship && (
                    <p className="text-xs text-muted-foreground">{patient.relationship}</p>
                  )}
                  {patient.birth_date && (
                    <p className="text-xs text-muted-foreground">DOB: {patient.birth_date}</p>
                  )}
                </div>
              </div>
              {patient.can_manage && (
                <div className="flex shrink-0 gap-1">
                  <Button variant="ghost" size="icon" onClick={() => startEdit(patient)} title="Edit">
                    <Pencil className="size-4" />
                  </Button>
                  {patient.can_share && (
                    <Button
                      variant="ghost"
                      size="icon"
                      className="text-destructive hover:text-destructive"
                      onClick={() => setDeletingId(patient.id)}
                      title="Delete"
                    >
                      <Trash2 className="size-4" />
                    </Button>
                  )}
                </div>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}
