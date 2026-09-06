import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactElement } from 'react'

import ImportsPage from '@/phr/imports/ImportsPage'
import { DicomUploadProvider } from '@/phr/uploads'
import { makeDicomFile, makeDicomUpload, MockUploadXMLHttpRequest, TEST_PATIENT_ID as PATIENT_ID } from '@/phr/uploads/__tests__/uploadTestDoubles'

const mockGet = jest.fn()
const mockPost = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    patch: jest.fn(),
    delete: jest.fn(),
  },
  getCsrfToken: () => null,
}))

function makePatient(overrides: Record<string, unknown> = {}) {
  return {
    id: PATIENT_ID,
    owner_user_id: 2,
    display_name: 'Primary',
    relationship: 'self',
    birth_date: null,
    sex_at_birth: null,
    notes: null,
    archived_at: null,
    created_at: null,
    updated_at: null,
    access_level: 'owner',
    can_manage: true,
    can_share: true,
    access_grants: [],
    ...overrides,
  }
}

function renderImports(patientId?: number): ReturnType<typeof render> {
  const page: ReactElement = <ImportsPage {...(patientId !== undefined ? { patientId } : {})} />
  return render(<DicomUploadProvider>{page}</DicomUploadProvider>)
}

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
  mockGet.mockResolvedValue({ patients: [makePatient()] })
  mockPost.mockResolvedValue({ upload: makeDicomUpload('pending') })
})

describe('ImportsPage', () => {
  it('renders the DICOM upload trigger instead of a placeholder', async () => {
    renderImports(PATIENT_ID)

    expect(await screen.findByRole('heading', { name: 'Imports' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /DICOM imaging/i })).toBeInTheDocument()
    const trigger = await screen.findByRole('button', { name: /upload dicom folder/i })
    await waitFor(() => expect(trigger).toBeEnabled())
  })

  it('defaults the import target to the patient in context', async () => {
    mockGet.mockResolvedValue({
      patients: [makePatient({ id: 55, display_name: 'Other' }), makePatient()],
    })

    renderImports(PATIENT_ID)

    const select = await screen.findByLabelText('Import into')
    await waitFor(() => expect(select).toHaveValue(String(PATIENT_ID)))
  })

  it('falls back to the first manageable patient when opened without one', async () => {
    mockGet.mockResolvedValue({
      patients: [
        makePatient({ id: 55, display_name: 'View only', can_manage: false }),
        makePatient({ id: 56, display_name: 'Archived', archived_at: '2026-01-01T00:00:00Z' }),
        makePatient({ id: 57, display_name: 'Manageable' }),
      ],
    })

    renderImports()

    const select = await screen.findByLabelText('Import into')
    await waitFor(() => expect(select).toHaveValue('57'))
    expect(screen.queryByRole('option', { name: 'View only' })).not.toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Archived' })).not.toBeInTheDocument()
  })

  it('disables importing when no patient can be managed', async () => {
    mockGet.mockResolvedValue({ patients: [makePatient({ can_manage: false })] })

    renderImports()

    await waitFor(() => expect(screen.getByRole('button', { name: /upload dicom folder/i })).toBeDisabled())
    expect(screen.getByText(/need manage access to a patient/i)).toBeInTheDocument()
  })

  it('opens an upload session for the selected patient', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.autoComplete = false

    try {
      const { container } = renderImports(PATIENT_ID)
      await waitFor(() => expect(screen.getByRole('button', { name: /upload dicom folder/i })).toBeEnabled())

      const input = container.querySelector('input[type="file"]')
      if (!(input instanceof HTMLInputElement)) {
        throw new Error('Expected the DICOM folder input to render.')
      }
      fireEvent.change(input, { target: { files: [makeDicomFile('CARDIAC_CT/IM0001')] } })

      await waitFor(() => expect(mockPost).toHaveBeenCalledWith(
        `/api/phr/patients/${PATIENT_ID}/dicom/uploads`,
        { root_name: 'CARDIAC_CT' },
      ))
      // The folder input is cleared so re-picking the same folder starts another job.
      expect(input.value).toBe('')
    } finally {
      restoreXhr()
      MockUploadXMLHttpRequest.instances.forEach((instance) => instance.abort())
    }
  })
})
