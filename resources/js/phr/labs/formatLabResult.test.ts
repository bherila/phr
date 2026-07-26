import {
  formatLabReferenceBound,
  formatLabReferenceRange,
  formatLabValue,
  formatLabValueNumber,
} from '@/phr/labs/formatLabResult'

describe('lab result formatting', () => {
  it('trims trailing zeroes from numeric lab values without rounding meaningful precision', () => {
    expect(formatLabValueNumber('3.5000000000')).toBe('3.5')
    expect(formatLabValueNumber('1.2460000000')).toBe('1.246')
    expect(formatLabValueNumber('0.0060000000')).toBe('0.006')
    expect(formatLabValueNumber('115.0000000000')).toBe('115')
  })

  it('does not treat large measured lab values as infinity placeholders', () => {
    expect(formatLabValue({ value: null, value_numeric: '100000.0000000000' })).toBe('100000')
    expect(formatLabValue({ value: null, value_numeric: '9999999.0000000000' })).toBe('9999999')
  })

  it('treats very large reference-bound sentinels as infinity placeholders', () => {
    expect(formatLabReferenceBound('99999')).toBe('∞')
    expect(formatLabReferenceBound('9999999.0000000000')).toBe('∞')
    expect(formatLabReferenceBound('-99999.0000000000')).toBe('-∞')
  })

  it('formats reference ranges with concise numbers and infinity placeholders', () => {
    expect(formatLabReferenceRange({
      range_min: '59.0000000000',
      range_max: '9999999.0000000000',
      range_unit: 'mL/min/1.73m2',
      reference_range_text: null,
    })).toBe('59–∞ mL/min/1.73m2')
  })

  it('preserves negative lower-bound sentinels in reference ranges', () => {
    expect(formatLabReferenceRange({
      range_min: '-99999.0000000000',
      range_max: '10.0000000000',
      range_unit: 'mg/L',
      reference_range_text: null,
    })).toBe('-∞–10 mg/L')
  })

  it('preserves meaningful reference range precision beyond two decimals', () => {
    expect(formatLabReferenceRange({
      range_min: '0.0060000000',
      range_max: '0.0100000000',
      range_unit: 'mg/L',
      reference_range_text: null,
    })).toBe('0.006–0.01 mg/L')
  })

  it('falls back to formatted numeric values when display values are absent', () => {
    expect(formatLabValue({ value: null, value_numeric: '0.8800000000' })).toBe('0.88')
    expect(formatLabValue({ value: 'Positive', value_numeric: null })).toBe('Positive')
  })
})
