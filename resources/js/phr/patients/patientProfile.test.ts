import type { PhrPatient } from '@/phr/types'

import { ageOn, profileFacts } from './patientProfile'

function patient(overrides: Partial<PhrPatient> = {}): PhrPatient {
  return {
    id: 7, owner_user_id: 1, display_name: 'Sam', relationship: null, birth_date: null, sex_at_birth: null, notes: null,
    archived_at: null, created_at: null, updated_at: null, access_level: 'owner', can_manage: true, can_share: true, access_grants: [],
    ...overrides,
  }
}

describe('ageOn', () => {
  const today = new Date(2026, 10, 26)

  it.each([
    ['2016-04-09', '10 y'],
    ['2016-11-26', '10 y'],
    ['2016-11-27', '9 y'],
    ['2025-06-30', '16 mo'],
    ['2026-11-26', '0 mo'],
    ['2026-12-01', null],
    ['not a date', null],
  ])('%s is %p', (birth, age) => {
    expect(ageOn(birth, today)).toBe(age)
  })
})

describe('profileFacts', () => {
  it('says what identifies the patient, in a stable order', () => {
    expect(profileFacts(patient({ relationship: 'Child', birth_date: '2016-04-09', sex_at_birth: 'M', real_names: ['RIVERA, SAM', 'Samuel Rivera'] }), new Date(2026, 10, 26)))
      .toEqual(['Child', 'born 2016-04-09 (10 y)', 'Male', '2 names on documents'])
  })

  it('leaves out what is not recorded and keeps a value the choices do not name', () => {
    expect(profileFacts(patient({ sex_at_birth: 'X', real_names: ['Sam'] }))).toEqual(['X', '1 name on documents'])
    expect(profileFacts(patient())).toEqual([])
  })
})
