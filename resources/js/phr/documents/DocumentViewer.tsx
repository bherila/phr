import { ChevronLeft, ChevronRight, Download, ExternalLink, FileText, Loader2, Minus, Plus, Sparkles } from 'lucide-react'
import { useEffect, useState } from 'react'

import PdfViewer from '@/components/phr/PdfViewer'
import { Button } from '@/components/ui/button'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { fetchWrapper } from '@/fetchWrapper'
import { formatDate as displayDate } from '@/lib/dateFormat'
import { isBrowserPreviewableImage } from '@/phr/documents/browserImagePreview'
import { documentSourceLabel } from '@/phr/documents/documentSource'
import { documentTypeLabel } from '@/phr/documents/documentType'
import { usePhrPatients } from '@/phr/patients/usePhrPatients'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { PhrRecordNotFound } from '@/phr/shell/PhrRecordNotFound'
import { type PhrDocument, PhrDocumentResponseSchema } from '@/phr/types'

interface DocumentViewerProps {
  patientId: number
  recordId: string
}

export default function DocumentViewer({ patientId, recordId }: DocumentViewerProps) {
  const [document, setDocument] = useState<PhrDocument | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let cancelled = false

    async function loadDocument(): Promise<void> {
      setLoading(true)
      setError(null)
      setNotFound(false)

      try {
        const result = await fetchPhrDetail(
          `/api/phr/patients/${patientId}/documents/${recordId}`,
          PhrDocumentResponseSchema,
        )
        if (cancelled) return
        setNotFound(result.notFound)
        setDocument(result.data?.document ?? null)
      } catch (caught) {
        if (!cancelled) {
          setDocument(null)
          setError(errorMessage(caught))
        }
      } finally {
        if (!cancelled) {
          setLoading(false)
        }
      }
    }

    void loadDocument()
    return () => {
      cancelled = true
    }
  }, [patientId, recordId])

  if (loading) {
    return <div className="p-4 text-sm text-muted-foreground">Loading document…</div>
  }

  if (notFound) {
    return <PhrRecordNotFound />
  }

  if (error) {
    return <div className="p-4 text-sm text-destructive">{error}</div>
  }

  if (!document) {
    return <PhrRecordNotFound />
  }

  return <DocumentView document={document} patientId={patientId} />
}

const ZOOM_STEP = 1.25
const MIN_SCALE = 0.5
const MAX_SCALE = 3

