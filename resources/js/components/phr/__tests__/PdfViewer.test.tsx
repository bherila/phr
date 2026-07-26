import '@testing-library/jest-dom'

import { render, screen, waitFor } from '@testing-library/react'
import * as pdfjsLib from 'pdfjs-dist'

import PdfViewer from '../PdfViewer'

jest.mock('pdfjs-dist/build/pdf.worker.min.mjs?url', () => '/build/assets/pdf.worker-test.mjs', { virtual: true })

jest.mock('pdfjs-dist', () => ({
  GlobalWorkerOptions: {
    workerSrc: '',
  },
  getDocument: jest.fn(),
}))

jest.mock('@/components/ui/button', () => ({
  Button: ({ children, ...props }: React.ComponentProps<'button'>) => (
    <button {...props}>{children}</button>
  ),
}))

jest.mock('@/components/ui/spinner', () => ({
  Spinner: () => <div data-testid="spinner" />,
}))

describe('PdfViewer', () => {
  beforeEach(() => {
    jest.clearAllMocks()
  })

  it('loads PDFs through the bundled pdf.js package and worker asset', async () => {
    const renderTask = {
      cancel: jest.fn(),
      promise: Promise.resolve(),
    }
    const page = {
      getViewport: jest.fn(() => ({ height: 110, width: 85 })),
      render: jest.fn(() => renderTask),
    }
    const pdf = {
      getPage: jest.fn().mockResolvedValue(page),
      numPages: 1,
    }

    jest.mocked(pdfjsLib.getDocument).mockReturnValue({
      promise: Promise.resolve(pdf),
    } as unknown as ReturnType<typeof pdfjsLib.getDocument>)

    render(<PdfViewer url="/statement.pdf" />)

    expect(screen.getByTestId('spinner')).toBeInTheDocument()

    await waitFor(() => {
      expect(pdfjsLib.getDocument).toHaveBeenCalledWith({ url: '/statement.pdf' })
    })

    await waitFor(() => {
      expect(page.render).toHaveBeenCalled()
    })

    expect(pdfjsLib.GlobalWorkerOptions.workerSrc).toBe('/build/assets/pdf.worker-test.mjs')
    expect(screen.getByText('Page 1 of 1')).toBeInTheDocument()
  })
})
