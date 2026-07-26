import { UsersRound } from 'lucide-react'

import type { PhrPatient } from '@/phr/types'

interface PatientListProps {
  patients: PhrPatient[]
  selectedPatientId: number | null
  onSelect: (patientId: number) => void
}

export default function PatientList({ patients, selectedPatientId, onSelect }: PatientListProps) {
  return (
    <section className="rounded-md border border-border bg-card p-4">
      <div className="mb-3 flex items-center gap-2">
        <UsersRound className="size-4 text-primary" />
        <h2 className="text-sm font-semibold text-card-foreground">Profiles</h2>
      </div>
      <div className="flex flex-col gap-2">
        {patients.length === 0 ? (
          <p className="text-sm text-muted-foreground">No patient profiles.</p>
        ) : patients.map((patient) => (
          <button
            key={patient.id}
            type="button"
            className={[
              'rounded-md border px-3 py-2 text-left transition-colors',
              selectedPatientId === patient.id
                ? 'border-primary bg-accent text-accent-foreground'
                : 'border-border bg-background hover:bg-muted/60',
            ].join(' ')}
            onClick={() => onSelect(patient.id)}
          >
            <span className="block truncate text-sm font-medium">{patient.display_name}</span>
            <span className="mt-1 block truncate text-xs text-muted-foreground">
              {patient.relationship || 'Profile'} · {patient.access_level ?? 'viewer'}
            </span>
          </button>
        ))}
      </div>
    </section>
  )
}
