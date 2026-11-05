import { describeEhiError, ehiFailureText, EhiPreviewRunSchema, pendingProposalCount } from '../ehiApi'
import { makeEhiPackage } from './ehiFixtures'

describe('ehiApi helpers', () => {
  it('turns a fixed reason code into a sentence and passes other failures through', () => {
    expect(ehiFailureText('multiple_patients')).toBe('This holds records for more than one person (different birth dates), so nothing was imported.')
    expect(ehiFailureText('Server is busy, try later.')).toBe('Server is busy, try later.')
    expect(ehiFailureText(new Error('Network down'))).toBe('Network down')
    expect(ehiFailureText(undefined)).toBe('Request failed.')
  })

  it('distinguishes time restart guidance from hard capacity and legacy failures', () => {
    expect(describeEhiError('resource_limit', { resource_limit: 'time' })).toMatch(/restart preparation.*same limit/)
    expect(describeEhiError('resource_limit', { resource_limit: 'capacity' })).toMatch(/unchanged records and limits.*same limit.*Contact support/)
    expect(describeEhiError('resource_limit')).toMatch(/Contact support/)
    expect(describeEhiError('resource_limit')).not.toMatch(/continue|Try again/)
    expect(describeEhiError('not_bound', { resource_limit: 'capacity' })).toBe(describeEhiError('not_bound'))
  })

  it('accepts older preview responses and safely ignores unknown failure detail kinds', () => {
    const old = { id: 1, package_id: 9, status: 'failed', failure_reason: 'resource_limit', counts: null, statuses: null,
      findings: null, preview_digest: null, replayed: null, requested_at: null, started_at: null, completed_at: null }
    expect(EhiPreviewRunSchema.parse(old).failure_details).toBeUndefined()
    expect(EhiPreviewRunSchema.parse({ ...old, failure_details: { resource_limit: 'PRIVATE_unknown', raw: 'PRIVATE' } }).failure_details).toBeNull()
  })

  it('counts pending proposals over every mapping and kind', () => {
    const pkg = makeEhiPackage({ proposals: { by_status: {}, pending: { immunization: { create: 4, retain: 2 }, document: { create: 3 } }, pending_unreviewable: 1, previously_rejected: 0 } })
    expect(pendingProposalCount(pkg)).toBe(9)
  })
})
