import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import AccessPage from '@/phr/access/AccessPage'
import AllergiesPage from '@/phr/allergies/AllergiesPage'
import ConditionsPage from '@/phr/conditions/ConditionsPage'
import DocumentsPage from '@/phr/documents/DocumentsPage'
import HealthLogPage from '@/phr/health-log/HealthLogPage'
import ImagingPage from '@/phr/imaging/ImagingPage'
import ImmunizationsPage from '@/phr/immunizations/ImmunizationsPage'
import LabsPage from '@/phr/labs/LabsPage'
import MedicationsPage from '@/phr/medications/MedicationsPage'
import OfficeVisitsPage from '@/phr/office-visits/OfficeVisitsPage'
import PatientsPage from '@/phr/patients/PatientsPage'
import ProceduresPage from '@/phr/procedures/ProceduresPage'
import type { PhrDicomStudy } from '@/phr/types'
import VitalsPage from '@/phr/vitals/VitalsPage'

const PATIENT_ID = 101

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockPatch = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
}))

function makePatient() {
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
  }
}

beforeEach(() => {
  mockGet.mockClear()
  mockPost.mockClear()
  mockPatch.mockClear()
  mockDelete.mockClear()
  const patient = makePatient()
  mockGet.mockImplementation(async (url: string) => {
    if (url === '/api/phr/patients') return { patients: [patient] }
    if (url === `/api/phr/patients/${PATIENT_ID}`) return { patient }
    if (url.includes('/lab-results')) return { lab_results: [], can_manage: true }
    if (url.includes('/vitals')) return { vitals: [], can_manage: true }
    if (url.includes('/documents')) return { documents: [], can_manage: true }
    if (url.includes('/health-logs')) return { health_logs: [], can_manage: true }
    if (url.includes('/exports')) return { exports: [] }
    if (url.includes('/dicom/studies')) return { studies: [] }
    if (url.includes('/access')) return { access_grants: [] }
    if (url.includes('/conditions')) return { conditions: [], can_manage: true }
    if (url.includes('/procedures')) return { procedures: [], can_manage: true }
    if (url.includes('/immunizations')) return { immunizations: [], can_manage: true }
    if (url.includes('/allergies')) return { allergies: [], can_manage: true }
    return {}
  })
  mockPost.mockResolvedValue({ patient })
  mockPatch.mockResolvedValue({ patient })
  mockDelete.mockResolvedValue({})
})

