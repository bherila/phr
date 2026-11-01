import { useMemo, useState } from 'react'

import { fetchWrapper } from '@/fetchWrapper'
import { type FillColumn, saveDisplayPreferences, useDisplayPreferences } from '@/lib/displayPreferences'
import { useClinicalCrud } from '@/phr/clinical/crud'
import { recordChanged, useRecordChanges } from '@/phr/clinical/recordChanges'
import { MedicationSectionTabs, useMedicationCount } from '@/phr/medications/medicationSection'
import { useKnownPatientCanManage } from '@/phr/patients/usePhrPatients'
import { errorMessage } from '@/phr/shared'
import type { PhrListPageProps } from '@/phr/shell/phrPages'
import { openRecordId } from '@/phr/shell/phrRoutes'

import { FillEntryForm, FillFormDialog } from './FillEntryForm'
import { FillFilters, type FillFilterValues, NO_FILL_FILTERS } from './FillFilters'
import { FillsTable } from './FillsTable'
import {
  fillDrugName,
  type MedicationFill,
  MedicationFillResponseSchema,
  MedicationFillsResponseSchema,
} from './fillTypes'

/** A medication's detail links here with `?medication_id=`, so the list opens on its fills. */
function initialFilters(): FillFilterValues {
  const medicationId = new URLSearchParams(window.location.search).get('medication_id')
  return medicationId && /^\d+$/.test(medicationId) ? { ...NO_FILL_FILTERS, medicationId } : NO_FILL_FILTERS
}

export default function MedicationFillsPage({ patientId, onDrill, openRecord }: PhrListPageProps) {
  const openRowId = openRecordId(openRecord, 'medication-fill-detail')
  const [filters, setFilters] = useState(initialFilters)
  const { query, from, to, medicationId } = filters
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
    initialCanManage: useKnownPatientCanManage(patientId),
    onRecordChanged: (recordId) => recordChanged({ resource: 'medication-fills', patientId, recordId, origin: 'list' }),
  })
  useRecordChanges('medication-fills', patientId, 'detail', () => void crud.reload())
  const firstLoad = crud.busy && crud.records.length === 0
  const { hide_medication_list: hideMedicationList, hidden_fill_columns: hiddenColumns } = useDisplayPreferences()
  const medicationCount = useMedicationCount(patientId, !hideMedicationList)

  const medicationName = useMemo(
    () => crud.records.find((fill) => String(fill.medication_id) === medicationId)?.medication?.name ?? null,
    [crud.records, medicationId],
  )

  // Filters narrow the loaded list in the browser; dates are ISO strings, so they compare as text.
  const needle = query.trim().toLowerCase()
  const rows = useMemo(() => crud.records.filter((fill) => {
    const day = fill.filled_on.slice(0, 10)
    if (from && day < from) return false
    if (to && day > to) return false
    if (medicationId && String(fill.medication_id) !== medicationId) return false
    if (needle && !fillDrugName(fill).toLowerCase().includes(needle)) return false
    return true
  }), [crud.records, from, to, medicationId, needle])

  function setColumnShown(column: FillColumn, shown: boolean): void {
    const hidden = shown ? hiddenColumns.filter((candidate) => candidate !== column) : [...hiddenColumns, column]
    saveDisplayPreferences({ hidden_fill_columns: hidden }).catch((caught: unknown) => crud.setError(errorMessage(caught)))
  }

  function created(fill: MedicationFill): void {
    crud.setRecords((current) => [fill, ...current.filter((existing) => existing.id !== fill.id)])
  }

  function saved(fill: MedicationFill): void {
    crud.setRecords((current) => current.map((existing) => (existing.id === fill.id ? fill : existing)))
    setEditing(null)
    recordChanged({ resource: 'medication-fills', patientId, recordId: fill.id, origin: 'list' })
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
    <div className="@container/fills-page flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <h1 className="text-2xl font-semibold text-foreground">Medications</h1>
        {crud.canManage && <div className="ml-auto"><FillEntryForm patientId={patientId} onCreated={created} /></div>}
      </div>

      <MedicationSectionTabs patientId={patientId} value="fills" counts={{ fills: firstLoad ? null : crud.records.length, medications: medicationCount }} />

      <FillFilters
        values={filters}
        onChange={setFilters}
        medicationName={medicationName}
        shownCount={rows.length}
        hiddenColumns={hiddenColumns}
        onColumnChange={setColumnShown}
      />

      {crud.error && (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {crud.error}
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
            label="Fills"
            rows={rows}
            loading={firstLoad}
            empty={<div className="px-4 py-8 text-sm text-muted-foreground">No fills match these filters.</div>}
            hiddenColumns={hiddenColumns}
            onDrill={onDrill}
            openRowId={openRowId}
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
