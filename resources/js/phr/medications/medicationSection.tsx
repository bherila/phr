import { Tabs } from '@base-ui/react/tabs'
import { type ReactElement, useCallback, useEffect, useState } from 'react'

import { fetchWrapper } from '@/fetchWrapper'
import { useRecordChanges } from '@/phr/clinical/recordChanges'
import { MedicationFillsResponseSchema } from '@/phr/medication-fills/fillTypes'
import { patientPageUrl } from '@/phr/shell/phrRoutes'
import { type PhrMedication, PhrMedicationsResponseSchema } from '@/phr/types'

/**
 * Medications and their fills are one section: the same tabs head both lists, and each list
 * reads a little of the other, the counts for its tabs and each medication's last fill.
 */
export type MedicationTab = 'active' | 'historical' | 'all'
export type MedicationSectionTab = MedicationTab | 'fills'

export type MedicationSectionCounts = Record<MedicationSectionTab, number | null>

const TABS: Array<{ value: MedicationSectionTab, label: string }> = [
  { value: 'active', label: 'Active' },
  { value: 'historical', label: 'Historical' },
  { value: 'all', label: 'All' },
  { value: 'fills', label: 'Fills' },
]

export function inMedicationTab(medication: PhrMedication, tab: MedicationTab): boolean {
  if (tab === 'active') return medication.status === 'active'
  return tab === 'all' || medication.status !== 'active'
}

export function medicationTabCounts(medications: PhrMedication[]): Record<MedicationTab, number> {
  return {
    active: medications.filter((medication) => inMedicationTab(medication, 'active')).length,
    historical: medications.filter((medication) => inMedicationTab(medication, 'historical')).length,
    all: medications.length,
  }
}

/** The URL of a medications tab. Active is the section's plain address. */
export function medicationTabUrl(patientId: number, tab: MedicationTab): string {
  const base = patientPageUrl(patientId, 'medications')
  return tab === 'active' ? base : `${base}?tab=${tab}`
}

/** The tab a link asked for, read once when the list opens. */
export function initialMedicationTab(): MedicationTab {
  const tab = new URLSearchParams(window.location.search).get('tab')
  return tab === 'historical' || tab === 'all' ? tab : 'active'
}

export function MedicationSectionTabs({ value, counts, onSelect }: {
  value: MedicationSectionTab
  counts: MedicationSectionCounts
  onSelect: (tab: MedicationSectionTab) => void
}): ReactElement {
  return (
    <div className="border-b border-border">
      <Tabs.Root value={value} onValueChange={(next: MedicationSectionTab) => onSelect(next)} className="max-w-full">
        <Tabs.List aria-label="Medications and fills" className="flex gap-1 overflow-x-auto">
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

function parseMedicationCounts(raw: unknown): Record<MedicationTab, number> | null {
  const parsed = PhrMedicationsResponseSchema.safeParse(raw)
  return parsed.success ? medicationTabCounts(parsed.data.medications) : null
}

/** The medication tabs' counts, for the Fills list. */
export function useMedicationCounts(patientId: number): Record<MedicationTab, number> | null {
  return useSiblingList(`/api/phr/patients/${patientId}/medications`, 'medications', patientId, parseMedicationCounts)
}
