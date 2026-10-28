import { ArrowLeft } from 'lucide-react'
import type { ReactElement } from 'react'
import { useCallback, useEffect, useMemo, useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { errorMessage } from '@/phr/shared'
import { PhrDocumentResponseSchema, PhrImmunizationResponseSchema } from '@/phr/types'

import { describeEhiError, type EhiDecision, type EhiProposal, listAllEhiProposals, reviewEhiProposal } from './ehiApi'
import { decisionActions, formatSourceDate, proposalView, retainReason, REVIEW_GROUPS, reviewGroupOf, STATUS_LABELS } from './ehiProposalView'

export interface EhiReviewProps {
  patientId: number
  packageId: number
  /** The source label the package was bound to, for the heading. */
  sourceLabel?: string | null
  onBack: () => void
}

function reasonText(caught: unknown): string {
  return typeof caught === 'string' && /^[a-z_]+$/.test(caught) ? describeEhiError(caught) : errorMessage(caught)
}

/** One existing record a possible duplicate may be the same as. */
function CandidateRecord({ patientId, mapping, recordId }: { patientId: number, mapping: string, recordId: number }): ReactElement {
  const [summary, setSummary] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    async function load(): Promise<void> {
      try {
        if (mapping === 'immunization') {
          const { immunization } = PhrImmunizationResponseSchema.parse(await fetchWrapper.get(`/api/phr/patients/${patientId}/immunizations/${recordId}`))
          const date = formatSourceDate(immunization.administered_on)
          if (!cancelled) setSummary([immunization.vaccine_name, date].filter(Boolean).join(' · '))
        } else if (mapping === 'document') {
          const { document } = PhrDocumentResponseSchema.parse(await fetchWrapper.get(`/api/phr/patients/${patientId}/documents/${recordId}`))
          const date = formatSourceDate(document.observed_at)
          if (!cancelled) setSummary([document.title ?? 'Untitled document', date].filter(Boolean).join(' · '))
        }
      } catch {
        if (!cancelled) setSummary(null)
      }
    }
    void load()

    return () => {
      cancelled = true
    }
  }, [patientId, mapping, recordId])

  return <span>{summary ?? `Record ${recordId}`}</span>
}

interface ProposalCardProps {
  patientId: number
  proposal: EhiProposal
  busy: boolean
  error: string | null
  onDecide: (proposal: EhiProposal, decision: EhiDecision, linkId?: number) => void
}

