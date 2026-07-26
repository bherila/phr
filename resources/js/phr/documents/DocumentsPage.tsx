import {
  FileText,
  Grid2X2,
  Image as ImageIcon,
  List,
  Loader2,
  RefreshCw,
  Save,
  Sparkles,
  Trash2,
  Upload,
} from 'lucide-react'
import type { ChangeEvent, FormEvent, ReactNode } from 'react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fetchWrapper } from '@/fetchWrapper'
import { formatBytes } from '@/lib/utils'
import type { PhrListPageProps } from '@/phr/miller'
import { errorMessage } from '@/phr/shared'
import {
  type PhrDocument,
  type PhrDocumentMetadataFormData,
  PhrDocumentMetadataFormSchema,
  PhrDocumentSchema,
  PhrDocumentsResponseSchema,
} from '@/phr/types'

type DocumentType = PhrDocument['document_type']
type DocumentSource = NonNullable<PhrDocument['source']>
type ViewMode = 'grid' | 'list'

const DOCUMENT_TYPE_OPTIONS: DocumentType[] = [
  'lab_report',
  'office_visit_note',
  'discharge_summary',
  'imaging_report',
  'prescription',
  'insurance',
  'consent',
  'other',
]

const SOURCE_OPTIONS: DocumentSource[] = [
  'manual_upload',
  'genai_import',
  'fhir_import',
  'ccda_import',
  'mychart_zip',
]

const DOCUMENT_TYPE_LABELS: Record<DocumentType, string> = {
  lab_report: 'Lab Report',
  office_visit_note: 'Office Visit',
  discharge_summary: 'Discharge',
  imaging_report: 'Imaging',
  prescription: 'Prescription',
  insurance: 'Insurance',
  consent: 'Consent',
  other: 'Other',
}

const SOURCE_LABELS: Record<DocumentSource, string> = {
  manual_upload: 'Manual Upload',
  genai_import: 'GenAI Import',
  fhir_import: 'FHIR Import',
  ccda_import: 'CCDA Import',
  mychart_zip: 'MyChart ZIP',
}

interface UploadFormState {
  title: string
  document_type: DocumentType
  observed_at: string
  summary: string
  tags: string
}

interface FilterState {
  type: 'all' | DocumentType
  source: 'all' | DocumentSource
  tag: string
  date_from: string
  date_to: string
}

const emptyUploadForm: UploadFormState = {
  title: '',
  document_type: 'other',
  observed_at: '',
  summary: '',
  tags: '',
}

const emptyFilters: FilterState = {
  type: 'all',
  source: 'all',
  tag: '',
  date_from: '',
  date_to: '',
}

