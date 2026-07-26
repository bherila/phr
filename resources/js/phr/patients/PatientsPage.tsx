import { Plus, UserRound } from 'lucide-react'
import { type MouseEvent, useCallback, useEffect, useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { patientUrl } from '@/lib/phrRouteBuilder'
import { errorMessage } from '@/phr/shared'
import { type PhrPatient, PhrPatientListResponseSchema } from '@/phr/types'

interface PatientsPageProps {
  onPatientSelect?: (patientId: number, hash?: string) => void
  onManagePatients?: () => void
}

const ACCESS_BADGE: Record<string, string> = {
  owner: 'bg-primary/10 text-primary',
  manager: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300',
  viewer: 'bg-muted text-muted-foreground',
}

export default function PatientsPage({ onPatientSelect, onManagePatients }: PatientsPageProps) {
  const [patients, setPatients] = useState<PhrPatient[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

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

  function handleManagePatientsClick(event: MouseEvent<HTMLAnchorElement>): void {
    if (!onManagePatients) {
      return
    }

    event.preventDefault()
    onManagePatients()
  }

  function handlePatientClick(event: MouseEvent<HTMLAnchorElement>, patientId: number, hash?: string): void {
    if (!onPatientSelect) {
      return
    }

    event.preventDefault()
    onPatientSelect(patientId, hash)
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">Patients</h1>
          <p className="mt-1 text-sm text-muted-foreground">Your patient profiles and shared records.</p>
        </div>
        <Button asChild>
          <a href="/phr/patients/manage" onClick={handleManagePatientsClick}>
            <Plus className="mr-2 h-4 w-4" />
            Add Patient
          </a>
        </Button>
      </div>

      {error && (
        <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      {busy && <p className="text-sm text-muted-foreground">Loading…</p>}

      {!busy && patients.length === 0 && (
        <div className="flex flex-col items-center gap-4 rounded-lg border border-dashed border-border py-16 text-center">
          <UserRound className="h-10 w-10 text-muted-foreground/50" />
          <div>
            <p className="font-medium text-foreground">No patient profiles yet</p>
            <p className="mt-1 text-sm text-muted-foreground">Add a profile to start tracking health records.</p>
          </div>
          <Button asChild>
            <a href="/phr/patients/manage" onClick={handleManagePatientsClick}>Add Patient</a>
          </Button>
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {patients.map((patient) => (
          <a
            key={patient.id}
            href={patientUrl(patient.id)}
            onClick={(event) => handlePatientClick(event, patient.id)}
            className="group flex flex-col gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:border-primary/50 hover:bg-accent/40"
          >
            <div className="flex items-start justify-between gap-2">
              <div className="flex items-center gap-2">
                <UserRound className="h-5 w-5 shrink-0 text-muted-foreground" />
                <span className="font-medium text-card-foreground group-hover:text-foreground">
                  {patient.display_name ?? `Patient ${patient.id}`}
                </span>
              </div>
              {patient.access_level && (
                <span
                  className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${ACCESS_BADGE[patient.access_level] ?? ACCESS_BADGE.viewer}`}
                >
                  {patient.access_level}
                </span>
              )}
            </div>

            {patient.relationship && (
              <p className="text-xs text-muted-foreground">{patient.relationship}</p>
            )}

            <div className="mt-auto flex flex-wrap gap-1">
              {(['labs', 'vitals', 'imaging'] as const).map((tab) => (
                <Badge
                  key={tab}
                  variant="secondary"
                  className="text-xs"
                  onClick={(e) => {
                    if (!onPatientSelect) {
                      return
                    }

                    e.preventDefault()
                    e.stopPropagation()
                    onPatientSelect(patient.id, `#/${tab}`)
                  }}
                >
                  {tab.charAt(0).toUpperCase() + tab.slice(1)}
                </Badge>
              ))}
            </div>
          </a>
        ))}
      </div>
    </div>
  )
}
