import { Info, Plus, Stethoscope, Syringe } from 'lucide-react'
import type { FormEvent } from 'react'
import { useCallback, useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fetchWrapper } from '@/fetchWrapper'
import type { PhrListPageProps } from '@/phr/miller'
import { compactPayload, errorMessage } from '@/phr/shared'
import {
  type PhrOfficeVisit,
  PhrOfficeVisitsResponseSchema,
  type PhrProcedure,
  PhrProceduresResponseSchema,
} from '@/phr/types'

const ALLERGY_ADMINISTRATION_CODES = new Set(['95115', '95117'])

type VisitView = 'office-visits' | 'allergy-shots'

function sortAllergyShots(procedures: PhrProcedure[]): PhrProcedure[] {
  return procedures
    .filter((procedure) => procedure.cpt_code !== null && ALLERGY_ADMINISTRATION_CODES.has(procedure.cpt_code))
    .sort((left, right) => {
      const leftDate = left.performed_at ?? left.performed_on ?? ''
      const rightDate = right.performed_at ?? right.performed_on ?? ''
      const dateCompare = rightDate.localeCompare(leftDate)

      return dateCompare !== 0 ? dateCompare : right.id - left.id
    })
}

function allergyShotDate(procedure: PhrProcedure): string {
  if (procedure.performed_at) {
    return procedure.performed_at.replace('T', ' ').slice(0, 16)
  }

  return procedure.performed_on ?? 'Date not recorded'
}

function allergyShotLabel(procedure: PhrProcedure): string {
  if (procedure.cpt_code === '95115') {
    return 'Single allergy injection'
  }
  if (procedure.cpt_code === '95117') {
    return 'Multiple allergy injections'
  }

  return procedure.name
}

interface AddFormProps {
  patientId: number
  onAdded: (v: PhrOfficeVisit) => void
}

