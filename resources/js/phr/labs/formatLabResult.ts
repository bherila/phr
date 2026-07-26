const INFINITY_PLACEHOLDER_THRESHOLD = 99999
const DECIMAL_NUMBER_PATTERN = /^([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))$/

function normalizedLabNumber(value: string | number | null | undefined): string | null {
  if (value === null || value === undefined) {
    return null
  }

  const rawValue = String(value).trim()
  if (rawValue === '') {
    return null
  }

  return rawValue
}

function formatDecimalLiteral(rawValue: string): string {
  const match = DECIMAL_NUMBER_PATTERN.exec(rawValue)
  if (match === null) {
    return rawValue
  }

  const sign = match[1] === '-' ? '-' : ''
  const integerDigits = match[2] ?? '0'
  const fractionDigits = (match[3] ?? match[4] ?? '').replace(/0+$/, '')
  const integer = integerDigits.replace(/^0+(?=\d)/, '') || '0'

  if (integer === '0' && fractionDigits === '') {
    return '0'
  }

  return `${sign}${integer}${fractionDigits ? `.${fractionDigits}` : ''}`
}

export function formatLabValueNumber(value: string | number | null | undefined): string | null {
  const rawValue = normalizedLabNumber(value)
  if (rawValue === null) {
    return null
  }

  return formatDecimalLiteral(rawValue)
}

export function formatLabReferenceBound(value: string | number | null | undefined): string | null {
  const rawValue = normalizedLabNumber(value)
  if (rawValue === null) {
    return null
  }

  const parsed = Number(rawValue)
  if (Number.isFinite(parsed) && parsed <= -INFINITY_PLACEHOLDER_THRESHOLD) {
    return '-∞'
  }
  if (Number.isFinite(parsed) && parsed >= INFINITY_PLACEHOLDER_THRESHOLD) {
    return '∞'
  }

  return formatDecimalLiteral(rawValue)
}

interface LabRangeResult {
  range_min: string | null
  range_max: string | null
  range_unit: string | null
  reference_range_text: string | null
}

export function formatLabReferenceRange(result: LabRangeResult): string | null {
  const min = formatLabReferenceBound(result.range_min)
  const max = formatLabReferenceBound(result.range_max)

  if (min !== null && max !== null) {
    return `${min}–${max}${result.range_unit ? ` ${result.range_unit}` : ''}`
  }

  return result.reference_range_text
}

interface LabValueResult {
  value: string | null
  value_numeric: string | null
}

export function formatLabValue(result: LabValueResult): string | null {
  return result.value ?? formatLabValueNumber(result.value_numeric)
}
