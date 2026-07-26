import { addDays, format } from 'date-fns'
import { useEffect, useMemo, useState } from 'react'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'

import { Button } from '@/components/ui/button'
import { PhrNotFoundColumn } from '@/phr/miller'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { type PhrVitalTrendPoint, PhrVitalTrendResponseSchema } from '@/phr/types'

interface VitalsTrendProps {
  patientId: number
  recordId: string
}

function parseRecordedAt(value: string | null | undefined): Date | null {
  if (!value) return null
  const normalized = value.includes('T') ? value : value.replace(' ', 'T')
  const parsed = new Date(normalized)
  return Number.isFinite(parsed.getTime()) ? parsed : null
}

export default function VitalsTrend({ patientId, recordId }: VitalsTrendProps) {
  const [range, setRange] = useState<'30d' | '90d' | '1y' | 'all'>('90d')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [metricLabel, setMetricLabel] = useState<string>('')
  const [unit, setUnit] = useState<string | null>(null)
  const [points, setPoints] = useState<PhrVitalTrendPoint[]>([])

  useEffect(() => {
    let active = true

    const load = async () => {
      setLoading(true)
      setError(null)
      setNotFound(false)
      try {
        const result = await fetchPhrDetail(
          `/api/phr/patients/${patientId}/vitals/trend/${recordId}`,
          PhrVitalTrendResponseSchema,
        )
        if (!active) return
        if (result.notFound || !result.data) {
          setNotFound(true)
          setPoints([])
          return
        }
        setMetricLabel(result.data.metric_label)
        setUnit(result.data.unit)
        setPoints(result.data.points)
      } catch (caught) {
        if (!active) return
        setPoints([])
        setError(errorMessage(caught))
      } finally {
        if (active) setLoading(false)
      }
    }

    void load()

    return () => {
      active = false
    }
  }, [patientId, recordId])

  const filteredPoints = useMemo(() => {
    if (range === 'all') return points
    const now = new Date()
    const offsets: Record<'30d' | '90d' | '1y', number> = {
      '30d': -30,
      '90d': -90,
      '1y': -365,
    }
    const boundary = addDays(now, offsets[range])
    return points.filter((point) => {
      const parsed = parseRecordedAt(point.recorded_at)
      return parsed !== null && parsed >= boundary
    })
  }, [points, range])

  const chartRows = useMemo(
    () => filteredPoints.map((point) => {
      const parsed = parseRecordedAt(point.recorded_at)
      return {
        ...point,
        label: parsed ? format(parsed, 'MMM d, yyyy') : 'Unknown',
      }
    }),
    [filteredPoints],
  )

  if (loading) {
    return <p className="text-sm text-muted-foreground">Loading trend…</p>
  }

  if (notFound) {
    return <PhrNotFoundColumn />
  }

  if (error) {
    return (
      <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
        {error}
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="text-lg font-semibold text-foreground">{metricLabel || 'Vitals Trend'}</h2>
          <p className="text-sm text-muted-foreground">
            Metric key: {recordId}
            {unit ? ` · ${unit}` : ''}
          </p>
        </div>
        <div className="flex items-center gap-1">
          {(['30d', '90d', '1y', 'all'] as const).map((option) => (
            <Button
              key={option}
              type="button"
              size="sm"
              variant={range === option ? 'default' : 'outline'}
              onClick={() => setRange(option)}
            >
              {option}
            </Button>
          ))}
        </div>
      </div>

      {chartRows.length === 0 ? (
        <div className="rounded-lg border border-dashed border-border py-10 text-center text-sm text-muted-foreground">
          No trend points in this range.
        </div>
      ) : (
        <div className="h-[360px] w-full rounded-lg border border-border p-2 sm:p-4">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={chartRows} margin={{ top: 12, right: 20, left: 8, bottom: 8 }}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="label" minTickGap={20} />
              <YAxis />
              <Tooltip formatter={(value) => `${value}${unit ? ` ${unit}` : ''}`} />
              <Line type="monotone" dataKey="value" stroke="hsl(var(--primary))" strokeWidth={2} dot />
            </LineChart>
          </ResponsiveContainer>
        </div>
      )}
    </div>
  )
}
