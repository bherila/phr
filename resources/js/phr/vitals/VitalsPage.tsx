import { HeartPulse, Plus } from 'lucide-react'
import type { ComponentProps, FormEvent } from 'react'
import { useCallback, useEffect, useMemo, useState } from 'react'

import { type RecordColumn, RecordTable } from '@/components/record-list/RecordTable'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fetchWrapper } from '@/fetchWrapper'
import { type PhrReviewDecision, ReviewActions, ShowRejectedToggle } from '@/phr/clinical/review'
import { recordReviewDecision } from '@/phr/clinical/reviewDecision'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { useKnownPatientCanManage } from '@/phr/patients/usePhrPatients'
import { reviewStateChanged } from '@/phr/review/reviewSummary'
import { errorMessage, numericPayload } from '@/phr/shared'
import { drillOpener, type PhrListPageProps } from '@/phr/shell/phrPages'
import { openRecordId } from '@/phr/shell/phrRoutes'
import {
  type PhrVital,
  type PhrVitalFormData,
  PhrVitalFormSchema,
  PhrVitalResponseSchema,
  PhrVitalsResponseSchema,
} from '@/phr/types'
import { metricCandidates } from '@/phr/vitals/metrics'

interface LabeledInputProps extends Omit<ComponentProps<typeof Input>, 'onChange'> {
  label: string
  onChange: (value: string) => void
}

function LabeledInput({ label, onChange, ...props }: LabeledInputProps) {
  return (
    <label className="grid gap-1 text-sm font-medium text-foreground">
      {label}
      <Input {...props} onChange={(e) => onChange(e.target.value)} />
    </label>
  )
}

const emptyForm: PhrVitalFormData = {
  vital_name: '',
  vital_date: '',
  observed_at: '',
  vital_value: '',
  value_numeric: '',
  value_numeric_secondary: '',
  unit: '',
  secondary_unit: '',
  body_site: '',
  notes: '',
}

interface AddVitalFormProps {
  patientId: number
  onAdded: (vital: PhrVital) => void
}

function AddVitalForm({ patientId, onAdded }: AddVitalFormProps) {
  const [form, setForm] = useState<PhrVitalFormData>(emptyForm)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [open, setOpen] = useState(false)

  async function handleSubmit(e: FormEvent<HTMLFormElement>): Promise<void> {
    e.preventDefault()
    setError(null)
    const parsed = PhrVitalFormSchema.safeParse(form)
    if (!parsed.success) {
      setError(parsed.error.issues[0]?.message ?? 'Invalid input.')
      return
    }
    setBusy(true)
    try {
      const raw: unknown = await fetchWrapper.post(
        `/api/phr/patients/${patientId}/vitals`,
        numericPayload(parsed.data, ['value_numeric', 'value_numeric_secondary']),
      )
      onAdded(PhrVitalResponseSchema.parse(raw).vital)
      void reviewStateChanged(patientId)
      setForm(emptyForm)
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
        Add Vital
      </Button>
    )
  }

  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <h3 className="mb-3 text-sm font-semibold text-card-foreground">Add Vital</h3>
      <form onSubmit={(e) => void handleSubmit(e)}>
        <div className="grid gap-3 sm:grid-cols-2">
          <LabeledInput label="Vital Name *" value={form.vital_name} onChange={(v) => setForm({ ...form, vital_name: v })} required />
          <LabeledInput label="Date" type="date" value={form.vital_date ?? ''} onChange={(v) => setForm({ ...form, vital_date: v })} />
          <LabeledInput label="Observed At" type="datetime-local" value={form.observed_at ?? ''} onChange={(v) => setForm({ ...form, observed_at: v })} />
          <LabeledInput label="Value" value={form.vital_value ?? ''} onChange={(v) => setForm({ ...form, vital_value: v })} />
          <LabeledInput label="Numeric (systolic / primary)" inputMode="decimal" value={form.value_numeric ?? ''} onChange={(v) => setForm({ ...form, value_numeric: v })} />
          <LabeledInput label="Secondary numeric (diastolic)" inputMode="decimal" value={form.value_numeric_secondary ?? ''} onChange={(v) => setForm({ ...form, value_numeric_secondary: v })} />
          <LabeledInput label="Unit" value={form.unit ?? ''} onChange={(v) => setForm({ ...form, unit: v })} />
          <LabeledInput label="Body Site" value={form.body_site ?? ''} onChange={(v) => setForm({ ...form, body_site: v })} />
        </div>
        {error && <p className="mt-2 text-sm text-destructive">{error}</p>}
        <div className="mt-4 flex gap-2">
          <Button type="submit" size="sm" disabled={busy}>
            {busy ? 'Adding…' : 'Add'}
          </Button>
          <Button type="button" variant="outline" size="sm" onClick={() => setOpen(false)} disabled={busy}>
            Cancel
          </Button>
        </div>
      </form>
    </div>
  )
}

