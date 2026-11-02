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
