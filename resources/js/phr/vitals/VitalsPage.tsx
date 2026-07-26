import { HeartPulse, Plus } from 'lucide-react'
import type { ComponentProps, FormEvent } from 'react'
import { useCallback, useEffect, useMemo, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fetchWrapper } from '@/fetchWrapper'
import type { PhrListPageProps } from '@/phr/miller'
import { errorMessage, numericPayload } from '@/phr/shared'
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

export default function VitalsPage({ patientId, onDrill }: PhrListPageProps) {
  const [vitals, setVitals] = useState<PhrVital[]>([])
  const [canManage, setCanManage] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [sortDir, setSortDir] = useState<'desc' | 'asc'>('desc')

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      const rawVitals = await fetchWrapper.get(`/api/phr/patients/${patientId}/vitals`)
      const parsed = PhrVitalsResponseSchema.parse(rawVitals)
      setVitals(parsed.vitals)
      setCanManage(parsed.can_manage)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }, [patientId])

  useEffect(() => {
    void load()
  }, [load])

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

  const vitalNames = useMemo(
    () => Array.from(new Set(vitals.map((v) => v.vital_name).filter(Boolean))).sort(),
    [vitals],
  )

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
      </div>

      {busy && <p className="text-sm text-muted-foreground">Loading…</p>}

      {!busy && filtered.length === 0 && (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          {vitals.length === 0 ? 'No vitals recorded.' : 'No vitals match the current filter.'}
        </div>
      )}

      {filtered.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/40">
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Vital</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Value</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Unit</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Body Site</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Date</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Trend</th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((v) => {
                const candidates = metricCandidates(v)

                return (
                  <tr
                    key={v.id}
                    className="cursor-pointer border-b border-border last:border-0 hover:bg-muted/20"
                    onClick={() => onDrill?.({ id: 'vitals-reading-detail', instance: String(v.id) })}
                  >
                    <td className="px-3 py-2 font-medium text-foreground">{v.vital_name ?? '—'}</td>
                    <td className="px-3 py-2 text-right font-semibold text-foreground">{displayValue(v)}</td>
                    <td className="px-3 py-2 text-muted-foreground">{v.unit ?? (v.secondary_unit ?? '—')}</td>
                    <td className="px-3 py-2 text-muted-foreground">{v.body_site ?? '—'}</td>
                    <td className="px-3 py-2 text-muted-foreground">{vitalDate(v) || '—'}</td>
                    <td className="px-3 py-2">
                      <div className="flex flex-wrap gap-1">
                        {candidates.length === 0 && <span className="text-muted-foreground">—</span>}
                        {candidates.map((candidate) => (
                          <Button
                            key={`${v.id}-${candidate.key}`}
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2 text-xs"
                            onClick={(event) => {
                              event.stopPropagation()
                              onDrill?.({ id: 'vitals-trend', instance: candidate.key })
                            }}
                          >
                            {candidate.label} trend
                          </Button>
                        ))}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
