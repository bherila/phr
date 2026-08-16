import { CalendarDays, Plus, ShieldPlus, Stethoscope, Syringe } from 'lucide-react'
import type { FormEvent } from 'react'
import { useCallback, useEffect, useMemo, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fetchWrapper } from '@/fetchWrapper'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import type { PhrListPageProps, PhrModuleId } from '@/phr/miller'
import { compactPayload, errorMessage } from '@/phr/shared'
import {
  type PhrImmunization,
  PhrImmunizationsResponseSchema,
  type PhrOfficeVisit,
  PhrOfficeVisitsResponseSchema,
  type PhrProcedure,
  PhrProceduresResponseSchema,
} from '@/phr/types'

const ALLERGY_ADMINISTRATION_CODES = new Set(['95115', '95117'])
const DAY_IN_MILLISECONDS = 86_400_000

type VisitFilter = 'all' | 'office-visits' | 'allergy-shots' | 'immunizations'
type EncounterKind = Exclude<VisitFilter, 'all'>

interface EncounterEvent {
  key: string
  kind: EncounterKind
  recordId: number
  date: string | null
  displayDate: string
  title: string
  subtitle: string
  detail?: string | null
  badge: string
  reviewStatus?: PhrOfficeVisit['review_status']
  drillId: PhrModuleId
}

const FILTERS: Array<{ id: VisitFilter; label: string; icon: typeof CalendarDays }> = [
  { id: 'all', label: 'All', icon: CalendarDays },
  { id: 'office-visits', label: 'Office Visits', icon: Stethoscope },
  { id: 'allergy-shots', label: 'Allergy Shots', icon: Syringe },
  { id: 'immunizations', label: 'Immunizations', icon: ShieldPlus },
]

const FILTER_DESCRIPTIONS: Record<VisitFilter, string> = {
  all: 'A combined timeline of office visits, allergy shot administrations, and immunizations.',
  'office-visits': 'Recorded office and telehealth encounters.',
  'allergy-shots': 'Administration procedures (CPT 95115 or 95117). Allergen extract preparation is excluded.',
  immunizations: 'Vaccination administrations recorded in the PHR.',
}

const PREVIOUS_LABELS: Record<VisitFilter, string> = {
  all: 'encounter',
  'office-visits': 'office visit',
  'allergy-shots': 'allergy shot',
  immunizations: 'immunization',
}

function dateOnlyMilliseconds(value: string | null): number | null {
  if (!value) return null
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
  if (!match) return null
  return Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
}

function daysSincePrevious(current: EncounterEvent, previous: EncounterEvent | undefined): number | null {
  const currentDate = dateOnlyMilliseconds(current.date)
  const previousDate = dateOnlyMilliseconds(previous?.date ?? null)
  if (currentDate === null || previousDate === null) return null
  return Math.round((currentDate - previousDate) / DAY_IN_MILLISECONDS)
}

function intervalLabel(days: number, filter: VisitFilter): string {
  const previous = PREVIOUS_LABELS[filter]
  if (days === 0) return `Same day as previous ${previous}`
  return `${days} day${days === 1 ? '' : 's'} since previous ${previous}`
}

function displayDate(value: string | null): string {
  if (!value) return 'Date not recorded'
  return value.includes('T') ? value.replace('T', ' ').slice(0, 16) : value.slice(0, 10)
}

function allergyShotLabel(procedure: PhrProcedure): string {
  if (procedure.cpt_code === '95115') return 'Single allergy injection'
  if (procedure.cpt_code === '95117') return 'Multiple allergy injections'
  return procedure.name
}

