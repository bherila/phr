const MICROGRAM = 'µg'

/** ug, mcg or the Greek-mu spelling, after a number ("50 mcg", "1-2ug/mL"). */
const AFTER_A_NUMBER = /(?<=\d\s?)(?:mcg|ug|μg)(?![A-Za-z])/gi
/** The same as a whole unit, or the top of a ratio ("mcg", "ug/dL"). */
const AS_A_UNIT = /^\s*(?:mcg|ug|μg)\s*$|(?<![A-Za-z])(?:mcg|ug|μg)(?=\/)/gi

/**
 * Micrograms as this app writes them for a person to read, in any text that may hold a measure.
 * Display only: stored values, API responses and exports keep what they have, because `ug` is the
 * code C-CDA and FHIR exchange. A word that merely looks like it ("UG Medical") is left alone.
 */
export function displayUnits(text: string): string
export function displayUnits(text: string | null | undefined): string | null | undefined
export function displayUnits(text: string | null | undefined): string | null | undefined {
  if (!text) return text
  return text.replace(AFTER_A_NUMBER, MICROGRAM).replace(AS_A_UNIT, MICROGRAM)
}
