import type { PhrVital } from '@/phr/types'

export interface VitalMetric {
  key: string
  label: string
  unit: string | null
  value: number | null
}

function slug(value: string): string {
  return value.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
}

function numericValue(value: string | null | undefined): number | null {
  if (!value) return null
  const n = Number.parseFloat(value)
  if (Number.isFinite(n)) return n
  const match = value.match(/-?\d+(?:\.\d+)?/)
  return match ? Number.parseFloat(match[0]) : null
}

function isBloodPressure(vital: PhrVital): boolean {
  const name = (vital.vital_name ?? '').toLowerCase()
  return (
    vital.value_numeric_secondary !== null &&
    (name.includes('blood pressure') || name.includes('bp') || (vital.unit ?? '').toLowerCase() === 'mmhg')
  )
}

export function metricCandidates(vital: PhrVital): VitalMetric[] {
  if (isBloodPressure(vital)) {
    return [
      {
        key: 'systolic_bp',
        label: 'Systolic BP',
        unit: vital.unit ?? 'mmHg',
        value: numericValue(vital.value_numeric),
      },
      {
        key: 'diastolic_bp',
        label: 'Diastolic BP',
        unit: vital.secondary_unit ?? vital.unit ?? 'mmHg',
        value: numericValue(vital.value_numeric_secondary),
      },
    ].filter((candidate) => candidate.value !== null)
  }

  const value = numericValue(vital.value_numeric ?? vital.vital_value)
  if (value === null) return []

  const name = slug(vital.vital_name ?? 'vital')
  const unit = slug(vital.unit ?? '')
  const key = unit ? `${name}_${unit}` : name

  return [{
    key,
    label: vital.vital_name ?? 'Vital',
    unit: vital.unit ?? null,
    value,
  }]
}
