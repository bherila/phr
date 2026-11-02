import { formatDate } from '@/lib/dateFormat'
import { displayUnits } from '@/lib/measureUnits'

import type { EhiDecision, EhiProposal } from './ehiApi'

/** How a proposal reads on the review screen. Pure, so the screen stays declarative. */
export interface EhiProposalView {
  domain: string
  title: string
  date: string | null
  detail: string | null
  /** Changed fields of an update, with the value in PHR now and in the export. */
  changes: { field: string, before: string, after: string }[]
  /** What a person should know before deciding, when the proposal says something beyond its fields. */
  notice: string | null
}

export type EhiReviewGroup = 'change' | 'duplicate' | 'new' | 'evidence' | 'kept'

export const REVIEW_GROUPS: { group: EhiReviewGroup, title: string, description: string }[] = [
  { group: 'change', title: 'Changed in the source', description: 'The export has a different value for a record already in PHR.' },
  { group: 'duplicate', title: 'Might already be in PHR', description: 'A record in PHR looks like the same thing, from another source.' },
  { group: 'new', title: 'New records', description: 'Not in PHR yet.' },
  { group: 'evidence', title: 'Matched records already in PHR', description: 'They share an identifier with an existing record, so accepting adds the export as its evidence. The record does not change.' },
  { group: 'kept', title: 'Kept as evidence only', description: 'Rows the export marks as deleted or that could not be linked to a record. They are kept with the export; nothing is added.' },
]

export function reviewGroupOf(proposal: EhiProposal): EhiReviewGroup {
  switch (proposal.kind) {
    case 'update':
      return 'change'
    case 'possible_duplicate':
      return 'duplicate'
    case 'corroborate':
      return 'evidence'
    case 'retain':
      return 'kept'
    default:
      return 'new'
  }
}

const DOMAINS: Record<string, string> = {
  immunization: 'Immunization',
  document: 'Document',
  lab_result: 'Lab result',
}

/** Record-page nouns for the pending banner and counts. */
export const MAPPING_NOUNS: Record<string, { one: string, many: string }> = {
  immunization: { one: 'immunization', many: 'immunizations' },
  document: { one: 'document', many: 'documents' },
  lab_result: { one: 'lab result', many: 'lab results' },
}

const FIELD_LABELS: Record<string, string> = {
  vaccine_name: 'Vaccine',
  administered_on: 'Date given',
  administration_status: 'Status',
  lot_number: 'Lot number',
  manufacturer: 'Manufacturer',
  route: 'Route',
  site: 'Site',
  dose_amount: 'Dose',
  dose_unit: 'Dose unit',
  title: 'Title',
  document_type: 'Type',
  observed_at: 'Date',
  extracted_text: 'Text',
  summary: 'Summary',
}

/** Lab fields, as an update names them. The AI review reads fieldLabel() and keeps its own wording. */
const UPDATE_FIELD_LABELS: Record<string, string> = {
  test_name: 'Test',
  analyte: 'Analyte',
  value: 'Value',
  value_comparator: 'Comparator',
  value_raw: 'Value as entered',
  unit: 'Unit',
  range_min: 'Range low',
  range_max: 'Range high',
  range_unit: 'Range unit',
  reference_range_text: 'Reference range',
  abnormal_flag: 'Flag',
  result_status: 'Status',
  result_comment: 'Comment',
  notes: 'Narrative',
  ordering_provider: 'Ordered by',
  resulting_lab: 'Lab',
  collection_datetime: 'Collected',
  result_datetime: 'Resulted',
}

/** Bookkeeping a reviewer does not decide on. */
const HIDDEN_FIELDS = new Set(['import_source', 'external_id', 'review_status', 'source', 'tags', 'mime_type', 'original_filename', 'retain_reason', 'administered_at_source', 'administered_at_precision', 'administered_at_timezone', 'cvx_code', 'dose_number', 'value_numeric', 'analyte_code', 'analyte_code_system', 'collected_at_source', 'collected_at_precision', 'collected_at_timezone', 'resulted_at_source', 'resulted_at_precision', 'resulted_at_timezone'])

export function fieldLabel(field: string): string {
  return FIELD_LABELS[field] ?? field.replaceAll('_', ' ')
}

function text(value: unknown): string | null {
  if (value === null || value === undefined || value === '') {
    return null
  }
  if (typeof value === 'string') {
    return value.length > 160 ? `${value.slice(0, 157)}…` : value
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value)
  }

  return null
}

