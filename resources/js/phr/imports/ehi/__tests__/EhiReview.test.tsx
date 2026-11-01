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
