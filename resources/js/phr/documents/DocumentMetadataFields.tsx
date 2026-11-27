import type { ReactElement } from 'react'

import { Input } from '@/components/ui/input'
import { DOCUMENT_TYPE_LABELS, DOCUMENT_TYPE_OPTIONS, documentTypeLabel, isKnownDocumentType } from '@/phr/documents/documentType'
import type { PhrDocument, PhrDocumentMetadataFormData } from '@/phr/types'

export type DocumentMetadataForm = Omit<PhrDocumentMetadataFormData, 'document_type'> & { document_type: string }

export function documentMetadataForm(document: PhrDocument): DocumentMetadataForm {
  return { title: document.title ?? '', document_type: document.document_type,
    observed_at: document.observed_at?.replace(' ', 'T').slice(0, 16) ?? '', summary: document.summary ?? '', tags: document.tags }
}

/** The same metadata fields in the list's editor and the document's own page. */
export function DocumentMetadataFields({ form, onChange, disabled = false }: {
  form: DocumentMetadataForm
  onChange: (form: DocumentMetadataForm) => void
  disabled?: boolean
}): ReactElement {
  return (
    <fieldset disabled={disabled} className="grid min-w-0 gap-3">
      <label className="grid gap-1 text-sm font-medium">Title<Input value={form.title ?? ''} onChange={(event) => onChange({ ...form, title: event.target.value })} /></label>
      <label className="grid gap-1 text-sm font-medium">Type
        <select className="h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm" value={form.document_type} onChange={(event) => onChange({ ...form, document_type: event.target.value })}>
          {!isKnownDocumentType(form.document_type) && <option value={form.document_type}>{documentTypeLabel(form.document_type)}</option>}
          {DOCUMENT_TYPE_OPTIONS.map((type) => <option key={type} value={type}>{DOCUMENT_TYPE_LABELS[type]}</option>)}
        </select>
      </label>
      <label className="grid gap-1 text-sm font-medium">Observed<Input type="datetime-local" value={form.observed_at ?? ''} onChange={(event) => onChange({ ...form, observed_at: event.target.value })} /></label>
      <label className="grid gap-1 text-sm font-medium">Tags (comma-separated)<Input value={form.tags.join(', ')} onChange={(event) => onChange({ ...form, tags: splitDocumentTags(event.target.value) })} /></label>
      <label className="grid gap-1 text-sm font-medium">Summary
        <textarea className="min-h-24 w-full min-w-0 rounded-md border border-input bg-background px-3 py-2 text-sm" value={form.summary ?? ''} onChange={(event) => onChange({ ...form, summary: event.target.value })} />
      </label>
    </fieldset>
  )
}

function splitDocumentTags(raw: string): string[] {
  const seen = new Set<string>()
  return raw.split(',').map((tag) => tag.trim()).filter((tag) => {
    const key = tag.toLowerCase()
    if (!tag || seen.has(key)) return false
    seen.add(key)
    return true
  })
}
