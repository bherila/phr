import { useCallback, useEffect, useRef, useState } from 'react'
import { z } from 'zod'

import { RECORD_FIT_CLASS, RECORD_NUMERIC_CLASS } from '@/components/record-list/RecordTable'
import { DetailSkeleton } from '@/components/record-list/Skeleton'
import { formatDate } from '@/lib/dateFormat'
import { displayUnits } from '@/lib/measureUnits'
import { recordChanged, useRecordChanges } from '@/phr/clinical/recordChanges'
import { RecordReviewBar } from '@/phr/clinical/RecordDetailParts'
import type { PhrReviewDecision } from '@/phr/clinical/review'
import { recordReviewDecision } from '@/phr/clinical/reviewDecision'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { formatLabReferenceRange, formatLabValue, isNumericLabValue } from '@/phr/labs/formatLabResult'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { PhrRecordNotFound } from '@/phr/shell/PhrRecordNotFound'
import {
  type PhrLabPanel,
  PhrLabPanelDetailResponseSchema,
  type PhrLabPanelResultRow,
  type PhrLabResult,
  PhrLabResultResponseSchema,
} from '@/phr/types'

interface LabPanelDetailProps {
  patientId: number
  recordId: string
}

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

function trendLabel(trend: PhrLabPanelResultRow['trend']): string {
  return trend === 'up' ? '↑' : trend === 'down' ? '↓' : trend === 'flat' ? '→' : '—'
}

const ReviewResponseSchema = PhrLabResultResponseSchema.extend({ can_manage: z.boolean().default(false) })

