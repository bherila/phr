import { ExternalLink } from 'lucide-react'
import { useEffect, useState } from 'react'

import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { fetchWrapper } from '@/fetchWrapper'
import { errorMessage } from '@/phr/shared'

import {
  type DrugDetail,
  DrugDetailResponseSchema,
  fillDrugName,
  type MedicationFill,
  type NdcLabel,
  NdcLabelSchema,
} from './fillTypes'

type Loaded<T> = { phase: 'loading' } | { phase: 'error', message: string } | { phase: 'done', value: T }

function ExternalLinkItem({ href, children }: { href: string, children: React.ReactNode }) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-primary underline-offset-2 hover:underline">
      {children}
      <ExternalLink className="size-3.5" aria-hidden="true" />
    </a>
  )
}

function Facts({ rows }: { rows: Array<[string, React.ReactNode]> }) {
  const shown = rows.filter(([, value]) => value !== null && value !== undefined && value !== '')
  if (shown.length === 0) return null
  return (
    <dl className="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
      {shown.map(([term, value]) => (
        <div key={term} className="contents">
          <dt className="text-muted-foreground">{term}</dt>
          <dd className="text-card-foreground">{value}</dd>
        </div>
      ))}
    </dl>
  )
}

function LabelSections({ state, onRetry }: { state: Loaded<NdcLabel>, onRetry: () => void }) {
  if (state.phase === 'loading') return <p className="text-sm text-muted-foreground">Loading the FDA label…</p>
  if (state.phase === 'error') {
    return (
      <p role="alert" className="text-sm text-destructive">
        {state.message} <button type="button" className="underline" onClick={onRetry}>Try again</button>
      </p>
    )
  }
  const label = state.value
  if (label.status === 'unavailable') {
    return (
      <p className="text-sm text-muted-foreground">
        The FDA label service could not be reached just now. <button type="button" className="underline" onClick={onRetry}>Try again</button>
      </p>
    )
  }
  if (label.status !== 'found' || label.sections.length === 0) {
    return (
      <p className="rounded-md border border-dashed border-border px-3 py-4 text-sm text-muted-foreground">
        No FDA label is on file for this NDC. That is common for discontinued products, whose labels are withdrawn.
      </p>
    )
  }
  return (
    <div className="space-y-2">
      <p className="text-xs text-muted-foreground">
        As published by the FDA (openFDA drug label){label.effective_date ? `, effective ${label.effective_date}` : ''}{label.version ? `, version ${label.version}` : ''}. General labeling, not advice for you.
      </p>
      {label.sections.map((section) => (
        <details key={section.key} className="rounded-md border border-border">
          <summary className="cursor-pointer px-3 py-2 text-sm font-medium text-card-foreground">{section.title}</summary>
          <div className="whitespace-pre-line border-t border-border px-3 py-2 text-sm text-card-foreground">
            {section.text}
            {section.truncated && <p className="mt-2 text-xs text-muted-foreground">Shortened. Open the full label on DailyMed.</p>}
          </div>
        </details>
      ))}
    </div>
  )
}

