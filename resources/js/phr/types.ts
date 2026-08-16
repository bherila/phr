import { z } from 'zod'

const nullableString = z.string().nullable()

export const PhrConditionClinicalStatusSchema = z.enum([
  'active',
  'recurrence',
  'relapse',
  'inactive',
  'remission',
  'resolved',
])

export const PhrConditionVerificationStatusSchema = z.enum([
  'unconfirmed',
  'provisional',
  'differential',
  'confirmed',
  'refuted',
  'entered_in_error',
])

export const PhrProcedureStatusSchema = z.enum([
  'preparation',
  'in_progress',
  'completed',
  'cancelled',
  'entered_in_error',
])

export const PhrAllergyCategorySchema = z.enum(['food', 'medication', 'environment', 'biologic'])
export const PhrAllergyCriticalitySchema = z.enum(['low', 'high', 'unable_to_assess'])
export const PhrAllergyClinicalStatusSchema = z.enum(['active', 'inactive', 'resolved'])
export const PhrAllergyVerificationStatusSchema = z.enum(['unconfirmed', 'confirmed', 'refuted', 'entered_in_error'])
export const PhrSeveritySchema = z.enum(['mild', 'moderate', 'severe'])

export const PhrAccessGrantSchema = z.object({
  id: z.number(),
  user_id: z.number(),
  user_name: nullableString,
  user_email: nullableString,
  access_level: z.enum(['owner', 'manager', 'viewer']),
  granted_at: nullableString,
})

export type PhrAccessGrant = z.infer<typeof PhrAccessGrantSchema>

export const PhrPatientSchema = z.object({
  id: z.number(),
  owner_user_id: z.number(),
  display_name: nullableString,
  relationship: nullableString,
  birth_date: nullableString,
  sex_at_birth: nullableString,
  notes: nullableString,
  archived_at: nullableString,
  created_at: nullableString,
  updated_at: nullableString,
  access_level: z.enum(['owner', 'manager', 'viewer']).nullable(),
  can_manage: z.boolean(),
  can_share: z.boolean(),
  access_grants: z.array(PhrAccessGrantSchema),
})

export type PhrPatient = z.infer<typeof PhrPatientSchema>

export const PhrLabResultSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  user_id: z.number(),
  test_name: nullableString,
  collection_datetime: nullableString,
  result_datetime: nullableString,
  result_status: nullableString,
  ordering_provider: nullableString,
  resulting_lab: nullableString,
  analyte: nullableString,
  value: nullableString,
  value_numeric: nullableString,
  unit: nullableString,
  range_min: nullableString,
  range_max: nullableString,
  range_unit: nullableString,
  reference_range_text: nullableString,
  normal_value: nullableString,
  abnormal_flag: nullableString,
  message_from_provider: nullableString,
  result_comment: nullableString,
  lab_director: nullableString,
  source: nullableString,
  notes: nullableString,
  created_at: nullableString,
  updated_at: nullableString,
})

export type PhrLabResult = z.infer<typeof PhrLabResultSchema>

export const PhrVitalSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  user_id: z.number(),
  vital_name: nullableString,
  vital_date: nullableString,
  observed_at: nullableString,
  vital_value: nullableString,
  value_numeric: nullableString,
  value_numeric_secondary: nullableString,
  unit: nullableString,
  secondary_unit: nullableString,
  body_site: nullableString,
  source: nullableString,
  notes: nullableString,
  created_at: nullableString,
  updated_at: nullableString,
})

export type PhrVital = z.infer<typeof PhrVitalSchema>

export const PhrDicomStudySchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  upload_id: z.number().nullable(),
  study_instance_uid: z.string(),
  study_date: nullableString,
  study_time: nullableString,
  accession_number: nullableString,
  description: nullableString,
  modalities: nullableString,
  series_count: z.number(),
  instance_count: z.number(),
  file_size_bytes: z.number(),
  created_at: nullableString,
  updated_at: nullableString,
})

export type PhrDicomStudy = z.infer<typeof PhrDicomStudySchema>

export const PhrPatientSearchResultSchema = z.object({
  id: z.number(),
  category: z.string(),
  label: z.string(),
  description: nullableString,
  date: nullableString,
  module_id: z.string(),
})

export type PhrPatientSearchResult = z.infer<typeof PhrPatientSearchResultSchema>

export const PhrPatientSearchResponseSchema = z.object({
  results: z.array(PhrPatientSearchResultSchema),
})

export const PhrDicomSkippedFileSchema = z.object({
  path: z.string(),
  reason: z.string(),
})

