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
      { field: 'Resulted, as written', before: '3/6/2024', after: '3/7/2024' },
    ])
  })

  it('never hides a field an update changes, for any mapping', () => {
    const hiddenBefore: [string, string, string][] = [
      ['lab_result', 'value_numeric', 'Numeric value'],
      ['lab_result', 'value_raw', 'Value as entered'],
      ['lab_result', 'collected_at_timezone', 'Collected time zone'],
      ['encounter', 'raw_text', 'Source details'],
      ['encounter', 'visit_started_source', 'Started, as written'],
    ]
    for (const [mapping, field, label] of hiddenBefore) {
      const update = makeEhiProposal({ mapping, kind: 'update', before: { [field]: 'old' }, proposed: { changed: [field], attributes: { [field]: 'new' } } })
      expect(proposalView(update).changes).toEqual([{ field: label, before: 'old', after: 'new' }])
    }
    const immunization = makeEhiProposal({ kind: 'update', before: { administered_at_source: '3/5/2024' }, proposed: { administered_at_source: '2024-03-05' } })
    expect(proposalView(immunization).changes).toEqual([{ field: 'Date given, as written', before: '3/5/2024', after: '2024-03-05' }])
  })

  it('shows where two long texts differ, not two identical openings', () => {
    const opening = 'Epic EHI IMMUNE '.repeat(20)
    const update = makeEhiProposal({ kind: 'update', before: { raw_text: `${opening}DOSE: 0.5 mL` }, proposed: { raw_text: `${opening}DOSE: 1.0 mL` } })
    const [change] = proposalView(update).changes
    expect(change?.before).toContain('DOSE: 0.5 mL')
    expect(change?.after).toContain('DOSE: 1.0 mL')
    expect(change?.before).not.toBe(change?.after)
    expect(change?.before.length).toBeLessThan(170)
  })

  it('shows a change to a diagnosis beyond its name', () => {
    const update = makeEhiProposal({
      mapping: 'encounter',
      kind: 'update',
      before: { encounter_diagnoses: [{ line: '1', name: 'Synthetic one', primary: true, annotation: 'Old note' }] },
      proposed: { changed: ['encounter_diagnoses'], attributes: { encounter_diagnoses: [{ line: '1', name: 'Synthetic one', primary: true, annotation: 'New note' }] } },
    })
    expect(proposalView(update).changes).toEqual([{ field: 'Diagnoses', before: 'Line 1: Synthetic one (primary), Old note', after: 'Line 1: Synthetic one (primary), New note' }])
  })

  it('shows a difference for any compared field whose stored value changed, however it renders', () => {
    const cases: [string, Record<string, unknown>, Record<string, unknown>][] = [
      ['value', { value: '50', value_comparator: '<' }, { value: '50', value_comparator: '>' }],
      ['value', { value: '50', value_comparator: null }, { value: '50', value_comparator: '<' }],
      ['raw_text', { raw_text: 'same text' }, { raw_text: 'same  text' }],
      // Renderings that would collapse: the stored values still differ.
      ['unit', { unit: 'mcg' }, { unit: 'µg' }],
      ['encounter_diagnoses', { encounter_diagnoses: [] }, { encounter_diagnoses: null }],
      ['encounter_diagnoses', { encounter_diagnoses: [{ line: '1', name: 'A', extra: 'x' }] }, { encounter_diagnoses: [{ line: '1', name: 'A', extra: 'y' }] }],
    ]
    for (const [field, before, after] of cases) {
      const update = makeEhiProposal({ mapping: 'lab_result', kind: 'update', before, proposed: { changed: [field], attributes: after } })
      const [change] = proposalView(update).changes
      expect(change).toBeDefined()
      expect(change?.before).not.toBe(change?.after)
    }
  })

  it('has nothing to approve when no field of an update visibly differs', () => {
    const same = makeEhiProposal({ kind: 'update', allowed_decisions: ['approve_update', 'reject', 'retain_evidence'], before: { lot_number: 'A' }, proposed: { lot_number: 'A' } })
    expect(proposalView(same).changes).toEqual([])
    expect(decisionActions(same).map((action) => action.decision)).toEqual(['retain_evidence', 'reject'])
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

  it('reads an encounter with its diagnoses, and says why one was kept as evidence', () => {
    const attributes = { visit_type: 'Office Visit', visit_date: '2024-05-01', provider_name: 'Synthetic Provider', facility_name: 'Synthetic Clinic', encounter_diagnoses: [{ line: '1', name: 'Synthetic one' }, { line: '2', name: 'Synthetic two' }], raw_text: 'Epic EHI PAT_ENC', visit_started_timezone: 'local' }
    const encounter = makeEhiProposal({ mapping: 'encounter', proposed: { attributes } })
    expect(proposalView(encounter)).toMatchObject({ domain: 'Encounter', title: 'Office Visit', date: '2024-05-01', detail: 'Office Visit · Synthetic Provider · Synthetic Clinic · Diagnoses: Synthetic one; Synthetic two' })

    const update = makeEhiProposal({ mapping: 'encounter', kind: 'update', before: { provider_name: 'Old', raw_text: 'old' }, proposed: { changed: ['provider_name', 'raw_text'], attributes } })
    expect(proposalView(update).changes).toEqual([
      { field: 'Provider', before: 'Old', after: 'Synthetic Provider' },
      { field: 'Source details', before: 'old', after: 'Epic EHI PAT_ENC' },
    ])

    const kept = (reason: string) => retainReason(makeEhiProposal({ mapping: 'encounter', kind: 'retain', proposed: { retain_reason: reason } }))
    expect(kept('category_telephone')).toMatch(/telephone/i)
    expect(kept('category_portal')).toMatch(/portal/i)
    expect(kept('category_cancelled')).toMatch(/canceled/i)
    expect(kept('category_administrative')).toMatch(/administrative/i)
    expect(kept('category_unknown')).toMatch(/did not assume/)
    expect(kept('linked_encounter_not_clinical')).toMatch(/record in PHR is unchanged/)
  })

  it('says when an encounter\'s diagnoses could not be read rather than implying there are none', () => {
    const proposal = makeEhiProposal({ mapping: 'encounter', proposed: { attributes: { visit_type: 'Office Visit' }, ehi: { diagnoses_unavailable: true } } })
    expect(proposalView(proposal).notice).toMatch(/could not vouch.*diagnoses/)
    expect(proposalView(makeEhiProposal({ mapping: 'encounter', proposed: { attributes: { visit_type: 'Office Visit' }, ehi: { diagnoses_unavailable: false } } })).notice).toBeNull()
  })

  it('says when a lab result\'s comments or narrative could not be read', () => {
    const proposal = makeEhiProposal({ mapping: 'lab_result', proposed: { attributes: { analyte: 'Synthetic LDL' }, ehi: { comments_unavailable: true, narrative_unavailable: false } } })
    expect(proposalView(proposal).notice).toMatch(/could not vouch.*comments or narrative/)
  })

  it('says when a document\'s text could not all be read', () => {
    const attributes = { title: 'Progress note', document_type: 'office_visit_note', observed_at: '2025-10-14', extracted_text: 'Part of it' }
    const update = makeEhiProposal({ mapping: 'document', kind: 'update', before: { title: 'Old title' }, proposed: { changed: ['title'], attributes, ehi: { text_unavailable: true } } })
    expect(proposalView(update).notice).toMatch(/could not read all of this document's text.*keeps the text it has/)

    const created = makeEhiProposal({ mapping: 'document', proposed: { attributes, ehi: { text_unavailable: true, text_incomplete: true } } })
    expect(proposalView(created).notice).toMatch(/may be incomplete/)

    const whole = makeEhiProposal({ mapping: 'document', proposed: { attributes, ehi: {} } })
    expect(proposalView(whole).notice).toBeNull()
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
    const phone = { mapping: 'encounter', proposed: { attributes: { encounter_category: 'telephone' } } }
    expect(reviewGroupOf(makeEhiProposal(phone))).toBe('phone')
    expect(decisionActions(makeEhiProposal(phone)).map((action) => action.label)).toEqual(['Add as visit', 'Keep as evidence only', 'Reject'])
    expect(reviewGroupOf(makeEhiProposal({ ...phone, kind: 'possible_duplicate' }))).toBe('duplicate')
    expect(reviewGroupOf(makeEhiProposal({ ...phone, kind: 'update' }))).toBe('change')
    expect(reviewGroupOf(makeEhiProposal({ mapping: 'encounter', proposed: { attributes: { encounter_category: 'clinical' } } }))).toBe('new')
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