function DocumentView({ document, patientId }: { document: PhrDocument, patientId: number }) {
  const { patients } = usePhrPatients()
  const canManage = patients.find((patient) => patient.id === patientId)?.can_manage ?? false
  const pdf = isPdf(document)
  const zoomable = pdf || isImage(document)
  const [page, setPage] = useState(1)
  const [numPages, setNumPages] = useState<number | null>(null)
  const [scale, setScale] = useState(pdf ? 1.5 : 1)
  const [sending, setSending] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const title = displayTitle(document)

  async function sendToAi(): Promise<void> {
    setSending(true)
    setNotice(null)
    try {
      await fetchWrapper.post(`/api/phr/patients/${patientId}/documents/${document.id}/process`, {})
      setNotice('Sent to AI. Extracted records will wait for review.')
    } catch (caught) {
      setNotice(errorMessage(caught))
    } finally {
      setSending(false)
    }
  }

  const facts = [
    documentTypeLabel(document.document_type),
    documentSourceLabel(document.source),
    `Added ${formatDate(document.created_at)}`,
    [formatName(document), numPages ? `${numPages} page${numPages === 1 ? '' : 's'}` : null].filter(Boolean).join(' · '),
  ].filter(Boolean).join(' · ')

  return (
    <div className="grid gap-4">
      {/* One row: what this is, how to move through it, and what you can do with it. It wraps
          at narrow widths, putting the page and zoom controls under the title. */}
      <div role="toolbar" aria-label="Document" className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <div className="min-w-0 flex-[1_1_16rem]">
          <h1 className="truncate text-xl font-semibold text-foreground" title={title}>{title}</h1>
          <p className="truncate text-xs text-muted-foreground">{facts}</p>
        </div>
        {pdf && numPages !== null && numPages > 1 && (
          <div className="flex items-center gap-1">
            <Button type="button" variant="ghost" size="icon-sm" aria-label="Previous page" disabled={page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))}>
              <ChevronLeft className="size-4" aria-hidden="true" />
            </Button>
            <span className="text-sm tabular-nums" aria-live="polite">Page {page} of {numPages}</span>
            <Button type="button" variant="ghost" size="icon-sm" aria-label="Next page" disabled={page >= numPages} onClick={() => setPage((current) => Math.min(numPages, current + 1))}>
              <ChevronRight className="size-4" aria-hidden="true" />
            </Button>
          </div>
        )}
        {zoomable && (
          <div className="flex items-center gap-1">
            <Button type="button" variant="ghost" size="icon-sm" aria-label="Zoom out" disabled={scale <= MIN_SCALE} onClick={() => setScale((current) => Math.max(MIN_SCALE, current / ZOOM_STEP))}>
              <Minus className="size-4" aria-hidden="true" />
            </Button>
            <span className="min-w-12 text-center text-sm tabular-nums" aria-live="polite">{Math.round(scale * 100)}%</span>
            <Button type="button" variant="ghost" size="icon-sm" aria-label="Zoom in" disabled={scale >= MAX_SCALE} onClick={() => setScale((current) => Math.min(MAX_SCALE, current * ZOOM_STEP))}>
              <Plus className="size-4" aria-hidden="true" />
            </Button>
          </div>
        )}
        <div className="hidden h-6 w-px bg-border sm:block" aria-hidden="true" />
        <div className="flex items-center gap-2">
          {canManage && (
            <Button type="button" variant="outline" size="sm" disabled={sending} onClick={() => void sendToAi()}>
              {sending ? <Loader2 className="size-4 animate-spin" aria-hidden="true" /> : <Sparkles className="size-4" aria-hidden="true" />}
              Send to AI
            </Button>
          )}
          <Tooltip>
            <TooltipTrigger asChild>
              <Button asChild variant="ghost" size="icon-sm">
                <a href={document.file_url} target="_blank" rel="noopener noreferrer" aria-label="Open in new tab">
                  <ExternalLink className="size-4" aria-hidden="true" />
                </a>
              </Button>
            </TooltipTrigger>
            <TooltipContent side="bottom">Open in new tab</TooltipContent>
          </Tooltip>
          <Button asChild size="sm">
            <a href={document.file_url} download={document.original_filename ?? undefined}>
              <Download className="size-4" aria-hidden="true" />
              Download
            </a>
          </Button>
        </div>
      </div>

      {notice && <p role="status" className="text-sm text-muted-foreground">{notice}</p>}

      <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_18rem]">
        <section aria-label="Document preview" className="min-w-0">
          {renderViewer(document, { page, scale, onPdfLoad: setNumPages })}
        </section>
        <aside aria-label="About this document" className="rounded-lg border border-border bg-card p-4">
          <h2 className="text-sm font-semibold text-card-foreground">Records from this document</h2>
          {document.linked_rows.length === 0 ? (
            <p className="mt-2 text-sm text-muted-foreground">No records link to this document yet.</p>
          ) : (
            <ul className="mt-2 grid gap-1">
              {document.linked_rows.map((row) => (
                <li key={`${row.type}-${row.id}`}>
                  <a href={row.href} className="block rounded-md px-2 py-1.5 text-sm text-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    {row.label}
                  </a>
                </li>
              ))}
            </ul>
          )}
        </aside>
      </div>
    </div>
  )
}

interface ViewerState {
  page: number
  scale: number
  onPdfLoad: (numPages: number) => void
}

function renderViewer(document: PhrDocument, { page, scale, onPdfLoad }: ViewerState) {
  if (isPdf(document)) {
    return <PdfViewer url={document.file_url} page={page} scale={scale} onLoad={onPdfLoad} />
  }

  if (isImage(document)) {
    return (
      <div className="max-h-[75vh] overflow-auto rounded-md border border-border bg-background">
        <img src={document.file_url} alt={displayTitle(document)} style={{ width: `${scale * 100}%` }} className="max-w-none object-contain" />
      </div>
    )
  }

  if (isTextLike(document)) {
    return (
      <iframe
        title="Document viewer"
        src={document.file_url}
        className="h-[75vh] w-full rounded-md border border-border bg-background"
      />
    )
  }

  return (
    <div className="flex h-56 flex-col items-center justify-center gap-2 rounded-md border border-border bg-muted/30 p-6 text-center text-sm text-muted-foreground">
      <FileText className="size-5" />
      Inline preview is unavailable for this file type.
    </div>
  )
}

function formatName(document: PhrDocument): string | null {
  if (isPdf(document)) return 'PDF'
  const subtype = document.mime_type?.split('/')[1]
  return subtype ? subtype.toUpperCase() : null
}

function displayTitle(document: PhrDocument): string {
  return document.title || document.original_filename || `Document ${document.id}`
}

function isPdf(document: PhrDocument): boolean {
  return document.mime_type === 'application/pdf' || (document.original_filename ?? '').toLowerCase().endsWith('.pdf')
}

function isImage(document: PhrDocument): boolean {
  return isBrowserPreviewableImage(document.mime_type)
}

function isTextLike(document: PhrDocument): boolean {
  return ['text/plain', 'text/html'].includes(document.mime_type ?? '')
}

function formatDate(value: string | null): string {
  return displayDate(value) || 'Unknown'
}