export const PhrDicomUploadSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  uploaded_by_user_id: z.number(),
  status: z.string(),
  original_root_name: nullableString,
  total_files: z.number(),
  stored_files: z.number(),
  skipped_files: z.number(),
  total_bytes: z.number(),
  stored_bytes: z.number(),
  manifest_json: z.record(z.string(), z.unknown()).nullable(),
  skipped_files_json: z.array(PhrDicomSkippedFileSchema).nullable(),
  error_message: nullableString.optional(),
  created_at: nullableString,
  updated_at: nullableString,
})

export type PhrDicomUpload = z.infer<typeof PhrDicomUploadSchema>

export const PhrPatientListResponseSchema = z.object({
  patients: z.array(PhrPatientSchema),
})

export const PhrPatientResponseSchema = z.object({
  patient: PhrPatientSchema,
})

export const PhrLabResultsResponseSchema = z.object({
  lab_results: z.array(PhrLabResultSchema),
  can_manage: z.boolean().default(false),
})

export const PhrLabResultResponseSchema = z.object({
  lab_result: PhrLabResultSchema,
})

export const PhrLabPanelResultRowSchema = z.object({
  id: z.number(),
  analyte: nullableString,
  value: nullableString,
  value_numeric: nullableString,
  unit: nullableString,
  range_min: nullableString,
  range_max: nullableString,
  range_unit: nullableString,
  reference_range_text: nullableString,
  abnormal_flag: nullableString,
  result_datetime: nullableString,
  collection_datetime: nullableString,
  trend: z.enum(['up', 'down', 'flat']).nullable(),
})

export type PhrLabPanelResultRow = z.infer<typeof PhrLabPanelResultRowSchema>

export const PhrLabPanelSchema = z.object({
  id: z.number(),
  panel_name: nullableString,
  collection_datetime: nullableString,
  ordering_provider: nullableString,
  resulting_lab: nullableString,
  source: nullableString,
  source_document_id: z.number().nullable(),
  source_document_url: nullableString,
  rows: z.array(PhrLabPanelResultRowSchema),
})

export type PhrLabPanel = z.infer<typeof PhrLabPanelSchema>

export const PhrLabPanelDetailResponseSchema = z.object({
  panel: PhrLabPanelSchema,
})

export const PhrVitalsResponseSchema = z.object({
  vitals: z.array(PhrVitalSchema),
  can_manage: z.boolean().default(false),
})

export const PhrVitalResponseSchema = z.object({
  vital: PhrVitalSchema,
})

export const PhrVitalReadingDetailResponseSchema = z.object({
  vital: PhrVitalSchema,
})

export const PhrVitalTrendPointSchema = z.object({
  reading_id: z.number(),
  recorded_at: nullableString,
  value: z.number(),
})
export type PhrVitalTrendPoint = z.infer<typeof PhrVitalTrendPointSchema>

export const PhrVitalTrendResponseSchema = z.object({
  metric_key: z.string().trim().min(1),
  metric_label: z.string().trim().min(1),
  unit: nullableString,
  points: z.array(PhrVitalTrendPointSchema),
})
export type PhrVitalTrendResponse = z.infer<typeof PhrVitalTrendResponseSchema>

export const PhrDicomStudiesResponseSchema = z.object({
  studies: z.array(PhrDicomStudySchema),
})

export const PhrDicomStudyResponseSchema = z.object({
  study: PhrDicomStudySchema,
})

export const PhrDicomViewerInstanceSchema = z.object({
  metadata: z.record(z.string(), z.unknown()),
  url: z.string(),
})

export const PhrDicomViewerSeriesSchema = z.object({
  id: z.number(),
  SeriesInstanceUID: z.string(),
  SeriesNumber: z.number().nullable(),
  Modality: z.string(),
  SeriesDescription: z.string(),
  instances: z.array(PhrDicomViewerInstanceSchema),
})

export type PhrDicomViewerSeries = z.infer<typeof PhrDicomViewerSeriesSchema>

export const PhrDicomViewerStudySchema = z.object({
  StudyInstanceUID: z.string(),
  StudyDate: z.string(),
  StudyTime: z.string(),
  PatientName: z.string(),
  PatientID: z.string(),
  AccessionNumber: z.string(),
  PatientAge: z.string(),
  PatientSex: z.string(),
  StudyDescription: z.string(),
  series: z.array(PhrDicomViewerSeriesSchema),
  NumInstances: z.number(),
  Modalities: z.string(),
})