function eventsFromRecords(
  visits: PhrOfficeVisit[],
  procedures: PhrProcedure[],
  immunizations: PhrImmunization[],
): EncounterEvent[] {
  const officeEvents: EncounterEvent[] = visits.map((visit) => ({
    key: `office-${visit.id}`,
    kind: 'office-visits',
    recordId: visit.id,
    date: visit.visit_started_at ?? visit.visit_date,
    displayDate: displayDate(visit.visit_started_at ?? visit.visit_date),
    title: visit.visit_type ?? 'Office Visit',
    subtitle: [visit.provider_name, visit.facility_name].filter(Boolean).join(' · ') || 'Provider or facility not recorded',
    detail: visit.chief_complaint ? `CC: ${visit.chief_complaint}` : visit.assessment,
    badge: 'Office Visit',
    reviewStatus: visit.review_status,
    drillId: 'office-visit-detail',
  }))

  const allergyEvents: EncounterEvent[] = procedures
    .filter((procedure) => procedure.cpt_code !== null && ALLERGY_ADMINISTRATION_CODES.has(procedure.cpt_code))
    .map((procedure) => ({
      key: `allergy-${procedure.id}`,
      kind: 'allergy-shots',
      recordId: procedure.id,
      date: procedure.performed_at ?? procedure.performed_on,
      displayDate: displayDate(procedure.performed_at ?? procedure.performed_on),
      title: allergyShotLabel(procedure),
      subtitle: [procedure.performer_name, procedure.facility_name].filter(Boolean).join(' · ') || 'Provider or facility not recorded',
      badge: `CPT ${procedure.cpt_code}`,
      reviewStatus: procedure.review_status,
      drillId: 'procedure-detail',
    }))

  const immunizationEvents: EncounterEvent[] = immunizations.map((immunization) => ({
    key: `immunization-${immunization.id}`,
    kind: 'immunizations',
    recordId: immunization.id,
    date: immunization.administered_on,
    displayDate: displayDate(immunization.administered_on),
    title: immunization.vaccine_name,
    subtitle: [immunization.manufacturer, immunization.facility_name, immunization.administered_by].filter(Boolean).join(' · ') || 'Manufacturer or administrator not recorded',
    detail: immunization.dose_number ? `Dose ${immunization.dose_number}${immunization.series_doses ? ` of ${immunization.series_doses}` : ''}` : null,
    badge: 'Immunization',
    drillId: 'immunization-detail',
  }))

  return [...officeEvents, ...allergyEvents, ...immunizationEvents].sort((left, right) => {
    const dateCompare = (right.date ?? '').localeCompare(left.date ?? '')
    return dateCompare !== 0 ? dateCompare : right.key.localeCompare(left.key)
  })
}

interface AddFormProps {
  patientId: number
  onAdded: (visit: PhrOfficeVisit) => void
}

function AddForm({ patientId, onAdded }: AddFormProps) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [visitDate, setVisitDate] = useState('')
  const [visitType, setVisitType] = useState('')
  const [providerName, setProviderName] = useState('')
  const [chiefComplaint, setChiefComplaint] = useState('')

  async function handleSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.post(
        `/api/phr/patients/${patientId}/office-visits`,
        compactPayload({ visit_date: visitDate, visit_type: visitType, provider_name: providerName, chief_complaint: chiefComplaint }),
      )
      const visit = (raw as { office_visit: PhrOfficeVisit }).office_visit
      onAdded(visit)
      setVisitDate('')
      setVisitType('')
      setProviderName('')
      setChiefComplaint('')
      setOpen(false)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }

  if (!open) {
    return (
      <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
        <Plus className="size-4" />
        Add Visit
      </Button>
    )
  }

  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <h3 className="mb-3 text-sm font-semibold">Add Office Visit</h3>
      <form onSubmit={(event) => void handleSubmit(event)} className="grid gap-3 sm:grid-cols-2">
        <label className="grid gap-1 text-sm font-medium">Date <Input type="date" value={visitDate} onChange={(event) => setVisitDate(event.target.value)} /></label>
        <label className="grid gap-1 text-sm font-medium">Type <Input value={visitType} onChange={(event) => setVisitType(event.target.value)} placeholder="Office, Telehealth…" /></label>
        <label className="grid gap-1 text-sm font-medium">Provider <Input value={providerName} onChange={(event) => setProviderName(event.target.value)} /></label>
        <label className="grid gap-1 text-sm font-medium sm:col-span-2">Chief Complaint <Input value={chiefComplaint} onChange={(event) => setChiefComplaint(event.target.value)} /></label>
        {error && <p className="text-sm text-destructive sm:col-span-2">{error}</p>}
        <div className="flex gap-2 sm:col-span-2">
          <Button type="submit" size="sm" disabled={busy}>{busy ? 'Adding…' : 'Add'}</Button>
          <Button type="button" variant="outline" size="sm" onClick={() => setOpen(false)}>Cancel</Button>
        </div>
      </form>
    </div>
  )
}

