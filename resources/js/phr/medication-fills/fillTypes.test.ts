import { dollarsToCents, formatCents, formatFillDate, formatQuantity } from './fillTypes'

describe('fill formatting', () => {
  it('formats dates as YYYY-MM-DD without timezone drift', () => {
    expect(formatFillDate('2026-10-28')).toBe('2026-10-28')
    expect(formatFillDate('2026-01-01T00:00:00.000000Z')).toBe('2026-01-01')
    expect(formatFillDate(null)).toBe('—')
  })

  it('formats cents as $X.XX and null as a dash', () => {
    expect(formatCents(1000)).toBe('$10.00')
    expect(formatCents(18999)).toBe('$189.99')
    expect(formatCents(5)).toBe('$0.05')
    expect(formatCents(0)).toBe('$0.00')
    expect(formatCents(null)).toBe('—')
  })

  it('trims trailing zeros from decimal quantities', () => {
    expect(formatQuantity('90.000', 'tablet')).toBe('90 tablet')
    expect(formatQuantity('2.50', 'mL')).toBe('2.5 mL')
    expect(formatQuantity('90', null)).toBe('90')
    expect(formatQuantity(null, 'tablet')).toBe('—')
  })

  it('converts typed dollars to integer cents', () => {
    expect(dollarsToCents('10')).toBe(1000)
    expect(dollarsToCents('18.99')).toBe(1899)
    expect(dollarsToCents('$1,234.50')).toBe(123450)
    expect(dollarsToCents('0.07')).toBe(7)
    expect(dollarsToCents('')).toBeNull()
    expect(dollarsToCents('abc')).toBeNaN()
    expect(dollarsToCents('1.234')).toBeNaN()
  })
})