/** Clicking a fill's Name opens this: the stored FDA/RxNav listing, plus the FDA label fetched on demand. */
export function DrugDetailPanel({ patientId, fill, onClose }: { patientId: number, fill: MedicationFill, onClose: () => void }) {
  const resolved = fill.ndc_status === 'resolved' && fill.ndc_10 !== null
  const [detail, setDetail] = useState<Loaded<DrugDetail>>({ phase: resolved ? 'loading' : 'error', message: '' })
  const [label, setLabel] = useState<Loaded<NdcLabel>>({ phase: 'loading' })
  const [labelTry, setLabelTry] = useState(0)

  useEffect(() => {
    if (!resolved) return
    let cancelled = false
    fetchWrapper.get(`/api/phr/patients/${patientId}/medication-fills/${fill.id}/drug-detail`).then((raw: unknown) => {
      if (!cancelled) setDetail({ phase: 'done', value: DrugDetailResponseSchema.parse(raw).drug_detail })
    }).catch((caught: unknown) => {
      if (!cancelled) setDetail({ phase: 'error', message: errorMessage(caught) })
    })
    return () => {
      cancelled = true
    }
  }, [patientId, fill.id, resolved])

  useEffect(() => {
    if (!resolved) return
    let cancelled = false
    fetchWrapper.get(`/api/ndc/label?ndc_10=${encodeURIComponent(fill.ndc_10 ?? '')}`).then((raw: unknown) => {
      if (!cancelled) setLabel({ phase: 'done', value: NdcLabelSchema.parse(raw) })
    }).catch((caught: unknown) => {
      if (!cancelled) setLabel({ phase: 'error', message: errorMessage(caught) })
    })
    return () => {
      cancelled = true
    }
  }, [fill.ndc_10, resolved, labelTry])

  const name = fillDrugName(fill)
  const info = detail.phase === 'done' ? detail.value : null
  const links = info?.links ?? { dailymed: null, ndc_directory: null }
  const labelSetId = label.phase === 'done' ? label.value.spl_set_id : null
  // openFDA only holds current listings; a discontinued (RxNav) NDC would 404 there.
  const ndcDirectory = fill.ndc_source === 'rxnav' ? null : links.ndc_directory
  const dailymed = links.dailymed ?? (labelSetId ? `https://dailymed.nlm.nih.gov/dailymed/lookup.cfm?setid=${encodeURIComponent(labelSetId)}` : null)
  const drug = fill.drug
  const ingredients = info?.ingredients.length
    ? info.ingredients.map((ingredient) => [ingredient.name, ingredient.strength].filter(Boolean).join(' ')).join('; ')
    : drug ? drug.substance_names.map((substance, index) => [substance, drug.strengths[index]].filter(Boolean).join(' ')).join('; ') : ''

  return (
    <Dialog open onOpenChange={(open) => { if (!open) onClose() }}>
      <DialogContent className="top-0 right-0 left-auto h-dvh max-h-none w-full max-w-full translate-x-0 translate-y-0 content-start rounded-none sm:max-w-xl" aria-label={`${name} details`}>
        <DialogHeader>
          <DialogTitle className="pr-6">{name}</DialogTitle>
          <DialogDescription>{fill.ndc_10 ? `NDC ${fill.ndc_10}` : 'No FDA-listed NDC on this fill'}</DialogDescription>
        </DialogHeader>

        {!resolved ? (
          <p className="rounded-md border border-dashed border-border px-3 py-4 text-sm text-muted-foreground">
            No FDA listing is stored for this fill, so there are no drug details to show. Edit the fill to search the FDA listings by name.
          </p>
        ) : (
          <>
            <section aria-label="Drug" className="space-y-3">
              <h3 className="text-sm font-semibold text-card-foreground">Drug</h3>
              {detail.phase === 'loading' && <p className="text-sm text-muted-foreground">Loading drug details…</p>}
              {detail.phase === 'error' && <p role="alert" className="text-sm text-destructive">{detail.message}</p>}
              <Facts rows={[
                ['Ingredients', ingredients],
                ['Brand', drug?.brand_name ?? null],
                ['Dosage form', info?.dosage_form ?? drug?.dosage_form ?? null],
                ['Route', info?.route ?? drug?.route ?? null],
                ['Pharmacologic class', info?.pharm_class.join('; ') || null],
                ['Application number', info?.application_number ?? null],
                ['Marketing category', info?.marketing_category ?? null],
                ['DEA schedule', info?.dea_schedule ?? null],
                ['Imprint', info?.imprint ?? null],
                ['Color', info?.color ?? null],
                ['Shape', info?.shape ?? null],
                ['Manufacturer', info?.manufacturer ?? drug?.labeler_name ?? null],
                ['Package', info?.package_description ?? drug?.package_description ?? null],
                ['Listing source', fill.ndc_source === 'rxnav' ? 'NLM RxNav NDC history (no longer in the FDA directory)' : fill.ndc_source === 'openfda' ? 'FDA NDC Directory (openFDA)' : null],
              ]} />
              {(dailymed || ndcDirectory) && (
                <p className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                  {dailymed && <ExternalLinkItem href={dailymed}>DailyMed</ExternalLinkItem>}
                  {ndcDirectory && <ExternalLinkItem href={ndcDirectory}>FDA NDC listing</ExternalLinkItem>}
                </p>
              )}
            </section>

            <section aria-label="Prescribing information" className="space-y-3">
              <h3 className="text-sm font-semibold text-card-foreground">Prescribing information</h3>
              <LabelSections state={label} onRetry={() => { setLabel({ phase: 'loading' }); setLabelTry((n) => n + 1) }} />
            </section>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
