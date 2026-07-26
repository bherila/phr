import { Download, ExternalLink, FileText } from 'lucide-react'
import { useEffect, useState } from 'react'

import PdfViewer from '@/components/phr/PdfViewer'
import { Button } from '@/components/ui/button'
import { PhrNotFoundColumn } from '@/phr/miller/PhrNotFoundColumn'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { type PhrDocument, PhrDocumentResponseSchema } from '@/phr/types'

interface DocumentViewerProps {
  patientId: number
  recordId: string
}

const SOURCE_LABELS: Record<NonNullable<PhrDocument['source']>, string> = {
  manual_upload: 'Manual Upload',
  genai_import: 'GenAI Import',
  fhir_import: 'FHIR Import',
  ccda_import: 'CCDA Import',
  mychart_zip: 'MyChart ZIP',
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
    return <PhrNotFoundColumn />
  }

  if (error) {
    return <div className="p-4 text-sm text-destructive">{error}</div>
  }

  if (!document) {
    return <PhrNotFoundColumn />
  }

  return (
    <div className="grid gap-4 p-4">
      <div className="grid gap-1">
        <h2 className="text-lg font-semibold text-card-foreground">{displayTitle(document)}</h2>
        <p className="text-xs text-muted-foreground">
          Kind: {formatLabel(document.document_type)} · Uploaded: {formatDate(document.created_at)} · Source: {document.source ? SOURCE_LABELS[document.source] : 'Unknown'}
        </p>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button asChild size="sm">
          <a href={document.file_url} download={document.original_filename ?? undefined}>
            <Download className="size-4" />
            Download
          </a>
        </Button>
        <Button asChild variant="outline" size="sm">
          <a href={document.file_url} target="_blank" rel="noopener noreferrer">
            <ExternalLink className="size-4" />
            Open in new tab
          </a>
        </Button>
      </div>

      {renderViewer(document)}
    </div>
  )
}

function renderViewer(document: PhrDocument) {
  if (isPdf(document)) {
    return (
      <div className="overflow-hidden rounded-md border border-border bg-background">
        <PdfViewer url={document.file_url} />
      </div>
    )
  }

  if (isImage(document)) {
    return (
      <div className="overflow-hidden rounded-md border border-border bg-background">
        <img src={document.file_url} alt={displayTitle(document)} className="max-h-[70vh] w-full object-contain" />
      </div>
    )
  }

  if (isTextLike(document)) {
    return (
      <iframe
        title="Document viewer"
        src={document.file_url}
        className="h-[70vh] w-full rounded-md border border-border bg-background"
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

function displayTitle(document: PhrDocument): string {
  return document.title || document.original_filename || `Document ${document.id}`
}

function isPdf(document: PhrDocument): boolean {
  return document.mime_type === 'application/pdf' || (document.original_filename ?? '').toLowerCase().endsWith('.pdf')
}

function isImage(document: PhrDocument): boolean {
  return document.mime_type?.startsWith('image/') ?? false
}

function isTextLike(document: PhrDocument): boolean {
  return ['text/plain', 'text/html'].includes(document.mime_type ?? '')
}

function formatDate(value: string | null): string {
  if (!value) return 'Unknown'
  return value.slice(0, 10)
}

function formatLabel(value: string): string {
  return value.replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase())
}
