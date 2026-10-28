import type { EhiPackage, EhiProposal, EhiUpload } from '../ehiApi'

export const EHI_PATIENT_ID = 7

export function makeEhiUpload(overrides: Partial<EhiUpload> = {}): EhiUpload {
  return {
    id: 5,
    patient_id: EHI_PATIENT_ID,
    status: 'uploading',
    failure_reason: null,
    transport: 'chunks',
    file_size_bytes: 10,
    uploaded_bytes: 0,
    chunk_size_bytes: 4,
    package_id: null,
    replayed: null,
    resumed: null,
    repaired: null,
    expires_at: '2026-10-30T00:00:00+00:00',
    completed_at: null,
    created_at: '2026-10-28T00:00:00+00:00',
    ...overrides,
  }
}

export function makeEhiPackage(overrides: Partial<EhiPackage> = {}): EhiPackage {
  return {
    id: 9,
    patient_id: EHI_PATIENT_ID,
    preservation_status: 'preserved',
    accounting_status: 'accounted',
    clinical_status: 'not_started',
    binding_status: 'unbound',
    failure_category: null,
    source: null,
    source_patient_key: '****1234',
    intake_count: 1,
    last_intake_at: null,
    created_at: '2026-10-28T00:00:00+00:00',
    intake_in_progress: false,
    projection_in_progress: false,
    projection_interrupted: false,
    accounting: {
      members: 12,
      members_by_kind: {},
      tables: 8,
      tables_by_state: { parsed: 6, empty: 2 },
      source_rows: 140,
      malformed_rows: 0,
      dispositions: { retained_unmapped: 100, out_of_scope: 40 },
      findings_by_code: {},
      unreconciled_tables: 0,
    },
    projection: null,
    preview: { state: 'not_started', generation: null },
    proposals: { by_status: {}, pending: {}, pending_unreviewable: 0 },
    preview_run: null,
    ...overrides,
  }
}

export function makeEhiProposal(overrides: Partial<EhiProposal> = {}): EhiProposal {
  return {
    id: 30,
    package_id: 9,
    mapping: 'immunization',
    mapping_version: 'immunization-1',
    kind: 'create',
    status: 'pending',
    reviewable: true,
    allowed_decisions: ['accept', 'link_existing', 'reject', 'retain_evidence'],
    target: null,
    candidate_ids: [],
    candidate_total: 0,
    candidates_truncated: false,
    proposed: { vaccine_name: 'Synthetic Vaccine A', administered_on: '2024-09-20', administration_status: 'given', import_source: 'ehi:1', external_id: 'IMMUNE:601' },
    before: null,
    preview_digest: 'a'.repeat(64),
    decided_at: null,
    ...overrides,
  }
}
