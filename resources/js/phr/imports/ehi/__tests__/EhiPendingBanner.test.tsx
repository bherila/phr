import '@testing-library/jest-dom'

import { render, screen, waitFor } from '@testing-library/react'

import { EhiPendingBanner } from '../EhiPendingBanner'
import { EHI_PATIENT_ID } from './ehiFixtures'

const mockGet = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: { get: (...args: unknown[]) => mockGet(...args) },
  getCsrfToken: () => null,
}))

beforeEach(() => mockGet.mockReset())

describe('EhiPendingBanner', () => {
  it('says how many proposals of this kind wait and links to their review', async () => {
    mockGet.mockResolvedValue({ pending: { immunization: { count: 8, package_ids: [9] } } })

    render(<EhiPendingBanner patientId={EHI_PATIENT_ID} mapping="immunization" />)

    expect(await screen.findByText('8 immunizations from an EHI export are waiting for review.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Review with the import' })).toHaveAttribute('href', `/phr/imports?patient_id=${EHI_PATIENT_ID}&ehi_package=9`)
    expect(mockGet).toHaveBeenCalledWith(`/api/phr/patients/${EHI_PATIENT_ID}/ehi/pending`)
  })

  it('sends proposals spread over several exports to the Imports page, which lists each', async () => {
    mockGet.mockResolvedValue({ pending: { document: { count: 42, package_ids: [9, 5] } } })

    render(<EhiPendingBanner patientId={EHI_PATIENT_ID} mapping="document" />)

    expect(await screen.findByText('42 documents from 2 EHI exports are waiting for review.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Review in Imports' })).toHaveAttribute('href', `/phr/imports?patient_id=${EHI_PATIENT_ID}`)
  })

  it('renders nothing when none of its kind wait, or when the count fails to load', async () => {
    mockGet.mockResolvedValueOnce({ pending: [] })
    const { container, rerender } = render(<EhiPendingBanner patientId={EHI_PATIENT_ID} mapping="document" />)
    await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(1))
    expect(container).toBeEmptyDOMElement()

    mockGet.mockRejectedValueOnce('Server error')
    rerender(<EhiPendingBanner patientId={EHI_PATIENT_ID + 1} mapping="document" />)
    await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(2))
    expect(container).toBeEmptyDOMElement()
  })
})