export type PhrDicomViewerStudy = z.infer<typeof PhrDicomViewerStudySchema>

export const PhrDicomViewerResponseSchema = z.object({
  studies: z.array(PhrDicomViewerStudySchema),
})

const vec3 = z.tuple([z.number(), z.number(), z.number()])

export const PhrDicomVolumeManifestInstanceSchema = z.object({
  id: z.number(),
  sop_instance_uid: z.string(),
  position: vec3,
  projection: z.number(),
  transfer_syntax_uid: z.string(),
  url: z.string(),
})
export type PhrDicomVolumeManifestInstance = z.infer<typeof PhrDicomVolumeManifestInstanceSchema>

export const PhrDicomVolumeGeometrySchema = z.object({
  rows: z.number(),
  columns: z.number(),
  pixel_spacing: z.tuple([z.number(), z.number()]),
  slice_spacing: z.number(),
  slice_count: z.number(),
  orientation: z.tuple([z.number(), z.number(), z.number(), z.number(), z.number(), z.number()]),
  origin: vec3,
  bits_allocated: z.number().nullable(),
  pixel_representation: z.number().nullable(),
  default_window: z.object({ center: z.number(), width: z.number() }).nullable(),
  rescale: z.object({ slope: z.number(), intercept: z.number() }).nullable(),
})
export type PhrDicomVolumeGeometry = z.infer<typeof PhrDicomVolumeGeometrySchema>

export const PhrDicomVolumeManifestResponseSchema = z.object({
  series: z.object({
    id: z.number(),
    series_instance_uid: z.string(),
    modality: nullableString,
    description: nullableString,
  }),
  eligible: z.boolean(),
  reasons: z.array(z.string()),
  warnings: z.array(z.string()),
  excluded_instance_count: z.number(),
  volume: PhrDicomVolumeGeometrySchema.nullable(),
  instances: z.array(PhrDicomVolumeManifestInstanceSchema),
  cache: z.object({
    available: z.boolean(),
    url: z.string().nullable(),
    pipeline_version: z.number(),
  }),
})
export type PhrDicomVolumeManifestResponse = z.infer<typeof PhrDicomVolumeManifestResponseSchema>

export const PhrDicomUploadResponseSchema = z.object({
  upload: PhrDicomUploadSchema,
  limits: z.object({
    max_file_bytes: z.number().nullable(),
    max_file_size_label: nullableString,
    direct_upload: z.boolean().optional(),
  }).optional(),
})

export const PhrDicomUploadFinalizeResponseSchema = PhrDicomUploadResponseSchema.extend({
  duplicate_upload: z.boolean().optional(),
})

export const PhrDicomUploadFileResultSchema = z.object({
  stored: z.boolean(),
  skipped_reason: z.string().nullable(),
  relative_path: z.string(),
  study_id: z.number().nullable(),
})

export type PhrDicomUploadFileResult = z.infer<typeof PhrDicomUploadFileResultSchema>

export const PhrDicomUploadFileResponseSchema = z.object({
  result: PhrDicomUploadFileResultSchema,
  upload: PhrDicomUploadSchema,
})

export type PhrDicomUploadFileResponse = z.infer<typeof PhrDicomUploadFileResponseSchema>

export const PhrAccessGrantDetailResponseSchema = z.object({
  access: PhrAccessGrantSchema,
})

export const PhrDocumentSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  user_id: z.number(),
  uploaded_by_user_id: z.number().nullable(),
  genai_job_id: z.number().nullable(),
  title: nullableString,
  document_type: z.enum(['lab_report', 'office_visit_note', 'clinical_questionnaire', 'patient_symptom_log', 'discharge_summary', 'imaging_report', 'prescription', 'medical_necessity_letter', 'prior_authorization', 'insurance', 'consent', 'care_correspondence', 'other']),
  observed_at: nullableString,
  original_filename: nullableString,
  mime_type: nullableString,
  byte_size: z.number(),
  file_hash: nullableString,
  summary: nullableString,
  source: z.enum(['manual_upload', 'genai_import', 'fhir_import', 'ccda_import', 'mychart_zip']).nullable(),
  tags: z.array(z.string()),
  imported_at: nullableString,
  created_at: nullableString,
  updated_at: nullableString,
  file_url: z.string(),
  linked_rows: z.array(z.object({
    type: z.string(),
    id: z.number(),
    label: z.string(),
    href: z.string(),
  })),
})

export type PhrDocument = z.infer<typeof PhrDocumentSchema>

