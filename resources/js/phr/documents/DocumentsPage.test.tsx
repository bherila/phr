import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

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

  it('fires the Process with GenAI action for the selected document', async () => {
    render(<DocumentsPage patientId={42} />)

    fireEvent.click(await screen.findByRole('button', { name: /process with genai/i }))

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
})