/** A date or date-time as the source gave it, shown as a calendar date. */
export function formatSourceDate(value: unknown): string | null {
  const raw = text(value)
  if (raw === null) {
    return null
  }
  const match = /^(\d{4})-(\d{2})(?:-(\d{2}))?/.exec(raw)
  if (!match) {
    return raw
  }
  const [, year, month, day] = match
  if (day) return formatDate(raw)
  // A month with no day, as some sources give it, has no place in a day-precise format.
  return new Intl.DateTimeFormat('en-US', { year: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(Number(year), Number(month) - 1, 1)))
}

/** The record attributes of a proposal: a document mapping nests them under `attributes`. */
export function proposedAttributes(proposal: EhiProposal): Record<string, unknown> {
  const proposed = proposal.proposed ?? {}
  const nested = proposed['attributes']

  return nested !== null && typeof nested === 'object' && !Array.isArray(nested) ? nested as Record<string, unknown> : proposed
}

/** A lab value as a reader should see it: an inequality stated beside it is part of it. */
function labValueText(fields: Record<string, unknown>): string | null {
  const value = text(fields['value'])
  const comparator = text(fields['value_comparator'])

  return value !== null && comparator !== null && !value.startsWith(comparator) ? `${comparator}${value}` : value
}

function changesOf(proposal: EhiProposal, attributes: Record<string, unknown>): EhiProposalView['changes'] {
  if (proposal.kind !== 'update') {
    return []
  }
  const before = proposal.before ?? {}
  const declared = proposal.proposed?.['changed']
  const fields = Array.isArray(declared)
    ? declared.map(String)
    : Object.keys(attributes).filter((field) => !HIDDEN_FIELDS.has(field) && JSON.stringify(attributes[field]) !== JSON.stringify(before[field]))

  return fields.filter((field) => !HIDDEN_FIELDS.has(field)).map((field) => ({
    field: UPDATE_FIELD_LABELS[field] ?? fieldLabel(field),
    before: displayUnits(field === 'value' ? labValueText(before) : text(before[field])) ?? 'Not recorded',
    after: displayUnits(field === 'value' ? labValueText(attributes) : text(attributes[field])) ?? 'Not recorded',
  }))
}

const CONFLICT_LABELS: Record<string, string> = { value: 'value', unit: 'unit', date: 'date' }

/** A source-ID match that disagrees, or a look-alike of a record a person removed. */
function noticeOf(proposal: EhiProposal): string | null {
  const proposed = proposal.proposed ?? {}
  const bridge = proposed['bridge']
  if (bridge !== null && typeof bridge === 'object' && !Array.isArray(bridge)) {
    const conflicts = (bridge as Record<string, unknown>)['conflicts']
    if (Array.isArray(conflicts) && conflicts.length > 0) {
      return `It carries the same source identifier as a record in PHR, but the ${conflicts.map((conflict) => CONFLICT_LABELS[String(conflict)] ?? String(conflict)).join(' and ')} differ${conflicts.length === 1 ? 's' : ''}. Neither is changed unless you choose.`
    }
  }
  if (proposed['withdrawn_match'] === true) {
    return 'A matching record was deleted, retracted, or rejected earlier. Adding this one is your choice; that record stays as it is.'
  }

  return null
}

export function proposalView(proposal: EhiProposal): EhiProposalView {
  const attributes = proposedAttributes(proposal)
  const domain = DOMAINS[proposal.mapping] ?? fieldLabel(proposal.mapping)
  const changes = changesOf(proposal, attributes)
  const notice = noticeOf(proposal)

  if (proposal.mapping === 'document') {
    return {
      domain,
      title: text(attributes['title']) ?? 'Untitled document',
      date: formatSourceDate(attributes['observed_at']),
      detail: text(attributes['document_type']),
      changes,
      notice,
    }
  }
  if (proposal.mapping === 'immunization') {
    const status = text(attributes['administration_status'])
    const details = [status, text(attributes['site']), text(attributes['lot_number']) ? `Lot ${text(attributes['lot_number'])}` : null].filter(Boolean)
    return {
      domain,
      title: text(attributes['vaccine_name']) ?? 'Unnamed vaccine',
      date: formatSourceDate(attributes['administered_on']),
      detail: details.length > 0 ? details.join(' · ') : null,
      changes,
      notice,
    }
  }
  if (proposal.mapping === 'lab_result') {
    // The value exactly as the source stored it: an inequality is part of it.
    const measured = [labValueText(attributes), text(attributes['unit'])].filter(Boolean).join(' ')
    const details = [measured === '' ? null : displayUnits(measured), text(attributes['abnormal_flag']), text(attributes['result_status'])].filter(Boolean)
    return {
      domain,
      title: text(attributes['analyte']) ?? text(attributes['test_name']) ?? 'Unnamed result',
      date: formatSourceDate(attributes['result_datetime'] ?? attributes['collection_datetime']),
      detail: details.length > 0 ? details.join(' · ') : null,
      changes,
      notice,
    }
  }

  const first = Object.entries(attributes).find(([field, value]) => !HIDDEN_FIELDS.has(field) && text(value) !== null)
  return { domain, title: first ? String(text(first[1])) : `Proposal ${proposal.id}`, date: null, detail: null, changes, notice }
}

