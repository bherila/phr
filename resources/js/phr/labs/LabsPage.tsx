import { FlaskConical, Plus } from 'lucide-react'
import type { ComponentProps, FormEvent } from 'react'
import { useCallback, useEffect, useMemo, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fetchWrapper } from '@/fetchWrapper'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { formatLabReferenceRange, formatLabValue } from '@/phr/labs/formatLabResult'
import type { PhrListPageProps } from '@/phr/miller'
import { errorMessage, numericPayload } from '@/phr/shared'
import {
  type PhrLabResult,
  type PhrLabResultFormData,
  PhrLabResultFormSchema,
  PhrLabResultResponseSchema,
  PhrLabResultsResponseSchema,
} from '@/phr/types'

const FLAG_CLASS: Record<string, string> = {
  H: 'text-orange-600 dark:text-orange-400',
  HH: 'text-red-600 dark:text-red-400 font-bold',
  L: 'text-blue-600 dark:text-blue-400',
  LL: 'text-red-600 dark:text-red-400 font-bold',
  A: 'text-orange-600 dark:text-orange-400',
  AA: 'text-red-600 dark:text-red-400 font-bold',
  C: 'text-red-600 dark:text-red-400 font-bold',
}

function flagClass(flag: string | null | undefined): string {
  return flag ? (FLAG_CLASS[flag.toUpperCase()] ?? '') : ''
}

const emptyForm: PhrLabResultFormData = {
  test_name: '',
  analyte: '',
  value: '',
  value_numeric: '',
  unit: '',
  result_datetime: '',
  range_min: '',
  range_max: '',
  abnormal_flag: '',
  notes: '',
}

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

interface LabResultGroup {
  key: string
  title: string
  results: PhrLabResult[]
  showPanelColumn: boolean
}

function panelName(result: PhrLabResult): string | null {
  const trimmed = result.test_name?.trim()

  return trimmed ? trimmed : null
}

function groupLabResults(results: PhrLabResult[]): LabResultGroup[] {
  const panelCounts = new Map<string, number>()

  for (const result of results) {
    const name = panelName(result)

    if (name === null) {
      continue
    }

    panelCounts.set(name, (panelCounts.get(name) ?? 0) + 1)
  }

  const groupsByKey = new Map<string, LabResultGroup>()

  for (const result of results) {
    const name = panelName(result)
    const isPanel = name !== null && (panelCounts.get(name) ?? 0) > 1
    const key = isPanel ? `panel:${name}` : 'other'

    let group = groupsByKey.get(key)
    if (group === undefined) {
      group = {
        key,
        title: isPanel ? (name as string) : 'Other',
        results: [],
        showPanelColumn: !isPanel,
      }
      groupsByKey.set(key, group)
    }

    group.results.push(result)
  }

  return Array.from(groupsByKey.values())
}

interface LabResultsTableProps {
  results: PhrLabResult[]
  showPanelColumn: boolean
  onDrill: PhrListPageProps['onDrill']
}

