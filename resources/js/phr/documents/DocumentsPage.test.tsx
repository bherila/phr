import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import DocumentsPage from '@/phr/documents/DocumentsPage'
import type { PhrDocument } from '@/phr/types'

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

function makeDocument(overrides: Partial<PhrDocument> = {}): PhrDocument {
  return {
    id: 10,
    patient_id: 42,
    user_id: 7,
    uploaded_by_user_id: 7,
    genai_job_id: null,
    title: 'January Labs',
    document_type: 'lab_report',
    observed_at: '2026-01-15 10:30:00',
    original_filename: 'lab.pdf',
    mime_type: 'application/pdf',
    byte_size: 12,
    file_hash: 'abc123',
    summary: 'CBC and metabolic panel',
    source: 'manual_upload',
    tags: ['labs', 'mychart'],
    imported_at: '2026-01-15 10:31:00',
    created_at: '2026-01-15 10:31:00',
    updated_at: '2026-01-15 10:31:00',
    file_url: '/api/phr/patients/42/documents/10/file',
    linked_rows: [{ type: 'lab_result', id: 99, label: 'Hemoglobin', href: '/phr/patient/42/labs' }],
    ...overrides,
  }
}

beforeEach(() => {
  mockGet.mockClear()
  mockPost.mockClear()
  mockPatch.mockClear()
  mockDelete.mockClear()

  mockGet.mockResolvedValue({
    documents: [makeDocument()],
    can_manage: true,
  })
  mockPost.mockResolvedValue({
    job_id: 700,
    status: 'pending',
    document: makeDocument({ genai_job_id: 700 }),
  })
})

