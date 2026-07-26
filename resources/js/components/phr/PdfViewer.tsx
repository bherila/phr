import { ChevronFirst, ChevronLast, ChevronLeft, ChevronRight, ZoomIn } from 'lucide-react'
import type { PDFDocumentProxy, RenderTask } from 'pdfjs-dist'
import * as pdfjsLib from 'pdfjs-dist'
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { useCallback, useEffect, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl

interface PdfViewerProps {
  url: string
}

function isRenderingCancelledException(error: unknown): boolean {
  return error instanceof Error && error.name === 'RenderingCancelledException'
}

export default function PdfViewer({ url }: PdfViewerProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const [pdfDocument, setPdfDocument] = useState<PDFDocumentProxy | null>(null)
  const [pageNum, setPageNum] = useState(1)
  const [numPages, setNumPages] = useState(0)
  const [scale, setScale] = useState(1.5)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const renderTaskRef = useRef<RenderTask | null>(null)

  useEffect(() => {
    const loadPdf = async () => {
      setIsLoading(true)
      setError(null)

      try {
        const loadingTask = pdfjsLib.getDocument({ url })
        const pdfDoc = await loadingTask.promise
        setPdfDocument(pdfDoc)
        setNumPages(pdfDoc.numPages)
        setPageNum(1)
      } catch (err) {
        console.error('Error loading PDF:', err)
        setError('Failed to load PDF. Please try downloading it instead.')
      } finally {
        setIsLoading(false)
      }
    }
    loadPdf()
  }, [url])

  const renderPage = useCallback(async (num: number, currentScale: number) => {
    if (!pdfDocument || !canvasRef.current) {
      return
    }

    if (renderTaskRef.current) {
      renderTaskRef.current.cancel()
    }

    try {
      const page = await pdfDocument.getPage(num)
      const viewport = page.getViewport({ scale: currentScale })
      const canvas = canvasRef.current

      canvas.height = viewport.height
      canvas.width = viewport.width

      const renderTask = page.render({
        canvas,
        viewport,
      })
      renderTaskRef.current = renderTask
      await renderTask.promise
    } catch (err: unknown) {
      if (!isRenderingCancelledException(err)) {
        console.error('Error rendering page:', err)
      }
    }
  }, [pdfDocument])

  useEffect(() => {
    if (pdfDocument) {
      renderPage(pageNum, scale)
    }
  }, [pdfDocument, pageNum, scale, renderPage])

  const changePage = (offset: number) => {
    setPageNum((prev) => Math.min(Math.max(1, prev + offset), numPages))
  }

  const zoom = (factor: number) => {
    setScale((prev) => Math.min(Math.max(0.5, prev * factor), 3.0))
  }

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center h-[60vh]">
        <Spinner />
        <p className="mt-2 text-muted-foreground">Loading statement...</p>
      </div>
    )
  }

  if (error) {
    return (
      <div className="flex items-center justify-center h-[60vh] text-destructive italic">
        {error}
      </div>
    )
  }

  return (
    <div className="flex flex-col h-full">
      {/* Controls */}
      <div className="flex items-center justify-between p-2 bg-muted/50 border-b mb-2 rounded-t-lg sticky top-0 z-10">
        <div className="flex items-center gap-1">
          <Button variant="ghost" size="icon" onClick={() => setPageNum(1)} disabled={pageNum <= 1}>
            <ChevronFirst className="h-4 w-4" />
          </Button>
          <Button variant="ghost" size="icon" onClick={() => changePage(-1)} disabled={pageNum <= 1}>
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <span className="text-sm px-2">
            Page {pageNum} of {numPages}
          </span>
          <Button variant="ghost" size="icon" onClick={() => changePage(1)} disabled={pageNum >= numPages}>
            <ChevronRight className="h-4 w-4" />
          </Button>
          <Button variant="ghost" size="icon" onClick={() => setPageNum(numPages)} disabled={pageNum >= numPages}>
            <ChevronLast className="h-4 w-4" />
          </Button>
        </div>

        <div className="flex items-center gap-1">
          <Button variant="ghost" size="icon" onClick={() => zoom(0.8)}>
            <span className="text-lg font-bold">-</span>
          </Button>
          <div className="flex items-center gap-1 text-sm text-muted-foreground min-w-[60px] justify-center">
            <ZoomIn className="h-3 w-3" />
            {Math.round(scale * 100)}%
          </div>
          <Button variant="ghost" size="icon" onClick={() => zoom(1.2)}>
            <span className="text-lg font-bold">+</span>
          </Button>
        </div>
      </div>

      {/* Canvas container */}
      <div className="flex-1 overflow-auto flex justify-center bg-zinc-800 p-4 rounded-b-lg min-h-[60vh]">
        <div className="shadow-2xl">
          <canvas ref={canvasRef} className="max-w-full" />
        </div>
      </div>
    </div>
  )
}
