import { useEffect, useState } from 'react'

import { PhrNotFoundColumn } from '@/phr/miller'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { type PhrVital, PhrVitalReadingDetailResponseSchema } from '@/phr/types'

interface VitalsReadingDetailProps {
  patientId: number
  recordId: string
}

export default function VitalsReadingDetail({ patientId, recordId }: VitalsReadingDetailProps) {
  const [vital, setVital] = useState<PhrVital | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [notFound, setNotFound] = useState(false)

  useEffect(() => {
    let active = true

    const load = async () => {
      setLoading(true)
      setError(null)
      setNotFound(false)

      try {
        const result = await fetchPhrDetail(
          `/api/phr/patients/${patientId}/vitals/${recordId}`,
          PhrVitalReadingDetailResponseSchema,
        )
        if (!active) return
        setVital(result.data?.vital ?? null)
        setNotFound(result.notFound)
      } catch (caught) {
        if (!active) return
        setVital(null)
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

  if (loading) {
    return <p className="text-sm text-muted-foreground">Loading vital reading…</p>
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

  if (vital === null) {
    return <PhrNotFoundColumn />
  }

  const measurements: Array<{ label: string; value: string }> = [
    { label: 'Name', value: vital.vital_name ?? '—' },
    { label: 'Value', value: vital.vital_value ?? '—' },
    { label: 'Primary numeric', value: vital.value_numeric ?? '—' },
    { label: 'Secondary numeric', value: vital.value_numeric_secondary ?? '—' },
    { label: 'Unit', value: vital.unit ?? '—' },
    { label: 'Secondary unit', value: vital.secondary_unit ?? '—' },
    { label: 'Body site', value: vital.body_site ?? '—' },
  ]

  return (
    <div className="space-y-5">
      <div>
        <h2 className="text-lg font-semibold text-foreground">{vital.vital_name ?? 'Vital reading'}</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Captured {vital.observed_at ?? vital.vital_date ?? 'unknown date'}
          {vital.source ? ` · ${vital.source}` : ''}
        </p>
      </div>

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
