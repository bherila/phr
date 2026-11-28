import { CalendarDays, Plus, ShieldPlus, Stethoscope, Syringe } from 'lucide-react'
import type { FormEvent } from 'react'
import { useCallback, useEffect, useId, useMemo, useState } from 'react'

import { RecordList } from '@/components/record-list/RecordList'
import { RECORD_ONLY_BELOW_WIDE } from '@/components/record-list/RecordTable'
import { SkeletonBlock } from '@/components/record-list/Skeleton'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { fetchWrapper } from '@/fetchWrapper'
import { formatDate, formatDateTime } from '@/lib/dateFormat'
import { useRecordChanges } from '@/phr/clinical/recordChanges'
import { RecordFormDialog } from '@/phr/clinical/RecordFormDialog'
import { ShowRejectedToggle } from '@/phr/clinical/review'
import { EhiPendingBanner } from '@/phr/imports/ehi/EhiPendingBanner'
import { encounterClassLabel } from '@/phr/office-visits/encounterLabels'
import { useKnownPatientCanManage } from '@/phr/patients/usePhrPatients'
import { reviewStateChanged } from '@/phr/review/reviewSummary'
import { compactPayload, errorMessage } from '@/phr/shared'
import { drillOpener, type PhrListPageProps } from '@/phr/shell/phrPages'
import type { PhrModuleId } from '@/phr/shell/phrRoutes'
import {
  type PhrImmunization,
  PhrImmunizationsResponseSchema,
  type PhrOfficeVisit,
  PhrOfficeVisitsResponseSchema,
  type PhrProcedure,
  PhrProceduresResponseSchema,
} from '@/phr/types'

import { TimelineReviewStatus } from './TimelineReviewStatus'

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
  return value.includes('T') ? formatDateTime(value) : formatDate(value)
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
    subtitle: [encounterClassLabel(visit.encounter_class), visit.provider_name, visit.facility_name, visit.location].filter(Boolean).join(' · ') || 'Provider or facility not recorded',
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

interface AddVisitDialogProps {
  patientId: number
  onAdded: (visit: PhrOfficeVisit) => void
  onClose: () => void
}

/**
 * Adds a visit over the page, so the timeline stays where it is. Every field is optional; the
 * inputs stop at the lengths the server accepts, and a refused save is shown in the dialog.
 */
function AddVisitDialog({ patientId, onAdded, onClose }: AddVisitDialogProps) {
  const id = useId()
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
        compactPayload({ visit_date: visitDate, visit_type: visitType.trim(), provider_name: providerName.trim(), chief_complaint: chiefComplaint.trim() }),
      )
      void reviewStateChanged(patientId)
      onAdded((raw as { office_visit: PhrOfficeVisit }).office_visit)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }

  return (
    <RecordFormDialog
      title="Add visit"
      description="When the visit was, what kind, the provider and the chief complaint."
      submitLabel="Add visit"
      busy={busy}
      error={error}
      onSubmit={(event) => void handleSubmit(event)}
      onClose={onClose}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="grid gap-1">
          <label htmlFor={`${id}-date`} className="text-sm font-medium">Date</label>
          <Input id={`${id}-date`} type="date" value={visitDate} onChange={(event) => setVisitDate(event.target.value)} />
        </div>
        <div className="grid gap-1">
          <label htmlFor={`${id}-type`} className="text-sm font-medium">Type</label>
          <Input id={`${id}-type`} value={visitType} maxLength={100} placeholder="Office, Telehealth…" onChange={(event) => setVisitType(event.target.value)} />
        </div>
        <div className="grid gap-1 sm:col-span-2">
          <label htmlFor={`${id}-provider`} className="text-sm font-medium">Provider</label>
          <Input id={`${id}-provider`} value={providerName} maxLength={255} onChange={(event) => setProviderName(event.target.value)} />
        </div>
        <div className="grid gap-1 sm:col-span-2">
          <label htmlFor={`${id}-complaint`} className="text-sm font-medium">Chief Complaint</label>
          <Textarea id={`${id}-complaint`} value={chiefComplaint} maxLength={10000} onChange={(event) => setChiefComplaint(event.target.value)} />
        </div>
      </div>
    </RecordFormDialog>
  )
}

