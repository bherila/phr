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