function AddForm({ patientId, onAdded }: AddFormProps) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [visitDate, setVisitDate] = useState('')
  const [visitType, setVisitType] = useState('')
  const [providerName, setProviderName] = useState('')
  const [chiefComplaint, setChiefComplaint] = useState('')

  async function handleSubmit(e: FormEvent<HTMLFormElement>): Promise<void> {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.post(
        `/api/phr/patients/${patientId}/office-visits`,
        compactPayload({ visit_date: visitDate, visit_type: visitType, provider_name: providerName, chief_complaint: chiefComplaint }),
      )
      const visit = (raw as any)?.office_visit as PhrOfficeVisit
      onAdded(visit)
      setVisitDate('')
      setVisitType('')
      setProviderName('')
      setChiefComplaint('')
      setOpen(false)
    } catch (err) {
      setError(errorMessage(err))
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
      <form onSubmit={(e) => void handleSubmit(e)} className="grid gap-3 sm:grid-cols-2">
        <label className="grid gap-1 text-sm font-medium">Date <Input type="date" value={visitDate} onChange={(e) => setVisitDate(e.target.value)} /></label>
        <label className="grid gap-1 text-sm font-medium">Type <Input value={visitType} onChange={(e) => setVisitType(e.target.value)} placeholder="Office, Telehealth…" /></label>
        <label className="grid gap-1 text-sm font-medium">Provider <Input value={providerName} onChange={(e) => setProviderName(e.target.value)} /></label>
        <label className="grid gap-1 text-sm font-medium sm:col-span-2">Chief Complaint <Input value={chiefComplaint} onChange={(e) => setChiefComplaint(e.target.value)} /></label>
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
  const [allergyShots, setAllergyShots] = useState<PhrProcedure[]>([])
  const [view, setView] = useState<VisitView>('office-visits')
  const [canManage, setCanManage] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      const [rawVisits, rawProcedures] = await Promise.all([
        fetchWrapper.get(`/api/phr/patients/${patientId}/office-visits`),
        fetchWrapper.get(`/api/phr/patients/${patientId}/procedures`),
      ])
      const parsedVisits = PhrOfficeVisitsResponseSchema.parse(rawVisits)
      const parsedProcedures = PhrProceduresResponseSchema.parse(rawProcedures)
      setVisits(parsedVisits.office_visits)
      setAllergyShots(sortAllergyShots(parsedProcedures.procedures))
      setCanManage(parsedVisits.can_manage)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }, [patientId])

  useEffect(() => { void load() }, [load])

  return (
    <div>
      <div className="mb-6">
        <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
          <Stethoscope className="size-6 text-primary" />
          Visits
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">Office visits and allergy shot administration encounters.</p>
      </div>
      {error && <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</div>}
      {busy && <p className="text-sm text-muted-foreground">Loading…</p>}
      {!busy && (
        <>
          <div role="tablist" aria-label="Visit records" className="mb-4 flex flex-wrap gap-2">
            <Button
              type="button"
              role="tab"
              aria-selected={view === 'office-visits'}
              variant={view === 'office-visits' ? 'default' : 'outline'}
              onClick={() => setView('office-visits')}
            >
              <Stethoscope className="size-4" />
              Office Visits
              <span className="rounded-full bg-background/20 px-1.5 py-0.5 text-xs tabular-nums">{visits.length}</span>
            </Button>
            <Button
              type="button"
              role="tab"
              aria-selected={view === 'allergy-shots'}
              variant={view === 'allergy-shots' ? 'default' : 'outline'}
              onClick={() => setView('allergy-shots')}
            >
              <Syringe className="size-4" />
              Allergy Shots
              <span className="rounded-full bg-background/20 px-1.5 py-0.5 text-xs tabular-nums">{allergyShots.length}</span>
            </Button>
          </div>

          {view === 'office-visits' && (
            <>
              <button
                type="button"
                className="mb-6 flex w-full items-start gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-left text-sm text-muted-foreground transition-colors hover:bg-muted/50"
                onClick={() => setView('allergy-shots')}
              >
                <Info className="mt-0.5 size-4 shrink-0 text-primary" />
                <span>
                  Looking for allergy shots? <strong className="font-medium text-foreground">{allergyShots.length} administration record{allergyShots.length === 1 ? '' : 's'}</strong> are available in the Allergy Shots view.
                </span>
              </button>
              {canManage && <div className="mb-6"><AddForm patientId={patientId} onAdded={(v) => setVisits((p) => [v, ...p])} /></div>}
              {visits.length === 0 && <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">No office visits recorded.</div>}
              <div className="flex flex-col gap-3">
                {visits.map((v) => (
                  <div
                    key={v.id}
                    className={`rounded-lg border border-border bg-card p-4 ${onDrill ? 'cursor-pointer transition-colors hover:bg-muted/30' : ''}`}
                    onClick={() => onDrill?.({ id: 'office-visit-detail', instance: String(v.id) })}
                  >
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <p className="font-medium text-card-foreground">{v.visit_date ?? '—'} {v.visit_type ? `· ${v.visit_type}` : ''}</p>
                        {v.provider_name && <p className="text-sm text-muted-foreground">{v.provider_name}{v.facility_name ? ` · ${v.facility_name}` : ''}</p>}
                      </div>
                    </div>
                    {v.chief_complaint && <p className="mt-2 text-sm text-foreground"><span className="text-xs font-medium text-muted-foreground">CC: </span>{v.chief_complaint}</p>}
                    {v.assessment && <p className="mt-1 text-sm text-foreground"><span className="text-xs font-medium text-muted-foreground">Assessment: </span>{v.assessment}</p>}
                  </div>
                ))}
              </div>
            </>
          )}

          {view === 'allergy-shots' && (
            <>
              <div className="mb-6 flex items-start gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                <Info className="mt-0.5 size-4 shrink-0 text-primary" />
                <p>
                  These are billed administration procedures (CPT 95115 or 95117), not E/M office visits. Allergen extract preparation (CPT 95165) is excluded from this list.
                </p>
              </div>
              {allergyShots.length === 0 && <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">No allergy shot administrations recorded.</div>}
              <ol className="relative space-y-4 border-l border-border pl-5">
                {allergyShots.map((procedure) => (
                  <li key={procedure.id} className="relative">
                    <span className="absolute -left-[1.65rem] top-4 size-3 rounded-full border-2 border-background bg-primary" />
                    <div
                      className={`rounded-lg border border-border bg-card px-4 py-3 ${onDrill ? 'cursor-pointer transition-colors hover:bg-muted/30' : ''}`}
                      onClick={() => onDrill?.({ id: 'procedure-detail', instance: String(procedure.id) })}
                    >
                      <div className="grid gap-2 md:grid-cols-[150px_minmax(0,1fr)_auto] md:items-start">
                        <div className="text-sm font-medium text-muted-foreground">{allergyShotDate(procedure)}</div>
                        <div className="min-w-0">
                          <p className="font-medium text-card-foreground">{allergyShotLabel(procedure)}</p>
                          <p className="mt-1 text-sm text-muted-foreground">
                            {[procedure.performer_name, procedure.facility_name].filter(Boolean).join(' · ') || 'Provider or facility not recorded'}
                          </p>
                        </div>
                        <span className="w-fit rounded-full bg-muted px-2 py-1 text-xs font-medium text-muted-foreground">CPT {procedure.cpt_code}</span>
                      </div>
                    </div>
                  </li>
                ))}
              </ol>
            </>
          )}
        </>
      )}
    </div>
  )
}
