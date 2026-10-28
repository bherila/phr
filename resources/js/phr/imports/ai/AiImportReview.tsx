import { ArrowLeft } from 'lucide-react'
import type { ReactElement } from 'react'
import { useCallback, useEffect, useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
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

function display(value: unknown): string | null {
  if (value === null || value === undefined || value === '') return null
  if (typeof value === 'string') return /^\d{4}-\d{2}(-\d{2})?([T ]|$)/.test(value) ? formatSourceDate(value) : value.length > 160 ? `${value.slice(0, 157)}…` : value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  return null
}

export function aiResultView(result: AiImportResult): { title: string, fields: { label: string, value: string }[] } {
  const titleField = TITLE_FIELDS.find((field) => display(result.data[field]) !== null)
  const fields = Object.entries(result.data)
    .filter(([field]) => field !== titleField && !HIDDEN_FIELDS.has(field))
    .map(([field, value]) => ({ label: fieldLabel(field), value: display(value) }))
    .filter((field): field is { label: string, value: string } => field.value !== null)

  return { title: titleField ? String(display(result.data[titleField])) : `Proposal ${result.result_index + 1}`, fields }
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
                  {view.fields.length > 0 && (
                    <dl className="grid grid-cols-[minmax(8rem,auto)_minmax(0,1fr)] gap-x-4 gap-y-1 text-sm">
                      {view.fields.map((field) => (
                        <div key={field.label} className="contents">
                          <dt className="text-muted-foreground">{field.label}</dt>
                          <dd>{field.value}</dd>
                        </div>
                      ))}
                    </dl>
                  )}
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