describe('PHR page mounts', () => {
  it('mounts patients page and shows Add Patient button', async () => {
    render(<PatientsPage />)
    await waitFor(() => expect(mockGet).toHaveBeenCalledWith('/api/phr/patients'))
    expect(screen.getByRole('link', { name: /add patient/i })).toBeInTheDocument()
  })

  it('mounts patients page and shows patient card', async () => {
    render(<PatientsPage />)
    await waitFor(() => expect(screen.getByText('Primary')).toBeInTheDocument())
  })

  it('mounts labs page without crash', () => {
    render(<LabsPage patientId={PATIENT_ID} />)
    expect(document.body).toBeTruthy()
  })

  it('mounts vitals page without crash', () => {
    render(<VitalsPage patientId={PATIENT_ID} />)
    expect(document.body).toBeTruthy()
  })

  it('mounts health log page without crash', () => {
    render(<HealthLogPage patientId={PATIENT_ID} />)
    expect(document.body).toBeTruthy()
  })

  it('mounts imaging page without crash', () => {
    render(<ImagingPage patientId={PATIENT_ID} />)
    expect(document.body).toBeTruthy()
  })

  it('opens imaging studies with the OHIF DICOM JSON route', async () => {
    const openSpy = jest.fn()
    const originalOpen = window.open
    window.open = openSpy as unknown as typeof window.open

    mockGet.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}`) return { patient: makePatient() }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/studies`) {
        return { studies: [makeDicomStudy()] }
      }
      return {}
    })

    try {
      render(<ImagingPage patientId={PATIENT_ID} />)

      fireEvent.click(await screen.findByRole('button', { name: /viewer/i }))

      expect(openSpy).toHaveBeenCalledWith(
        `/ohif/viewer/dicomjson?url=${encodeURIComponent(`/api/phr/patients/${PATIENT_ID}/dicom/studies/7001/viewer-json`)}`,
        '_blank',
        'noopener,noreferrer',
      )
    } finally {
      window.open = originalOpen
    }
  })

  it('renders imaging studies newest first with file sizes', async () => {
    mockGet.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}`) return { patient: makePatient() }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/studies`) {
        return {
          studies: [
            makeDicomStudy({
              id: 7001,
              description: 'Older CT',
              study_date: '2026-05-17',
              study_time: '120000',
              file_size_bytes: 1024 * 1024,
            }),
            makeDicomStudy({
              id: 7002,
              description: 'Recent MR',
              modalities: 'MR',
              study_date: '2026-05-18',
              study_time: '090000',
              file_size_bytes: 2 * 1024 * 1024,
            }),
          ],
        }
      }
      return {}
    })

    const { container } = render(<ImagingPage patientId={PATIENT_ID} />)

    await waitFor(() => expect(screen.getByText('Recent MR')).toBeInTheDocument())
    expect(screen.getByText(/2\.0 MB/)).toBeInTheDocument()
    expect(screen.getByText(/1\.0 MB/)).toBeInTheDocument()

    const text = container.textContent ?? ''
    expect(text.indexOf('Recent MR')).toBeGreaterThanOrEqual(0)
    expect(text.indexOf('Recent MR')).toBeLessThan(text.indexOf('Older CT'))
  })

  it('keeps imaging upload dialog failed when finalize fails', async () => {
    const originalXmlHttpRequest = globalThis.XMLHttpRequest
    MockUploadXMLHttpRequest.reset()
    globalThis.XMLHttpRequest = MockUploadXMLHttpRequest as unknown as typeof XMLHttpRequest

    mockPost.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads`) {
        return { upload: makeDicomUpload('pending') }
      }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/signed-urls`) {
        return makeSignedDicomUploadBatch()
      }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/files/complete`) {
        return makeDicomUploadFileResponse()
      }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/finalize`) {
        throw new Error('Finalize failed.')
      }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/cancel`) {
        return { upload: makeDicomUpload('failed') }
      }
      return { patient: makePatient() }
    })

    try {
      const { container } = render(<ImagingPage patientId={PATIENT_ID} />)

      await waitFor(() => expect(screen.getByRole('button', { name: /upload dicom/i })).toBeInTheDocument())

      const input = container.querySelector('input[type="file"]')
      if (!(input instanceof HTMLInputElement)) {
        throw new Error('Expected DICOM file input to render.')
      }

      const file = new File(['dicom'], 'IM0001', { type: 'application/dicom' })
      Object.defineProperty(file, 'webkitRelativePath', { value: 'CARDIAC_CT/IM0001' })

      fireEvent.change(input, { target: { files: [file] } })

      await waitFor(() => expect(screen.getByText('Upload failed')).toBeInTheDocument())
      expect(MockUploadXMLHttpRequest.instances[0]?.requestHeaders).toMatchObject({
        'Content-Type': 'application/dicom',
        'x-amz-meta-upload': 'dicom',
      })
      expect(screen.queryByText('Upload complete')).not.toBeInTheDocument()
      expect(screen.getAllByText(/Finalize failed\./).length).toBeGreaterThan(0)
      expect(mockPost).toHaveBeenCalledWith(`/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/signed-urls`, {
        files: [{
          client_id: '0',
          filename: 'IM0001',
          relative_path: 'CARDIAC_CT/IM0001',
          content_type: 'application/dicom',
          file_size: 5,
        }],
      })
      expect(mockPost).toHaveBeenCalledWith(`/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/files/complete`, {
        r2_key: 'phr/dicom/patients/1/uploads/upload-uuid/CARDIAC_CT/IM0001',
        relative_path: 'CARDIAC_CT/IM0001',
        original_filename: 'IM0001',
        mime_type: 'application/dicom',
        file_size_bytes: 5,
      })
      expect(mockPost).toHaveBeenCalledWith(`/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/cancel`, {})
    } finally {
      globalThis.XMLHttpRequest = originalXmlHttpRequest
    }
  })

  it('uploads imaging files when signed URL response has empty headers array', async () => {
    const originalXmlHttpRequest = globalThis.XMLHttpRequest
    MockUploadXMLHttpRequest.reset()
    globalThis.XMLHttpRequest = MockUploadXMLHttpRequest as unknown as typeof XMLHttpRequest

    mockPost.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads`) {
        return { upload: makeDicomUpload('pending') }
      }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/signed-urls`) {
        return makeSignedDicomUploadBatch([])
      }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/files/complete`) {
        return makeDicomUploadFileResponse()
      }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/finalize`) {
        return { upload: makeDicomUpload('processed') }
      }
      return { patient: makePatient() }
    })

    try {
      const { container } = render(<ImagingPage patientId={PATIENT_ID} />)

      await waitFor(() => expect(screen.getByRole('button', { name: /upload dicom/i })).toBeInTheDocument())

      const input = container.querySelector('input[type="file"]')
      if (!(input instanceof HTMLInputElement)) {
        throw new Error('Expected DICOM file input to render.')
      }

      const file = new File(['dicom'], 'IM0001', { type: 'application/dicom' })
      Object.defineProperty(file, 'webkitRelativePath', { value: 'CARDIAC_CT/IM0001' })

      fireEvent.change(input, { target: { files: [file] } })

      await waitFor(() => expect(screen.getByText('Upload complete')).toBeInTheDocument())
      expect(MockUploadXMLHttpRequest.instances[0]?.requestHeaders).toEqual({
        'Content-Type': 'application/dicom',
      })
      expect(mockPost).toHaveBeenCalledWith(`/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/files/complete`, {
        r2_key: 'phr/dicom/patients/1/uploads/upload-uuid/CARDIAC_CT/IM0001',
        relative_path: 'CARDIAC_CT/IM0001',
        original_filename: 'IM0001',
        mime_type: 'application/dicom',
        file_size_bytes: 5,
      })
      expect(mockPost).toHaveBeenCalledWith(`/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/finalize`, {})
    } finally {
      globalThis.XMLHttpRequest = originalXmlHttpRequest
    }
  })

  it('shows duplicate skipped completion when finalize discards a duplicate imaging upload', async () => {
    const originalXmlHttpRequest = globalThis.XMLHttpRequest
    MockUploadXMLHttpRequest.reset()
    globalThis.XMLHttpRequest = MockUploadXMLHttpRequest as unknown as typeof XMLHttpRequest

    const finalizeUrl = `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/finalize`
    const cancelUrl = `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/cancel`

    mockPost.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads`) {
        return { upload: makeDicomUpload('pending') }
      }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/signed-urls`) {
        return makeSignedDicomUploadBatch()
      }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/files/complete`) {
        return makeDicomUploadFileResponse('CARDIAC_CT/IM0001', {
          stored: false,
          skipped_reason: 'duplicate_sop_instance',
          study_id: null,
        })
      }
      if (url === finalizeUrl) {
        return {
          upload: makeDicomUpload('failed', {
            stored_files: 0,
            stored_bytes: 0,
            skipped_files: 1,
            error_message: 'Duplicate DICOM study upload was skipped because all image instances already exist for this patient.',
          }),
          duplicate_upload: true,
        }
      }
      if (url === cancelUrl) {
        throw new Error('Cancel should not be called.')
      }
      return { patient: makePatient() }
    })

    try {
      const { container } = render(<ImagingPage patientId={PATIENT_ID} />)

      await waitFor(() => expect(screen.getByRole('button', { name: /upload dicom/i })).toBeInTheDocument())

      const input = container.querySelector('input[type="file"]')
      if (!(input instanceof HTMLInputElement)) {
        throw new Error('Expected DICOM file input to render.')
      }

      const file = new File(['dicom'], 'IM0001', { type: 'application/dicom' })
      Object.defineProperty(file, 'webkitRelativePath', { value: 'CARDIAC_CT/IM0001' })

      fireEvent.change(input, { target: { files: [file] } })

      await waitFor(() => expect(screen.getByText('Duplicate study skipped')).toBeInTheDocument())
      expect(screen.queryByText('Upload failed')).not.toBeInTheDocument()
      expect(screen.getByText('No new images were added. Stored 0 · Skipped 1')).toBeInTheDocument()
      expect(screen.getByText('This study is already available in the imaging library.')).toBeInTheDocument()
      expect(mockPost).toHaveBeenCalledWith(finalizeUrl, {})
      expect(mockPost).not.toHaveBeenCalledWith(cancelUrl, {})
    } finally {
      globalThis.XMLHttpRequest = originalXmlHttpRequest
    }
  })

  it('requests signed DICOM upload URLs in batches', async () => {
    const originalXmlHttpRequest = globalThis.XMLHttpRequest
    MockUploadXMLHttpRequest.reset()
    globalThis.XMLHttpRequest = MockUploadXMLHttpRequest as unknown as typeof XMLHttpRequest

    const signedUrlsUrl = `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/signed-urls`
    const completeUrl = `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/files/complete`

    mockPost.mockImplementation(async (url: string, payload?: unknown) => {
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads`) {
        return { upload: makeDicomUpload('pending') }
      }
      if (url === signedUrlsUrl) {
        return {
          uploads: [
            makeSignedDicomUpload('0', 'CARDIAC_CT/IM0001', {
              'Content-Type': 'application/dicom',
              'x-amz-meta-upload': 'dicom',
            }),
            makeSignedDicomUpload('1', 'CARDIAC_CT/IM0002', {
              'Content-Type': 'application/dicom',
              'x-amz-meta-upload': 'dicom',
            }),
          ],
        }
      }
      if (url === completeUrl) {
        const completePayload = payload as { relative_path?: string }

        return makeDicomUploadFileResponse(completePayload.relative_path ?? 'CARDIAC_CT/IM0001')
      }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/finalize`) {
        return { upload: makeDicomUpload('processed') }
      }
      return { patient: makePatient() }
    })

    try {
      const { container } = render(<ImagingPage patientId={PATIENT_ID} />)

      await waitFor(() => expect(screen.getByRole('button', { name: /upload dicom/i })).toBeInTheDocument())

      const input = container.querySelector('input[type="file"]')
      if (!(input instanceof HTMLInputElement)) {
        throw new Error('Expected DICOM file input to render.')
      }

      const firstFile = new File(['dicom'], 'IM0001', { type: 'application/dicom' })
      const secondFile = new File(['dicom2'], 'IM0002', { type: 'application/dicom' })
      Object.defineProperty(firstFile, 'webkitRelativePath', { value: 'CARDIAC_CT/IM0001' })
      Object.defineProperty(secondFile, 'webkitRelativePath', { value: 'CARDIAC_CT/IM0002' })

      fireEvent.change(input, { target: { files: [firstFile, secondFile] } })

      await waitFor(() => expect(screen.getByText('Upload complete')).toBeInTheDocument())
      expect(MockUploadXMLHttpRequest.instances).toHaveLength(2)
      expect(mockPost).toHaveBeenCalledWith(signedUrlsUrl, {
        files: [
          {
            client_id: '0',
            filename: 'IM0001',
            relative_path: 'CARDIAC_CT/IM0001',
            content_type: 'application/dicom',
            file_size: 5,
          },
          {
            client_id: '1',
            filename: 'IM0002',
            relative_path: 'CARDIAC_CT/IM0002',
            content_type: 'application/dicom',
            file_size: 6,
          },
        ],
      })
      expect(mockPost).toHaveBeenCalledWith(completeUrl, {
        r2_key: 'phr/dicom/patients/1/uploads/upload-uuid/CARDIAC_CT/IM0001',
        relative_path: 'CARDIAC_CT/IM0001',
        original_filename: 'IM0001',
        mime_type: 'application/dicom',
        file_size_bytes: 5,
      })
      expect(mockPost).toHaveBeenCalledWith(completeUrl, {
        r2_key: 'phr/dicom/patients/1/uploads/upload-uuid/CARDIAC_CT/IM0002',
        relative_path: 'CARDIAC_CT/IM0002',
        original_filename: 'IM0002',
        mime_type: 'application/dicom',
        file_size_bytes: 6,
      })
    } finally {
      globalThis.XMLHttpRequest = originalXmlHttpRequest
    }
  })

  it('cancels imaging upload sessions when direct storage upload fails', async () => {
    const originalXmlHttpRequest = globalThis.XMLHttpRequest
    MockUploadXMLHttpRequest.reset()
    MockUploadXMLHttpRequest.status = 403
    MockUploadXMLHttpRequest.statusText = 'Forbidden'
    MockUploadXMLHttpRequest.responseText = '<!DOCTYPE html><html lang="en"><head><title>Redirected</title></head></html>'
    globalThis.XMLHttpRequest = MockUploadXMLHttpRequest as unknown as typeof XMLHttpRequest

    const finalizeUrl = `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/finalize`
    const cancelUrl = `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/cancel`

    mockPost.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads`) {
        return { upload: makeDicomUpload('pending') }
      }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/signed-urls`) {
        return makeSignedDicomUploadBatch()
      }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/files/complete`) {
        throw new Error('Complete should not be called.')
      }
      if (url === finalizeUrl) {
        throw new Error('Finalize should not be called.')
      }
      if (url === cancelUrl) {
        return { upload: makeDicomUpload('failed') }
      }
      return { patient: makePatient() }
    })

    try {
      const { container } = render(<ImagingPage patientId={PATIENT_ID} />)

      await waitFor(() => expect(screen.getByRole('button', { name: /upload dicom/i })).toBeInTheDocument())

      const input = container.querySelector('input[type="file"]')
      if (!(input instanceof HTMLInputElement)) {
        throw new Error('Expected DICOM file input to render.')
      }

      const file = new File(['dicom'], 'IM0001', { type: 'application/dicom' })
      Object.defineProperty(file, 'webkitRelativePath', { value: 'CARDIAC_CT/IM0001' })

      fireEvent.change(input, { target: { files: [file] } })

      await waitFor(() => expect(screen.getByText('Upload failed')).toBeInTheDocument())
      expect(MockUploadXMLHttpRequest.instances[0]?.requestHeaders['Content-Type']).toBe('application/dicom')
      expect(screen.queryByText('Upload complete')).not.toBeInTheDocument()
      expect(screen.getAllByText(/Storage upload failed/).length).toBeGreaterThan(0)
      expect(mockPost).toHaveBeenCalledWith(cancelUrl, {})
      expect(mockPost).not.toHaveBeenCalledWith(finalizeUrl, {})
    } finally {
      globalThis.XMLHttpRequest = originalXmlHttpRequest
    }
  })

  it('fails oversized imaging files before sending them to the server', async () => {
    const originalXmlHttpRequest = globalThis.XMLHttpRequest
    MockUploadXMLHttpRequest.reset()
    globalThis.XMLHttpRequest = MockUploadXMLHttpRequest as unknown as typeof XMLHttpRequest

    const finalizeUrl = `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/finalize`
    const cancelUrl = `/api/phr/patients/${PATIENT_ID}/dicom/uploads/501/cancel`

    mockPost.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/uploads`) {
        return {
          upload: makeDicomUpload('pending'),
          limits: {
            max_file_bytes: 3,
            max_file_size_label: '3 B',
          },
        }
      }
      if (url === cancelUrl) {
        return { upload: makeDicomUpload('failed') }
      }
      if (url === finalizeUrl) {
        throw new Error('Finalize should not be called.')
      }
      return { patient: makePatient() }
    })

    try {
      const { container } = render(<ImagingPage patientId={PATIENT_ID} />)

      await waitFor(() => expect(screen.getByRole('button', { name: /upload dicom/i })).toBeInTheDocument())

      const input = container.querySelector('input[type="file"]')
      if (!(input instanceof HTMLInputElement)) {
        throw new Error('Expected DICOM file input to render.')
      }

      const file = new File(['dicom'], 'IM0001', { type: 'application/dicom' })
      Object.defineProperty(file, 'webkitRelativePath', { value: 'CARDIAC_CT/IM0001' })

      fireEvent.change(input, { target: { files: [file] } })

      await waitFor(() => expect(screen.getByText('Upload failed')).toBeInTheDocument())
      expect(MockUploadXMLHttpRequest.instances).toHaveLength(0)
      expect(screen.getAllByText(/exceeds the server upload limit of 3 B/).length).toBeGreaterThan(0)
      expect(mockPost).toHaveBeenCalledWith(cancelUrl, {})
      expect(mockPost).not.toHaveBeenCalledWith(finalizeUrl, {})
    } finally {
      globalThis.XMLHttpRequest = originalXmlHttpRequest
    }
  })

  it('mounts access page without crash', () => {
    render(<AccessPage patientId={PATIENT_ID} />)
    expect(document.body).toBeTruthy()
  })

  it('mounts stub pages without crash', () => {
    render(<AllergiesPage patientId={PATIENT_ID} />)
    render(<ConditionsPage patientId={PATIENT_ID} />)
    render(<DocumentsPage patientId={PATIENT_ID} />)
    render(<ImmunizationsPage patientId={PATIENT_ID} />)
    render(<MedicationsPage patientId={PATIENT_ID} />)
    render(<OfficeVisitsPage patientId={PATIENT_ID} />)
    render(<ProceduresPage patientId={PATIENT_ID} />)
    expect(document.body).toBeTruthy()
  })

  it('renders condition actions including GenAI import handoff', async () => {
    const onDrill = jest.fn()
    render(<ConditionsPage patientId={PATIENT_ID} onDrill={onDrill} />)

    await waitFor(() => expect(screen.getByRole('button', { name: /add condition/i })).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: /import via genai/i }))
    expect(onDrill).toHaveBeenCalledWith({ id: 'documents' })
  })

  it('renders procedure manual entry with import guidance', async () => {
    render(<ProceduresPage patientId={PATIENT_ID} />)

    await waitFor(() => expect(screen.getByRole('button', { name: /add procedure/i })).toBeInTheDocument())
    expect(screen.getByText(/CCDA or FHIR record imports/i)).toBeInTheDocument()
  })

  it('renders immunization actions including GenAI import handoff', async () => {
    const onDrill = jest.fn()
    render(<ImmunizationsPage patientId={PATIENT_ID} onDrill={onDrill} />)

    await waitFor(() => expect(screen.getByRole('button', { name: /add immunization/i })).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: /import via genai/i }))
    expect(onDrill).toHaveBeenCalledWith({ id: 'documents' })
  })

  it('renders allergy manual entry with import guidance', async () => {
    render(<AllergiesPage patientId={PATIENT_ID} />)

    await waitFor(() => expect(screen.getByRole('button', { name: /add allergy/i })).toBeInTheDocument())
    expect(screen.getByText(/extracted as part of office-visit review/i)).toBeInTheDocument()
  })

  it('calls onDrill when an imaging study row is clicked', async () => {
    mockGet.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}`) return { patient: makePatient() }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/studies`) {
        return { studies: [makeDicomStudy()] }
      }
      return {}
    })

    const onDrill = jest.fn()
    render(<ImagingPage patientId={PATIENT_ID} onDrill={onDrill} />)

    // Click on the study title — the click bubbles up to the card's onClick handler
    const studyTitle = await screen.findByText('Cardiac CT')
    fireEvent.click(studyTitle)

    expect(onDrill).toHaveBeenCalledWith({ id: 'imaging-study-detail', instance: '7001' })
  })

  it('Viewer button does not trigger onDrill when study row is clicked via Viewer button', async () => {
    mockGet.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}`) return { patient: makePatient() }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/studies`) {
        return { studies: [makeDicomStudy()] }
      }
      return {}
    })

    const openSpy = jest.fn()
    const originalOpen = window.open
    window.open = openSpy as unknown as typeof window.open

    const onDrill = jest.fn()
    try {
      render(<ImagingPage patientId={PATIENT_ID} onDrill={onDrill} />)

      fireEvent.click(await screen.findByRole('button', { name: /viewer/i }))

      expect(openSpy).toHaveBeenCalled()
      expect(onDrill).not.toHaveBeenCalled()
    } finally {
      window.open = originalOpen
    }
  })
})

