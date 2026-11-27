import { ChevronLeft, ChevronRight, Download, ExternalLink, FileText, Loader2, Minus, Plus, Sparkles } from 'lucide-react'
import { useEffect, useState } from 'react'

import PdfViewer from '@/components/phr/PdfViewer'
import { Button } from '@/components/ui/button'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { fetchWrapper } from '@/fetchWrapper'
import { formatDate as displayDate } from '@/lib/dateFormat'
import { ConfirmDeleteDialog } from '@/phr/clinical/ConfirmDeleteDialog'
import { recordChanged, useRecordChanges } from '@/phr/clinical/recordChanges'
import { type PhrReviewDecision, ReviewActions } from '@/phr/clinical/review'
import { recordReviewDecision } from '@/phr/clinical/reviewDecision'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { isBrowserPreviewableImage } from '@/phr/documents/browserImagePreview'
import { DocumentMetadataFields, documentMetadataForm } from '@/phr/documents/DocumentMetadataFields'
import { documentSourceLabel } from '@/phr/documents/documentSource'
import { isKnownDocumentType } from '@/phr/documents/documentType'
import { documentTypeLabel, noteAuthorship } from '@/phr/documents/documentType'
import { isTextDocument } from '@/phr/documents/textPreview'
import { usePhrPatients } from '@/phr/patients/usePhrPatients'
import { reviewStateChanged } from '@/phr/review/reviewSummary'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { PhrRecordNotFound } from '@/phr/shell/PhrRecordNotFound'
import { type PhrDocument, PhrDocumentMetadataFormSchema, PhrDocumentResponseSchema } from '@/phr/types'

interface DocumentViewerProps {
  patientId: number
  recordId: string
}

export default function DocumentViewer(props: DocumentViewerProps) {
  return <DocumentRecord key={`${props.patientId}:${props.recordId}`} {...props} />
}

function DocumentRecord({ patientId, recordId }: DocumentViewerProps) {
  const [document, setDocument] = useState<PhrDocument | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [reload, setReload] = useState(0)
  useRecordChanges('documents', patientId, 'list', (id) => { if (id === Number(recordId)) setReload((current) => current + 1) })

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
  }, [patientId, recordId, reload])

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

  return <DocumentView document={document} patientId={patientId} onChanged={setDocument} onDeleted={() => setNotFound(true)} />
}

const ZOOM_STEP = 1.25
const MIN_SCALE = 0.5
const MAX_SCALE = 3