export default function OfficeVisitsPage({ patientId, onDrill, openRecord }: PhrListPageProps) {
  const [visits, setVisits] = useState<PhrOfficeVisit[]>([])
  const [procedures, setProcedures] = useState<PhrProcedure[]>([])
  const [immunizations, setImmunizations] = useState<PhrImmunization[]>([])
  const [filter, setFilter] = useState<VisitFilter>('all')
  const knownCanManage = useKnownPatientCanManage(patientId)
  const [canManage, setCanManage] = useState(knownCanManage)
  const [showRejected, setShowRejected] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [adding, setAdding] = useState(false)

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      // The timeline merges three resources, so the filter has to reach all of
      // them or a rejected record would still surface through one of the feeds.
      const rejected = showRejected ? '?include_rejected=1' : ''
      const [rawVisits, rawProcedures, rawImmunizations] = await Promise.all([
        fetchWrapper.get(`/api/phr/patients/${patientId}/office-visits${rejected}`),
        fetchWrapper.get(`/api/phr/patients/${patientId}/procedures${rejected}`),
        fetchWrapper.get(`/api/phr/patients/${patientId}/immunizations${rejected}`),
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
  }, [patientId, showRejected])

  useEffect(() => { void load() }, [load])
  useRecordChanges('procedures', patientId, 'detail', () => { void load() })
  useRecordChanges('procedures', patientId, 'list', () => { void load() })

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
        {canManage && (
          <Button type="button" size="sm" variant="outline" onClick={() => setAdding(true)}>
            <Plus className="size-4" aria-hidden="true" />
            Add visit
          </Button>
        )}
      </div>
      <EhiPendingBanner patientId={patientId} mapping="encounter" className="mb-4" />

      {error && <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</div>}
      {/* The filters stay while a refetch runs; only the first load shows skeleton entries. */}
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
          <ShowRejectedToggle showRejected={showRejected} onChange={setShowRejected} disabled={busy} />
        </div>
        <p className="mb-6 text-sm text-muted-foreground">{FILTER_DESCRIPTIONS[filter]}</p>

        {!busy && filteredEvents.length === 0 && (
          <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
            No matching visit records.
          </div>
        )}
        <RecordList
          label="Visit timeline"
          loading={busy && allEvents.length === 0}
          skeletonRows={5}
          skeletonItem={<div className="rounded-lg border border-border bg-card px-4 py-3"><SkeletonBlock className="w-1/3 text-sm" /><SkeletonBlock className="mt-1 w-2/3" /></div>}
          as="ol"
          className="@container/record-list relative min-w-0 space-y-3 border-l border-border pl-5"
          itemClassName="relative"
          rows={filteredEvents}
          rowId={(event) => event.key}
          openRowId={filteredEvents.find((event) => event.drillId === openRecord?.module && String(event.recordId) === openRecord.id)?.key ?? null}
          onOpen={drillOpener(onDrill, (event: EncounterEvent) => ({ id: event.drillId, instance: String(event.recordId) }))}
          renderItem={(event) => {
            const interval = daysSincePrevious(event, filteredEvents[filteredEvents.indexOf(event) + 1])
            return (
              <>
                <span className="absolute -left-[1.65rem] top-4 size-3 rounded-full border-2 border-background bg-primary" />
                <div className="w-full min-w-0 rounded-lg border border-border bg-card px-3 py-3 text-left wrap-anywhere transition-colors hover:bg-muted/30 in-data-[open]:bg-accent/60 @[560px]/record-list:px-4">
                  <div className="grid min-w-0 grid-cols-1 gap-1 @[960px]/record-list:grid-cols-[190px_minmax(0,1fr)_auto] @[960px]/record-list:items-start @[960px]/record-list:gap-2">
                    <div className="hidden @[960px]/record-list:block">
                      <p className="text-sm font-medium text-muted-foreground">{event.displayDate}</p>
                      {interval !== null && interval >= 0 && (
                        <p className="mt-1 text-xs font-medium text-primary">{intervalLabel(interval, filter)}</p>
                      )}
                    </div>
                    <div className="min-w-0">
                      <div className="flex min-w-0 items-start justify-between gap-2"><p className="min-w-0 font-medium text-card-foreground">{event.title}</p><TimelineReviewStatus status={event.reviewStatus} /></div>
                      <p className={`mt-1 text-sm text-muted-foreground ${RECORD_ONLY_BELOW_WIDE}`}>
                        {[event.displayDate, event.badge, interval !== null && interval >= 0 ? intervalLabel(interval, filter) : null].filter(Boolean).join(' · ')}<span className="hidden @[560px]/record-list:inline">{event.subtitle && ` · ${event.subtitle}`}</span>
                      </p>
                      <p className="mt-1 hidden text-sm text-muted-foreground @[960px]/record-list:block">{event.subtitle}</p>
                      {event.detail && <p className="mt-2 hidden text-sm text-foreground @[960px]/record-list:block">{event.detail}</p>}
                    </div>
                    <div className="flex min-w-0 flex-wrap gap-1.5 @[960px]/record-list:justify-end">
                      <span className="hidden w-fit rounded-full bg-muted px-2 py-1 text-xs font-medium text-muted-foreground @[960px]/record-list:inline">{event.badge}</span>

                    </div>
                  </div>
                </div>
              </>
            )
          }}
        />
      </>
      {adding && (
        <AddVisitDialog
          patientId={patientId}
          onClose={() => setAdding(false)}
          onAdded={(visit) => {
            setVisits((current) => [visit, ...current])
            setAdding(false)
            // A new visit opens, so its detail shows what was saved.
            onDrill?.({ id: 'office-visit-detail', instance: String(visit.id) })
          }}
        />
      )}
    </div>
  )
}
