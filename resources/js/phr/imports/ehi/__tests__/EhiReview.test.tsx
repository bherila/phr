import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { EhiReview } from '../EhiReview'
import { EHI_PATIENT_ID, makeEhiProposal } from './ehiFixtures'

const mockGet = jest.fn()
const mockPost = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
  getCsrfToken: () => null,
}))

const proposalsUrl = `/api/phr/patients/${EHI_PATIENT_ID}/ehi/packages/9/proposals?limit=500&after_id=0`

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
})

describe('EhiReview', () => {
  it('groups proposals and records one decision with the reviewed digest', async () => {
    const created = makeEhiProposal({ id: 31 })
    const duplicate = makeEhiProposal({ id: 32, kind: 'possible_duplicate', candidate_ids: [4], proposed: { vaccine_name: 'Synthetic Tdap', administered_on: '2019-06-02' } })
    mockGet.mockImplementation((url: string) => {
      if (url === proposalsUrl) return Promise.resolve({ proposals: [created, duplicate], next_after_id: null })
      if (url === `/api/phr/patients/${EHI_PATIENT_ID}/immunizations/4`) {
        return Promise.resolve({
          immunization: {
            id: 4, patient_id: EHI_PATIENT_ID, user_id: 1, vaccine_name: 'Tdap (entered by you)', cvx_code: null, manufacturer: null, lot_number: null,
            administered_on: '2019-06-02', dose_number: null, series_doses: null, site: null, route: null, administered_by: null, facility_name: null,
            notes: null, raw_text: null, created_at: null, updated_at: null,
          },
          can_manage: true,
        })
      }
      return Promise.reject(new Error(`unexpected ${url}`))
    })
    mockPost.mockResolvedValue({ proposal: { ...created, status: 'accepted', reviewable: false }, record: { table: 'phr_immunizations', id: 50 } })

    render(<EhiReview patientId={EHI_PATIENT_ID} packageId={9} sourceLabel="Synthetic Clinic" onBack={jest.fn()} />)

    expect(await screen.findByRole('heading', { name: 'Review Synthetic Clinic' })).toBeInTheDocument()
    const duplicates = await screen.findByRole('region', { name: 'Might already be in PHR' })
    expect(await within(duplicates).findByText('Tdap (entered by you) · 2019-06-02')).toBeInTheDocument()
    expect(within(duplicates).getByRole('button', { name: 'Same record, link as evidence: Synthetic Tdap' })).toBeInTheDocument()

    const fresh = screen.getByRole('region', { name: 'New records' })
    fireEvent.click(within(fresh).getByRole('button', { name: 'Accept: Synthetic Vaccine A' }))

    await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`/api/phr/patients/${EHI_PATIENT_ID}/ehi/proposals/31/review`, {
      decision: 'accept',
      preview_digest: created.preview_digest,
    }))
    expect(await within(fresh).findByText('Accepted')).toBeInTheDocument()
  })

  it('offers a lab result next to the record it may duplicate, which it fetches from the labs route', async () => {
    const duplicate = makeEhiProposal({
      id: 41,
      mapping: 'lab_result',
      kind: 'possible_duplicate',
      candidate_ids: [7],
      allowed_decisions: ['accept', 'link_existing', 'reject', 'retain_evidence'],
      proposed: { attributes: { analyte: 'Synthetic glucose', value: '95.0', unit: 'mg/dL', result_datetime: '2024-03-05 11:00:00' } },
    })
    mockGet.mockImplementation((url: string) => {
      if (url === proposalsUrl) return Promise.resolve({ proposals: [duplicate], next_after_id: null })
      if (url === `/api/phr/patients/${EHI_PATIENT_ID}/lab-results/7`) {
        return Promise.resolve({
          lab_result: {
            id: 7, patient_id: EHI_PATIENT_ID, user_id: 1, test_name: null, collection_datetime: '2024-03-05 08:00:00', result_datetime: null, result_status: null, ordering_provider: null,
            resulting_lab: null, analyte: 'Glucose (entered by you)', value: '95', value_numeric: '95', unit: 'mg/dL', range_min: null, range_max: null, range_unit: null, reference_range_text: null,
            normal_value: null, abnormal_flag: null, message_from_provider: null, result_comment: null, lab_director: null, source: null, notes: null, created_at: null, updated_at: null,
          },
        })
      }
      return Promise.reject(new Error(`unexpected ${url}`))
    })
    mockPost.mockResolvedValue({ proposal: { ...duplicate, status: 'linked', reviewable: false }, record: { table: 'phr_lab_results', id: 7 } })

    render(<EhiReview patientId={EHI_PATIENT_ID} packageId={9} onBack={jest.fn()} />)

    const duplicates = await screen.findByRole('region', { name: 'Might already be in PHR' })
    expect(await within(duplicates).findByText('Glucose (entered by you) · 95 mg/dL · 2024-03-05')).toBeInTheDocument()
    fireEvent.click(within(duplicates).getByRole('button', { name: 'Same record, link as evidence: Synthetic glucose' }))
    await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`/api/phr/patients/${EHI_PATIENT_ID}/ehi/proposals/41/review`, {
      decision: 'link_existing',
      preview_digest: duplicate.preview_digest,
      link_id: 7,
    }))
    expect(await within(duplicates).findByText('Linked as evidence')).toBeInTheDocument()
  })

  describe('a document update that changes the text', () => {
    const attributes = { title: 'Synthetic note', document_type: 'office_visit_note', observed_at: '2024-03-05', extracted_text: 'Synthetic corrected line' }
    const update = (status: 'pending' | 'accepted', before: Record<string, unknown>) => makeEhiProposal({
      id: 43,
      mapping: 'document',
      kind: 'update',
      status,
      reviewable: status === 'pending',
      allowed_decisions: status === 'pending' ? ['approve_update', 'reject', 'retain_evidence'] : [],
      before: { title: 'Synthetic note', document_type: 'office_visit_note', observed_at: '2024-03-05', extracted_text_sha256: 'a'.repeat(64), ...before },
      proposed: { changed: ['extracted_text'], attributes },
    })
    const textRow = async (proposal: ReturnType<typeof update>) => {
      mockGet.mockImplementation((url: string) => (url === proposalsUrl ? Promise.resolve({ proposals: [proposal], next_after_id: null }) : Promise.reject(new Error(`unexpected ${url}`))))
      render(<EhiReview patientId={EHI_PATIENT_ID} packageId={9} onBack={jest.fn()} />)

      return within((await screen.findByText('Text')).closest('tr') as HTMLElement).getAllByRole('cell')
    }

    it('shows the text the record holds now beside the proposed text', async () => {
      const [, before, after] = await textRow(update('pending', { extracted_text: 'Synthetic stored line' }))
      expect(before).toHaveTextContent('Synthetic stored line')
      expect(after).toHaveTextContent('Synthetic corrected line')
    })

    it('says when it is cut short, even if what is shown matches', async () => {
      const [, before] = await textRow(update('pending', { extracted_text: 'Synthetic corrected line', extracted_text_truncated: true }))
      expect(before).toHaveTextContent('Synthetic corrected line (cut short)')
    })

    it('says the record changed rather than that it holds no text', async () => {
      const [, before] = await textRow(update('pending', { extracted_text_unavailable: true }))
      expect(before).toHaveTextContent('Changed since this preview')
    })

    it('says the earlier text is gone once the update was accepted', async () => {
      const [, before] = await textRow(update('accepted', { extracted_text_unavailable: true }))
      expect(before).toHaveTextContent('No longer stored')
    })

    it('still says not recorded when the record had no text', async () => {
      const [, before] = await textRow(update('pending', { extracted_text_sha256: null }))
      expect(before).toHaveTextContent('Not recorded')
    })
  })

  it('shows an encounter next to the visit it may duplicate, fetched from the visits route', async () => {
    const duplicate = makeEhiProposal({ id: 42, mapping: 'encounter', kind: 'possible_duplicate', candidate_ids: [8], proposed: { attributes: { visit_type: 'Office Visit', visit_date: '2024-05-01' } } })
    mockGet.mockImplementation((url: string) => {
      if (url === proposalsUrl) return Promise.resolve({ proposals: [duplicate], next_after_id: null })
      if (url === `/api/phr/patients/${EHI_PATIENT_ID}/office-visits/8`) {
        return Promise.resolve({
          office_visit: {
            id: 8, patient_id: EHI_PATIENT_ID, user_id: 1, visit_date: '2024-05-01', visit_started_at: null, visit_ended_at: null, visit_type: 'Follow-up (entered by you)', provider_name: null, provider_specialty: null,
            facility_name: null, chief_complaint: null, assessment: null, plan: null, subjective: null, objective: null, icd10_codes: [], cpt_codes: [], review_status: 'confirmed', created_at: null, updated_at: null,
          },
          can_manage: true,
        })
      }
      return Promise.reject(new Error(`unexpected ${url}`))
    })

    render(<EhiReview patientId={EHI_PATIENT_ID} packageId={9} onBack={jest.fn()} />)

    const duplicates = await screen.findByRole('region', { name: 'Might already be in PHR' })
    expect(await within(duplicates).findByText('Follow-up (entered by you) · 2024-05-01')).toBeInTheDocument()
    expect(within(duplicates).getByRole('button', { name: 'Same record, link as evidence: Office Visit' })).toBeInTheDocument()
  })

  it('shows the corrected dose an immunization update changes only in its source details, then approves it', async () => {
    const shared = 'Epic EHI IMMUNE\nIMMUNE_ID: 601\nIMMUNZATN_ID_NAME: SYNTHETIC VACCINE A'
    const update = makeEhiProposal({
      id: 43,
      kind: 'update',
      allowed_decisions: ['approve_update', 'reject', 'retain_evidence'],
      target: { table: 'phr_immunizations', id: 5 },
      before: { raw_text: `${shared}\nDOSE: 0.5 mL` },
      proposed: { raw_text: `${shared}\nDOSE: 1.0 mL` },
    })
    mockGet.mockImplementation((url: string) => (url === proposalsUrl ? Promise.resolve({ proposals: [update], next_after_id: null }) : Promise.reject(new Error(`unexpected ${url}`))))
    mockPost.mockResolvedValue({ proposal: { ...update, status: 'accepted', reviewable: false }, record: { table: 'phr_immunizations', id: 5 } })

    render(<EhiReview patientId={EHI_PATIENT_ID} packageId={9} onBack={jest.fn()} />)

    const changed = await screen.findByRole('region', { name: 'Changed in the source' })
    expect(within(changed).getByText('Source details')).toBeInTheDocument()
    expect(within(changed).getByText(/DOSE: 0\.5 mL/)).toBeInTheDocument()
    expect(within(changed).getByText(/DOSE: 1\.0 mL/)).toBeInTheDocument()
    fireEvent.click(within(changed).getByRole('button', { name: /^Approve update/ }))
    await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`/api/phr/patients/${EHI_PATIENT_ID}/ehi/proposals/43/review`, { decision: 'approve_update', preview_digest: update.preview_digest }))
  })

  it.each<[string, Record<string, unknown>, Record<string, unknown>?]>([
    ['line', { line: '2' }],
    ['name', { name: 'Synthetic other name' }],
    ['primary, true to false', { primary: false }],
    ['primary, unknown to false', { primary: false }, { primary: null }],
    ['chronic, unknown to false', { chronic: false }],
    ['chronic, unknown to true', { chronic: true }],
    ['ED impression, unknown to true', { ed_impression: true }],
    ['ED impression, unknown to false', { ed_impression: false }],
    ['ED impression, true to false', { ed_impression: false }, { ed_impression: true }],
    ['qualifier', { qualifier: 'Synthetic qualifier' }],
    ['annotation', { annotation: 'Synthetic annotation' }],
    ['comments', { comments: 'Synthetic comment' }],
  ])('shows a diagnosis change in %s and can approve it', async (_name, after, before = {}) => {
    const entry = { line: '1', name: 'Synthetic diagnosis', primary: true, chronic: null, ed_impression: null, qualifier: null, annotation: null, comments: null }
    const update = makeEhiProposal({
      id: 45,
      mapping: 'encounter',
      kind: 'update',
      allowed_decisions: ['approve_update', 'reject', 'retain_evidence'],
      target: { table: 'phr_office_visits', id: 6 },
      before: { encounter_diagnoses: [{ ...entry, ...before }] },
      proposed: { changed: ['encounter_diagnoses'], attributes: { visit_type: 'Office Visit', encounter_diagnoses: [{ ...entry, ...before, ...after }] } },
    })
    mockGet.mockImplementation((url: string) => (url === proposalsUrl ? Promise.resolve({ proposals: [update], next_after_id: null }) : Promise.reject(new Error(`unexpected ${url}`))))

    render(<EhiReview patientId={EHI_PATIENT_ID} packageId={9} onBack={jest.fn()} />)

    const changed = await screen.findByRole('region', { name: 'Changed in the source' })
    expect(within(changed).getByText('Diagnoses')).toBeInTheDocument()
    expect(within(changed).getByRole('button', { name: /^Approve update/ })).toBeInTheDocument()
  })

  it('offers no "Approve update" when the update shows no difference', async () => {
    const update = makeEhiProposal({
      id: 44,
      kind: 'update',
      allowed_decisions: ['approve_update', 'reject', 'retain_evidence'],
      target: { table: 'phr_immunizations', id: 5 },
      before: { lot_number: 'SYN-LOT-1' },
      proposed: { lot_number: 'SYN-LOT-1' },
    })
    mockGet.mockImplementation((url: string) => (url === proposalsUrl ? Promise.resolve({ proposals: [update], next_after_id: null }) : Promise.reject(new Error(`unexpected ${url}`))))

    render(<EhiReview patientId={EHI_PATIENT_ID} packageId={9} onBack={jest.fn()} />)

    const changed = await screen.findByRole('region', { name: 'Changed in the source' })
    expect(within(changed).queryByRole('button', { name: /^Approve update/ })).not.toBeInTheDocument()
    expect(within(changed).getByRole('button', { name: /^Keep as evidence only/ })).toBeInTheDocument()
    expect(within(changed).getByRole('button', { name: /^Reject/ })).toBeInTheDocument()
  })

  it('accepts every new record in turn and reports the ones that failed', async () => {
    const first = makeEhiProposal({ id: 41, proposed: { vaccine_name: 'Vaccine One' } })
    const second = makeEhiProposal({ id: 42, proposed: { vaccine_name: 'Vaccine Two' } })
    mockGet.mockResolvedValue({ proposals: [first, second], next_after_id: null })
    mockPost.mockImplementation((url: string) => (url.endsWith('/41/review')
      ? Promise.resolve({ proposal: { ...first, status: 'accepted', reviewable: false }, record: null })
      : Promise.reject('stale_preview')))

    render(<EhiReview patientId={EHI_PATIENT_ID} packageId={9} onBack={jest.fn()} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Accept all 2 new records' }))

    expect(await screen.findByText(/Accepted 1\. 1 could not be accepted/)).toBeInTheDocument()
    expect(screen.getByText(/This proposal changed since the review screen loaded/)).toBeInTheDocument()
    expect(mockPost).toHaveBeenCalledTimes(2)
  })
})