function LabResultsTable({ results, showPanelColumn, onDrill }: LabResultsTableProps) {
  return (
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-border bg-muted/40">
          <th className="px-3 py-2 text-left font-medium text-muted-foreground">Analyte</th>
          {showPanelColumn && (
            <th className="px-3 py-2 text-left font-medium text-muted-foreground">Panel</th>
          )}
          <th className="px-3 py-2 text-right font-medium text-muted-foreground">Value</th>
          <th className="px-3 py-2 text-left font-medium text-muted-foreground">Range</th>
          <th className="px-3 py-2 text-center font-medium text-muted-foreground">Flag</th>
          <th className="px-3 py-2 text-left font-medium text-muted-foreground">Date</th>
        </tr>
      </thead>
      <tbody>
        {results.map((result) => (
          <tr
            key={result.id}
            className={`border-b border-border last:border-0 hover:bg-muted/20 ${onDrill ? 'cursor-pointer' : ''}`}
            onClick={() => onDrill?.({ id: 'lab-panel-detail', instance: String(result.id) })}
          >
            <td className="px-3 py-2 font-medium text-foreground">
              <div>{result.analyte ?? '—'}</div>
              <div className="mt-1">{reviewStatusBadge(result.review_status)}</div>
            </td>
            {showPanelColumn && (
              <td className="px-3 py-2 text-muted-foreground">{result.test_name ?? '—'}</td>
            )}
            <td className={`px-3 py-2 text-right ${flagClass(result.abnormal_flag)}`}>
              {formatLabValue(result) ?? '—'}
              {result.unit && <span className="ml-1 text-xs text-muted-foreground">{result.unit}</span>}
            </td>
            <td className="px-3 py-2 text-muted-foreground">{formatLabReferenceRange(result) ?? '—'}</td>
            <td className={`px-3 py-2 text-center font-semibold ${flagClass(result.abnormal_flag)}`}>
              {result.abnormal_flag && result.abnormal_flag !== 'N' ? result.abnormal_flag : ''}
            </td>
            <td className="px-3 py-2 text-muted-foreground">
              {(result.result_datetime ?? result.collection_datetime ?? '').slice(0, 10) || '—'}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

interface AddLabFormProps {
  patientId: number
  onAdded: (result: PhrLabResult) => void
}

function AddLabForm({ patientId, onAdded }: AddLabFormProps) {
  const [form, setForm] = useState<PhrLabResultFormData>(emptyForm)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [open, setOpen] = useState(false)

  async function handleSubmit(e: FormEvent<HTMLFormElement>): Promise<void> {
    e.preventDefault()
    setError(null)
    const parsed = PhrLabResultFormSchema.safeParse(form)
    if (!parsed.success) {
      setError(parsed.error.issues[0]?.message ?? 'Invalid input.')
      return
    }
    setBusy(true)
    try {
      const raw: unknown = await fetchWrapper.post(
        `/api/phr/patients/${patientId}/lab-results`,
        numericPayload(parsed.data, ['value_numeric', 'range_min', 'range_max']),
      )
      onAdded(PhrLabResultResponseSchema.parse(raw).lab_result)
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
        Add Lab Result
      </Button>
    )
  }

  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <h3 className="mb-3 text-sm font-semibold text-card-foreground">Add Lab Result</h3>
      <form onSubmit={(e) => void handleSubmit(e)}>
        <div className="grid gap-3 sm:grid-cols-2">
          <LabeledInput label="Panel / Test Name" value={form.test_name ?? ''} onChange={(v) => setForm({ ...form, test_name: v })} />
          <LabeledInput label="Analyte *" value={form.analyte} onChange={(v) => setForm({ ...form, analyte: v })} required />
          <LabeledInput label="Value" value={form.value ?? ''} onChange={(v) => setForm({ ...form, value: v })} />
          <LabeledInput label="Value (Numeric)" inputMode="decimal" value={form.value_numeric ?? ''} onChange={(v) => setForm({ ...form, value_numeric: v })} />
          <LabeledInput label="Unit" value={form.unit ?? ''} onChange={(v) => setForm({ ...form, unit: v })} />
          <LabeledInput label="Result Date/Time" type="datetime-local" value={form.result_datetime ?? ''} onChange={(v) => setForm({ ...form, result_datetime: v })} />
          <LabeledInput label="Range Min" inputMode="decimal" value={form.range_min ?? ''} onChange={(v) => setForm({ ...form, range_min: v })} />
          <LabeledInput label="Range Max" inputMode="decimal" value={form.range_max ?? ''} onChange={(v) => setForm({ ...form, range_max: v })} />
          <LabeledInput label="Abnormal Flag (H/L/HH/LL/C)" value={form.abnormal_flag ?? ''} onChange={(v) => setForm({ ...form, abnormal_flag: v })} />
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

export default function LabsPage({ patientId, onDrill }: PhrListPageProps) {
  const [results, setResults] = useState<PhrLabResult[]>([])
  const [canManage, setCanManage] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [flagFilter, setFlagFilter] = useState<'all' | 'abnormal'>('all')
  const [sortDir, setSortDir] = useState<'desc' | 'asc'>('desc')

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      const rawLabs = await fetchWrapper.get(`/api/phr/patients/${patientId}/lab-results`)
      const parsed = PhrLabResultsResponseSchema.parse(rawLabs)
      setResults(parsed.lab_results)
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
    let list = results
    if (flagFilter === 'abnormal') {
      list = list.filter((r) => r.abnormal_flag && r.abnormal_flag !== 'N')
    }
    if (search.trim()) {
      const q = search.toLowerCase()
      list = list.filter(
        (r) =>
          r.analyte?.toLowerCase().includes(q) ||
          r.test_name?.toLowerCase().includes(q),
      )
    }
    list = [...list].sort((a, b) => {
      const da = a.result_datetime ?? a.collection_datetime ?? ''
      const db = b.result_datetime ?? b.collection_datetime ?? ''
      return sortDir === 'desc' ? db.localeCompare(da) : da.localeCompare(db)
    })
    return list
  }, [results, search, flagFilter, sortDir])

  const groupedResults = useMemo(() => groupLabResults(filtered), [filtered])

  const abnormalCount = useMemo(
    () => results.filter((r) => r.abnormal_flag && r.abnormal_flag !== 'N').length,
    [results],
  )

  return (
    <div>
      <div className="mb-6 flex items-center justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
            <FlaskConical className="size-6 text-primary" />
            Labs
          </h1>
          {results.length > 0 && (
            <p className="mt-1 text-sm text-muted-foreground">
              {results.length} result{results.length === 1 ? '' : 's'}
              {abnormalCount > 0 && (
                <span className="ml-2 text-orange-600 dark:text-orange-400">
                  · {abnormalCount} abnormal
                </span>
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
          <AddLabForm patientId={patientId} onAdded={(r) => setResults((prev) => [r, ...prev])} />
        </div>
      )}

      <div className="mb-4 flex flex-wrap gap-3">
        <Input
          placeholder="Search analyte or panel…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="max-w-xs"
        />
        <div className="flex items-center gap-2">
          <Button
            variant={flagFilter === 'all' ? 'default' : 'outline'}
            size="sm"
            onClick={() => setFlagFilter('all')}
          >
            All
          </Button>
          <Button
            variant={flagFilter === 'abnormal' ? 'default' : 'outline'}
            size="sm"
            onClick={() => setFlagFilter('abnormal')}
          >
            Abnormal only
          </Button>
        </div>
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
          {results.length === 0 ? 'No lab results recorded.' : 'No results match the current filter.'}
        </div>
      )}

      {filtered.length > 0 && (
        <div className="space-y-4">
          {groupedResults.map((group) => (
            <section
              key={group.key}
              aria-label={`${group.title} lab results`}
              className="overflow-hidden rounded-lg border border-border bg-card"
            >
              <div className="flex items-center justify-between gap-3 border-b border-border bg-muted/30 px-3 py-2">
                <h2 className="text-sm font-semibold text-foreground">{group.title}</h2>
                <span className="text-xs text-muted-foreground">
                  {group.results.length} result{group.results.length === 1 ? '' : 's'}
                </span>
              </div>
              <div className="overflow-x-auto">
                <LabResultsTable
                  results={group.results}
                  showPanelColumn={group.showPanelColumn}
                  onDrill={onDrill}
                />
              </div>
            </section>
          ))}
        </div>
      )}
    </div>
  )
}
