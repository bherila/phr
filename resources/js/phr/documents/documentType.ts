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
  progress_note: 'Progress Note',
  consult_note: 'Consult Note',
  history_and_physical: 'History & Physical',
  procedure_note: 'Procedure Note',
  nursing_note: 'Nursing Note',
  advance_directive: 'Advance Directive',
  ems_report: 'EMS Report',
  clinical_image: 'Clinical Image',
  patient_education: 'Patient Education',
  other: 'Other',
}

export const DOCUMENT_TYPE_OPTIONS: PhrDocumentType[] = [...PhrDocumentTypeSchema.options]

const DOC_STATUS_LABELS: Record<string, string> = {
  preliminary: 'Preliminary',
  final: 'Final',
  amended: 'Amended',
  'entered-in-error': 'Entered in error',
}

const PATIENT_SIGNATURE_LABELS: Record<string, string> = {
  to_sign: 'Awaiting patient signature',
  signed: 'Signed by patient',
  not_applicable: 'No patient signature needed',
}

/** How a note's author, cosigner and signature read in one line, or null when the document records none. */
export function noteAuthorship(document: {
  author_name?: string | null | undefined
  author_role?: string | null | undefined
  cosigner_name?: string | null | undefined
  doc_status?: string | null | undefined
  patient_signature_status?: string | null | undefined
}): string | null {
  const parts = [
    document.author_name ? `By ${document.author_name}${document.author_role ? ` (${document.author_role})` : ''}` : null,
    document.cosigner_name ? `cosigned by ${document.cosigner_name}` : null,
    document.doc_status ? DOC_STATUS_LABELS[document.doc_status] ?? document.doc_status : null,
    document.patient_signature_status ? PATIENT_SIGNATURE_LABELS[document.patient_signature_status] ?? document.patient_signature_status : null,
  ].filter((part): part is string => part !== null)

  return parts.length === 0 ? null : parts.join(' · ')
}

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