export default function DocumentsPage({ patientId, onDrill }: PhrListPageProps) {
  const fileInputRef = useRef<HTMLInputElement | null>(null)
  const [documents, setDocuments] = useState<PhrDocument[]>([])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [canManage, setCanManage] = useState(false)
  const [viewMode, setViewMode] = useState<ViewMode>('grid')
  const [filters, setFilters] = useState<FilterState>(emptyFilters)
  const [uploadForm, setUploadForm] = useState<UploadFormState>(emptyUploadForm)
  const [uploadFile, setUploadFile] = useState<File | null>(null)
  const [editForm, setEditForm] = useState<PhrDocumentMetadataFormData | null>(null)
  const [busy, setBusy] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [processingId, setProcessingId] = useState<number | null>(null)
  const [deletingId, setDeletingId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  const selectedDocument = useMemo(
    () => documents.find((document) => document.id === selectedId) ?? documents[0] ?? null,
    [documents, selectedId],
  )

  const loadDocuments = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      const params = new URLSearchParams()
      if (filters.type !== 'all') params.set('type', filters.type)
      if (filters.source !== 'all') params.set('source', filters.source)
      if (filters.tag.trim() !== '') params.set('tag', filters.tag.trim())
      if (filters.date_from !== '') params.set('date_from', filters.date_from)
      if (filters.date_to !== '') params.set('date_to', filters.date_to)

      const query = params.toString()
      const raw = await fetchWrapper.get(`/api/phr/patients/${patientId}/documents${query ? `?${query}` : ''}`)
      const parsed = PhrDocumentsResponseSchema.parse(raw)
      setDocuments(parsed.documents)
      setCanManage(parsed.can_manage)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }, [filters, patientId])

  useEffect(() => {
    void loadDocuments()
  }, [loadDocuments])

  useEffect(() => {
    if (!selectedDocument) {
      setEditForm(null)
      return
    }

    setEditForm({
      title: selectedDocument.title ?? '',
      document_type: selectedDocument.document_type,
      observed_at: toInputDateTime(selectedDocument.observed_at),
      summary: selectedDocument.summary ?? '',
      tags: selectedDocument.tags,
    })
  }, [selectedDocument])

  async function handleUpload(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    if (!uploadFile) {
      setError('Choose a document file.')
      return
    }

    setUploading(true)
    setError(null)
    try {
      const formData = new FormData()
      formData.append('file', uploadFile)
      formData.append('document_type', uploadForm.document_type)
      appendIfPresent(formData, 'title', uploadForm.title)
      appendIfPresent(formData, 'observed_at', uploadForm.observed_at)
      appendIfPresent(formData, 'summary', uploadForm.summary)
      for (const tag of splitTags(uploadForm.tags)) {
        formData.append('tags[]', tag)
      }

      const raw = await fetchWrapper.post(`/api/phr/patients/${patientId}/documents`, formData)
      const response = PhrDocumentSchema.parse((raw as { document: unknown }).document)
      setDocuments((current) => [response, ...current])
      setSelectedId(response.id)
      setUploadForm(emptyUploadForm)
      setUploadFile(null)
      if (fileInputRef.current) {
        fileInputRef.current.value = ''
      }
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setUploading(false)
    }
  }

  async function saveMetadata(): Promise<void> {
    if (!selectedDocument || !editForm) return

    const parsed = PhrDocumentMetadataFormSchema.safeParse(editForm)
    if (!parsed.success) {
      setError(parsed.error.issues[0]?.message ?? 'Invalid document metadata.')
      return
    }

    setSaving(true)
    setError(null)
    try {
      const payload = {
        ...parsed.data,
        title: parsed.data.title === '' ? null : parsed.data.title,
        observed_at: parsed.data.observed_at === '' ? null : parsed.data.observed_at,
        summary: parsed.data.summary === '' ? null : parsed.data.summary,
      }
      const raw = await fetchWrapper.patch(`/api/phr/patients/${patientId}/documents/${selectedDocument.id}`, payload)
      const updated = PhrDocumentSchema.parse((raw as { document: unknown }).document)
      setDocuments((current) => current.map((document) => (document.id === updated.id ? updated : document)))
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setSaving(false)
    }
  }

  async function processWithGenAi(document: PhrDocument): Promise<void> {
    setProcessingId(document.id)
    setError(null)
    try {
      const raw = await fetchWrapper.post(`/api/phr/patients/${patientId}/documents/${document.id}/process`, {})
      const updated = PhrDocumentSchema.parse((raw as { document: unknown }).document)
      setDocuments((current) => current.map((candidate) => (candidate.id === updated.id ? updated : candidate)))
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setProcessingId(null)
    }
  }

  async function deleteDocument(document: PhrDocument): Promise<void> {
    setDeletingId(document.id)
    setError(null)
    try {
      await fetchWrapper.delete(`/api/phr/patients/${patientId}/documents/${document.id}`, {})
      setDocuments((current) => current.filter((candidate) => candidate.id !== document.id))
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setDeletingId(null)
    }
  }

  function updateUploadFile(event: ChangeEvent<HTMLInputElement>): void {
    setUploadFile(event.target.files?.[0] ?? null)
  }

  function selectDocument(document: PhrDocument): void {
    setSelectedId(document.id)
    onDrill?.({ id: 'document-viewer', instance: String(document.id) })
  }

  return (
    <div className="@container grid min-w-0 gap-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
            <FileText className="size-6 text-primary" />
            Documents
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {documents.length} document{documents.length === 1 ? '' : 's'}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" size="sm" onClick={() => setViewMode('grid')} aria-pressed={viewMode === 'grid'} aria-label="Grid view">
            <Grid2X2 className="size-4" />
          </Button>
          <Button type="button" variant="outline" size="sm" onClick={() => setViewMode('list')} aria-pressed={viewMode === 'list'} aria-label="List view">
            <List className="size-4" />
          </Button>
          <Button type="button" variant="outline" size="sm" onClick={() => void loadDocuments()} disabled={busy}>
            <RefreshCw className="size-4" />
            Refresh
          </Button>
        </div>
      </div>

      {error && (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      <div
        className="grid min-w-0 gap-4 @min-[72rem]:grid-cols-[260px_minmax(0,1fr)_minmax(360px,420px)]"
        data-testid="documents-pane-layout"
      >
        <aside className="grid min-w-0 content-start gap-4 rounded-lg border border-border bg-card p-4">
          <div className="grid gap-3">
            <h2 className="text-sm font-semibold text-card-foreground">Filters</h2>
            <LabeledSelect
              label="Type"
              value={filters.type}
              onChange={(value) => setFilters((current) => ({ ...current, type: value as FilterState['type'] }))}
            >
              <option value="all">All Types</option>
              {DOCUMENT_TYPE_OPTIONS.map((type) => (
                <option key={type} value={type}>{DOCUMENT_TYPE_LABELS[type]}</option>
              ))}
            </LabeledSelect>
            <LabeledSelect
              label="Source"
              value={filters.source}
              onChange={(value) => setFilters((current) => ({ ...current, source: value as FilterState['source'] }))}
            >
              <option value="all">All Sources</option>
              {SOURCE_OPTIONS.map((source) => (
                <option key={source} value={source}>{SOURCE_LABELS[source]}</option>
              ))}
            </LabeledSelect>
            <LabeledInput label="Tag" value={filters.tag} onChange={(value) => setFilters((current) => ({ ...current, tag: value }))} />
            <LabeledInput label="From" type="date" value={filters.date_from} onChange={(value) => setFilters((current) => ({ ...current, date_from: value }))} />
            <LabeledInput label="To" type="date" value={filters.date_to} onChange={(value) => setFilters((current) => ({ ...current, date_to: value }))} />
            <Button type="button" variant="ghost" size="sm" onClick={() => setFilters(emptyFilters)}>
              Clear
            </Button>
          </div>

          {canManage && (
            <form className="grid gap-3 border-t border-border pt-4" onSubmit={(event) => void handleUpload(event)}>
              <h2 className="text-sm font-semibold text-card-foreground">Upload</h2>
              <Input ref={fileInputRef} type="file" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.tif,.tiff,.txt,.html,.htm" onChange={updateUploadFile} />
              <LabeledSelect
                label="Type"
                value={uploadForm.document_type}
                onChange={(value) => setUploadForm((current) => ({ ...current, document_type: value as DocumentType }))}
              >
                {DOCUMENT_TYPE_OPTIONS.map((type) => (
                  <option key={type} value={type}>{DOCUMENT_TYPE_LABELS[type]}</option>
                ))}
              </LabeledSelect>
              <LabeledInput label="Title" value={uploadForm.title} onChange={(value) => setUploadForm((current) => ({ ...current, title: value }))} />
              <LabeledInput label="Observed" type="datetime-local" value={uploadForm.observed_at} onChange={(value) => setUploadForm((current) => ({ ...current, observed_at: value }))} />
              <LabeledInput label="Tags" value={uploadForm.tags} onChange={(value) => setUploadForm((current) => ({ ...current, tags: value }))} />
              <label className="grid gap-1 text-sm font-medium text-foreground">
                Summary
                <textarea
                  className="min-h-20 w-full min-w-0 rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground"
                  value={uploadForm.summary}
                  onChange={(event) => setUploadForm((current) => ({ ...current, summary: event.target.value }))}
                />
              </label>
              <Button type="submit" disabled={uploading || !uploadFile}>
                {uploading ? <Loader2 className="size-4 animate-spin" /> : <Upload className="size-4" />}
                Upload
              </Button>
            </form>
          )}
        </aside>

        <main className="min-w-0">
          {busy && documents.length === 0 && <p className="text-sm text-muted-foreground">Loading...</p>}

          {!busy && documents.length === 0 && (
            <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
              No documents match the current filters.
            </div>
          )}

          {documents.length > 0 && viewMode === 'grid' && (
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">
              {documents.map((document) => (
                <DocumentCard
                  key={document.id}
                  document={document}
                  selected={document.id === selectedDocument?.id}
                  onSelect={() => selectDocument(document)}
                />
              ))}
            </div>
          )}

          {documents.length > 0 && viewMode === 'list' && (
            <div className="overflow-hidden rounded-lg border border-border">
              <div className="grid grid-cols-[minmax(0,1fr)_140px_140px_110px] gap-3 border-b border-border bg-muted/40 px-3 py-2 text-xs font-medium uppercase text-muted-foreground max-lg:hidden">
                <span>Document</span>
                <span>Type</span>
                <span>Source</span>
                <span>Observed</span>
              </div>
              <div className="divide-y divide-border">
                {documents.map((document) => (
                  <button
                    key={document.id}
                    type="button"
                    className={`grid w-full gap-2 px-3 py-3 text-left hover:bg-muted/30 lg:grid-cols-[minmax(0,1fr)_140px_140px_110px] lg:items-center ${document.id === selectedDocument?.id ? 'bg-accent/50' : 'bg-background'}`}
                    onClick={() => selectDocument(document)}
                  >
                    <span className="min-w-0">
                      <span className="block truncate text-sm font-medium text-foreground">{displayTitle(document)}</span>
                      {document.summary && <span className="mt-1 block line-clamp-1 text-sm text-muted-foreground">{document.summary}</span>}
                    </span>
                    <span className="text-sm text-muted-foreground">{DOCUMENT_TYPE_LABELS[document.document_type]}</span>
                    <span className="text-sm text-muted-foreground">{document.source ? SOURCE_LABELS[document.source] : 'Unknown'}</span>
                    <span className="text-sm text-muted-foreground">{formatDate(document.observed_at)}</span>
                  </button>
                ))}
              </div>
            </div>
          )}
        </main>

        <section className="min-w-0 rounded-lg border border-border bg-card">
          {selectedDocument && editForm ? (
            <div className="grid gap-4 p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <h2 className="truncate text-lg font-semibold text-card-foreground">{displayTitle(selectedDocument)}</h2>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {[DOCUMENT_TYPE_LABELS[selectedDocument.document_type], formatBytes(selectedDocument.byte_size), formatDate(selectedDocument.observed_at)].filter(Boolean).join(' · ')}
                  </p>
                </div>
                {canManage && (
                  <Button type="button" variant="outline" size="sm" onClick={() => void processWithGenAi(selectedDocument)} disabled={processingId === selectedDocument.id}>
                    {processingId === selectedDocument.id ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />}
                    Process with GenAI
                  </Button>
                )}
              </div>

              <DocumentPreview document={selectedDocument} />

              {selectedDocument.linked_rows.length > 0 && (
                <div className="grid gap-2">
                  <h3 className="text-sm font-semibold text-card-foreground">Linked Rows</h3>
                  <div className="flex flex-wrap gap-2">
                    {selectedDocument.linked_rows.map((row) => (
                      <a key={`${row.type}-${row.id}`} href={row.href} className="rounded-md border border-border px-2 py-1 text-xs text-foreground hover:bg-muted">
                        {row.label}
                      </a>
                    ))}
                  </div>
                </div>
              )}

              <div className="grid gap-3 border-t border-border pt-4">
                <h3 className="text-sm font-semibold text-card-foreground">Metadata</h3>
                <LabeledInput label="Title" value={editForm.title ?? ''} onChange={(value) => setEditForm({ ...editForm, title: value })} />
                <LabeledSelect label="Type" value={editForm.document_type} onChange={(value) => setEditForm({ ...editForm, document_type: value as DocumentType })}>
                  {DOCUMENT_TYPE_OPTIONS.map((type) => (
                    <option key={type} value={type}>{DOCUMENT_TYPE_LABELS[type]}</option>
                  ))}
                </LabeledSelect>
                <LabeledInput label="Observed" type="datetime-local" value={editForm.observed_at ?? ''} onChange={(value) => setEditForm({ ...editForm, observed_at: value })} />
                <LabeledInput label="Tags" value={editForm.tags.join(', ')} onChange={(value) => setEditForm({ ...editForm, tags: splitTags(value) })} />
                <label className="grid gap-1 text-sm font-medium text-foreground">
                  Summary
                  <textarea
                    className="min-h-24 w-full min-w-0 rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground"
                    value={editForm.summary ?? ''}
                    onChange={(event) => setEditForm({ ...editForm, summary: event.target.value })}
                  />
                </label>
                {canManage && (
                  <div className="flex flex-wrap justify-between gap-2">
                    <Button type="button" onClick={() => void saveMetadata()} disabled={saving}>
                      {saving ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
                      Save
                    </Button>
                    <Button type="button" variant="destructive" onClick={() => void deleteDocument(selectedDocument)} disabled={deletingId === selectedDocument.id}>
                      {deletingId === selectedDocument.id ? <Loader2 className="size-4 animate-spin" /> : <Trash2 className="size-4" />}
                      Delete
                    </Button>
                  </div>
                )}
              </div>
            </div>
          ) : (
            <div className="p-6 text-sm text-muted-foreground">Select a document.</div>
          )}
        </section>
      </div>
    </div>
  )
}

interface LabeledInputProps {
  label: string
  value: string
  type?: string
  onChange: (value: string) => void
}

function LabeledInput({ label, value, type = 'text', onChange }: LabeledInputProps) {
  return (
    <label className="grid gap-1 text-sm font-medium text-foreground">
      {label}
      <Input type={type} value={value} onChange={(event) => onChange(event.target.value)} />
    </label>
  )
}

interface LabeledSelectProps {
  label: string
  value: string
  children: ReactNode
  onChange: (value: string) => void
}

function LabeledSelect({ label, value, children, onChange }: LabeledSelectProps) {
  return (
    <label className="grid gap-1 text-sm font-medium text-foreground">
      {label}
      <select
        className="h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm text-foreground"
        value={value}
        onChange={(event) => onChange(event.target.value)}
      >
        {children}
      </select>
    </label>
  )
}

interface DocumentCardProps {
  document: PhrDocument
  selected: boolean
  onSelect: () => void
}

function DocumentCard({ document, selected, onSelect }: DocumentCardProps) {
  return (
    <button
      type="button"
      className={`grid min-h-64 gap-3 rounded-lg border p-3 text-left transition hover:bg-muted/30 ${selected ? 'border-primary bg-accent/40' : 'border-border bg-card'}`}
      onClick={onSelect}
    >
      <div className="aspect-[4/3] overflow-hidden rounded-md border border-border bg-muted/40">
        <Thumbnail document={document} />
      </div>
      <div className="min-w-0">
        <p className="truncate text-sm font-semibold text-card-foreground">{displayTitle(document)}</p>
        <p className="mt-1 text-xs text-muted-foreground">
          {[DOCUMENT_TYPE_LABELS[document.document_type], formatDate(document.observed_at), formatBytes(document.byte_size)].filter(Boolean).join(' · ')}
        </p>
        {document.tags.length > 0 && (
          <div className="mt-2 flex flex-wrap gap-1">
            {document.tags.slice(0, 3).map((tag) => (
              <span key={tag} className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{tag}</span>
            ))}
          </div>
        )}
      </div>
    </button>
  )
}

interface ThumbnailProps {
  document: PhrDocument
}

function Thumbnail({ document }: ThumbnailProps) {
  if (isImage(document)) {
    return <img src={document.file_url} alt="" className="size-full object-cover" loading="lazy" />
  }

  if (isPdf(document)) {
    return (
      <iframe
        title={`${displayTitle(document)} preview`}
        src={`${document.file_url}#toolbar=0&navpanes=0&page=1`}
        className="size-full bg-background"
        tabIndex={-1}
        sandbox=""
      />
    )
  }

  return (
    <div className="flex size-full items-center justify-center text-muted-foreground">
      <FileText className="size-10" />
    </div>
  )
}

interface DocumentPreviewProps {
  document: PhrDocument
}

function DocumentPreview({ document }: DocumentPreviewProps) {
  if (isImage(document)) {
    return (
      <div className="overflow-hidden rounded-md border border-border bg-background">
        <img src={document.file_url} alt={displayTitle(document)} className="max-h-[520px] w-full object-contain" />
      </div>
    )
  }

  if (isPdf(document) || isTextLike(document)) {
    return (
      <iframe
        title="Document viewer"
        src={document.file_url}
        className="h-[520px] w-full rounded-md border border-border bg-background"
        sandbox=""
      />
    )
  }

  return (
    <a href={document.file_url} className="flex items-center justify-center gap-2 rounded-md border border-border bg-background p-8 text-sm text-foreground hover:bg-muted">
      <ImageIcon className="size-5" />
      Open file
    </a>
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
  if (!value) return ''
  return value.slice(0, 10)
}

function toInputDateTime(value: string | null): string {
  if (!value) return ''
  return value.replace(' ', 'T').slice(0, 16)
}

function splitTags(raw: string): string[] {
  const seen = new Set<string>()
  const tags: string[] = []
  for (const part of raw.split(',')) {
    const tag = part.trim()
    const key = tag.toLowerCase()
    if (tag === '' || seen.has(key)) continue
    seen.add(key)
    tags.push(tag)
  }
  return tags
}

function appendIfPresent(formData: FormData, key: string, value: string): void {
  if (value.trim() !== '') {
    formData.append(key, value)
  }
}