export default function OfficeVisitsPage({ patientId, onDrill }: PhrListPageProps) {
  const [visits, setVisits] = useState<PhrOfficeVisit[]>([])
  const [procedures, setProcedures] = useState<PhrProcedure[]>([])
  const [immunizations, setImmunizations] = useState<PhrImmunization[]>([])
  const [filter, setFilter] = useState<VisitFilter>('all')
  const [canManage, setCanManage] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      const [rawVisits, rawProcedures, rawImmunizations] = await Promise.all([
        fetchWrapper.get(`/api/phr/patients/${patientId}/office-visits`),
        fetchWrapper.get(`/api/phr/patients/${patientId}/procedures`),
        fetchWrapper.get(`/api/phr/patients/${patientId}/immunizations`),
      ])
      const parsedVisits = PhrOfficeVisitsResponseSchema.parse(rawVisits)
      const parsedProcedures = PhrProceduresResponseSchema.parse(rawProcedures)
      const parsedImmunizations = PhrImmunizationsResponseSchema.parse(rawImmunizations)
      setVisits(parsedVisits.office_visits)
      setProcedures(parsedProcedures.procedures)
      setImmunizations(parsedImmunizations.immunizations)
      setCanManage(parsedVisits.can_manage)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }, [patientId])

  useEffect(() => { void load() }, [load])

  const allEvents = useMemo(
    () => eventsFromRecords(visits, procedures, immunizations),
    [immunizations, procedures, visits],
  )
  const filteredEvents = useMemo(
    () => filter === 'all' ? allEvents : allEvents.filter((event) => event.kind === filter),
    [allEvents, filter],
  )
  const counts = useMemo(() => ({
    all: allEvents.length,
    'office-visits': allEvents.filter((event) => event.kind === 'office-visits').length,
    'allergy-shots': allEvents.filter((event) => event.kind === 'allergy-shots').length,
    immunizations: allEvents.filter((event) => event.kind === 'immunizations').length,
  }), [allEvents])

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
            <Stethoscope className="size-6 text-primary" />
            Visits
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">A chronological view of care encounters.</p>
        </div>
        {canManage && <AddForm patientId={patientId} onAdded={(visit) => setVisits((current) => [visit, ...current])} />}
      </div>
      {error && <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</div>}
      {busy && <p className="text-sm text-muted-foreground">Loading…</p>}
      {!busy && (
        <>
          <div role="group" aria-label="Filter visit timeline" className="mb-3 flex flex-wrap gap-2">
            {FILTERS.map((candidate) => {
              const Icon = candidate.icon
              const active = filter === candidate.id
              return (
                <Button
                  key={candidate.id}
                  type="button"
                  aria-pressed={active}
                  variant={active ? 'default' : 'outline'}
                  onClick={() => setFilter(candidate.id)}
                >
                  <Icon className="size-4" />
                  {candidate.label}
                  <span className="rounded-full bg-background/20 px-1.5 py-0.5 text-xs tabular-nums">{counts[candidate.id]}</span>
                </Button>
              )
            })}
          </div>
          <p className="mb-6 text-sm text-muted-foreground">{FILTER_DESCRIPTIONS[filter]}</p>

          {filteredEvents.length === 0 && (
            <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
              No matching visit records.
            </div>
          )}
          <ol className="relative space-y-4 border-l border-border pl-5">
            {filteredEvents.map((event, index) => {
              const interval = daysSincePrevious(event, filteredEvents[index + 1])
              return (
                <li key={event.key} className="relative">
                  <span className="absolute -left-[1.65rem] top-4 size-3 rounded-full border-2 border-background bg-primary" />
                  <button
                    type="button"
                    className="w-full rounded-lg border border-border bg-card px-4 py-3 text-left transition-colors hover:bg-muted/30 disabled:cursor-default"
                    disabled={!onDrill}
                    onClick={() => onDrill?.({ id: event.drillId, instance: String(event.recordId) })}
                  >
                    <div className="grid gap-2 md:grid-cols-[190px_minmax(0,1fr)_auto] md:items-start">
                      <div>
                        <p className="text-sm font-medium text-muted-foreground">{event.displayDate}</p>
                        {interval !== null && interval >= 0 && (
                          <p className="mt-1 text-xs font-medium text-primary">{intervalLabel(interval, filter)}</p>
                        )}
                      </div>
                      <div className="min-w-0">
                        <p className="font-medium text-card-foreground">{event.title}</p>
                        <p className="mt-1 text-sm text-muted-foreground">{event.subtitle}</p>
                        {event.detail && <p className="mt-2 text-sm text-foreground">{event.detail}</p>}
                      </div>
                      <div className="flex flex-wrap justify-end gap-1.5">
                        <span className="w-fit rounded-full bg-muted px-2 py-1 text-xs font-medium text-muted-foreground">{event.badge}</span>
                        {event.reviewStatus && reviewStatusBadge(event.reviewStatus)}
                      </div>
                    </div>
                  </button>
                </li>
              )
            })}
          </ol>
        </>
      )}
    </div>
  )
}
