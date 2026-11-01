import { PillBottle } from 'lucide-react'
import { useMemo, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fetchWrapper } from '@/fetchWrapper'
import { useClinicalCrud } from '@/phr/clinical/crud'
import { ShowRejectedToggle } from '@/phr/clinical/review'
import { errorMessage } from '@/phr/shared'
import type { PhrListPageProps } from '@/phr/shell/phrPages'

import { FillEntryForm, FillFormDialog } from './FillEntryForm'
import { FillsTable } from './FillsTable'
import {
  type MedicationFill,
  MedicationFillResponseSchema,
  MedicationFillsResponseSchema,
} from './fillTypes'

const SELECT_CLASS = 'flex h-9 min-w-40 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm'

export default function MedicationFillsPage({ patientId }: PhrListPageProps) {
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [medicationId, setMedicationId] = useState('')
  const [editing, setEditing] = useState<MedicationFill | null>(null)
  const [editKey, setEditKey] = useState(0)

  const crud = useClinicalCrud<MedicationFill, Record<string, never>>({
    endpoint: `/api/phr/patients/${patientId}/medication-fills`,
    emptyForm: {},
    formFromRecord: () => ({}),
    parseItem: (raw) => MedicationFillResponseSchema.parse(raw).medication_fill,
    parseList: (raw) => {
      const parsed = MedicationFillsResponseSchema.parse(raw)
      return { records: parsed.medication_fills, canManage: parsed.can_manage }
    },
    payloadFromForm: () => ({}),
  })
  const firstLoad = crud.busy && crud.records.length === 0

  const medications = useMemo(() => {
    const byId = new Map<number, string>()
    for (const fill of crud.records) {
      if (fill.medication) byId.set(fill.medication.id, fill.medication.name)
    }
    return [...byId.entries()].sort((a, b) => a[1].localeCompare(b[1]))
  }, [crud.records])

  // Filters narrow the loaded list in the browser; dates are ISO strings, so they compare as text.
  const rows = useMemo(() => crud.records.filter((fill) => {
    const day = fill.filled_on.slice(0, 10)
    if (from && day < from) return false
    if (to && day > to) return false
    if (medicationId && String(fill.medication_id) !== medicationId) return false
    return true
  }), [crud.records, from, to, medicationId])

  const filtered = from !== '' || to !== '' || medicationId !== ''

  function created(fill: MedicationFill): void {
    crud.setRecords((current) => [fill, ...current.filter((existing) => existing.id !== fill.id)])
  }

  function saved(fill: MedicationFill): void {
    crud.setRecords((current) => current.map((existing) => (existing.id === fill.id ? fill : existing)))
    setEditing(null)
  }

  // The fill changed elsewhere: fetch the latest and reopen the form on it.
  async function reloadEditing(): Promise<void> {
    if (!editing) return
    try {
      const raw: unknown = await fetchWrapper.get(`/api/phr/patients/${patientId}/medication-fills/${editing.id}`)
      const latest = MedicationFillResponseSchema.parse(raw).medication_fill
      crud.setRecords((current) => current.map((existing) => (existing.id === latest.id ? latest : existing)))
      setEditing(latest)
      setEditKey((key) => key + 1)
    } catch (caught) {
      crud.setError(errorMessage(caught))
    }
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
            <PillBottle className="size-6 text-primary" />
            Fills
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Prescription fills: what was dispensed, when, and what it cost.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <label className="grid gap-1 text-sm font-medium text-foreground">
            From
            <Input type="date" aria-label="Filled from" value={from} onChange={(event) => setFrom(event.target.value)} />
          </label>
          <label className="grid gap-1 text-sm font-medium text-foreground">
            To
            <Input type="date" aria-label="Filled to" value={to} onChange={(event) => setTo(event.target.value)} />
          </label>
          <label className="grid gap-1 text-sm font-medium text-foreground">
            Medication
            <select aria-label="Medication filter" value={medicationId} onChange={(event) => setMedicationId(event.target.value)} className={SELECT_CLASS}>
              <option value="">All medications</option>
              {medications.map(([id, name]) => <option key={id} value={String(id)}>{name}</option>)}
            </select>
          </label>
          {filtered && (
            <Button type="button" size="sm" variant="ghost" onClick={() => { setFrom(''); setTo(''); setMedicationId('') }}>Clear filters</Button>
          )}
          <ShowRejectedToggle showRejected={crud.showRejected} onChange={crud.setShowRejected} disabled={crud.busy} />
        </div>
      </div>

      {crud.error && (
        <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {crud.error}
        </div>
      )}

      {crud.canManage && (
        <div className="mb-6 flex flex-wrap items-center gap-2">
          <FillEntryForm patientId={patientId} onCreated={created} />
        </div>
      )}

      {editing && (
        <FillFormDialog
          patientId={patientId}
          fill={editing}
          formKey={`${editing.id}:${editKey}`}
          onSaved={saved}
          onClose={() => setEditing(null)}
          onReload={() => void reloadEditing()}
        />
      )}

      {!crud.busy && crud.records.length === 0 ? (
        <div className="rounded-lg border border-dashed border-border px-6 py-12 text-center text-sm text-muted-foreground">
          <p className="font-medium text-foreground">No fills recorded.</p>
          <p className="mx-auto mt-2 max-w-xl">
            Fills arrive two ways: add one by hand with its NDC, or ask your connected AI agent to import a photo of a prescription label, a pharmacy receipt or a CSV over MCP. Imported fills wait here for your review.
          </p>
        </div>
      ) : (
        <section className="rounded-lg border border-border bg-card">
          <FillsTable
            patientId={patientId}
            label="Fills"
            rows={rows}
            loading={firstLoad}
            empty={<div className="px-4 py-8 text-sm text-muted-foreground">No fills match the current filters.</div>}
            onEdit={crud.canManage ? setEditing : undefined}
            actions={crud.canManage ? {
              isMutating: crud.isMutating,
              onReview: (id, decision) => void crud.reviewRecord(id, decision),
              deletingId: crud.deletingId,
              onStartDelete: crud.startDelete,
              onCancelDelete: crud.cancelDelete,
              onConfirmDelete: (id) => void crud.deleteRecord(id),
            } : undefined}
          />
        </section>
      )}
    </div>
  )
}
