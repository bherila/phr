import { type PhrDocumentType, PhrDocumentTypeSchema } from '@/phr/types'

/** Labels for the document types the server writes today (PhrDocument::DOCUMENT_TYPES). */
export const DOCUMENT_TYPE_LABELS: Record<PhrDocumentType, string> = {
  lab_report: 'Lab Report',
  office_visit_note: 'Office Visit',
  clinical_questionnaire: 'Clinical Questionnaire',
  patient_symptom_log: 'Patient Symptom Log',
  discharge_summary: 'Discharge',
  imaging_report: 'Imaging',
  prescription: 'Prescription',
  medical_necessity_letter: 'Medical Necessity Letter',
  prior_authorization: 'Prior Authorization',
  insurance: 'Insurance',
  consent: 'Consent',
  care_correspondence: 'Care Correspondence',
  other: 'Other',
}

export const DOCUMENT_TYPE_OPTIONS: PhrDocumentType[] = [...PhrDocumentTypeSchema.options]

export function isKnownDocumentType(type: string): type is PhrDocumentType {
  return PhrDocumentTypeSchema.safeParse(type).success
}

/**
 * A document's type for display. A value this build has no label for (a
 * legacy row, or a type added on the server later) shows as stored rather than
 * failing the document.
 */
export function documentTypeLabel(type: string | null): string {
  if (type === null || type.trim() === '') return 'Unknown'

  return isKnownDocumentType(type) ? DOCUMENT_TYPE_LABELS[type] : type
}
