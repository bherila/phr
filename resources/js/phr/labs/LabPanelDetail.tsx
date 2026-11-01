import { useCallback, useEffect, useState } from 'react'

import { RECORD_FIT_CLASS, RECORD_NUMERIC_CLASS } from '@/components/record-list/RecordTable'
import { DetailSkeleton } from '@/components/record-list/Skeleton'
import { formatDate } from '@/lib/dateFormat'
import { displayUnits } from '@/lib/measureUnits'
import { formatLabReferenceRange, formatLabValue, isNumericLabValue } from '@/phr/labs/formatLabResult'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { PhrRecordNotFound } from '@/phr/shell/PhrRecordNotFound'
import {
  type PhrLabPanel,
  PhrLabPanelDetailResponseSchema,
  type PhrLabPanelResultRow,
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

export default function LabPanelDetail({ patientId, recordId }: LabPanelDetailProps) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [panel, setPanel] = useState<PhrLabPanel | null>(null)

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    setNotFound(false)

    try {
      const result = await fetchPhrDetail(
        `/api/phr/patients/${patientId}/labs/${recordId}`,
        PhrLabPanelDetailResponseSchema,
      )
      setNotFound(result.notFound)
      setPanel(result.data?.panel ?? null)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }, [patientId, recordId])

  useEffect(() => {
    void load()
  }, [load])

  if (notFound) {
    return <PhrRecordNotFound />
  }

  return (
    <div className="@container space-y-4">
      {error && (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      {busy && !panel && <DetailSkeleton label="Loading lab panel" />}

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
                <div className="flex items-baseline justify-between gap-3">
                  <span className="min-w-0 font-medium text-foreground">{row.analyte ?? '—'}</span>
                  <span className={`shrink-0 whitespace-nowrap ${isNumericLabValue(row) ? 'tabular-nums' : ''} ${flagClass(row.abnormal_flag)}`}>
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
                    <td className={`px-3 py-2 ${RECORD_FIT_CLASS} ${isNumericLabValue(row) ? RECORD_NUMERIC_CLASS : ''} ${flagClass(row.abnormal_flag)}`}>
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
