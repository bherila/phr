import { ClipboardList } from 'lucide-react'

import { formatDate } from '@/lib/dateFormat'
import type { PhrOfficeVisitLinkedRecord, PhrOfficeVisitLinkedRecords, PhrOfficeVisitMedicationTransition } from '@/phr/types'

interface OfficeVisitLinkedRecordsProps {
  records: PhrOfficeVisitLinkedRecords | undefined
  transitions?: PhrOfficeVisitMedicationTransition[] | undefined
}

const GROUPS: ReadonlyArray<{ key: keyof PhrOfficeVisitLinkedRecords; label: string }> = [
  { key: 'lab_results', label: 'Lab results' },
  { key: 'vitals', label: 'Vitals' },
  { key: 'medications', label: 'Medications' },
  { key: 'procedures', label: 'Procedures' },
  { key: 'documents', label: 'Documents and notes' },
]

const PHASE_LABELS: Record<PhrOfficeVisitMedicationTransition['phase'], string> = {
  admission: 'Admission',
  discharge: 'Discharge',
}

function recordName(record: PhrOfficeVisitLinkedRecord): string {
  return record.name ?? record.title ?? record.document_type ?? `Record ${record.id}`
}

function recordDetail(record: PhrOfficeVisitLinkedRecord): string {
  const value = [record.value, record.unit].filter(Boolean).join(' ')
  const date = record.observed_at ?? record.performed_on

  return [
    value || null,
    record.abnormal_flag,
    record.dose,
    record.status,
    date ? formatDate(date) : null,
  ].filter(Boolean).join(' · ')
}

/** "Admission: changed to insulin lispro sliding scale", "Discharge: continued". */
function transitionStep(transition: PhrOfficeVisitMedicationTransition): string {
  const order = transition.action === 'changed' && transition.order_medication ? ` to ${transition.order_medication.name}` : ''
  const pending = transition.review_status === 'pending_review' ? ' (waiting for review)' : ''

  return `${PHASE_LABELS[transition.phase]}: ${transition.action}${order}${pending}`
}

interface TransitionRow {
  key: string
  name: string
  steps: string[]
  notes: string[]
}

/**
 * Each home medication with what happened to it during the stay (#414), admission before
 * discharge, then each new order.
 */
function medicationTransitionRows(transitions: PhrOfficeVisitMedicationTransition[]): TransitionRow[] {
  const ordered = [...transitions].sort((a, b) => (a.phase === b.phase ? a.id - b.id : a.phase === 'admission' ? -1 : 1))
  const rows = new Map<string, TransitionRow>()
  for (const transition of ordered) {
    const medication = transition.home_medication ?? transition.order_medication
    const key = transition.home_medication ? `home-${transition.home_medication.id}` : `new-${transition.order_medication?.id ?? transition.id}`
    const row = rows.get(key) ?? { key, name: medication?.name ?? `Medication change ${transition.id}`, steps: [], notes: [] }
    row.steps.push(transitionStep(transition))
    if (transition.note) row.notes.push(transition.note)
    rows.set(key, row)
  }

  return [...rows.values()].sort((a, b) => Number(a.key.startsWith('new-')) - Number(b.key.startsWith('new-')))
}

/** The records linked to a visit as made during it (#411), grouped by kind, and its medication changes (#414). */
export default function OfficeVisitLinkedRecords({ records, transitions }: OfficeVisitLinkedRecordsProps) {
  const groups = GROUPS.map((group) => ({ ...group, items: records?.[group.key] ?? [] }))
    .filter((group) => group.items.length > 0)
  const changes = medicationTransitionRows(transitions ?? [])

  return (
    <section className="rounded-lg border border-border bg-card p-4" aria-label="Records from this visit">
      <div>
        <h2 className="flex items-center gap-2 font-semibold text-card-foreground">
          <ClipboardList className="size-4 text-primary" />
          Records from this visit
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Results, vitals, medications, procedures and documents recorded during this encounter.
        </p>
      </div>
      {groups.length === 0 && changes.length === 0 ? (
        <p className="mt-4 text-sm text-muted-foreground">No records are linked to this visit.</p>
      ) : (
        <div className="mt-4 space-y-4">
          {groups.map((group) => (
            <div key={group.key}>
              <h3 className="text-sm font-medium text-card-foreground">{group.label}</h3>
              <ul className="mt-2 divide-y divide-border rounded-md border border-border">
                {group.items.map((record) => {
                  const detail = recordDetail(record)

                  return (
                    <li key={record.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                      <p className="min-w-0 text-sm font-medium text-card-foreground">{recordName(record)}</p>
                      {detail && <span className="text-xs text-muted-foreground">{detail}</span>}
                    </li>
                  )
                })}
              </ul>
            </div>
          ))}
          {changes.length > 0 && (
            <div>
              <h3 className="text-sm font-medium text-card-foreground">Medication changes</h3>
              <p className="mt-1 text-xs text-muted-foreground">What happened to each home medication at admission and discharge, and the orders that were new.</p>
              <ul className="mt-2 divide-y divide-border rounded-md border border-border">
                {changes.map((row) => (
                  <li key={row.key} className="px-3 py-2">
                    <p className="text-sm font-medium text-card-foreground">{row.name}</p>
                    <p className="text-xs text-muted-foreground">{row.steps.join(' · ')}</p>
                    {row.notes.map((note) => (
                      <p key={note} className="text-xs text-muted-foreground">{note}</p>
                    ))}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </section>
  )
}
