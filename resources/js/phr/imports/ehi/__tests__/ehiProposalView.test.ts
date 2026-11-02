import { decisionActions, formatSourceDate, proposalView, retainReason, reviewGroupOf } from '../ehiProposalView'
import { makeEhiProposal } from './ehiFixtures'

describe('ehiProposalView', () => {
  it('reads an immunization from its flat fields and a document from its attributes', () => {
    expect(proposalView(makeEhiProposal())).toMatchObject({ domain: 'Immunization', title: 'Synthetic Vaccine A', date: '2024-09-20', detail: 'given' })
    const document = makeEhiProposal({ mapping: 'document', proposed: { attributes: { title: 'Progress note', observed_at: '2025-10-14 09:30:00', document_type: 'clinical_note' } } })
    expect(proposalView(document)).toMatchObject({ domain: 'Document', title: 'Progress note', date: '2025-10-14', detail: 'clinical_note' })
  })

  it('reads a lab result with its value exactly as the source stored it', () => {
    const attributes = { analyte: 'Synthetic LDL', value: '<50', value_comparator: '<', unit: 'mg/dL', abnormal_flag: 'Low', result_status: 'Final', result_datetime: '2024-03-05 11:40:30', external_id: 'ORDER_RESULTS:1:66000.01:1' }
    const lab = makeEhiProposal({ mapping: 'lab_result', proposed: { attributes } })
    expect(proposalView(lab)).toMatchObject({ domain: 'Lab result', title: 'Synthetic LDL', date: '2024-03-05', detail: '<50 mg/dL · Low · Final', notice: null })

    const stated = makeEhiProposal({ mapping: 'lab_result', proposed: { attributes: { ...attributes, value: '50' } } })
    expect(proposalView(stated).detail).toContain('<50 mg/dL')
  })

  it('lists a lab update from its declared fields, in words, with no provenance bookkeeping', () => {
    const update = makeEhiProposal({
      mapping: 'lab_result',
      kind: 'update',
      before: { value: 'No growth', result_status: 'Final', resulted_at_source: '3/6/2024' },
      proposed: { changed: ['value', 'result_status', 'resulted_at_source'], attributes: { analyte: 'Synthetic culture', value: 'Light growth', result_status: 'Corrected', resulted_at_source: '3/7/2024' } },
    })
    expect(proposalView(update).changes).toEqual([
      { field: 'Value', before: 'No growth', after: 'Light growth' },
      { field: 'Status', before: 'Final', after: 'Corrected' },
    ])
  })

  it('shows an update to a lab value with the inequality on each side', () => {
    const update = makeEhiProposal({
      mapping: 'lab_result',
      kind: 'update',
      before: { value: '50', value_comparator: '<' },
      proposed: { changed: ['value'], attributes: { analyte: 'Synthetic LDL', value: '60', value_comparator: '<' } },
    })
    expect(proposalView(update).changes).toEqual([{ field: 'Value', before: '<50', after: '<60' }])
  })

  it('says what a person should know about a conflicting identifier or a withdrawn look-alike', () => {
    const conflict = makeEhiProposal({ mapping: 'lab_result', kind: 'possible_duplicate', candidate_ids: [4], proposed: { attributes: { analyte: 'Synthetic glucose' }, bridge: { version: 'ehi-bridge-1', rule: 'ccda_order_extension', conflicts: ['value'] } } })
    expect(proposalView(conflict).notice).toMatch(/same source identifier.*value differs/)
    const lookalike = makeEhiProposal({ mapping: 'lab_result', kind: 'possible_duplicate', proposed: { attributes: { analyte: 'Synthetic glucose' }, withdrawn_match: true } })
    expect(proposalView(lookalike).notice).toMatch(/deleted, retracted, or rejected/)
    expect(decisionActions(lookalike).map((action) => action.decision)).not.toContain('link_existing')
  })

  it('explains why a lab row was kept, and says the record is unchanged when the source withdrew it', () => {
    const kept = (reason: string) => retainReason(makeEhiProposal({ mapping: 'lab_result', kind: 'retain', proposed: { retain_reason: reason } }))
    expect(kept('order_withdrawn')).toBe('The order was canceled in the source.')
    expect(kept('linked_lab_status_not_clinical')).toMatch(/record in PHR is unchanged/)
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
