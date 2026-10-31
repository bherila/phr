import { Lock, Plus, Search, UserRound } from 'lucide-react'
import { type MouseEvent, type ReactElement, useEffect, useMemo, useState } from 'react'

import { Button } from '@/components/ui/button'
import { patientUrl } from '@/lib/phrRouteBuilder'
import { cn } from '@/lib/utils'
import { formatSourceDate } from '@/phr/imports/ehi/ehiProposalView'
import { patientPageUrl, type PhrPageId } from '@/phr/shell/phrRoutes'
import { patientDisplayName } from '@/phr/shell/PhrSidebar'
import type { PhrPatient } from '@/phr/types'

import { attentionTitle, getPatientOverview, type PatientOverview } from './patientOverview'
import { usePhrPatients } from './usePhrPatients'

interface PatientsPageProps {
  onPatientSelect?: (patientId: number, page?: PhrPageId) => void
  onManagePatients?: () => void
}

const count = new Intl.NumberFormat('en-US')

function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]!.toUpperCase()).join('') || '?'
}

/**
 * Your relationship to a record, as the card says it: your own profile, one you own for
 * someone else, or one someone shared with you (marked view only when you cannot edit it).
 */
export function relationshipLabel(patient: PhrPatient): string {
  if (patient.can_share) return patient.relationship === 'self' ? 'You' : 'You manage this record'
  return 'Shared with you'
}

/**
 * Patient selection: a card per patient this person can open, with their relationship to
 * it, how much it holds and what waits for review, then what needs attention across all of
 * them. The counts are one request; the list itself is the shared patient list.
 */
