import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import { EhiImportFlow } from '../EhiImportFlow'
import { EHI_PATIENT_ID, makeEhiUpload } from './ehiFixtures'

const mockGet = jest.fn()
const mockPost = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
  getCsrfToken: () => null,
}))

const uploadUrl = `/api/phr/patients/${EHI_PATIENT_ID}/ehi/uploads/5`

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
})

describe('EhiImportFlow', () => {
  it('starts the import of an upload whose bytes all arrived but whose intake never started', async () => {
    mockGet.mockResolvedValue({ upload: makeEhiUpload({ uploaded_bytes: 10, file_size_bytes: 10 }) })
    mockPost.mockResolvedValue({ upload: makeEhiUpload({ status: 'queued', uploaded_bytes: 10, file_size_bytes: 10 }) })

    render(<EhiImportFlow patientId={EHI_PATIENT_ID} canBind uploadId={5} onReview={jest.fn()} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Start the import' }))
    await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`${uploadUrl}/intake`, {}))
  })

  it('shows what a MyChart ZIP added, with no review stage', async () => {
    mockGet.mockResolvedValue({ upload: makeEhiUpload({
      status: 'completed', format: 'mychart', uploaded_bytes: 10, file_size_bytes: 10,
      import_result: { created: 4, updated: 0, unchanged: 1, skipped: 0, warnings: [], warnings_omitted: 0 },
    }) })

    render(<EhiImportFlow patientId={EHI_PATIENT_ID} canBind uploadId={5} onReview={jest.fn()} />)

    expect(await screen.findByText('A MyChart record ZIP: its records were added directly.')).toBeInTheDocument()
    expect(screen.getByText('Added as new records').closest('dt')?.nextElementSibling).toHaveTextContent('4')
    expect(screen.queryByText('Review')).not.toBeInTheDocument()
    expect(screen.queryByText('Read and account for every row')).not.toBeInTheDocument()
  })

  it('says an upload that stopped partway has to be chosen again', async () => {
    mockGet.mockResolvedValue({ upload: makeEhiUpload({ uploaded_bytes: 4, file_size_bytes: 10 }) })

    render(<EhiImportFlow patientId={EHI_PATIENT_ID} canBind uploadId={5} onReview={jest.fn()} />)

    expect(await screen.findByText(/The upload stopped after .* Choose the file again to start over\./)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Start the import' })).not.toBeInTheDocument()
  })
})
