import { displayUnits } from './measureUnits'

describe('displayUnits', () => {
  it.each([
    ['50 ug/1', '50 µg/1'],
    ['50 mcg', '50 µg'],
    ['50mcg', '50µg'],
    ['50 MCG', '50 µg'],
    ['250 ug/1; 50 ug/1', '250 µg/1; 50 µg/1'],
    ['1-2 mcg/mL', '1-2 µg/mL'],
    ['ug', 'µg'],
    ['mcg', 'µg'],
    ['ug/dL', 'µg/dL'],
    ['5.0–10 ug/dL', '5.0–10 µg/dL'],
    ['levothyroxine 50 mcg tablet', 'levothyroxine 50 µg tablet'],
    ['50 μg', '50 µg'],
    ['50 µg', '50 µg'],
  ])('writes %s as %s', (text, expected) => {
    expect(displayUnits(text)).toBe(expected)
  })

  it.each(['UG Medical · Dr. Mcgee', 'mug', 'plug/socket', '10 mg/mL', 'Dr. Ug'])('leaves %s alone', (text) => {
    expect(displayUnits(text)).toBe(text)
  })

  it('keeps a missing value missing', () => {
    expect(displayUnits(null)).toBeNull()
    expect(displayUnits(undefined)).toBeUndefined()
    expect(displayUnits('')).toBe('')
  })
})
