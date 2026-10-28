import { ehiFailureText, pendingProposalCount } from '../ehiApi'
import { makeEhiPackage } from './ehiFixtures'

describe('ehiApi helpers', () => {
  it('turns a fixed reason code into a sentence and passes other failures through', () => {
    expect(ehiFailureText('multiple_patients')).toBe('This export holds records for more than one patient, so it was not imported.')
    expect(ehiFailureText('Server is busy, try later.')).toBe('Server is busy, try later.')
    expect(ehiFailureText(new Error('Network down'))).toBe('Network down')
    expect(ehiFailureText(undefined)).toBe('Request failed.')
  })

  it('counts pending proposals over every mapping and kind', () => {
    const pkg = makeEhiPackage({ proposals: { by_status: {}, pending: { immunization: { create: 4, retain: 2 }, document: { create: 3 } }, pending_unreviewable: 1 } })
    expect(pendingProposalCount(pkg)).toBe(9)
  })
})