const RETAIN_REASONS: Record<string, string> = {
  order_withdrawn: 'The order was canceled in the source.',
  order_type_not_laboratory: 'The source does not mark this order as a laboratory test.',
  status_incomplete: 'The source marks this result as incomplete.',
  status_in_process: 'The source marks this result as still in process.',
  status_unknown: 'The source gives a status PHR does not recognize.',
  no_result_value: 'The source gives no value or comment for this result.',
  foreign_patient_lab_result: 'The source links this result to another patient.',
  orphan_lab_result: 'Not linked to this patient in the source.',
  lab_bridge_target_withdrawn: 'The record it matches was deleted, retracted, or rejected, and stays that way.',
  linked_record_withdrawn: 'The record it was linked to was deleted, retracted, or rejected, and stays that way.',
  lab_identity_withdrawn: 'The record it was imported as was deleted, retracted, or rejected, and stays that way.',
  linked_lab_status_not_clinical: 'The source no longer asserts this result. The record in PHR is unchanged; change it yourself if it is wrong.',
}

/** Why a kept-as-evidence row was not imported, in words. */
export function retainReason(proposal: EhiProposal): string | null {
  const reason = proposal.proposed?.['retain_reason'] ?? proposal.proposed?.['reason']
  if (typeof reason !== 'string') {
    return null
  }
  const known = RETAIN_REASONS[reason]
  if (known !== undefined) {
    return known
  }
  if (reason === 'deleted' || reason === 'status_deleted') {
    return 'Marked as deleted in the source.'
  }
  if (reason.startsWith('status_')) {
    return `Status in the source: ${reason.slice('status_'.length).replaceAll('_', ' ')}.`
  }
  if (reason.startsWith('orphan')) {
    return 'Not linked to this patient in the source.'
  }

  return reason.replaceAll('_', ' ')
}

export interface DecisionAction {
  decision: EhiDecision
  label: string
  primary: boolean
}

/**
 * The buttons a proposal offers, in order, from what the server allows for it. A
 * duplicate's "accept" means adding it as a separate record, so it says so.
 */
export function decisionActions(proposal: EhiProposal): DecisionAction[] {
  const group = reviewGroupOf(proposal)
  const labels: Record<string, string> = {
    accept: group === 'duplicate' ? 'Different, add as new' : group === 'evidence' ? 'Add as evidence' : 'Accept',
    approve_update: 'Approve update',
    link_existing: 'Same record, link as evidence',
    reject: 'Reject',
    retain_evidence: group === 'kept' ? 'Keep as evidence' : 'Keep as evidence only',
  }
  const order: EhiDecision[] = group === 'duplicate'
    ? ['link_existing', 'accept', 'retain_evidence', 'reject']
    : group === 'kept'
      ? ['retain_evidence', 'reject']
      : ['approve_update', 'accept', 'link_existing', 'retain_evidence', 'reject']
  const allowed = new Set(proposal.allowed_decisions)
  // Linking an existing record needs a candidate to link; offer it only with one.
  const actions = order.filter((decision) => allowed.has(decision) && (decision !== 'link_existing' || proposal.candidate_ids.length > 0))

  return actions.map((decision, index) => ({ decision, label: labels[decision] ?? decision, primary: index === 0 }))
}

export const STATUS_LABELS: Record<EhiProposal['status'], string> = {
  pending: 'To decide',
  accepted: 'Accepted',
  rejected: 'Rejected',
  retained: 'Kept as evidence',
  linked: 'Linked as evidence',
  superseded: 'Replaced by a newer export',
}
