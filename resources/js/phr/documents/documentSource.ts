import { type PhrDocumentSource, PhrDocumentSourceSchema } from '@/phr/types'

/** Labels for the sources the server writes today (PhrDocument::SOURCES). */
export const DOCUMENT_SOURCE_LABELS: Record<PhrDocumentSource, string> = {
  manual_upload: 'Manual Upload',
  agent_upload: 'Agent Upload',
  genai_import: 'GenAI Import',
  fhir_import: 'FHIR Import',
  ccda_import: 'CCDA Import',
  mychart_zip: 'MyChart ZIP',
  ehi_tables: 'EHI Tables Import',
}

export function isKnownDocumentSource(source: string): source is PhrDocumentSource {
  return PhrDocumentSourceSchema.safeParse(source).success
}

/**
 * A document's source for display. A value this build has no label for (a
 * legacy row, or a source added on the server later) shows as stored rather
 * than failing the document.
 */
export function documentSourceLabel(source: string | null): string {
  if (source === null || source.trim() === '') return 'Unknown'

  return isKnownDocumentSource(source) ? DOCUMENT_SOURCE_LABELS[source] : source
}
