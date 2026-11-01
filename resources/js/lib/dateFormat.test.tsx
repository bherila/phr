import { formatDate, formatDateTime, formatInstant, formatInstantDate } from './dateFormat'
import { resetDisplayPreferences } from './displayPreferences'

function useFormat(format: string | null): void {
  document.getElementById('app-initial-data')?.remove()
  if (format !== null) {
    const initial = document.createElement('script')
    initial.id = 'app-initial-data'
    initial.type = 'application/json'
    initial.textContent = JSON.stringify({ currentUser: { name: 'Example', display_preferences: { date_format: format } } })
    document.body.append(initial)
  }
  resetDisplayPreferences()
}

afterEach(() => useFormat(null))

describe('dates in a person\'s format', () => {
  it('defaults to YYYY-MM-DD, also for an unknown value', () => {
    useFormat(null)
    expect(formatDate('2026-11-01')).toBe('2026-11-01')
    useFormat('nonsense')
    expect(formatDate('2026-11-01')).toBe('2026-11-01')
  })

  it.each([
    ['MM/DD/YYYY', '11/01/2026'],
    ['DD/MM/YYYY', '01/11/2026'],
    ['MMM D, YYYY', 'Nov 1, 2026'],
  ])('writes %s', (format, expected) => {
    useFormat(format)
    expect(formatDate('2026-11-01')).toBe(expected)
  })

  it('never moves a stored date to another day, whatever the timestamp or timezone says', () => {
    useFormat('MM/DD/YYYY')
    expect(formatDate('2026-01-01T00:00:00.000000Z')).toBe('01/01/2026')
    expect(formatDate('2026-01-01T23:59:59-11:00')).toBe('01/01/2026')
    expect(new Date('2026-01-01T00:00:00.000000Z').toISOString().slice(0, 10)).toBe('2026-01-01')
  })

  it('leaves missing values empty and other text as it is', () => {
    expect(formatDate(null)).toBe('')
    expect(formatDate('')).toBe('')
    expect(formatDate('Spring 2019')).toBe('Spring 2019')
    expect(formatInstant('not a date')).toBe('')
  })

  it('keeps a stored time as written', () => {
    useFormat('DD/MM/YYYY')
    expect(formatDateTime('2026-03-04T15:30:00')).toBe('04/03/2026 15:30')
    expect(formatDateTime('2026-03-04 15:30:00')).toBe('04/03/2026 15:30')
    expect(formatDateTime('2026-03-04')).toBe('04/03/2026')
  })

  it('shows a moment in time on the viewer\'s own day', () => {
    useFormat('YYYY-MM-DD')
    const moment = new Date(2026, 2, 4, 23, 30)
    expect(formatInstantDate(moment.toISOString())).toBe('2026-03-04')
    expect(formatInstant(moment.toISOString())).toMatch(/^2026-03-04 /)
  })
})
