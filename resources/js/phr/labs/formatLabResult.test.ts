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

  describe('one representation: numeric bounds and their unit, text only without bounds', () => {
    const range = (overrides: Partial<Parameters<typeof formatLabReferenceRange>[0]>) => formatLabReferenceRange({
      range_min: null,
      range_max: null,
      range_unit: null,
      reference_range_text: null,
      ...overrides,
    })

    it('renders both bounds with the unit', () => {
      expect(range({ range_min: '13.6000000000', range_max: '17.5000000000', range_unit: 'g/dL' })).toBe('13.6–17.5 g/dL')
    })

    it('renders a lower bound alone with the unit', () => {
      expect(range({ range_min: '40.0000000000', range_unit: 'mg/dL' })).toBe('>40 mg/dL')
    })

    it('renders an upper bound alone with the unit', () => {
      expect(range({ range_max: '200.0000000000', range_unit: 'mg/dL' })).toBe('<200 mg/dL')
    })

    it('keeps an inclusive comparator the source text states for a single bound', () => {
      expect(range({ range_min: '60', range_unit: 'mL/min', reference_range_text: '>=60' })).toBe('≥60 mL/min')
      expect(range({ range_max: '5', range_unit: 'mg/L', reference_range_text: '≤5 mg/L' })).toBe('≤5 mg/L')
    })

    it('uses the inclusiveness a single bound states', () => {
      expect(range({ range_max: '5', range_max_inclusive: true, range_unit: 'mg/dL' })).toBe('≤5 mg/dL')
      expect(range({ range_max: '5', range_max_inclusive: false, range_unit: 'mg/dL' })).toBe('<5 mg/dL')
      expect(range({ range_min: '60', range_min_inclusive: true, range_unit: 'mL/min' })).toBe('≥60 mL/min')
      expect(range({ range_min: '60', range_min_inclusive: false, range_unit: 'mL/min' })).toBe('>60 mL/min')
    })

    it('prefers the inclusiveness a bound states over the comparator in its text', () => {
      expect(range({ range_min: '60', range_min_inclusive: false, reference_range_text: '>=60' })).toBe('>60')
      expect(range({ range_max: '200', range_max_inclusive: true, reference_range_text: '<200 mg/dL' })).toBe('≤200')
    })

    it('reads the text as before when a bound does not say', () => {
      expect(range({ range_max: '200', range_max_inclusive: null, range_unit: 'mg/dL' })).toBe('<200 mg/dL')
      expect(range({ range_min: '60', range_min_inclusive: null, reference_range_text: '>=60' })).toBe('≥60')
    })

    it('renders two bounds as a span whatever their inclusiveness', () => {
      expect(range({ range_min: '1', range_min_inclusive: false, range_max: '9', range_max_inclusive: true, range_unit: 'g/dL' })).toBe('1–9 g/dL')
    })

    it('ignores the inclusiveness of the absent side', () => {
      expect(range({ range_max: '5', range_min_inclusive: true, range_max_inclusive: false })).toBe('<5')
    })

    it('uses the bounds over text that repeats them', () => {
      expect(range({ range_max: '165', range_unit: 'IU/mL', reference_range_text: '<165 IU/mL' })).toBe('<165 IU/mL')
    })

    it('renders text as written when there are no bounds', () => {
      expect(range({ reference_range_text: 'Not Detected' })).toBe('Not Detected')
    })

    it('does not append a unit to text without bounds', () => {
      expect(range({ reference_range_text: 'Negative', range_unit: 'mg/dL' })).toBe('Negative')
    })

    it('renders nothing when there is neither', () => {
      expect(range({ range_unit: 'mg/dL' })).toBeNull()
    })
  })

  it('falls back to formatted numeric values when display values are absent', () => {
    expect(formatLabValue({ value: null, value_numeric: '0.8800000000' })).toBe('0.88')
    expect(formatLabValue({ value: 'Positive', value_numeric: null })).toBe('Positive')
  })
})
