import { DataHubResponseSchema, NativeBackupResponseSchema, NativeBackupsResponseSchema,NativeRestoreResponseSchema, NativeRestoresResponseSchema } from '../../resources/js/phr/data-hub/dataHub.ts'
import { ContractFailure, requireContract, SCHEMA_IDS } from '../../scripts/contracts/protocol.mjs'

const schemas = { DataHubResponseSchema, NativeRestoreResponseSchema, NativeRestoresResponseSchema, NativeBackupResponseSchema, NativeBackupsResponseSchema }
requireContract(JSON.stringify(Object.keys(schemas).sort()) === JSON.stringify([...SCHEMA_IDS].sort()), 'schema_registry')

// Unknown/dynamic keys are deliberately masked, even if they look like identifiers.
const publicFields = new Set([
  'backup', 'backups', 'patient_id', 'file_size_bytes', 'archive_sha256', 'counts', 'generated_at', 'created_at', 'download_url',
  'owned_patients', 'shared_patients', 'record_counts', 'storage_bytes', 'restore', 'restores', 'tables',
  'id', 'display_name', 'relationship', 'last_updated_at', 'active_share_count', 'operations', 'access_level',
  'clinical_export', 'native_backup', 'aggregate_delete', 'eligible', 'status', 'format', 'schema_version',
  'source_file_size_bytes', 'uploaded_bytes', 'chunk_size_bytes', 'target', 'artifacts', 'create', 'skip', 'block', 'bytes',
  'access_grant_count', 'restore_access_grants', 'blockers', 'plan_digest', 'confirmation_text', 'failure_category',
  'completed_at', 'expires_at', 'documents', 'original_dicom', 'ehi', 'total',
])

export function kindOf(value) {
  return value === null ? 'null' : Array.isArray(value) ? 'array' : typeof value
}

function safePath(parts) {
  let result = ''
  for (const part of parts.slice(0, 12)) {
    result += typeof part === 'number' ? '[n]' : (result === '' ? '' : '.') + (publicFields.has(part) ? part : '*')
  }
  return result || '$'
}

function valueAt(value, parts) {
  for (const part of parts) value = value !== null && typeof value === 'object' ? value[part] : undefined
  return value
}

/** Parse with production schemas, but inspect original fields for privacy rather than Zod's stripped output. */
export function inspectResponse({ scenario, value }) {
  const parsed = schemas[scenario.schema].safeParse(value)
  if (!parsed.success) {
    return parsed.error.issues.slice(0, 12).map(issue => ({
      case_id: scenario.id, schema: scenario.schema, path: safePath(issue.path),
      kind: kindOf(valueAt(value, issue.path)), code: issue.code,
    }))
  }
  if (scenario.id === 'inventory-shared-viewer' || scenario.id === 'inventory-shared-manager') {
    for (const shared of value.shared_patients) {
      for (const field of ['record_counts', 'storage_bytes']) {
        if (Object.hasOwn(shared, field)) return [{
          case_id: scenario.id, schema: scenario.schema, path: `shared_patients[n].${field}`,
          kind: kindOf(shared[field]), code: 'private_field',
        }]
      }
    }
  }
  return []
}

export function assertResponse(response) {
  const issues = inspectResponse(response)
  if (issues.length) {
    const first = issues[0]
    throw new ContractFailure(first.code, first.case_id, first.path, first.kind)
  }
}
