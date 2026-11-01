import { ArrowLeft } from 'lucide-react'
import type { ReactElement } from 'react'
import { useCallback, useEffect, useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { displayUnits } from '@/lib/measureUnits'
import { ehiFailureText } from '@/phr/imports/ehi/ehiApi'
import { fieldLabel, formatSourceDate } from '@/phr/imports/ehi/ehiProposalView'

import { type AiImportDetail, type AiImportResult, decideAiResult, getAiImport } from './aiImportsApi'

/** Fields that name the record, in the order a proposal is titled by. */
const TITLE_FIELDS = ['vaccine_name', 'analyte', 'name', 'title', 'description', 'subject']

/** Provenance a reviewer does not decide on. */
const HIDDEN_FIELDS = new Set(['external_id', 'import_source', 'source', 'source_document_id', 'raw_text', 'review_status', 'genai_job_id'])

const JOB_TYPES: Record<string, string> = {
  phr_document: 'Records from a document',
  phr_lab_result: 'Lab results',
  phr_vital: 'Vitals',
  phr_medication: 'Medications',
  phr_problem_list: 'Conditions',
  phr_procedure: 'Procedures',
  phr_immunization: 'Immunizations',
  phr_allergy: 'Allergies',
  phr_office_visit: 'Office visits',
}

const WHOLE_DATE = /^\d{4}-\d{2}(-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?)?$/

function display(value: unknown): string | null {
  if (value === null || value === undefined || value === '') return null
  // Shown as a date only when the whole value is one, so text that begins with a
  // date keeps the rest of what it says.
  if (typeof value === 'string') return WHOLE_DATE.test(value) ? formatSourceDate(value) : displayUnits(value.length > 160 ? `${value.slice(0, 157)}…` : value)
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  return null
}

interface RecordView {
  title: string
  fields: { key: string, label: string, value: string }[]
}

export interface AiResultView extends RecordView {
  /**
   * Records nested in the proposal, such as a document's labs and medications.
   * Accepting the proposal imports every one, so every one is shown.
   */
  groups: { key: string, label: string, records: RecordView[] }[]
}

function recordView(data: Record<string, unknown>, fallback: string): RecordView {
  const titleField = TITLE_FIELDS.find((field) => display(data[field]) !== null)
  const fields = Object.entries(data)
    .filter(([field]) => field !== titleField && !HIDDEN_FIELDS.has(field))
    .map(([field, value]) => ({ key: field, label: fieldLabel(field), value: display(value) }))
    .filter((field): field is { key: string, label: string, value: string } => field.value !== null)

  return { title: titleField ? String(display(data[titleField])) : fallback, fields }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

export function aiResultView(result: AiImportResult): AiResultView {
  const groups = Object.entries(result.data)
    .filter(([field, value]) => !HIDDEN_FIELDS.has(field) && Array.isArray(value) && value.some(isRecord))
    .map(([field, value]) => ({
      key: field,
      label: fieldLabel(field),
      records: (value as unknown[]).filter(isRecord).map((record, index) => recordView(record, `${fieldLabel(field)} ${index + 1}`)),
    }))

  return { ...recordView(result.data, `Proposal ${result.result_index + 1}`), groups }
}

/**
 * Every value an AI proposal would add: its own fields and each nested record (a
 * document's labs, medications and so on). Accepting the proposal imports all of them,
 * so a reviewer sees all of them before accepting it, here and on the Review page.
 */
export function AiResultDetails({ view }: { view: AiResultView }): ReactElement {
  return (
    <>
      {view.fields.length > 0 && (
        <dl className="grid grid-cols-[minmax(8rem,auto)_minmax(0,1fr)] gap-x-4 gap-y-1 text-sm">
          {view.fields.map((field) => (
            <div key={field.key} className="contents">
              <dt className="text-muted-foreground">{field.label}</dt>
              <dd>{field.value}</dd>
            </div>
          ))}
        </dl>
      )}
      {view.groups.map((group) => (
        <div key={group.key} className="rounded-md border border-border bg-muted/30 px-3 py-2 text-sm">
          <p className="font-medium text-foreground">{group.label} ({group.records.length}) — accepting adds each of these</p>
          <ul className="mt-1 flex flex-col gap-1">
            {group.records.map((record, index) => (
              // Nested records have no id of their own; their order is stable.
              // eslint-disable-next-line @eslint-react/no-array-index-key
              <li key={index}>
                <span className="text-foreground">{record.title}</span>
                {record.fields.length > 0 && <span className="text-muted-foreground"> · {record.fields.map((field) => `${field.label}: ${field.value}`).join(' · ')}</span>}
              </li>
            ))}
          </ul>
        </div>
      ))}
    </>
  )
}

const STATUS_LABELS: Record<string, string> = { imported: 'Accepted', skipped: 'Rejected' }

export interface AiImportReviewProps {
  patientId: number
  importId: number
  onBack: () => void
}

/** Review of the records AI proposed from one document. Each decision takes effect right away. */
export function AiImportReview({ patientId, importId, onBack }: AiImportReviewProps): ReactElement {
  const [job, setJob] = useState<AiImportDetail | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [busy, setBusy] = useState<number | 'all' | null>(null)
  const [errors, setErrors] = useState<Readonly<Record<number, string>>>({})
  const [bulk, setBulk] = useState<{ done: number, failed: number } | null>(null)

  const load = useCallback(async (): Promise<void> => {
    setLoadError(null)
    try {
      setJob(await getAiImport(patientId, importId))
    } catch (caught) {
      setLoadError(ehiFailureText(caught))
    }
  }, [patientId, importId])

  useEffect(() => {
    void load()
  }, [load])

  async function decide(result: AiImportResult, decision: 'accept' | 'reject'): Promise<boolean> {
    setErrors(({ [result.id]: _cleared, ...rest }) => rest)
    try {
      const decided = await decideAiResult(patientId, importId, result.id, decision)
      setJob((current) => current && { ...current, results: current.results.map((candidate) => (candidate.id === result.id ? { ...candidate, status: decided.status } : candidate)) })
      return true
    } catch (caught) {
      setErrors((previous) => ({ ...previous, [result.id]: ehiFailureText(caught) }))
      return false
    }
  }

  async function decideOne(result: AiImportResult, decision: 'accept' | 'reject'): Promise<void> {
    setBusy(result.id)
    await decide(result, decision)
    setBusy(null)
  }

  async function acceptAll(): Promise<void> {
    const pending = (job?.results ?? []).filter((result) => result.status === 'pending_review')
    setBusy('all')
    let done = 0
    let failed = 0
    for (const result of pending) {
      if (await decide(result, 'accept')) done += 1
      else failed += 1
    }
    setBulk({ done, failed })
    setBusy(null)
  }

  const pending = (job?.results ?? []).filter((result) => result.status === 'pending_review')

  return (
    <div className="flex flex-col gap-4">
      <div>
        <Button type="button" variant="ghost" size="sm" onClick={onBack}>
          <ArrowLeft className="size-4" aria-hidden="true" />
          All imports
        </Button>
      </div>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h2 className="text-xl font-semibold text-foreground">Review {job?.document_title ?? 'AI import'}</h2>
          <p className="text-sm text-muted-foreground">
            {job === null ? 'Loading proposals…' : `${JOB_TYPES[job.job_type] ?? 'Records'} proposed by ${job.execution_mode === 'external' ? 'your agent, through the AI Agent Inbox' : 'server-side AI'}. ${pending.length === 0 ? 'Every proposal has been decided.' : `${pending.length} to decide; each decision takes effect right away.`}`}
          </p>
        </div>
        {pending.length > 1 && (
          <Button type="button" disabled={busy !== null} onClick={() => void acceptAll()}>Accept all {pending.length}</Button>
        )}
      </div>
      <p role="note" className="rounded-md border border-primary/30 bg-primary/5 px-3 py-2 text-sm text-foreground">
        AI output can be wrong. Check each proposal against the document before accepting it.
      </p>
      {bulk && (
        <p role="status" className="text-sm text-muted-foreground">
          Accepted {bulk.done}.{bulk.failed > 0 && ` ${bulk.failed} could not be accepted; they are still listed below with the reason.`}
        </p>
      )}
      {loadError && (
        <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          <span>{loadError}</span>
          <Button type="button" variant="outline" size="sm" onClick={() => void load()}>Retry</Button>
        </div>
      )}
      {job !== null && job.results.length === 0 && <p className="text-sm text-muted-foreground">No records were proposed from this document.</p>}
      {job !== null && job.results.length > 0 && (
        <section aria-label="Proposed records" className="rounded-lg border border-border bg-card px-4 py-1">
          <ul>
            {job.results.map((result) => {
              const view = aiResultView(result)
              const titleId = `ai-result-${result.id}`
              const decided = result.status !== 'pending_review'
              return (
                <li key={result.id} aria-labelledby={titleId} className="flex flex-col gap-2 border-t border-border py-4 first:border-t-0">
                  <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <span id={titleId} className="font-medium text-foreground">{view.title}</span>
                    {decided && <Badge variant="secondary">{STATUS_LABELS[result.status] ?? result.status}</Badge>}
                  </div>
                  <AiResultDetails view={view} />
                  {errors[result.id] && <p role="alert" className="text-sm text-destructive">{errors[result.id]}</p>}
                  {!decided && (
                    <div className="flex flex-wrap gap-2">
                      <Button type="button" size="sm" disabled={busy !== null} aria-label={`Accept: ${view.title}`} onClick={() => void decideOne(result, 'accept')}>Accept</Button>
                      <Button type="button" size="sm" variant="outline" disabled={busy !== null} aria-label={`Reject: ${view.title}`} onClick={() => void decideOne(result, 'reject')}>Reject</Button>
                    </div>
                  )}
                </li>
              )
            })}
          </ul>
        </section>
      )}
    </div>
  )
}
