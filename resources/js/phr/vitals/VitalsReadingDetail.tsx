import { useCallback, useEffect, useRef, useState } from 'react'
import { z } from 'zod'

import { Button } from '@/components/ui/button'
import { formatDateTime } from '@/lib/dateFormat'
import { displayUnits } from '@/lib/measureUnits'
import { recordChanged, useRecordChanges } from '@/phr/clinical/recordChanges'
import { RecordReviewBar } from '@/phr/clinical/RecordDetailParts'
import type { PhrReviewDecision } from '@/phr/clinical/review'
import { recordReviewDecision } from '@/phr/clinical/reviewDecision'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import type { PhrDetailPageProps } from '@/phr/shell/phrPages'
import { PhrRecordNotFound } from '@/phr/shell/PhrRecordNotFound'
import { type PhrVital, PhrVitalReadingDetailResponseSchema } from '@/phr/types'
import { metricCandidates } from '@/phr/vitals/metrics'

const DetailResponseSchema = PhrVitalReadingDetailResponseSchema.extend({ can_manage: z.boolean().default(false) })

export default function VitalsReadingDetail({ patientId, recordId, onDrill }: PhrDetailPageProps) {
  const endpoint = `/api/phr/patients/${patientId}/vitals/${recordId}`
  const [vital, setVital] = useState<PhrVital | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [canManage, setCanManage] = useState(false)
  const [reviewing, setReviewing] = useState(false)
  const generation = useRef(0)
  const invalidateRequests = useCallback(() => { generation.current++ }, [])

  const load = useCallback(async (): Promise<void> => {
    const current = ++generation.current
    setLoading(true)
    setError(null)
    setNotFound(false)
    setReviewing(false)
    try {
      const result = await fetchPhrDetail(endpoint, DetailResponseSchema)
      if (current !== generation.current) return
      setVital(result.data?.vital ?? null)
      setCanManage(result.data?.can_manage ?? false)
      setNotFound(result.notFound)
    } catch (caught) {
      if (current !== generation.current) return
      setVital(null)
      setCanManage(false)
      setError(errorMessage(caught))
    } finally {
      if (current === generation.current) setLoading(false)
    }
  }, [endpoint])

  useEffect(() => {
    setVital(null)
    setCanManage(false)
    void load()
    return invalidateRequests
  }, [load, invalidateRequests])
  useRecordChanges('vitals', patientId, 'list', (id) => { if (String(id) === recordId) void load() })

  async function review(decision: PhrReviewDecision): Promise<void> {
    if (!vital || !canManage || reviewing) return
    const current = generation.current
    const id = vital.id
    setReviewing(true)
    setError(null)
    try {
      const raw = await recordReviewDecision(endpoint, decision)
      if (current !== generation.current) return
      setVital(PhrVitalReadingDetailResponseSchema.parse(raw).vital)
      recordChanged({ resource: 'vitals', patientId, recordId: id, origin: 'detail' })
    } catch (caught) {
      if (current === generation.current) setError(errorMessage(caught))
    } finally {
      if (current === generation.current) setReviewing(false)
    }
  }

  if (loading) {
    return <p className="text-sm text-muted-foreground">Loading vital reading…</p>
  }

  if (notFound) {
    return <PhrRecordNotFound />
  }

  if (error && !vital) {
    return (
      <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
        {error}
      </div>
    )
  }

  if (vital === null) {
    return <PhrRecordNotFound />
  }

  const measurements: Array<{ label: string; value: string }> = [
    { label: 'Name', value: vital.vital_name ?? '—' },
    { label: 'Value', value: vital.vital_value ?? '—' },
    { label: 'Primary numeric', value: vital.value_numeric ?? '—' },
    { label: 'Secondary numeric', value: vital.value_numeric_secondary ?? '—' },
    { label: 'Unit', value: displayUnits(vital.unit) ?? '—' },
    { label: 'Secondary unit', value: vital.secondary_unit ?? '—' },
    { label: 'Body site', value: vital.body_site ?? '—' },
  ]

  return (
    <div className="flex min-h-full flex-col gap-5 wrap-anywhere">
      {error && <div role="alert" className="rounded-md border border-destructive/40 px-3 py-2 text-sm text-destructive">{error}</div>}
      {canManage ? <RecordReviewBar status={vital.review_status} label={vital.vital_name ?? 'vital reading'} busy={reviewing} onReview={(decision) => void review(decision)} /> : reviewStatusBadge(vital.review_status)}
      <div>
        <h2 className="text-lg font-semibold text-foreground">{vital.vital_name ?? 'Vital reading'}</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Captured {formatDateTime(vital.observed_at ?? vital.vital_date) || 'unknown date'}
          {vital.source ? ` · ${vital.source}` : ''}
        </p>
      </div>

      {onDrill && metricCandidates(vital).length > 0 && (
        <div className="flex flex-wrap gap-2" aria-label="Vital trends">
          {metricCandidates(vital).map((candidate) => (
            <Button key={candidate.key} type="button" variant="outline" size="sm" onClick={() => onDrill({ id: 'vitals-trend', instance: candidate.key })}>{candidate.label} trend</Button>
          ))}
        </div>
      )}

      <div className="grid gap-2 sm:grid-cols-2">
        {measurements.map((item) => (
          <div key={item.label} className="rounded-md border border-border px-3 py-2">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">{item.label}</p>
            <p className="text-sm font-medium text-foreground">{item.value}</p>
          </div>
        ))}
      </div>

      <div className="rounded-md border border-border px-3 py-2">
        <p className="text-xs uppercase tracking-wide text-muted-foreground">Notes</p>
        <p className="whitespace-pre-wrap text-sm text-foreground">{vital.notes ?? '—'}</p>
      </div>
    </div>
  )
}
