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
  /** Whether each bound is itself in range; null or absent when the source did not say. */
  range_min_inclusive?: boolean | null | undefined
  range_max_inclusive?: boolean | null | undefined
  range_unit: string | null
  reference_range_text: string | null
}

const STATED_COMPARATOR = /^\s*(<=|>=|≤|≥|<|>)/

/**
 * The comparator for a range with one bound. A bound whose inclusiveness the
 * source stated uses it. Otherwise a source text that states an inclusive one
 * ("≥60", ">=60") is kept, and the bound reads as exclusive, as labs usually
 * print it ("<200").
 */
function singleBoundComparator(side: 'low' | 'high', inclusive: boolean | null | undefined, text: string | null): string {
  const stated = text === null ? null : (STATED_COMPARATOR.exec(text)?.[1] ?? null)
  if (side === 'low') {
    return (inclusive ?? (stated === '>=' || stated === '≥')) ? '≥' : '>'
  }

  return (inclusive ?? (stated === '<=' || stated === '≤')) ? '≤' : '<'
}

/**
 * A reference range is its numeric bounds and their unit; `reference_range_text`
 * is shown only when there are no bounds, as the source wrote it, with no unit
 * added.
 */
export function formatLabReferenceRange(result: LabRangeResult): string | null {
  const min = formatLabReferenceBound(result.range_min)
  const max = formatLabReferenceBound(result.range_max)
  const unit = result.range_unit ? ` ${result.range_unit}` : ''

  if (min !== null && max !== null) {
    return `${min}–${max}${unit}`
  }
  if (max !== null) {
    return `${singleBoundComparator('high', result.range_max_inclusive, result.reference_range_text)}${max}${unit}`
  }
  if (min !== null) {
    return `${singleBoundComparator('low', result.range_min_inclusive, result.reference_range_text)}${min}${unit}`
  }

  return result.reference_range_text
}

interface LabValueResult {
  value: string | null
  value_numeric: string | null
}

/** Whether the result is a number (right-aligned) rather than a word such as "Not Detected". */
export function isNumericLabValue(result: LabValueResult): boolean {
  return formatLabValueNumber(result.value_numeric) !== null
}

export function formatLabValue(result: LabValueResult): string | null {
  return result.value ?? formatLabValueNumber(result.value_numeric)
}
