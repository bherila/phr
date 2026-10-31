import type { PDFDocumentProxy, RenderTask } from 'pdfjs-dist'
import * as pdfjsLib from 'pdfjs-dist'
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { useCallback, useEffect, useRef, useState } from 'react'

import { Spinner } from '@/components/ui/spinner'

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl

interface PdfViewerProps {
  url: string
  /** 1-based page to show. The caller owns paging and zoom so its toolbar can hold them. */
  page: number
  scale: number
  onLoad?: (numPages: number) => void
}

function isRenderingCancelledException(error: unknown): boolean {
  return error instanceof Error && error.name === 'RenderingCancelledException'
}

export default function PdfViewer({ url, page, scale, onLoad }: PdfViewerProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const [pdfDocument, setPdfDocument] = useState<PDFDocumentProxy | null>(null)
  const onLoadRef = useRef(onLoad)
  useEffect(() => { onLoadRef.current = onLoad })
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
        onLoadRef.current?.(pdfDoc.numPages)
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
      void renderPage(Math.min(Math.max(1, page), pdfDocument.numPages), scale)
    }
  }, [pdfDocument, page, scale, renderPage])

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center h-[60vh]">
        <Spinner />
        <p className="mt-2 text-muted-foreground">Loading document…</p>
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
    <div className="flex min-h-[60vh] justify-center overflow-auto rounded-md bg-zinc-800 p-4">
      <div className="shadow-2xl">
        <canvas ref={canvasRef} className="max-w-none" />
      </div>
    </div>
  )
}