function makeDicomUpload(status: string, overrides: Record<string, unknown> = {}) {
  return {
    id: 501,
    patient_id: PATIENT_ID,
    uploaded_by_user_id: 2,
    status,
    original_root_name: 'CARDIAC_CT',
    total_files: 1,
    stored_files: status === 'pending' ? 0 : 1,
    skipped_files: 0,
    total_bytes: 5,
    stored_bytes: status === 'pending' ? 0 : 5,
    manifest_json: null,
    skipped_files_json: [],
    error_message: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

function makeDicomStudy(overrides: Partial<PhrDicomStudy> = {}): PhrDicomStudy {
  return {
    id: 7001,
    patient_id: PATIENT_ID,
    upload_id: 501,
    study_instance_uid: '1.2.840.113619.2.55.3.604688437.20260517.1',
    study_date: '2026-05-17',
    study_time: null,
    accession_number: null,
    description: 'Cardiac CT',
    modalities: 'CT',
    series_count: 1,
    instance_count: 1,
    file_size_bytes: 2 * 1024 * 1024,
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

function makeSignedDicomUploadBatch(headers: Record<string, string> | [] = {
  'Content-Type': 'application/dicom',
  'x-amz-meta-upload': 'dicom',
}) {
  return {
    uploads: [makeSignedDicomUpload('0', 'CARDIAC_CT/IM0001', headers)],
  }
}

function makeSignedDicomUpload(clientId: string, relativePath: string, headers: Record<string, string> | []) {
  return {
    client_id: clientId,
    upload_url: `https://r2.example.test/signed-put/${clientId}`,
    headers,
    r2_key: `phr/dicom/patients/1/uploads/upload-uuid/${relativePath}`,
    relative_path: relativePath,
    expires_in: 900,
  }
}

function makeDicomUploadFileResponse(
  relativePath = 'CARDIAC_CT/IM0001',
  overrides: Partial<{ stored: boolean, skipped_reason: string | null, study_id: number | null }> = {},
) {
  return {
    result: {
      stored: true,
      skipped_reason: null,
      relative_path: relativePath,
      study_id: 7001,
      ...overrides,
    },
    upload: makeDicomUpload('pending'),
  }
}

class MockUploadXMLHttpRequest {
  static instances: MockUploadXMLHttpRequest[] = []

  static status = 200

  static statusText = 'OK'

  static responseText = ''

  static reset(): void {
    MockUploadXMLHttpRequest.instances = []
    MockUploadXMLHttpRequest.status = 200
    MockUploadXMLHttpRequest.statusText = 'OK'
    MockUploadXMLHttpRequest.responseText = ''
  }

  upload = new MockUploadEventTarget()

  status = MockUploadXMLHttpRequest.status

  statusText = MockUploadXMLHttpRequest.statusText

  responseText = MockUploadXMLHttpRequest.responseText

  withCredentials = false

  readonly requestHeaders: Record<string, string> = {}

  private readonly listeners = new MockUploadEventTarget()

  constructor() {
    MockUploadXMLHttpRequest.instances.push(this)
  }

  open(): void {}

  setRequestHeader(name: string, value: string): void {
    this.requestHeaders[name] = value
  }

  addEventListener(type: string, listener: () => void): void {
    this.listeners.addEventListener(type, listener)
  }

  send(): void {
    queueMicrotask(() => this.listeners.dispatch('load'))
  }

  abort(): void {
    this.listeners.dispatch('abort')
  }
}

class MockUploadEventTarget {
  private readonly listeners = new Map<string, Array<(event?: ProgressEvent) => void>>()

  addEventListener(type: string, listener: (event?: ProgressEvent) => void): void {
    this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener])
  }

  dispatch(type: string): void {
    for (const listener of this.listeners.get(type) ?? []) {
      listener()
    }
  }
}