export const PhrDocumentsResponseSchema = z.object({
  documents: z.array(PhrDocumentSchema),
  can_manage: z.boolean().default(false),
})

export const PhrDocumentResponseSchema = z.object({
  document: PhrDocumentSchema,
})

export const PhrDocumentMetadataFormSchema = z.object({
  title: z.string().trim().max(255).optional(),
  document_type: z.enum(['lab_report', 'office_visit_note', 'clinical_questionnaire', 'patient_symptom_log', 'discharge_summary', 'imaging_report', 'prescription', 'medical_necessity_letter', 'prior_authorization', 'insurance', 'consent', 'care_correspondence', 'other']),
  observed_at: z.string().trim().optional(),
  summary: z.string().trim().max(20000).optional(),
  tags: z.array(z.string().trim().min(1).max(50)).max(30),
})

export type PhrDocumentMetadataFormData = z.infer<typeof PhrDocumentMetadataFormSchema>

export const PhrExportSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  formats: z.array(z.string()),
  format: z.string(),
  status: z.string(),
  filename: nullableString,
  file_size_bytes: z.number().nullable(),
  error_message: nullableString,
  generated_at: nullableString,
  expires_at: nullableString,
  created_at: nullableString,
  download_url: nullableString,
})

export type PhrExport = z.infer<typeof PhrExportSchema>

export const PhrExportsResponseSchema = z.object({
  exports: z.array(PhrExportSchema),
})

export const PhrExportResponseSchema = z.object({
  export: PhrExportSchema,
})

export const PhrPatientFormSchema = z.object({
  display_name: z.string().trim().min(1).max(255),
  relationship: z.string().trim().max(50).optional(),
  birth_date: z.string().trim().optional(),
  sex_at_birth: z.string().trim().max(50).optional(),
  notes: z.string().trim().max(10000).optional(),
})

export type PhrPatientFormData = z.infer<typeof PhrPatientFormSchema>

export const PhrLabResultFormSchema = z.object({
  test_name: z.string().trim().max(255).optional(),
  analyte: z.string().trim().min(1).max(100),
  value: z.string().trim().max(255).optional(),
  value_numeric: z.string().trim().optional(),
  unit: z.string().trim().max(50).optional(),
  result_datetime: z.string().trim().optional(),
  range_min: z.string().trim().optional(),
  range_max: z.string().trim().optional(),
  abnormal_flag: z.string().trim().max(50).optional(),
  notes: z.string().trim().max(10000).optional(),
})

export type PhrLabResultFormData = z.infer<typeof PhrLabResultFormSchema>

export const PhrVitalFormSchema = z.object({
  vital_name: z.string().trim().min(1).max(255),
  vital_date: z.string().trim().optional(),
  observed_at: z.string().trim().optional(),
  vital_value: z.string().trim().max(255).optional(),
  value_numeric: z.string().trim().optional(),
  value_numeric_secondary: z.string().trim().optional(),
  unit: z.string().trim().max(50).optional(),
  secondary_unit: z.string().trim().max(50).optional(),
  body_site: z.string().trim().max(100).optional(),
  notes: z.string().trim().max(10000).optional(),
})

export type PhrVitalFormData = z.infer<typeof PhrVitalFormSchema>

// ── Office Visits ──────────────────────────────────────────────────────────────

export const PhrEobSummarySchema = z.object({
  id: z.number(),
  claim_number: nullableString,
  claim_type: z.string(),
  provider_name: nullableString,
  administrator: nullableString,
  service_start: nullableString,
  service_end: nullableString,
  processed_date: nullableString,
  source_document_id: z.number().nullable(),
  source_document_url: nullableString,
})

export type PhrEobSummary = z.infer<typeof PhrEobSummarySchema>

export const PhrEobSearchResponseSchema = z.object({
  eobs: z.array(PhrEobSummarySchema),
  can_manage: z.boolean().default(false),
})

export const PhrEobLinkResponseSchema = z.object({
  eob: PhrEobSummarySchema,
})

export const PhrOfficeVisitRelatedServiceSchema = z.object({
  id: z.number(),
  procedure_code: z.string(),
  code_type: z.string(),
  description: nullableString,
  service_start: nullableString,
  service_end: nullableString,
})

export type PhrOfficeVisitRelatedService = z.infer<typeof PhrOfficeVisitRelatedServiceSchema>

export const PhrOfficeVisitImagingStudySchema = z.object({
  id: z.number(),
  study_date: nullableString,
  description: nullableString,
  modalities: nullableString,
  accession_number: nullableString,
})

