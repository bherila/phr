import '@testing-library/jest-dom'

import { render, screen, waitFor } from '@testing-library/react'

import DocumentViewer from '@/phr/documents/DocumentViewer'
import type { PhrDocument } from '@/phr/types'

jest.mock('@/components/phr/PdfViewer', () => ({
  __esModule: true,
  default: ({ url }: { url: string }) => <div data-testid="pdf-viewer">pdf:{url}</div>,
}))

const mockFetch = jest.fn()

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
    tags: ['labs'],
    imported_at: '2026-01-15 10:31:00',
    created_at: '2026-01-15 10:31:00',
    updated_at: '2026-01-15 10:31:00',
    file_url: '/api/phr/patients/42/documents/10/file',
    linked_rows: [],
    ...overrides,
  }
}

beforeEach(() => {
  mockFetch.mockReset()
  globalThis.fetch = mockFetch as unknown as typeof fetch
})

describe('DocumentViewer', () => {
  it('renders PDF documents with the bundled PDF viewer', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      status: 200,
      statusText: 'OK',
      text: async () => JSON.stringify({ document: makeDocument() }),
    })

    render(<DocumentViewer patientId={42} recordId="10" />)

    await waitFor(() => {
      expect(mockFetch).toHaveBeenCalledWith('/api/phr/patients/42/documents/10', expect.objectContaining({ method: 'GET' }))
    })

    expect(await screen.findByTestId('pdf-viewer')).toHaveTextContent('pdf:/api/phr/patients/42/documents/10/file')
  })

  it('renders image documents inline', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      status: 200,
      statusText: 'OK',
      text: async () => JSON.stringify({
        document: makeDocument({
          mime_type: 'image/png',
          original_filename: 'scan.png',
        }),
      }),
    })

    render(<DocumentViewer patientId={42} recordId="10" />)

    const image = await screen.findByRole('img', { name: 'January Labs' })
    expect(image).toHaveAttribute('src', '/api/phr/patients/42/documents/10/file')
    expect(screen.queryByTestId('pdf-viewer')).not.toBeInTheDocument()
  })
})