function DocumentView({ document, patientId, onChanged, onDeleted }: { document: PhrDocument, patientId: number, onChanged: (document: PhrDocument) => void, onDeleted: () => void }) {
  const { patients } = usePhrPatients()
  const canManage = patients.find((patient) => patient.id === patientId)?.can_manage ?? false
  const pdf = isPdf(document)
  const zoomable = pdf || isImage(document)
  const [page, setPage] = useState(1)
  const [numPages, setNumPages] = useState<number | null>(null)
  const [scale, setScale] = useState(pdf ? 1.5 : 1)
  const [sending, setSending] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState(() => documentMetadataForm(document))
  const [mutation, setMutation] = useState<'save' | 'review' | 'delete' | null>(null)
  const [mutationError, setMutationError] = useState<string | null>(null)
  const [deleting, setDeleting] = useState(false)
  const title = displayTitle(document)
  const recordUrl = `/api/phr/patients/${patientId}/documents/${document.id}`

  function changed(updated: PhrDocument): void {
    onChanged(updated)
    recordChanged({ resource: 'documents', patientId, recordId: document.id, origin: 'detail' })
  }

  async function saveMetadata(): Promise<void> {
    if (!canManage || mutation || sending) return
    const { document_type: type, ...rest } = form
    const parsed = PhrDocumentMetadataFormSchema.safeParse(isKnownDocumentType(type) ? { ...rest, document_type: type } : rest)
    if (!parsed.success) {
      setMutationError(parsed.error.issues[0]?.message ?? 'Invalid document metadata.')
      return
    }
    setMutation('save')
    setMutationError(null)
    try {
      const raw = await fetchWrapper.patch(recordUrl, { ...parsed.data, title: parsed.data.title === '' ? null : parsed.data.title,
        observed_at: parsed.data.observed_at === '' ? null : parsed.data.observed_at, summary: parsed.data.summary === '' ? null : parsed.data.summary })
      changed(PhrDocumentResponseSchema.parse(raw).document)
      setEditing(false)
    } catch (caught) {
      setMutationError(errorMessage(caught))
    } finally {
      setMutation(null)
    }
  }

  async function review(decision: PhrReviewDecision): Promise<void> {
    if (!canManage || mutation || sending) return
    setMutation('review')
    setMutationError(null)
    try {
      changed(PhrDocumentResponseSchema.parse(await recordReviewDecision(recordUrl, decision, document.version)).document)
    } catch (caught) {
      setMutationError(errorMessage(caught))
    } finally {
      setMutation(null)
    }
  }

  async function deleteDocument(): Promise<void> {
    if (!canManage || mutation || sending) return
    setMutation('delete')
    setMutationError(null)
    try {
      await fetchWrapper.delete(recordUrl, {})
      recordChanged({ resource: 'documents', patientId, recordId: document.id, origin: 'detail' })
      void reviewStateChanged(patientId)
      onDeleted()
    } catch (caught) {
      setMutationError(errorMessage(caught))
    } finally {
      setMutation(null)
    }
  }

  async function sendToAi(): Promise<void> {
    setSending(true)
    setNotice(null)
    try {
      const raw = await fetchWrapper.post(`/api/phr/patients/${patientId}/documents/${document.id}/process`, {})
      changed(PhrDocumentResponseSchema.parse(raw).document)
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
  const authorship = noteAuthorship(document)

  return (
    <div className="grid gap-4">
      {/* One row: what this is, how to move through it, and what you can do with it. It wraps
          at narrow widths, putting the page and zoom controls under the title. */}
      <div role="toolbar" aria-label="Document" className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <div className="min-w-0 flex-[1_1_16rem]">
          <div className="flex min-w-0 items-center gap-2">
            <h1 className="min-w-0 break-words [overflow-wrap:anywhere] text-xl font-semibold text-foreground" title={title}>{title}</h1>
            {reviewStatusBadge(document.review_status)}
          </div>
          <p className="break-words [overflow-wrap:anywhere] text-xs text-muted-foreground">{facts}</p>
          {authorship && <p className="break-words [overflow-wrap:anywhere] text-xs text-muted-foreground">{authorship}</p>}
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
        <div className="flex flex-wrap items-center gap-2">
          {canManage && (
            <Button type="button" variant="outline" size="sm" disabled={sending || mutation !== null} onClick={() => void sendToAi()}>
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

      {canManage && <div className="flex flex-wrap items-center gap-2">
        <ReviewActions status={document.review_status} label={title} busy={mutation !== null || sending} onReview={(decision) => void review(decision)} />
        <Button type="button" variant="outline" size="sm" disabled={mutation !== null || sending} onClick={() => { setForm(documentMetadataForm(document)); setMutationError(null); setEditing(true) }}>Edit metadata</Button>
        <Button type="button" variant="outline" size="sm" disabled={mutation !== null || sending} onClick={() => { setMutationError(null); setDeleting(true) }}>Delete document</Button>
      </div>}
      {mutationError && !deleting && <p role="alert" className="text-sm text-destructive">{mutationError}</p>}
      {editing && canManage && <form aria-label="Document metadata" className="grid gap-3 rounded-lg border border-border p-4" onSubmit={(event) => { event.preventDefault(); void saveMetadata() }}>
        <DocumentMetadataFields form={form} onChange={setForm} disabled={mutation !== null} />
        <div className="flex flex-wrap gap-2"><Button type="submit" disabled={mutation !== null}>{mutation === 'save' ? 'Saving…' : 'Save'}</Button><Button type="button" variant="outline" disabled={mutation !== null} onClick={() => setEditing(false)}>Cancel</Button></div>
      </form>}
      <ConfirmDeleteDialog open={deleting} title="Delete this document?" description={title} busy={mutation === 'delete'} error={mutationError} onConfirm={() => void deleteDocument()} onCancel={() => setDeleting(false)} />
      {notice && <p role="status" className="text-sm text-muted-foreground">{notice}</p>}

      <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_18rem]">
        <section aria-label="Document preview" className="min-w-0">
          {renderViewer(document, { page, scale, onPdfLoad: setNumPages })}
        </section>
        <aside aria-label="About this document" className="rounded-lg border border-border bg-card p-4">
          <dl className="grid min-w-0 gap-3 text-sm">
            <div><dt className="text-xs text-muted-foreground">Observed</dt><dd className="break-words">{displayDate(document.observed_at) || '—'}</dd></div>
            <div><dt className="text-xs text-muted-foreground">Summary</dt><dd className="whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{document.summary || '—'}</dd></div>
            <div><dt className="text-xs text-muted-foreground">Tags</dt><dd className="break-words [overflow-wrap:anywhere]">{document.tags.join(', ') || '—'}</dd></div>
          </dl>
          <h2 className="mt-4 text-sm font-semibold text-card-foreground">Records from this document</h2>
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
  return isTextDocument(document.mime_type)
}

function formatDate(value: string | null): string {
  return displayDate(value) || 'Unknown'
}