function vitalDate(v: PhrVital): string {
  return (v.observed_at ?? v.vital_date ?? '').slice(0, 10)
}

function trimNumeric(s: string | null | undefined): string {
  if (!s) return ''
  const n = parseFloat(s)
  return isNaN(n) ? s : String(n)
}

function displayValue(v: PhrVital): string {
  if (v.value_numeric !== null && v.value_numeric_secondary !== null) {
    return `${trimNumeric(v.value_numeric)}/${trimNumeric(v.value_numeric_secondary)}`
  }
  if (v.vital_value) {
    return v.vital_value
  }
  if (v.value_numeric !== null) {
    return trimNumeric(v.value_numeric)
  }
  return '—'
}

export default function VitalsPage({ patientId, onDrill, openRecord }: PhrListPageProps) {
  const [vitals, setVitals] = useState<PhrVital[]>([])
  const knownCanManage = useKnownPatientCanManage(patientId)
  const [canManage, setCanManage] = useState(knownCanManage)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [sortDir, setSortDir] = useState<'desc' | 'asc'>('desc')
  const [showRejected, setShowRejected] = useState(false)
  const [reviewingId, setReviewingId] = useState<number | null>(null)

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      const rawVitals = await fetchWrapper.get(
        showRejected
          ? `/api/phr/patients/${patientId}/vitals?include_rejected=1`
          : `/api/phr/patients/${patientId}/vitals`,
      )
      const parsed = PhrVitalsResponseSchema.parse(rawVitals)
      setVitals(parsed.vitals)
      setCanManage(parsed.can_manage)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }, [patientId, showRejected])

  useEffect(() => {
    void load()
  }, [load])

  async function reviewVital(vital: PhrVital, decision: PhrReviewDecision): Promise<void> {
    setReviewingId(vital.id)
    setError(null)
    try {
      const raw: unknown = await recordReviewDecision(`/api/phr/patients/${patientId}/vitals/${vital.id}`, decision)
      const updated = PhrVitalResponseSchema.parse(raw).vital
      if (decision === 'rejected' && !showRejected) {
        setVitals((prev) => prev.filter((v) => v.id !== updated.id))
      } else {
        setVitals((prev) => prev.map((v) => (v.id === updated.id ? updated : v)))
      }
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setReviewingId(null)
    }
  }

  const filtered = useMemo(() => {
    let list = vitals
    if (search.trim()) {
      const q = search.toLowerCase()
      list = list.filter((v) => v.vital_name?.toLowerCase().includes(q))
    }
    return [...list].sort((a, b) => {
      const da = vitalDate(a)
      const db = vitalDate(b)
      return sortDir === 'desc' ? db.localeCompare(da) : da.localeCompare(db)
    })
  }, [vitals, search, sortDir])

  // Skeleton rows on the first load only; a refetch keeps the current rows on screen.
  const firstLoad = busy && vitals.length === 0
  const vitalNames = useMemo(
    () => Array.from(new Set(vitals.map((v) => v.vital_name).filter(Boolean))).sort(),
    [vitals],
  )

  const head = 'px-3 py-2 font-medium text-muted-foreground'
  const columns: RecordColumn<PhrVital>[] = [
    {
      id: 'vital',
      header: 'Vital',
      headerClassName: head,
      className: 'px-3 py-2 font-medium text-foreground',
      cell: (v) => v.vital_name ?? '—',
    },
    { id: 'value', header: 'Value', fit: true, numeric: true, headerClassName: head, className: 'px-3 py-2 font-semibold text-foreground', cell: displayValue },
    { id: 'unit', header: 'Unit', fit: true, headerClassName: head, className: 'px-3 py-2 text-muted-foreground', cell: (v) => v.unit ?? (v.secondary_unit ?? '—') },
    { id: 'site', header: 'Body Site', fit: true, headerClassName: head, className: 'px-3 py-2 text-muted-foreground', cell: (v) => v.body_site ?? '—' },
    { id: 'date', header: 'Date', fit: true, headerClassName: head, className: 'px-3 py-2 text-muted-foreground', cell: (v) => vitalDate(v) || '—' },
    {
      id: 'trend',
      header: 'Trend',
      fit: true,
      headerClassName: head,
      className: 'px-3 py-2',
      cell: (v) => {
        const candidates = metricCandidates(v)
        return (
          <div className="flex flex-wrap gap-1">
            {candidates.length === 0 && <span className="text-muted-foreground">—</span>}
            {candidates.map((candidate) => (
              <Button
                key={`${v.id}-${candidate.key}`}
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 px-2 text-xs"
                onClick={() => onDrill?.({ id: 'vitals-trend', instance: candidate.key })}
              >
                {candidate.label} trend
              </Button>
            ))}
          </div>
        )
      },
    },
    {
      id: 'review',
      header: 'Review',
      fit: true,
      headerClassName: head,
      className: 'px-3 py-2',
      cell: (v) => (
        <div className="flex items-center gap-2">
          {reviewStatusBadge(v.review_status)}
          {canManage && (
            <ReviewActions
              status={v.review_status}
              label={v.vital_name ?? 'vital'}
              busy={reviewingId === v.id}
              onReview={(decision) => void reviewVital(v, decision)}
            />
          )}
        </div>
      ),
    },
  ]

  return (
    <div>
      <div className="mb-6 flex items-center justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
            <HeartPulse className="size-6 text-primary" />
            Vitals
          </h1>
          {vitals.length > 0 && (
            <p className="mt-1 text-sm text-muted-foreground">
              {vitals.length} reading{vitals.length === 1 ? '' : 's'}
              {vitalNames.length > 0 && (
                <span className="ml-2">· {vitalNames.slice(0, 5).join(', ')}</span>
              )}
            </p>
          )}
        </div>
      </div>

      {error && (
        <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      {canManage && (
        <div className="mb-6">
          <AddVitalForm patientId={patientId} onAdded={(v) => setVitals((prev) => [v, ...prev])} />
        </div>
      )}

      <div className="mb-4 flex flex-wrap gap-3">
        <Input
          placeholder="Filter by vital name…"
          aria-label="Filter by vital name"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="max-w-xs"
        />
        <Button
          variant="ghost"
          size="sm"
          onClick={() => setSortDir((d) => (d === 'desc' ? 'asc' : 'desc'))}
        >
          Date {sortDir === 'desc' ? '↓' : '↑'}
        </Button>
        <ShowRejectedToggle showRejected={showRejected} onChange={setShowRejected} disabled={busy} />
      </div>

      {!busy && filtered.length === 0 && (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          {vitals.length === 0 ? 'No vitals recorded.' : 'No vitals match the current filter.'}
        </div>
      )}

      {(firstLoad || filtered.length > 0) && (
        <RecordTable
          label="Vitals"
          loading={firstLoad}
          rows={filtered}
          rowId={(v) => String(v.id)}
          columns={columns}
          openRowId={openRecordId(openRecord, 'vitals-reading-detail')}
          onOpen={drillOpener(onDrill, (v: PhrVital) => ({ id: 'vitals-reading-detail', instance: String(v.id) }))}
          className="rounded-lg border border-border"
          tableClassName="w-full text-sm"
          headClassName="border-b border-border bg-muted/40"
          rowClassName="border-b border-border last:border-0 hover:bg-muted/20"
        />
      )}
    </div>
  )
}