export default function PatientsPage({ onPatientSelect, onManagePatients }: PatientsPageProps): ReactElement {
  const { patients, busy, error, loaded, reloadPatients } = usePhrPatients()
  const [overview, setOverview] = useState<PatientOverview | null>(null)
  const [query, setQuery] = useState('')

  useEffect(() => {
    let cancelled = false
    getPatientOverview().then((next) => {
      if (!cancelled) setOverview(next)
    }, () => {
      // Counts are extra: without them the cards still open each record.
    })
    return () => {
      cancelled = true
    }
  }, [])

  const counts = useMemo(() => new Map((overview?.patients ?? []).map((row) => [row.id, row])), [overview])
  const names = useMemo(() => new Map(patients.map((patient) => [patient.id, patientDisplayName(patient.display_name, patient.id)])), [patients])
  const visible = patients.filter((patient) => patientDisplayName(patient.display_name, patient.id).toLowerCase().includes(query.trim().toLowerCase()))
  const attention = (overview?.attention ?? []).filter((item) => names.has(item.patient_id))

  function handleManagePatientsClick(event: MouseEvent<HTMLAnchorElement>): void {
    if (!onManagePatients) return
    event.preventDefault()
    onManagePatients()
  }

  function handlePatientClick(event: MouseEvent<HTMLAnchorElement>, patientId: number): void {
    if (!onPatientSelect) return
    event.preventDefault()
    onPatientSelect(patientId)
  }

  return (
    <div className="mx-auto flex w-full max-w-[1120px] flex-col gap-8">
      <div className="flex flex-wrap items-end gap-4">
        <div className="flex min-w-0 flex-[1_1_320px] flex-col gap-1.5">
          <h1 className="text-2xl font-semibold text-foreground">Patients</h1>
          <p className="text-sm text-muted-foreground">Choose whose record to open. You only see people you manage or who shared their record with you.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {patients.length > 1 && (
            <label className="relative flex items-center">
              <span className="sr-only">Find a patient</span>
              <Search className="pointer-events-none absolute left-2.5 size-4 text-muted-foreground" aria-hidden="true" />
              <input
                type="search"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Find a patient"
                className="h-10 w-60 rounded-lg border border-border bg-card pr-3 pl-8 text-sm text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
            </label>
          )}
          <Button asChild>
            <a href="/phr/patients/manage" onClick={handleManagePatientsClick}>
              <Plus className="size-4" aria-hidden="true" />
              Add Patient
            </a>
          </Button>
        </div>
      </div>

      {error && (
        <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          <span>{error}</span>
          <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => void reloadPatients()}>Retry</Button>
        </div>
      )}

      {busy && !loaded && <p className="text-sm text-muted-foreground">Loading…</p>}

      {loaded && !error && patients.length === 0 && (
        <div className="flex flex-col items-center gap-4 rounded-lg border border-dashed border-border py-16 text-center">
          <UserRound className="size-10 text-muted-foreground/50" aria-hidden="true" />
          <div>
            <p className="font-medium text-foreground">No patient profiles yet</p>
            <p className="mt-1 text-sm text-muted-foreground">Add a profile to start tracking health records.</p>
          </div>
          <Button asChild>
            <a href="/phr/patients/manage" onClick={handleManagePatientsClick}>Add Patient</a>
          </Button>
        </div>
      )}

      {patients.length > 0 && (
        <ul className="grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] gap-4">
          {visible.map((patient) => {
            const name = patientDisplayName(patient.display_name, patient.id)
            const row = counts.get(patient.id)
            const updated = row?.last_updated ? formatSourceDate(row.last_updated) : null
            return (
              <li key={patient.id}>
                <a
                  href={patientUrl(patient.id)}
                  onClick={(event) => handlePatientClick(event, patient.id)}
                  className="flex h-full flex-col gap-4 rounded-xl border border-border bg-card p-5 text-foreground transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <span className="flex items-center gap-3">
                    <span aria-hidden="true" className="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent text-sm font-bold text-accent-foreground">{initials(name)}</span>
                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                      <span className="truncate text-[17px] font-semibold">{name}</span>
                      <span className="text-sm text-muted-foreground">{relationshipLabel(patient)}</span>
                    </span>
                  </span>
                  {row && (
                    <span className="grid grid-cols-2 gap-3">
                      <span className="flex flex-col gap-0.5"><span className="text-xs text-muted-foreground">Records</span><span className="text-[15px] font-semibold tabular-nums">{count.format(row.records)}</span></span>
                      <span className="flex flex-col gap-0.5"><span className="text-xs text-muted-foreground">Last updated</span><span className="text-[15px] font-semibold">{updated ?? 'Never'}</span></span>
                    </span>
                  )}
                  {row && row.pending > 0 && (
                    <span className="flex items-center gap-2 rounded-lg bg-accent px-3 py-2.5 text-sm text-accent-foreground">
                      <span aria-hidden="true" className="size-2 rounded-full bg-warning" />
                      {count.format(row.pending)} waiting for review
                    </span>
                  )}
                  {!patient.can_manage && (
                    <span className="flex items-center gap-2 rounded-lg border border-dashed border-border px-3 py-2.5 text-sm text-muted-foreground">
                      <Lock className="size-3.5" aria-hidden="true" />
                      View only
                    </span>
                  )}
                </a>
              </li>
            )
          })}
        </ul>
      )}
      {patients.length > 0 && visible.length === 0 && <p className="text-sm text-muted-foreground">No patient matches “{query}”.</p>}

      {attention.length > 0 && (
        <section aria-labelledby="needs-attention" className="flex flex-col gap-3">
          <h2 id="needs-attention" className="text-lg font-semibold text-foreground">Needs your attention</h2>
          <ul className="overflow-hidden rounded-xl border border-border bg-card">
            {attention.map((item) => {
              const failed = item.kind === 'failed'
              const href = failed ? `/phr/imports?patient_id=${item.patient_id}` : patientPageUrl(item.patient_id, 'review')
              const when = item.date ? formatSourceDate(item.date) : null
              return (
                <li key={`${item.kind}:${item.origin}:${item.patient_id}:${item.name ?? ''}:${item.date ?? ''}`} className="border-t border-border first:border-t-0">
                  <a href={href} className="flex min-h-14 flex-wrap items-center gap-3 px-4 py-2.5 text-foreground hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset">
                    <span aria-hidden="true" className={cn('size-2 rounded-full', failed ? 'bg-destructive' : 'bg-warning')} />
                    <span className="flex min-w-0 flex-[1_1_280px] flex-col gap-0.5">
                      <span className="text-sm font-semibold">{attentionTitle(item)}</span>
                      <span className="text-sm text-muted-foreground">{[names.get(item.patient_id), when].filter(Boolean).join(' · ')}</span>
                    </span>
                    <span className="text-sm font-medium text-primary">{failed ? 'See details' : 'Review'}</span>
                  </a>
                </li>
              )
            })}
          </ul>
        </section>
      )}
    </div>
  )
}
