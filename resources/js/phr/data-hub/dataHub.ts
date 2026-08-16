import { z } from 'zod'

export const DATA_HUB_CATEGORY_KEYS = [
  'lab_results',
  'vitals',
  'office_visits',
  'medications',
  'conditions',
  'procedures',
  'immunizations',
  'allergies',
  'portal_messages',
  'negative_assertions',
  'health_logs',
  'health_log_entries',
  'respiratory_events',
  'sinus_settings',
  'sinus_enrollments',
  'documents',
  'dicom_studies',
  'dicom_series',
  'dicom_instances',
  'original_dicom_files',
] as const

export type DataHubCategoryKey = typeof DATA_HUB_CATEGORY_KEYS[number]

const operationSchema = z.object({
  eligible: z.boolean(),
  status: z.enum(['available', 'planned', 'owner_only']),
  format: z.literal('ccda').optional(),
})

const operationsSchema = z.object({
  clinical_export: operationSchema,
  native_backup: operationSchema,
  restore: operationSchema,
  aggregate_delete: operationSchema,
})

export const OwnedPatientInventorySchema = z.object({
  id: z.number().int().positive(),
  display_name: z.string().nullable(),
  relationship: z.string().nullable(),
  record_counts: z.record(z.enum(DATA_HUB_CATEGORY_KEYS), z.number().int().nonnegative()),
  storage_bytes: z.object({
    documents: z.number().int().nonnegative(),
    original_dicom: z.number().int().nonnegative(),
    total: z.number().int().nonnegative(),
  }),
  last_updated_at: z.string().nullable(),
  active_share_count: z.number().int().nonnegative(),
  operations: operationsSchema,
})

export type OwnedPatientInventory = z.infer<typeof OwnedPatientInventorySchema>

export const SharedPatientInventorySchema = z.object({
  id: z.number().int().positive(),
  display_name: z.string().nullable(),
  relationship: z.string().nullable(),
  access_level: z.enum(['viewer', 'manager', 'owner']),
  operations: operationsSchema,
})

export const DataHubResponseSchema = z.object({
  owned_patients: z.array(OwnedPatientInventorySchema),
  shared_patients: z.array(SharedPatientInventorySchema),
})

export type DataHubResponse = z.infer<typeof DataHubResponseSchema>

export const NativeBackupSchema = z.object({
  id: z.number().int().positive(),
  patient_id: z.number().int().positive(),
  format: z.literal('phr-native-v1'),
  schema_version: z.literal(1),
  status: z.enum(['pending', 'processing', 'ready', 'failed']),
  file_size_bytes: z.number().int().nonnegative().nullable(),
  archive_sha256: z.string().length(64).nullable(),
  counts: z.record(z.string(), z.number().int().nonnegative()).nullable(),
  failure_category: z.string().nullable(),
  generated_at: z.string().nullable(),
  expires_at: z.string().nullable(),
  created_at: z.string().nullable(),
  download_url: z.string().nullable(),
})

export const NativeBackupResponseSchema = z.object({ backup: NativeBackupSchema })
export const NativeBackupsResponseSchema = z.object({ backups: z.array(NativeBackupSchema) })
export type NativeBackup = z.infer<typeof NativeBackupSchema>

const NativeRestoreActionCountsSchema = z.object({
  create: z.number().int().nonnegative(),
  skip: z.number().int().nonnegative(),
  block: z.number().int().nonnegative(),
})

export const NativeRestoreSchema = z.object({
  id: z.number().int().positive(),
  format: z.literal('phr-native-v1'),
  schema_version: z.literal(1).nullable(),
  status: z.enum(['uploading', 'preview_pending', 'preview_processing', 'preview_ready', 'pending_restore', 'restore_processing', 'restore_finalizing', 'restore_failed', 'completed']),
  source_file_size_bytes: z.number().int().positive(),
  uploaded_bytes: z.number().int().nonnegative(),
  chunk_size_bytes: z.number().int().positive(),
  target: z.enum(['new_patient', 'existing_patient']).nullable(),
  tables: z.record(z.string(), NativeRestoreActionCountsSchema),
  artifacts: NativeRestoreActionCountsSchema.extend({ bytes: z.number().int().nonnegative() }),
  access_grant_count: z.number().int().nonnegative(),
  restore_access_grants: z.boolean(),
  blockers: z.array(z.string()),
  plan_digest: z.string().regex(/^[a-f0-9]{64}$/).nullable(),
  confirmation_text: z.literal('RESTORE'),
  failure_category: z.string().nullable(),
  completed_at: z.string().nullable(),
  expires_at: z.string(),
})

export const NativeRestoreResponseSchema = z.object({ restore: NativeRestoreSchema })
export const NativeRestoresResponseSchema = z.object({ restores: z.array(NativeRestoreSchema) })
export type NativeRestore = z.infer<typeof NativeRestoreSchema>

export const PatientDeletionPreviewSchema = z.object({
  patient_id: z.number().int().positive(),
  record_counts: z.record(z.string(), z.number().int().nonnegative()),
  database_row_count: z.number().int().nonnegative(),
  active_share_count: z.number().int().nonnegative(),
  artifact_count: z.number().int().nonnegative(),
  artifact_bytes: z.number().int().nonnegative(),
  blockers: z.array(z.string()),
  preview_digest: z.string().regex(/^[a-f0-9]{64}$/),
  confirmation_text: z.literal('DELETE'),
})

export const PatientDeletionSchema = z.object({
  id: z.number().int().positive(),
  patient_root_id: z.number().int().positive(),
  status: z.enum(['pending_cleanup', 'cleanup_processing', 'cleanup_failed', 'completed']),
  record_counts: z.record(z.string(), z.number().int().nonnegative()),
  active_share_count: z.number().int().nonnegative(),
  artifact_count: z.number().int().nonnegative(),
  artifact_bytes: z.number().int().nonnegative(),
  failure_category: z.string().nullable(),
  deleted_at: z.string(),
  completed_at: z.string().nullable(),
})

export const PatientDeletionPreviewResponseSchema = z.object({ deletion_preview: PatientDeletionPreviewSchema })
export const PatientDeletionResponseSchema = z.object({ deletion: PatientDeletionSchema })
export const PatientDeletionsResponseSchema = z.object({ deletions: z.array(PatientDeletionSchema) })
export type PatientDeletionPreview = z.infer<typeof PatientDeletionPreviewSchema>
export type PatientDeletion = z.infer<typeof PatientDeletionSchema>

export const DATA_HUB_CATEGORY_LABELS: Record<DataHubCategoryKey, string> = {
  lab_results: 'Lab results',
  vitals: 'Vitals',
  office_visits: 'Office visits',
  medications: 'Medications',
  conditions: 'Conditions',
  procedures: 'Procedures',
  immunizations: 'Immunizations',
  allergies: 'Allergies',
  portal_messages: 'Portal messages',
  negative_assertions: 'Negative assertions',
  health_logs: 'Health logs',
  health_log_entries: 'Health log entries',
  respiratory_events: 'Respiratory events',
  sinus_settings: 'Sinus settings',
  sinus_enrollments: 'Sinus enrollments',
  documents: 'Source documents',
  dicom_studies: 'Imaging studies',
  dicom_series: 'Imaging series',
  dicom_instances: 'DICOM instances',
  original_dicom_files: 'Original DICOM files',
}
