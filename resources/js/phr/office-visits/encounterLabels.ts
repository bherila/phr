import type { PhrOfficeVisit } from '@/phr/types'

/** HL7 v3 ActCode encounter classes (PhrEncounterVocabulary::CLASSES). */
const CLASS_LABELS: Record<string, string> = {
  AMB: 'Ambulatory',
  EMER: 'Emergency',
  IMP: 'Inpatient',
  OBSENC: 'Observation',
  SS: 'Short stay',
  PRENC: 'Pre-admission',
  ACUTE: 'Inpatient acute',
  NONAC: 'Inpatient non-acute',
  VR: 'Virtual',
  HH: 'Home health',
  FLD: 'Field',
}

/** FHIR Encounter.status. */
const STATE_LABELS: Record<string, string> = {
  planned: 'Planned',
  arrived: 'Arrived',
  triaged: 'Triaged',
  'in-progress': 'In progress',
  onleave: 'On leave',
  finished: 'Finished',
  cancelled: 'Cancelled',
  'entered-in-error': 'Entered in error',
  unknown: 'Unknown',
}

/** FHIR admit-source. */
const ADMIT_SOURCE_LABELS: Record<string, string> = {
  'hosp-trans': 'Transferred from another hospital',
  emd: 'Emergency department',
  outp: 'Outpatient department',
  born: 'Born in hospital',
  gp: 'General practitioner referral',
  mp: 'Physician referral',
  nursing: 'Nursing home',
  psych: 'Psychiatric hospital',
  rehab: 'Rehabilitation facility',
  other: 'Other',
}

/** FHIR discharge-disposition. */
const DISCHARGE_LABELS: Record<string, string> = {
  home: 'Home',
  'alt-home': 'Alternative home',
  'other-hcf': 'Other healthcare facility',
  hosp: 'Hospice',
  long: 'Long-term care',
  aadvice: 'Left against advice',
  exp: 'Expired',
  psy: 'Psychiatric hospital',
  rehab: 'Rehabilitation',
  snf: 'Skilled nursing facility',
  oth: 'Other',
}

function label(labels: Record<string, string>, code: string | null | undefined): string | null {
  return code ? (labels[code] ?? code) : null
}

/** The source's words when it gave them, else the code's label. */
function worded(labels: Record<string, string>, code: string | null | undefined, text: string | null | undefined): string | null {
  return text && text.trim().length > 0 ? text : label(labels, code)
}

export interface EncounterFact {
  label: string
  value: string
}

/** The coded encounter facts a visit states, in reading order; facts it does not state are left out. */
export function encounterFacts(visit: PhrOfficeVisit): EncounterFact[] {
  const facts: Array<[string, string | null]> = [
    ['Encounter class', label(CLASS_LABELS, visit.encounter_class)],
    ['Encounter status', label(STATE_LABELS, visit.encounter_state)],
    ['Location', visit.location ?? null],
    ['Admitted from', worded(ADMIT_SOURCE_LABELS, visit.admit_source, visit.admit_source_text)],
    ['Discharged to', worded(DISCHARGE_LABELS, visit.discharge_disposition, visit.discharge_disposition_text)],
  ]

  return facts.filter((fact): fact is [string, string] => fact[1] !== null && fact[1].trim().length > 0)
    .map(([factLabel, value]) => ({ label: factLabel, value }))
}

export function encounterClassLabel(code: string | null | undefined): string | null {
  return label(CLASS_LABELS, code)
}
