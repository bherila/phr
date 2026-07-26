import { Plus, Stethoscope } from 'lucide-react'
import type { FormEvent } from 'react'
import { useCallback, useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fetchWrapper } from '@/fetchWrapper'
import type { PhrListPageProps } from '@/phr/miller'
import { compactPayload, errorMessage } from '@/phr/shared'
import { type PhrOfficeVisit, PhrOfficeVisitsResponseSchema } from '@/phr/types'

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
  const [canManage, setCanManage] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      const rawVisits = await fetchWrapper.get(`/api/phr/patients/${patientId}/office-visits`)
      const parsed = PhrOfficeVisitsResponseSchema.parse(rawVisits)
      setVisits(parsed.office_visits)
      setCanManage(parsed.can_manage)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }, [patientId])

  useEffect(() => { void load() }, [load])

  return (
    <div>
      <div className="mb-6 flex items-center gap-2">
        <Stethoscope className="size-6 text-primary" />
        <h1 className="text-2xl font-semibold text-foreground">Office Visits</h1>
      </div>
      {error && <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</div>}
      {canManage && <div className="mb-6"><AddForm patientId={patientId} onAdded={(v) => setVisits((p) => [v, ...p])} /></div>}
      {busy && <p className="text-sm text-muted-foreground">Loading…</p>}
      {!busy && visits.length === 0 && <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">No office visits recorded.</div>}
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
    </div>
  )
}
