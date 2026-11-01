import { decisionActions, formatSourceDate, proposalView, retainReason, reviewGroupOf } from '../ehiProposalView'
import { makeEhiProposal } from './ehiFixtures'

describe('ehiProposalView', () => {
  it('reads an immunization from its flat fields and a document from its attributes', () => {
    expect(proposalView(makeEhiProposal())).toMatchObject({ domain: 'Immunization', title: 'Synthetic Vaccine A', date: '2024-09-20', detail: 'given' })
    const document = makeEhiProposal({ mapping: 'document', proposed: { attributes: { title: 'Progress note', observed_at: '2025-10-14 09:30:00', document_type: 'clinical_note' } } })
    expect(proposalView(document)).toMatchObject({ domain: 'Document', title: 'Progress note', date: '2025-10-14', detail: 'clinical_note' })
  })

  it('shows a month-precision date without a day', () => {
    expect(formatSourceDate('2004-08')).toBe('Aug 2004')
    expect(formatSourceDate(null)).toBeNull()
  })

  it('lists only the changed fields of an update, without bookkeeping fields', () => {
    const update = makeEhiProposal({
      kind: 'update',
      before: { vaccine_name: 'Synthetic Vaccine A', lot_number: null, external_id: 'IMMUNE:601' },
      proposed: { vaccine_name: 'Synthetic Vaccine A', lot_number: 'FL4471', external_id: 'IMMUNE:602' },
    })
    expect(proposalView(update).changes).toEqual([{ field: 'Lot number', before: 'Not recorded', after: 'FL4471' }])
  })

  it('groups proposals by kind', () => {
    expect(reviewGroupOf(makeEhiProposal({ kind: 'update' }))).toBe('change')
    expect(reviewGroupOf(makeEhiProposal({ kind: 'possible_duplicate' }))).toBe('duplicate')
    expect(reviewGroupOf(makeEhiProposal({ kind: 'corroborate' }))).toBe('evidence')
    expect(reviewGroupOf(makeEhiProposal({ kind: 'retain' }))).toBe('kept')
    expect(reviewGroupOf(makeEhiProposal())).toBe('new')
  })

  it('offers linking only when there is a candidate, and leads a duplicate with it', () => {
    expect(decisionActions(makeEhiProposal()).map((action) => action.decision)).toEqual(['accept', 'retain_evidence', 'reject'])
    const duplicate = makeEhiProposal({ kind: 'possible_duplicate', candidate_ids: [4] })
    const actions = decisionActions(duplicate)
    expect(actions.map((action) => action.decision)).toEqual(['link_existing', 'accept', 'retain_evidence', 'reject'])
    expect(actions[0]).toMatchObject({ primary: true, label: 'Same record, link as evidence' })
    expect(actions[1]!.label).toBe('Different, add as new')
  })

  it('explains why a row was only kept as evidence', () => {
    expect(retainReason(makeEhiProposal({ kind: 'retain', proposed: { retain_reason: 'status_deleted' } }))).toBe('Marked as deleted in the source.')
    expect(retainReason(makeEhiProposal({ kind: 'retain', proposed: { retain_reason: 'orphan_immunization' } }))).toBe('Not linked to this patient in the source.')
  })
})