export default function LabPanelDetail({ patientId, recordId }: LabPanelDetailProps) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [panel, setPanel] = useState<PhrLabPanel | null>(null)

  const [anchor, setAnchor] = useState<PhrLabResult | null>(null)
  const [canManage, setCanManage] = useState(false)
  const [reviewing, setReviewing] = useState(false)
  const generation = useRef(0)
  const invalidateRequests = useCallback(() => { generation.current++ }, [])
  const endpoint = `/api/phr/patients/${patientId}/lab-results/${recordId}`

  const load = useCallback(async (): Promise<void> => {
    const current = ++generation.current
    setBusy(true)
    setError(null)
    setNotFound(false)
    setReviewing(false)
    try {
      const [result, selected] = await Promise.all([
        fetchPhrDetail(`/api/phr/patients/${patientId}/labs/${recordId}`, PhrLabPanelDetailResponseSchema),
        fetchPhrDetail(endpoint, ReviewResponseSchema),
      ])
      if (current !== generation.current) return
      setNotFound(result.notFound || selected.notFound)
      setPanel(result.data?.panel ?? null)
      setAnchor(selected.data?.lab_result ?? null)
      setCanManage(selected.data?.can_manage ?? false)
    } catch (err) {
      if (current !== generation.current) return
      setError(errorMessage(err))
      setAnchor(null)
      setCanManage(false)
    } finally {
      if (current === generation.current) setBusy(false)
    }
  }, [patientId, recordId, endpoint])

  useEffect(() => {
    setPanel(null)
    setAnchor(null)
    setCanManage(false)
    void load()
    return invalidateRequests
  }, [load, invalidateRequests])
  useRecordChanges('lab-results', patientId, 'list', (id) => {
    if (String(id) === recordId || panel?.rows.some((row) => row.id === id)) void load()
  })

  async function review(decision: PhrReviewDecision): Promise<void> {
    if (!anchor || !canManage || reviewing || busy) return
    const current = generation.current
    const id = anchor.id
    setReviewing(true)
    setError(null)
    try {
      const raw = await recordReviewDecision(endpoint, decision)
      if (current !== generation.current) return
      setAnchor(PhrLabResultResponseSchema.parse(raw).lab_result)
      recordChanged({ resource: 'lab-results', patientId, recordId: id, origin: 'detail' })
    } catch (caught) {
      if (current === generation.current) setError(errorMessage(caught))
    } finally {
      if (current === generation.current) setReviewing(false)
    }
  }

  if (notFound) {
    return <PhrRecordNotFound />
  }

  return (
    <div className="@container flex min-h-full flex-col gap-4 wrap-anywhere">
      {error && (
        <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      {busy && !panel && <DetailSkeleton label="Loading lab panel" />}

      {anchor && (
        <section aria-label="Selected result review" className="contents">
          <p className="text-sm text-muted-foreground">Selected result: {anchor.analyte ?? anchor.test_name ?? 'Lab result'}</p>
          {canManage ? <RecordReviewBar status={anchor.review_status} label={anchor.analyte ?? anchor.test_name ?? 'selected lab result'} busy={reviewing || busy} onReview={(decision) => void review(decision)} /> : reviewStatusBadge(anchor.review_status)}
        </section>
      )}

      {panel && (
        <>
          <div className="space-y-2">
            <h2 className="text-lg font-semibold text-foreground">{panel.panel_name ?? 'Lab Panel'}</h2>
            <div className="grid gap-1 text-sm text-muted-foreground">
              <p>Collected: {formatDate(panel.collection_datetime) || '—'}</p>
              <p>Ordering provider: {panel.ordering_provider ?? '—'}</p>
              <p>Lab/source: {[panel.resulting_lab, panel.source].filter(Boolean).join(' · ') || '—'}</p>
              {panel.source_document_url && (
                <p>
                  <a
                    className="font-medium text-primary underline-offset-2 hover:underline"
                    href={panel.source_document_url}
                    target="_blank"
                    rel="noreferrer"
                  >
                    View source document
                  </a>
                </p>
              )}
            </div>
          </div>

          {/* Narrow panels stack each result on two lines; wider ones get the table. */}
          <ul aria-label="Results" className="divide-y divide-border rounded-lg border border-border text-sm @min-[520px]:hidden" data-layout="stacked">
            {panel.rows.map((row) => (
              <li key={row.id} className="grid gap-0.5 px-3 py-2">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                  <span className="min-w-0 font-medium text-foreground">{row.analyte ?? '—'}</span>
                  <span data-lab-value="" className={`min-w-0 wrap-anywhere ${isNumericLabValue(row) ? 'tabular-nums' : ''} ${flagClass(row.abnormal_flag)}`}>
                    {formatLabValue(row) ?? '—'}
                    {row.unit && <span className="ml-1 text-xs text-muted-foreground">{displayUnits(row.unit)}</span>}
                  </span>
                </div>
                <div className="flex items-baseline justify-between gap-3 text-xs text-muted-foreground">
                  <span>{formatLabReferenceRange(row) ?? '—'}</span>
                  <span className="whitespace-nowrap">
                    {row.abnormal_flag && row.abnormal_flag !== 'N' && <span className={`mr-2 font-semibold ${flagClass(row.abnormal_flag)}`}>{row.abnormal_flag}</span>}
                    <span aria-label={`Trend ${trendLabel(row.trend)}`}>{trendLabel(row.trend)}</span>
                  </span>
                </div>
              </li>
            ))}
          </ul>

          <div className="hidden overflow-x-auto rounded-lg border border-border @min-[520px]:block" data-layout="table">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/40">
                  <th className="px-3 py-2 text-left font-medium text-muted-foreground">Analyte</th>
                  <th className={`px-3 py-2 text-left font-medium text-muted-foreground ${RECORD_FIT_CLASS}`}>Value</th>
                  <th className={`px-3 py-2 text-left font-medium text-muted-foreground ${RECORD_FIT_CLASS}`}>Range</th>
                  <th className={`px-3 py-2 text-center font-medium text-muted-foreground ${RECORD_FIT_CLASS}`}>Flag</th>
                  <th className={`px-3 py-2 text-center font-medium text-muted-foreground ${RECORD_FIT_CLASS}`}>Trend</th>
                </tr>
              </thead>
              <tbody>
                {panel.rows.map((row) => (
                  <tr key={row.id} className="border-b border-border last:border-0">
                    <td className="px-3 py-2 font-medium text-foreground">{row.analyte ?? '—'}</td>
                    <td className={`px-3 py-2 ${isNumericLabValue(row) ? RECORD_FIT_CLASS : 'wrap-anywhere'} ${isNumericLabValue(row) ? RECORD_NUMERIC_CLASS : ''} ${flagClass(row.abnormal_flag)}`}>
                      {formatLabValue(row) ?? '—'}
                      {row.unit && <span className="ml-1 text-xs text-muted-foreground">{displayUnits(row.unit)}</span>}
                    </td>
                    <td className={`px-3 py-2 text-muted-foreground ${RECORD_FIT_CLASS}`}>{formatLabReferenceRange(row) ?? '—'}</td>
                    <td className={`px-3 py-2 text-center font-semibold ${RECORD_FIT_CLASS} ${flagClass(row.abnormal_flag)}`}>
                      {row.abnormal_flag && row.abnormal_flag !== 'N' ? row.abnormal_flag : '—'}
                    </td>
                    <td className={`px-3 py-2 text-center text-muted-foreground ${RECORD_FIT_CLASS}`}>{trendLabel(row.trend)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  )
}