describe('DocumentsPage', () => {
  it('preserves and labels documents uploaded by an authorized agent', async () => {
    mockGet.mockResolvedValue({
      documents: [makeDocument({ source: 'agent_upload' })],
      can_manage: true,
    })

    render(<DocumentsPage patientId={42} />)

    expect((await screen.findAllByText('Agent Upload')).length).toBeGreaterThan(0)
  })

  it('lists a document whose source has no label, showing the stored value', async () => {
    mockGet.mockResolvedValue({
      documents: [makeDocument({ source: 'outlook_email_attachment' })],
      can_manage: true,
    })

    render(<DocumentsPage patientId={42} />)

    expect((await screen.findAllByText('January Labs')).length).toBeGreaterThan(0)
    expect(screen.queryByText(/invalid option/i)).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'List view' }))

    expect(screen.getByText('outlook_email_attachment')).toBeInTheDocument()
  })

  it('offers only the known sources as filters', async () => {
    mockGet.mockResolvedValue({
      documents: [makeDocument({ source: 'outlook_email_attachment' })],
      can_manage: true,
    })

    render(<DocumentsPage patientId={42} />)

    await screen.findAllByText('January Labs')
    const sourceFilter = screen.getAllByLabelText('Source')[0] as HTMLSelectElement
    const values = Array.from(sourceFilter.options).map((option) => option.value)
    expect(values).toEqual(['all', 'manual_upload', 'agent_upload', 'genai_import', 'fhir_import', 'ccda_import', 'mychart_zip', 'ehi_tables'])
  })

  it('lists a document whose type has no label, showing the stored value', async () => {
    mockGet.mockResolvedValue({
      documents: [makeDocument({ document_type: 'some_future_type' })],
      can_manage: true,
    })

    render(<DocumentsPage patientId={42} />)

    expect((await screen.findAllByText('January Labs')).length).toBeGreaterThan(0)
    expect(screen.queryByText(/invalid option/i)).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'List view' }))

    expect(screen.getAllByText('some_future_type').some((element) => element.tagName === 'SPAN')).toBe(true)
  })

  it('offers only the known types as filters', async () => {
    mockGet.mockResolvedValue({
      documents: [makeDocument({ document_type: 'some_future_type' })],
      can_manage: true,
    })

    render(<DocumentsPage patientId={42} />)

    await screen.findAllByText('January Labs')
    const typeFilter = screen.getAllByLabelText('Type')[0] as HTMLSelectElement
    const values = Array.from(typeFilter.options).map((option) => option.value)
    expect(values).toEqual(['all', 'lab_report', 'office_visit_note', 'clinical_questionnaire', 'patient_symptom_log', 'discharge_summary', 'imaging_report', 'prescription', 'medical_necessity_letter', 'prior_authorization', 'insurance', 'consent', 'care_correspondence', 'other'])
  })

  it('keeps an unknown stored type on a metadata save, and sends a type the user picks', async () => {
    const stored = makeDocument({ document_type: 'some_future_type' })
    mockGet.mockResolvedValue({ documents: [stored], can_manage: true })
    mockPatch.mockResolvedValue({ document: { ...stored, title: 'Renamed' } })

    render(<DocumentsPage patientId={42} />)

    await screen.findAllByText('January Labs')
    const metadata = within((await screen.findByRole('heading', { name: 'Metadata' })).parentElement!)
    expect((metadata.getByLabelText('Type') as HTMLSelectElement).value).toBe('some_future_type')

    fireEvent.change(metadata.getByLabelText('Title'), { target: { value: 'Renamed' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(mockPatch).toHaveBeenCalledTimes(1))
    const firstPayload = mockPatch.mock.calls[0]![1] as Record<string, unknown>
    expect(firstPayload.title).toBe('Renamed')
    expect(firstPayload).not.toHaveProperty('document_type')
    // The saved document comes back and resets the form; wait for that before the next edit.
    expect((await screen.findAllByText('Renamed')).length).toBeGreaterThan(0)

    fireEvent.change(metadata.getByLabelText('Type'), { target: { value: 'consent' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(mockPatch).toHaveBeenCalledTimes(2))
    expect((mockPatch.mock.calls[1]![1] as Record<string, unknown>).document_type).toBe('consent')
  })

  it('uses a container query for the internal pane layout', async () => {
    render(<DocumentsPage patientId={42} />)

    await screen.findAllByText('January Labs')

    const paneLayout = screen.getByTestId('documents-pane-layout')

    expect(paneLayout.parentElement).toHaveClass('@container')
    expect(paneLayout.className).toContain('@min-[72rem]:grid-cols-[260px_minmax(0,1fr)_minmax(360px,420px)]')
    expect(paneLayout.className).not.toContain('xl:grid-cols')
  })

  it('loads documents and refetches when filters change', async () => {
    render(<DocumentsPage patientId={42} />)

    expect((await screen.findAllByText('January Labs')).length).toBeGreaterThan(0)

    fireEvent.change(screen.getAllByLabelText('Type')[0]!, { target: { value: 'lab_report' } })

    await waitFor(() => {
      expect(mockGet).toHaveBeenLastCalledWith('/api/phr/patients/42/documents?type=lab_report')
    })
  })

  it('shows the inline viewer side panel with linked rows', async () => {
    render(<DocumentsPage patientId={42} />)

    expect(await screen.findByTitle('Document viewer')).toHaveAttribute('src', '/api/phr/patients/42/documents/10/file')
    expect(screen.getByText('Hemoglobin')).toHaveAttribute('href', '/phr/patient/42/labs')
  })

  it('sends the selected document to AI, named for where AI work goes', async () => {
    render(<DocumentsPage patientId={42} />)

    // No shell data in the test: the server-side mode's label.
    fireEvent.click(await screen.findByRole('button', { name: /process with ai/i }))

    await waitFor(() => {
      expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/42/documents/10/process', {})
    })
  })

  it('drills into the document viewer when a document row is selected', async () => {
    const onDrill = jest.fn()
    render(<DocumentsPage patientId={42} onDrill={onDrill} />)

    fireEvent.click((await screen.findAllByRole('button', { name: /january labs/i }))[0]!)

    expect(onDrill).toHaveBeenCalledWith({ id: 'document-viewer', instance: '10' })
  })
  it('previews browser-decodable images inline', async () => {
    mockGet.mockResolvedValue({
      documents: [makeDocument({ mime_type: 'image/png', original_filename: 'scan.png' })],
      can_manage: true,
    })

    render(<DocumentsPage patientId={42} />)

    const images = await screen.findAllByRole('img', { name: 'January Labs' })
    expect(images[0]).toHaveAttribute('src', '/api/phr/patients/42/documents/10/file')
  })

  it.each(['image/tiff', 'image/heic', 'image/heif'])(
    'falls back to the open-file prompt for %s instead of a broken <img>',
    async (mimeType) => {
      mockGet.mockResolvedValue({
        documents: [makeDocument({ mime_type: mimeType, original_filename: 'scan.bin' })],
        can_manage: true,
      })

      render(<DocumentsPage patientId={42} />)

      expect(await screen.findByRole('link', { name: /open file/i })).toHaveAttribute('href', '/api/phr/patients/42/documents/10/file')
      expect(screen.queryByRole('img')).not.toBeInTheDocument()
    },
  )
})
