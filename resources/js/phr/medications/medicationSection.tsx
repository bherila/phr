import { Tabs } from '@base-ui/react/tabs'
import { type ReactElement, useCallback, useEffect, useState } from 'react'

import { fetchWrapper } from '@/fetchWrapper'
import { useRecordChanges } from '@/phr/clinical/recordChanges'
import { MedicationFillsResponseSchema } from '@/phr/medication-fills/fillTypes'
import { pushPhrLocation } from '@/phr/shell/phrLocation'
import { patientPageUrl } from '@/phr/shell/phrRoutes'
import { type PhrMedication, PhrMedicationsResponseSchema } from '@/phr/types'

/**
 * Medications and their fills are one section that opens on Fills. The tabs head both lists, and
 * each list reads a little of the other: its tab's count, and each medication's last fill.
 */
export type MedicationSectionTab = 'fills' | 'medications'
export type MedicationStatusFilter = 'active' | 'ended' | 'all'

export type MedicationSectionCounts = Record<MedicationSectionTab, number | null>

const TABS: Array<{ value: MedicationSectionTab, label: string, page: 'medication-fills' | 'medications' }> = [
  { value: 'fills', label: 'Fills', page: 'medication-fills' },
  { value: 'medications', label: 'Medications', page: 'medications' },
]

const STATUS_FILTERS: Array<{ value: MedicationStatusFilter, label: string }> = [
  { value: 'active', label: 'Active' },
  { value: 'ended', label: 'Ended' },
  { value: 'all', label: 'All' },
]

/** On hold has not ended: it stays with what the person is taking. */
export function matchesMedicationStatus(medication: PhrMedication, filter: MedicationStatusFilter): boolean {
  if (filter === 'all') return true
  const running = medication.status === 'active' || medication.status === 'on_hold'
  return filter === 'active' ? running : !running
}

export function medicationStatusCounts(medications: PhrMedication[]): Record<MedicationStatusFilter, number> {
  return {
    active: medications.filter((medication) => matchesMedicationStatus(medication, 'active')).length,
    ended: medications.filter((medication) => matchesMedicationStatus(medication, 'ended')).length,
    all: medications.length,
  }
}

/** The filter a link asked for, read once when the list opens. */
export function initialMedicationStatus(): MedicationStatusFilter {
  const status = new URLSearchParams(window.location.search).get('status')
  return status === 'ended' || status === 'all' ? status : 'active'
}

export function MedicationSectionTabs({ patientId, value, counts }: {
  patientId: number
  value: MedicationSectionTab
  counts: MedicationSectionCounts
}): ReactElement {
  function select(next: MedicationSectionTab): void {
    const tab = TABS.find((candidate) => candidate.value === next)
    if (tab && next !== value) pushPhrLocation(patientPageUrl(patientId, tab.page))
  }
  return (
    <div className="border-b border-border">
      <Tabs.Root value={value} onValueChange={select} className="max-w-full">
        <Tabs.List aria-label="Medications views" className="flex gap-1 overflow-x-auto">
          {TABS.map((tab) => (
            <Tabs.Tab
              key={tab.value}
              value={tab.value}
              className="-mb-px h-9 whitespace-nowrap border-b-2 border-transparent px-3 text-sm font-medium text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring data-[active]:border-primary data-[active]:text-foreground max-md:h-11 max-md:px-2"
            >
              {tab.label} <span className="font-normal text-muted-foreground">{counts[tab.value] ?? ''}</span>
            </Tabs.Tab>
          ))}
        </Tabs.List>
      </Tabs.Root>
    </div>
  )
}

export function MedicationStatusFilters({ value, counts, onSelect }: {
  value: MedicationStatusFilter
  counts: Record<MedicationStatusFilter, number> | null
  onSelect: (filter: MedicationStatusFilter) => void
}): ReactElement {
  return (
    <div role="group" aria-label="Filter by status" className="flex flex-wrap gap-1.5">
      {STATUS_FILTERS.map((filter) => (
        <button
          key={filter.value}
          type="button"
          aria-pressed={value === filter.value}
          onClick={() => onSelect(filter.value)}
          className="h-8 rounded-full border border-border px-3 text-sm font-medium text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring aria-pressed:border-accent aria-pressed:bg-accent aria-pressed:text-accent-foreground max-md:h-11"
        >
          {filter.label} <span className="font-normal">{counts?.[filter.value] ?? ''}</span>
        </button>
      ))}
    </div>
  )
}

/** A list the other tab reads for what it shows; it follows changes to its records. */
function useSiblingList<T>(url: string, resource: string, patientId: number, parse: (raw: unknown) => T | null): T | null {
  const [value, setValue] = useState<T | null>(null)
  const load = useCallback(() => {
    fetchWrapper.get(url).then((raw: unknown) => setValue(parse(raw))).catch(() => setValue(null))
  }, [url, parse])
  useEffect(() => load(), [load])
  useRecordChanges(resource, patientId, 'list', load)
  useRecordChanges(resource, patientId, 'detail', load)
  return value
}

export interface MedicationFillSummary {
  count: number
  lastFilledOn: string
}

export interface FillOverview {
  total: number
  byMedication: Map<number, MedicationFillSummary>
}

function parseFillOverview(raw: unknown): FillOverview | null {
  const parsed = MedicationFillsResponseSchema.safeParse(raw)
  if (!parsed.success) return null
  const byMedication = new Map<number, MedicationFillSummary>()
  for (const fill of parsed.data.medication_fills) {
    if (fill.medication_id === null) continue
    const day = fill.filled_on.slice(0, 10)
    const seen = byMedication.get(fill.medication_id)
    byMedication.set(fill.medication_id, {
      count: (seen?.count ?? 0) + 1,
      lastFilledOn: seen && seen.lastFilledOn > day ? seen.lastFilledOn : day,
    })
  }
  return { total: parsed.data.medication_fills.length, byMedication }
}

/** How many fills the patient has, and each medication's count and latest date. */
export function useFillOverview(patientId: number): FillOverview | null {
  return useSiblingList(`/api/phr/patients/${patientId}/medication-fills`, 'medication-fills', patientId, parseFillOverview)
}

function parseMedicationCount(raw: unknown): number | null {
  const parsed = PhrMedicationsResponseSchema.safeParse(raw)
  return parsed.success ? parsed.data.medications.length : null
}

/** How many medications the patient has, for the Fills list's tabs. */
export function useMedicationCount(patientId: number): number | null {
  return useSiblingList(`/api/phr/patients/${patientId}/medications`, 'medications', patientId, parseMedicationCount)
}
