import { FlaskConical, Plus } from 'lucide-react'
import type { ComponentProps, FormEvent } from 'react'
import { useCallback, useEffect, useMemo, useState } from 'react'

import { type RecordColumn, RecordTable } from '@/components/record-list/RecordTable'
import { SkeletonBlock } from '@/components/record-list/Skeleton'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fetchWrapper } from '@/fetchWrapper'
import { type PhrReviewDecision, ReviewActions, ShowRejectedToggle } from '@/phr/clinical/review'
import { recordReviewDecision } from '@/phr/clinical/reviewDecision'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { formatLabReferenceRange, formatLabValue, isNumericLabValue } from '@/phr/labs/formatLabResult'
import { useKnownPatientCanManage } from '@/phr/patients/usePhrPatients'
import { reviewStateChanged } from '@/phr/review/reviewSummary'
import { errorMessage, numericPayload } from '@/phr/shared'
import { drillOpener, type PhrListPageProps } from '@/phr/shell/phrPages'
import { openRecordId } from '@/phr/shell/phrRoutes'
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
  label: string
  loading?: boolean
  results: PhrLabResult[]
  showPanelColumn: boolean
  onDrill: PhrListPageProps['onDrill']
  openRowId: string | null
  canManage: boolean
  reviewingId: number | null
  onReview: (result: PhrLabResult, decision: PhrReviewDecision) => void
}

const HEAD = 'px-3 py-2 font-medium text-muted-foreground'

function LabResultsTable({ label, loading = false, results, showPanelColumn, onDrill, openRowId, canManage, reviewingId, onReview }: LabResultsTableProps) {
  const columns: RecordColumn<PhrLabResult>[] = [
    {
      id: 'analyte',
      header: 'Analyte',
      headerClassName: HEAD,
      className: 'px-3 py-2 font-medium text-foreground',
      cell: (result) => result.analyte ?? '—',
    },
    ...(showPanelColumn ? [{
      id: 'panel',
      header: 'Panel',
      headerClassName: HEAD,
      className: 'px-3 py-2 text-muted-foreground',
      cell: (result: PhrLabResult) => result.test_name ?? '—',
    }] : []),
    {
      id: 'value',
      header: 'Value',
      headerClassName: HEAD,
      fit: true,
      numeric: isNumericLabValue,
      className: 'px-3 py-2',
      cell: (result) => (
        <span className={flagClass(result.abnormal_flag)}>
          {formatLabValue(result) ?? '—'}
          {result.unit && <span className="ml-1 text-xs text-muted-foreground">{result.unit}</span>}
        </span>
      ),
    },
    { id: 'range', header: 'Range', fit: true, headerClassName: HEAD, className: 'px-3 py-2 text-muted-foreground', cell: (result) => formatLabReferenceRange(result) ?? '—' },
    {
      id: 'flag',
      header: 'Flag',
      headerClassName: `${HEAD} text-center`,
      fit: true,
      className: 'px-3 py-2 text-center font-semibold',
      cell: (result) => <span className={flagClass(result.abnormal_flag)}>{result.abnormal_flag && result.abnormal_flag !== 'N' ? result.abnormal_flag : ''}</span>,
    },
    {
      id: 'date',
      header: 'Date',
      fit: true,
      headerClassName: HEAD,
      className: 'px-3 py-2 text-muted-foreground',
      cell: (result) => (result.result_datetime ?? result.collection_datetime ?? '').slice(0, 10) || '—',
    },
    {
      id: 'review',
      header: 'Review',
      fit: true,
      headerClassName: HEAD,
      className: 'px-3 py-2',
      cell: (result) => (
        <div className="flex items-center gap-2">
          {reviewStatusBadge(result.review_status)}
          {canManage && (
            <ReviewActions
              status={result.review_status}
              label={result.analyte ?? result.test_name ?? 'lab result'}
              busy={reviewingId === result.id}
              onReview={(decision) => onReview(result, decision)}
            />
          )}
        </div>
      ),
    },
  ]

  return (
    <RecordTable
      label={label}
      loading={loading}
      rows={results}
      rowId={(result) => String(result.id)}
      columns={columns}
      openRowId={openRowId}
      onOpen={drillOpener(onDrill, (result: PhrLabResult) => ({ id: 'lab-panel-detail', instance: String(result.id) }))}
      tableClassName="w-full text-sm"
      headClassName="border-b border-border bg-muted/40"
      rowClassName="border-b border-border last:border-0 hover:bg-muted/20"
    />
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

export default function LabsPage({ patientId, onDrill, openRecord }: PhrListPageProps) {
  const [results, setResults] = useState<PhrLabResult[]>([])
  const knownCanManage = useKnownPatientCanManage(patientId)
  const [canManage, setCanManage] = useState(knownCanManage)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [flagFilter, setFlagFilter] = useState<'all' | 'abnormal'>('all')
  const [sortDir, setSortDir] = useState<'desc' | 'asc'>('desc')
  const [showRejected, setShowRejected] = useState(false)
  const [reviewingId, setReviewingId] = useState<number | null>(null)

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      const rawLabs = await fetchWrapper.get(
        showRejected
          ? `/api/phr/patients/${patientId}/lab-results?include_rejected=1`
          : `/api/phr/patients/${patientId}/lab-results`,
      )
      const parsed = PhrLabResultsResponseSchema.parse(rawLabs)
      setResults(parsed.lab_results)
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

  async function reviewResult(result: PhrLabResult, decision: PhrReviewDecision): Promise<void> {
    setReviewingId(result.id)
    setError(null)
    try {
      const raw: unknown = await recordReviewDecision(`/api/phr/patients/${patientId}/lab-results/${result.id}`, decision)
      const updated = PhrLabResultResponseSchema.parse(raw).lab_result
      if (decision === 'rejected' && !showRejected) {
        setResults((prev) => prev.filter((r) => r.id !== updated.id))
      } else {
        setResults((prev) => prev.map((r) => (r.id === updated.id ? updated : r)))
      }
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setReviewingId(null)
    }
  }

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
          aria-label="Search analyte or panel"
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
          <ShowRejectedToggle showRejected={showRejected} onChange={setShowRejected} disabled={busy} />
        </div>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => setSortDir((d) => (d === 'desc' ? 'asc' : 'desc'))}
        >
          Date {sortDir === 'desc' ? '↓' : '↑'}
        </Button>
      </div>

      {busy && results.length === 0 && (
        <section aria-label="Lab results" className="overflow-hidden rounded-lg border border-border bg-card">
          <div className="border-b border-border bg-muted/30 px-3 py-2"><SkeletonBlock className="w-48 text-sm" /></div>
          <LabResultsTable
            label="Lab results"
            loading
            results={[]}
            showPanelColumn
            onDrill={onDrill}
            openRowId={null}
            canManage={canManage}
            reviewingId={null}
            onReview={() => undefined}
          />
        </section>
      )}

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
              <LabResultsTable
                label={group.title}
                results={group.results}
                showPanelColumn={group.showPanelColumn}
                onDrill={onDrill}
                openRowId={openRecordId(openRecord, 'lab-panel-detail')}
                canManage={canManage}
                reviewingId={reviewingId}
                onReview={(result, decision) => void reviewResult(result, decision)}
              />
            </section>
          ))}
        </div>
      )}
    </div>
  )
}