function ProposalCard({ patientId, proposal, busy, error, onDecide }: ProposalCardProps): ReactElement {
  const view = proposalView(proposal)
  const group = reviewGroupOf(proposal)
  const [linkId, setLinkId] = useState<number | undefined>(proposal.candidate_ids[0])
  const decided = proposal.status !== 'pending'
  const actions = proposal.reviewable ? decisionActions(proposal) : []
  const why = group === 'kept' ? retainReason(proposal) : null
  const titleId = `ehi-proposal-${proposal.id}`

  return (
    <li aria-labelledby={titleId} className="flex flex-col gap-3 border-t border-border py-4 first:border-t-0">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <div className="flex min-w-0 flex-col gap-0.5">
          <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{view.domain}</span>
          <span id={titleId} className="font-medium text-foreground">{view.title}</span>
          {(view.date || view.detail) && (
            <span className="text-sm text-muted-foreground">{[view.date, view.detail].filter(Boolean).join(' · ')}</span>
          )}
          {why && <span className="text-sm text-muted-foreground">{why}</span>}
        </div>
        {decided && <Badge variant="secondary">{STATUS_LABELS[proposal.status]}</Badge>}
      </div>

      {view.changes.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[420px] text-left text-sm">
            <thead className="text-xs text-muted-foreground">
              <tr><th className="py-1 pr-4 font-medium">Field</th><th className="py-1 pr-4 font-medium">In PHR now</th><th className="py-1 font-medium">In this export</th></tr>
            </thead>
            <tbody>
              {view.changes.map((change) => (
                <tr key={change.field} className="border-t border-border">
                  <td className="py-1.5 pr-4 text-muted-foreground">{change.field}</td>
                  <td className="py-1.5 pr-4">{change.before}</td>
                  <td className="py-1.5 font-medium">{change.after}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {group === 'duplicate' && proposal.candidate_ids.length > 0 && (
        <fieldset className="rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
          <legend className="px-1 text-xs font-medium text-muted-foreground">Already in PHR</legend>
          {proposal.candidate_ids.length === 1 ? (
            <CandidateRecord patientId={patientId} mapping={proposal.mapping} recordId={proposal.candidate_ids[0]!} />
          ) : (
            <div className="flex flex-col gap-1">
              {proposal.candidate_ids.map((candidateId) => (
                <label key={candidateId} className="flex items-center gap-2">
                  <input type="radio" name={`ehi-candidate-${proposal.id}`} checked={linkId === candidateId} disabled={decided || busy} onChange={() => setLinkId(candidateId)} />
                  <CandidateRecord patientId={patientId} mapping={proposal.mapping} recordId={candidateId} />
                </label>
              ))}
            </div>
          )}
          {proposal.candidates_truncated && (
            <p className="mt-1 text-xs text-muted-foreground">{proposal.candidate_total} records could match; the closest are shown.</p>
          )}
        </fieldset>
      )}

      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}

      {!decided && actions.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {actions.map((action) => (
            <Button
              key={action.decision}
              type="button"
              size="sm"
              variant={action.primary ? 'default' : 'outline'}
              disabled={busy}
              aria-label={`${action.label}: ${view.title}`}
              onClick={() => onDecide(proposal, action.decision, action.decision === 'link_existing' ? linkId : undefined)}
            >
              {action.label}
            </Button>
          ))}
        </div>
      )}
      {!decided && !proposal.reviewable && (
        <p className="text-sm text-muted-foreground">This proposal is being prepared again and cannot be decided yet.</p>
      )}
    </li>
  )
}

/**
 * The review screen of one EHI package. Each decision takes effect immediately on the
 * server, which rechecks access and the preview digest; there is no batch apply.
 */
export function EhiReview({ patientId, packageId, sourceLabel, onBack }: EhiReviewProps): ReactElement {
  const [proposals, setProposals] = useState<EhiProposal[] | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [busy, setBusy] = useState<ReadonlySet<number>>(() => new Set())
  const [errors, setErrors] = useState<Readonly<Record<number, string>>>({})
  const [bulk, setBulk] = useState<{ running: boolean, done: number, failed: number } | null>(null)

  const load = useCallback(async (): Promise<void> => {
    setLoadError(null)
    try {
      setProposals(await listAllEhiProposals(patientId, packageId))
    } catch (caught) {
      setLoadError(errorMessage(caught))
    }
  }, [patientId, packageId])

  useEffect(() => {
    void load()
  }, [load])

  const decide = useCallback(async (proposal: EhiProposal, decision: EhiDecision, linkId?: number): Promise<boolean> => {
    setBusy((previous) => new Set(previous).add(proposal.id))
    setErrors(({ [proposal.id]: _cleared, ...rest }) => rest)
    try {
      const result = await reviewEhiProposal(patientId, proposal, decision, linkId)
      setProposals((previous) => previous?.map((candidate) => (candidate.id === proposal.id ? result.proposal : candidate)) ?? null)

      return true
    } catch (caught) {
      setErrors((previous) => ({ ...previous, [proposal.id]: reasonText(caught) }))

      return false
    } finally {
      setBusy((previous) => {
        const next = new Set(previous)
        next.delete(proposal.id)
        return next
      })
    }
  }, [patientId])

  const grouped = useMemo(() => {
    const byGroup = new Map(REVIEW_GROUPS.map(({ group }) => [group, [] as EhiProposal[]]))
    for (const proposal of proposals ?? []) {
      if (proposal.status !== 'superseded') {
        byGroup.get(reviewGroupOf(proposal))!.push(proposal)
      }
    }
    for (const list of byGroup.values()) {
      list.sort((left, right) => Number(left.status !== 'pending') - Number(right.status !== 'pending') || left.id - right.id)
    }
    return byGroup
  }, [proposals])

  const undecided = (proposals ?? []).filter((proposal) => proposal.status === 'pending' && proposal.reviewable)
  const acceptableNew = undecided.filter((proposal) => reviewGroupOf(proposal) === 'new' && proposal.allowed_decisions.includes('accept'))

  async function acceptAllNew(): Promise<void> {
    const queue = [...acceptableNew]
    setBulk({ running: true, done: 0, failed: 0 })
    let done = 0
    let failed = 0
    for (const proposal of queue) {
      if (await decide(proposal, 'accept')) {
        done += 1
      } else {
        failed += 1
      }
      setBulk({ running: true, done, failed })
    }
    setBulk({ running: false, done, failed })
  }

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
          <h2 className="text-xl font-semibold text-foreground">Review {sourceLabel ?? 'EHI export'}</h2>
          <p className="text-sm text-muted-foreground">
            {proposals === null ? 'Loading proposals…' : undecided.length === 0 ? 'Every proposal from this import has been decided.' : `${undecided.length} to decide. Each decision takes effect right away.`}
          </p>
        </div>
        {acceptableNew.length > 0 && (
          <Button type="button" disabled={bulk?.running === true} onClick={() => void acceptAllNew()}>
            Accept all {acceptableNew.length} new records
          </Button>
        )}
      </div>

      {bulk && (
        <p role="status" className="text-sm text-muted-foreground">
          {bulk.running ? `Accepting… ${bulk.done} done.` : `Accepted ${bulk.done}.`}
          {bulk.failed > 0 && ` ${bulk.failed} could not be accepted; they are still listed below with the reason.`}
        </p>
      )}
      {loadError && (
        <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          <span>{loadError}</span>
          <Button type="button" variant="outline" size="sm" onClick={() => void load()}>Retry</Button>
        </div>
      )}

      {REVIEW_GROUPS.map(({ group, title, description }) => {
        const list = grouped.get(group) ?? []
        if (list.length === 0) {
          return null
        }
        const headingId = `ehi-review-${group}`
        return (
          <section key={group} aria-labelledby={headingId} className="rounded-lg border border-border bg-card px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <h3 id={headingId} className="text-base font-semibold text-card-foreground">{title}</h3>
              <span className="text-sm text-muted-foreground">{list.length}</span>
            </div>
            <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>
            <ul className="mt-1">
              {list.map((proposal) => (
                <ProposalCard
                  key={proposal.id}
                  patientId={patientId}
                  proposal={proposal}
                  busy={busy.has(proposal.id) || bulk?.running === true}
                  error={errors[proposal.id] ?? null}
                  onDecide={(target, decision, linkId) => void decide(target, decision, linkId)}
                />
              ))}
            </ul>
          </section>
        )
      })}
    </div>
  )
}
