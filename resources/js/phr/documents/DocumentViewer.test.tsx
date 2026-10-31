import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import DocumentViewer from '@/phr/documents/DocumentViewer'
import type { PhrDocument } from '@/phr/types'

let mockPdfPages = 1
jest.mock('@/components/phr/PdfViewer', () => {
  const { useEffect } = jest.requireActual<typeof import('react')>('react')
  return {
    __esModule: true,
    default: function MockPdfViewer({ url, page, onLoad }: { url: string, page: number, onLoad?: (numPages: number) => void }) {
      useEffect(() => { onLoad?.(mockPdfPages) }, [onLoad])
      return <div data-testid="pdf-viewer">pdf:{url} page:{page}</div>
    },
  }
})

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

function respondWith(document: PhrDocument): void {
  mockFetch.mockResolvedValue({
    ok: true,
    status: 200,
    statusText: 'OK',
    text: async () => JSON.stringify({ document }),
  })
}

beforeEach(() => {
  mockPdfPages = 1
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
  it.each([
    ['a legacy free-text source', 'onedrive_manual_review', 'onedrive_manual_review'],
    ['a source added after this build', 'some_future_import', 'some_future_import'],
    ['a known source', 'ehi_tables', 'EHI Tables Import'],
    ['no source', null, 'Unknown'],
  ])('still renders the document for %s', async (_case, source, label) => {
    mockFetch.mockResolvedValue({
      ok: true,
      status: 200,
      statusText: 'OK',
      text: async () => JSON.stringify({ document: makeDocument({ source }) }),
    })

    render(<DocumentViewer patientId={42} recordId="10" />)

    expect(await screen.findByTestId('pdf-viewer')).toBeInTheDocument()
    // The toolbar's facts line names the source between the type and the added date.
    const toolbar = screen.getByRole('toolbar', { name: 'Document' })
    expect(within(toolbar).getByText(new RegExp(`^Lab Report · ${label} · Added `))).toBeInTheDocument()
  })

  it.each([
    ['image/tiff', 'scan.tif'],
    ['image/heic', 'photo.heic'],
    ['image/heif', 'photo.heif'],
  ])('falls back to the download prompt for %s instead of a broken <img>', async (mimeType, filename) => {
    mockFetch.mockResolvedValue({
      ok: true,
      status: 200,
      statusText: 'OK',
      text: async () => JSON.stringify({
        document: makeDocument({
          mime_type: mimeType,
          original_filename: filename,
        }),
      }),
    })

    render(<DocumentViewer patientId={42} recordId="10" />)

    expect(await screen.findByText('Inline preview is unavailable for this file type.')).toBeInTheDocument()
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: /download/i })).toHaveAttribute('href', '/api/phr/patients/42/documents/10/file')
  })

  it('puts title, page and zoom controls and actions in one toolbar row for a multi-page PDF', async () => {
    mockPdfPages = 4
    respondWith(makeDocument())
    render(<DocumentViewer patientId={42} recordId="10" />)

    const toolbar = await screen.findByRole('toolbar', { name: 'Document' })
    expect(within(toolbar).getByRole('heading', { name: 'January Labs' })).toBeInTheDocument()
    expect(within(toolbar).getByText(/Lab Report · Manual Upload · Added 2026-01-15 · PDF · 4 pages/)).toBeInTheDocument()
    expect(within(toolbar).getByText('Page 1 of 4')).toBeInTheDocument()
    expect(within(toolbar).getByText('150%')).toBeInTheDocument()
    expect(within(toolbar).getByRole('link', { name: 'Open in new tab' })).toHaveAttribute('target', '_blank')
    expect(within(toolbar).getByRole('link', { name: /Download/ })).toHaveAttribute('href', '/api/phr/patients/42/documents/10/file')

    fireEvent.click(within(toolbar).getByRole('button', { name: 'Next page' }))
    expect(within(toolbar).getByText('Page 2 of 4')).toBeInTheDocument()
    expect(screen.getByTestId('pdf-viewer')).toHaveTextContent('page:2')
    fireEvent.click(within(toolbar).getByRole('button', { name: 'Zoom in' }))
    expect(within(toolbar).getByText('188%')).toBeInTheDocument()
  })

  it('shows zoom but no page controls for images, and neither for HTML', async () => {
    respondWith(makeDocument({ mime_type: 'image/png', original_filename: 'scan.png' }))
    const { unmount } = render(<DocumentViewer patientId={42} recordId="10" />)
    const toolbar = await screen.findByRole('toolbar', { name: 'Document' })
    expect(within(toolbar).getByRole('button', { name: 'Zoom in' })).toBeInTheDocument()
    expect(within(toolbar).queryByRole('button', { name: 'Next page' })).not.toBeInTheDocument()
    unmount()

    respondWith(makeDocument({ mime_type: 'text/html', original_filename: 'note.html' }))
    render(<DocumentViewer patientId={42} recordId="10" />)
    const htmlToolbar = await screen.findByRole('toolbar', { name: 'Document' })
    expect(within(htmlToolbar).queryByRole('button', { name: 'Zoom in' })).not.toBeInTheDocument()
    expect(within(htmlToolbar).queryByRole('button', { name: 'Next page' })).not.toBeInTheDocument()
    expect(screen.getByTitle('Document viewer')).toBeInTheDocument()
  })

  it('lists the records that came from the document beside the preview', async () => {
    respondWith(makeDocument({ linked_rows: [{ type: 'lab_result', id: 3, label: 'Synthetic CBC', href: '/phr/patient/42/labs' }] }))
    render(<DocumentViewer patientId={42} recordId="10" />)

    const aside = await screen.findByRole('complementary', { name: 'About this document' })
    expect(within(aside).getByRole('link', { name: 'Synthetic CBC' })).toHaveAttribute('href', '/phr/patient/42/labs')
  })
})
