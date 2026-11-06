import { DataHubResponseSchema, NativeBackupResponseSchema, NativeBackupsResponseSchema,NativeRestoreResponseSchema, NativeRestoresResponseSchema } from '../../resources/js/phr/data-hub/dataHub.ts'
import { EhiPackageResponseSchema, EhiPackagesResponseSchema, EhiPendingSchema, EhiProposalsResponseSchema, EhiUploadResponseSchema, EhiUploadsResponseSchema } from '../../resources/js/phr/imports/ehi/ehiApi.ts'
import { ContractFailure, requireContract, SCHEMA_IDS } from '../../scripts/contracts/protocol.mjs'

export const schemas = { DataHubResponseSchema, NativeRestoreResponseSchema, NativeRestoresResponseSchema, NativeBackupResponseSchema, NativeBackupsResponseSchema, EhiPackageResponseSchema, EhiPackagesResponseSchema, EhiPendingSchema, EhiProposalsResponseSchema, EhiUploadResponseSchema, EhiUploadsResponseSchema }
requireContract(JSON.stringify(Object.keys(schemas).sort()) === JSON.stringify([...SCHEMA_IDS].sort()), 'schema_registry')

// Unknown/dynamic keys are deliberately masked, even if they look like identifiers.
const publicFields = new Set([
  'package', 'packages', 'upload', 'uploads', 'preview_run', 'failure_reason', 'failure_details', 'resource_limit', 'counts', 'statuses', 'findings', 'preview_digest', 'replayed', 'requested_at', 'started_at', 'preview', 'state', 'generation', 'proposals', 'pending', 'pending_unreviewable', 'source_patient_key', 'proposed', 'before', 'next_after_id',
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

const uploadFields = ['id', 'patient_id', 'status', 'failure_reason', 'format', 'transport', 'file_size_bytes', 'uploaded_bytes', 'chunk_size_bytes', 'part_size_bytes', 'package_id', 'replayed', 'resumed', 'repaired', 'import_result', 'expires_at', 'completed_at', 'created_at']
const packageFields = ['id', 'patient_id', 'preservation_status', 'accounting_status', 'clinical_status', 'binding_status', 'failure_category', 'source', 'source_patient_key', 'intake_count', 'last_intake_at', 'created_at', 'intake_in_progress', 'projection_in_progress', 'projection_interrupted', 'accounting', 'projection', 'preview', 'proposals', 'preview_run']
const runFields = ['id', 'package_id', 'status', 'failure_reason', 'failure_details', 'counts', 'statuses', 'findings', 'preview_digest', 'replayed', 'requested_at', 'started_at', 'completed_at']

function privacyIssue(scenario, value, parts) {
  return [{ case_id: scenario.id, schema: scenario.schema, path: safePath(parts), kind: kindOf(value), code: 'private_field' }]
}

/** Inspect the raw decoded objects, independently of Zod stripping extras or catching bad details. */
function ehiPrivacy({ scenario, value }) {
  if (!scenario.schema.startsWith('Ehi')) return []
  const closed = (item, keys, parts) => {
    if (!item || typeof item !== 'object' || Array.isArray(item)) return []
    const extra = Object.keys(item).find(key => !keys.includes(key))
    return extra === undefined ? [] : privacyIssue(scenario, item[extra], [...parts, extra])
  }
  let issue = []
  for (const [index, upload] of (value.uploads ?? (value.upload ? [value.upload] : [])).entries()) {
    issue = closed(upload, uploadFields, value.upload ? ['upload'] : ['uploads', index])
    if (issue.length) return issue
  }
  for (const [index, pkg] of (value.packages ?? (value.package ? [value.package] : [])).entries()) {
    const parts = value.package ? ['package'] : ['packages', index]
    issue = closed(pkg, packageFields, parts)
    if (issue.length) return issue
    if (pkg.source_patient_key !== null && pkg.source_patient_key !== '(none)' && !/^\*{4,}[\x20-\x7e]{0,4}$/.test(pkg.source_patient_key)) {
      return privacyIssue(scenario, pkg.source_patient_key, [...parts, 'source_patient_key'])
    }
    for (const [child, keys] of [['source', ['id', 'label']], ['preview', ['state', 'generation']],
      ['proposals', ['by_status', 'pending', 'pending_unreviewable', 'previously_rejected']],
      ['accounting', ['members', 'members_by_kind', 'tables', 'tables_by_state', 'source_rows', 'malformed_rows', 'dispositions', 'findings_by_code', 'unreconciled_tables']]]) {
      issue = closed(pkg[child], keys, [...parts, child])
      if (issue.length) return issue
    }
  }
  const runs = value.package ? [[value.package.preview_run, ['package', 'preview_run']]]
    : (value.packages ?? []).map((pkg, index) => [pkg.preview_run, ['packages', index, 'preview_run']])
  if (value.preview_run) runs.push([value.preview_run, ['preview_run']])
  for (const [run, parts] of runs) {
    if (!run) continue
    issue = closed(run, runFields, parts)
    if (issue.length) return issue
    const detail = run.failure_details
    if (detail !== undefined && detail !== null && (typeof detail !== 'object' || Array.isArray(detail)
      || Object.keys(detail).length !== 1 || !Object.hasOwn(detail, 'resource_limit') || !['time', 'capacity'].includes(detail.resource_limit)
      || run.status !== 'failed' || run.failure_reason !== 'resource_limit')) {
      return privacyIssue(scenario, detail, [...parts, 'failure_details'])
    }
  }
  return []
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
  return ehiPrivacy({ scenario, value })
}

export function assertResponse(response) {
  const issues = inspectResponse(response)
  if (issues.length) {
    const first = issues[0]
    throw new ContractFailure(first.code, first.case_id, first.path, first.kind)
  }
}