export type PhrOfficeVisitImagingStudy = z.infer<typeof PhrOfficeVisitImagingStudySchema>

export const PhrOfficeVisitImagingStudyLinkResponseSchema = z.object({
  imaging_study: PhrOfficeVisitImagingStudySchema,
})

export const PhrReviewStatusSchema = z.enum(['pending_review', 'confirmed'])
export type PhrReviewStatus = z.infer<typeof PhrReviewStatusSchema>

export const PhrOfficeVisitSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  user_id: z.number(),
  visit_date: nullableString,
  visit_started_at: nullableString,
  visit_ended_at: nullableString,
  visit_type: nullableString,
  provider_name: nullableString,
  provider_specialty: nullableString,
  facility_name: nullableString,
  chief_complaint: nullableString,
  assessment: nullableString,
  plan: nullableString,
  subjective: nullableString,
  objective: nullableString,
  icd10_codes: z.array(z.record(z.string(), z.string())).nullable(),
  cpt_codes: z.array(z.record(z.string(), z.string())).nullable(),
  review_status: PhrReviewStatusSchema,
  eobs: z.array(PhrEobSummarySchema).default([]),
  related_services: z.array(PhrOfficeVisitRelatedServiceSchema).default([]),
  imaging_studies: z.array(PhrOfficeVisitImagingStudySchema).default([]),
  created_at: nullableString,
  updated_at: nullableString,
})

export type PhrOfficeVisit = z.infer<typeof PhrOfficeVisitSchema>

export const PhrOfficeVisitsResponseSchema = z.object({
  office_visits: z.array(PhrOfficeVisitSchema),
  can_manage: z.boolean().default(false),
})

export const PhrOfficeVisitResponseSchema = z.object({
  office_visit: PhrOfficeVisitSchema,
  can_manage: z.boolean().default(false),
})

// ── Medications ───────────────────────────────────────────────────────────────

export const PhrMedicationSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  user_id: z.number(),
  name: z.string(),
  rxnorm_code: nullableString,
  dose: nullableString,
  dose_unit: nullableString,
  route: nullableString,
  frequency: nullableString,
  started_on: nullableString,
  ended_on: nullableString,
  status: z.string(),
  prescriber_name: nullableString,
  reason_for_use: nullableString,
  raw_text: nullableString,
  created_at: nullableString,
  updated_at: nullableString,
})

export type PhrMedication = z.infer<typeof PhrMedicationSchema>

export const PhrMedicationResponseSchema = z.object({
  medication: PhrMedicationSchema,
})

export const PhrMedicationsResponseSchema = z.object({
  medications: z.array(PhrMedicationSchema),
  can_manage: z.boolean().default(false),
})

// ── Conditions ────────────────────────────────────────────────────────────────

export const PhrConditionSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  user_id: z.number(),
  name: z.string(),
  icd10_code: nullableString,
  snomed_code: nullableString,
  onset_date: nullableString,
  abated_date: nullableString,
  clinical_status: z.string(),
  verification_status: z.string(),
  severity: nullableString,
  notes: nullableString,
  raw_text: nullableString,
  created_at: nullableString,
  updated_at: nullableString,
})

export type PhrCondition = z.infer<typeof PhrConditionSchema>

export const PhrConditionResponseSchema = z.object({
  condition: PhrConditionSchema,
})

export const PhrConditionsResponseSchema = z.object({
  conditions: z.array(PhrConditionSchema),
  can_manage: z.boolean().default(false),
})

export const PhrConditionFormSchema = z.object({
  name: z.string().trim().min(1).max(255),
  icd10_code: z.string().trim().max(20),
  snomed_code: z.string().trim().max(50),
  onset_date: z.string().trim(),
  abated_date: z.string().trim(),
  clinical_status: PhrConditionClinicalStatusSchema,
  verification_status: PhrConditionVerificationStatusSchema,
  severity: PhrSeveritySchema.or(z.literal('')),
  notes: z.string().trim().max(10000),
})

export type PhrConditionFormData = z.infer<typeof PhrConditionFormSchema>

// ── Procedures ────────────────────────────────────────────────────────────────

export const PhrProcedureSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  user_id: z.number(),
  name: z.string(),
  cpt_code: nullableString,
  snomed_code: nullableString,
  performed_at: nullableString,
  performed_on: nullableString,
  performer_name: nullableString,
  performer_specialty: nullableString,
  facility_name: nullableString,
  status: z.string(),
  reason: nullableString,
  outcome: nullableString,
  notes: nullableString,
  raw_text: nullableString,
  review_status: PhrReviewStatusSchema,
  eobs: z.array(PhrEobSummarySchema).default([]),
  created_at: nullableString,
  updated_at: nullableString,
})

