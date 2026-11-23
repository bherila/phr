import { ClipboardList } from 'lucide-react'

import { formatDate } from '@/lib/dateFormat'
import type { PhrOfficeVisitLinkedRecord, PhrOfficeVisitLinkedRecords } from '@/phr/types'

interface OfficeVisitLinkedRecordsProps {
  records: PhrOfficeVisitLinkedRecords | undefined
}

const GROUPS: ReadonlyArray<{ key: keyof PhrOfficeVisitLinkedRecords; label: string }> = [
  { key: 'lab_results', label: 'Lab results' },
  { key: 'vitals', label: 'Vitals' },
  { key: 'medications', label: 'Medications' },
  { key: 'procedures', label: 'Procedures' },
  { key: 'documents', label: 'Documents and notes' },
]

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

/** The records linked to a visit as made during it (#411), grouped by kind. */
export default function OfficeVisitLinkedRecords({ records }: OfficeVisitLinkedRecordsProps) {
  const groups = GROUPS.map((group) => ({ ...group, items: records?.[group.key] ?? [] }))
    .filter((group) => group.items.length > 0)

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
      {groups.length === 0 ? (
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
        </div>
      )}
    </section>
  )
}