export type PhrProcedure = z.infer<typeof PhrProcedureSchema>

export const PhrProcedureResponseSchema = z.object({
  procedure: PhrProcedureSchema,
  can_manage: z.boolean().default(false),
})

export const PhrProceduresResponseSchema = z.object({
  procedures: z.array(PhrProcedureSchema),
  can_manage: z.boolean().default(false),
})

export const PhrProcedureFormSchema = z.object({
  name: z.string().trim().min(1).max(255),
  cpt_code: z.string().trim().max(20),
  snomed_code: z.string().trim().max(50),
  performed_at: z.string().trim(),
  performed_on: z.string().trim(),
  performer_name: z.string().trim().max(255),
  performer_specialty: z.string().trim().max(100),
  facility_name: z.string().trim().max(255),
  status: PhrProcedureStatusSchema,
  reason: z.string().trim().max(10000),
  outcome: z.string().trim().max(10000),
  notes: z.string().trim().max(10000),
})

export type PhrProcedureFormData = z.infer<typeof PhrProcedureFormSchema>

// ── Immunizations ─────────────────────────────────────────────────────────────

export const PhrImmunizationSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  user_id: z.number(),
  vaccine_name: z.string(),
  cvx_code: nullableString,
  manufacturer: nullableString,
  lot_number: nullableString,
  administered_on: nullableString,
  dose_number: z.number().nullable(),
  series_doses: z.number().nullable(),
  site: nullableString,
  route: nullableString,
  administered_by: nullableString,
  facility_name: nullableString,
  notes: nullableString,
  raw_text: nullableString,
  created_at: nullableString,
  updated_at: nullableString,
})

export type PhrImmunization = z.infer<typeof PhrImmunizationSchema>

export const PhrImmunizationResponseSchema = z.object({
  immunization: PhrImmunizationSchema,
})

export const PhrImmunizationsResponseSchema = z.object({
  immunizations: z.array(PhrImmunizationSchema),
  can_manage: z.boolean().default(false),
})

export const PhrImmunizationFormSchema = z.object({
  vaccine_name: z.string().trim().min(1).max(255),
  cvx_code: z.string().trim().max(20),
  manufacturer: z.string().trim().max(100),
  lot_number: z.string().trim().max(100),
  administered_on: z.string().trim(),
  dose_number: z.string().trim(),
  series_doses: z.string().trim(),
  site: z.string().trim().max(100),
  route: z.string().trim().max(100),
  administered_by: z.string().trim().max(255),
  facility_name: z.string().trim().max(255),
  notes: z.string().trim().max(10000),
})

export type PhrImmunizationFormData = z.infer<typeof PhrImmunizationFormSchema>

// ── Allergies ─────────────────────────────────────────────────────────────────

export const PhrAllergySchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  user_id: z.number(),
  substance: z.string(),
  rxnorm_code: nullableString,
  snomed_code: nullableString,
  category: nullableString,
  criticality: nullableString,
  clinical_status: z.string(),
  verification_status: z.string(),
  reaction: nullableString,
  severity: nullableString,
  notes: nullableString,
  raw_text: nullableString,
  created_at: nullableString,
  updated_at: nullableString,
})

export type PhrAllergy = z.infer<typeof PhrAllergySchema>

export const PhrAllergyResponseSchema = z.object({
  allergy: PhrAllergySchema,
  can_manage: z.boolean().default(false),
})

export const PhrAllergiesResponseSchema = z.object({
  allergies: z.array(PhrAllergySchema),
  can_manage: z.boolean().default(false),
})

export const PhrAllergyFormSchema = z.object({
  substance: z.string().trim().min(1).max(255),
  rxnorm_code: z.string().trim().max(50),
  snomed_code: z.string().trim().max(50),
  category: PhrAllergyCategorySchema.or(z.literal('')),
  criticality: PhrAllergyCriticalitySchema.or(z.literal('')),
  clinical_status: PhrAllergyClinicalStatusSchema,
  verification_status: PhrAllergyVerificationStatusSchema,
  reaction: z.string().trim().max(255),
  severity: PhrSeveritySchema.or(z.literal('')),
  notes: z.string().trim().max(10000),
})

export type PhrAllergyFormData = z.infer<typeof PhrAllergyFormSchema>
