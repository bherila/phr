import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import ReviewPage from './ReviewPage'
import { resetReviewSummaries } from './reviewSummary'

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockPatch = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
  },
  getCsrfToken: () => null,
}))

const PATIENT = 4
const VERSION = 'a'.repeat(64)

function record(id: number, section: string, title: string, change: 'new' | 'update' = 'new') {
  return { type: 'record', key: `record:${section}:${id}`, id, section, review_segment: section, title, detail: 'Synthetic detail', change, version: VERSION, updated_at: null }
}

function ehi(id: number, kind: string, vaccine: string, before: Record<string, unknown> | null = null) {
  return {
    type: 'ehi_proposal', key: `ehi_proposal:${id}`, id, package_id: 9, section: 'immunizations',
    proposal: {
      id, package_id: 9, mapping: 'immunization', mapping_version: '1', kind, status: 'pending', reviewable: true,
      allowed_decisions: ['accept', 'reject'], target: null, candidate_ids: [], candidate_total: 0, candidates_truncated: false,
      proposed: { vaccine_name: vaccine, lot_number: 'L2' }, before, preview_digest: `digest-${id}`, decided_at: null,
    },
  }
}

function group(key: string, origin: string, label: string, items: unknown[], extra: Record<string, unknown> = {}) {
  return { key, origin, label, name: null, parsed_by_phr: origin !== 'ai', ai_read: origin === 'ai', document_id: null, date: '2026-03-02T10:00:00+00:00', total: items.length, truncated_sections: [], items, ...extra }
}

function queue(groups: unknown[], canManage = true) {
  const total = groups.reduce((sum: number, candidate) => sum + (candidate as { items: unknown[] }).items.length, 0)
  return { summary: { total, sections: {}, ai_results: 0 }, groups, item_limit: 200, can_manage: canManage }
}

const ccda = group('records:ccda:', 'records', 'C-CDA import', [
  record(11, 'conditions', 'Synthetic rhinitis'),
  record(12, 'medications', 'Synthetic lisinopril', 'update'),
], { name: 'visit-summary.xml' })
const ai = group('ai:5', 'ai', 'AI extraction', [{
  type: 'ai_result', key: 'ai_result:31', id: 31, import_id: 5, job_type: 'phr_medication',
  result: { id: 31, status: 'pending_review', result_index: 0, data: { name: 'Synthetic amoxicillin', dose: '500 mg' } },
}], { execution_mode: 'external', document_id: 77 })
const export_ = group('ehi:9', 'ehi', 'EHI export', [
  ehi(41, 'create', 'Synthetic vaccine A'),
  ehi(42, 'possible_duplicate', 'Synthetic vaccine B'),
  { ...ehi(43, 'update', 'Synthetic vaccine C', { vaccine_name: 'Synthetic vaccine C', lot_number: 'L1' }) },
])

// A document's AI proposal: accepting it imports every nested record.
const letter = group('ai:6', 'ai', 'AI extraction', [{
  type: 'ai_result', key: 'ai_result:32', id: 32, import_id: 6, job_type: 'phr_document',
  result: { id: 32, status: 'pending_review', result_index: 0, data: {
    title: 'Synthetic letter',
    medications: [{ name: 'Synthetic amoxicillin', dose: '500 mg' }, { name: 'Synthetic ibuprofen', dose: '200 mg' }],
  } },
}], { name: 'letter.pdf', execution_mode: 'external', document_id: 78 })

beforeEach(() => {
  resetReviewSummaries()
  mockGet.mockReset()
  mockPost.mockReset()
  mockPatch.mockReset()
})

function section(name: string): HTMLElement {
  return screen.getByRole('region', { name })
}

describe('ReviewPage', () => {
  it('groups proposals by source with what each one changes', async () => {
    mockGet.mockResolvedValue(queue([ccda, ai, export_]))
    render(<ReviewPage patientId={PATIENT} />)

    expect(await screen.findByText('6 proposed changes from 3 sources. Nothing here changes the record until you approve it.')).toBeInTheDocument()
    const imported = section('C-CDA import · visit-summary.xml')
    expect(within(imported).getByText('1 condition')).toBeInTheDocument()
    expect(within(imported).getByText('1 medication')).toBeInTheDocument()
    expect(within(imported).getByText(/parsed by PHR, no AI/)).toBeInTheDocument()
    expect(within(imported).getByRole('button', { name: 'Approve 2 selected' })).toBeEnabled()
    expect(within(imported).getByRole('link', { name: 'Open Synthetic rhinitis' })).toHaveAttribute('href', `/phr/patient/${PATIENT}/conditions/11`)
    expect(within(imported).getByText('Updates existing')).toBeInTheDocument()

    const extracted = section('AI extraction')
    expect(within(extracted).getByText('AI-read: check against the document')).toBeInTheDocument()
    expect(within(extracted).getByText(/Processed from the AI Agent Inbox/)).toBeInTheDocument()
    expect(within(imported).queryByText('AI-read: check against the document')).toBeNull()

    // Possible duplicates start unchecked; an update says what it changes.
    const exported = section('EHI export')
    expect(within(exported).getByRole('checkbox', { name: 'Select Synthetic vaccine B' })).not.toBeChecked()
    expect(within(exported).getByRole('checkbox', { name: 'Select Synthetic vaccine A' })).toBeChecked()
    expect(within(exported).getByRole('button', { name: 'Approve 2 selected' })).toBeInTheDocument()
    expect(within(exported).getByText('Lot number L1 → L2')).toBeInTheDocument()
    expect(within(exported).getByRole('link', { name: 'Compare Synthetic vaccine B' })).toHaveAttribute('href', `/phr/imports?patient_id=${PATIENT}&ehi_package=9`)
  })

  it('confirms the count, decides each item through its own route, and reports refusals per row', async () => {
    mockGet.mockResolvedValueOnce(queue([ccda, ai, export_]))
    // The condition changed after the page loaded: its route refuses it.
    mockPatch.mockImplementation((url: string) => (url.includes('/conditions/11/') ? Promise.reject('This record changed since it was loaded. Reload and review it again.') : Promise.resolve({})))
    mockPost.mockResolvedValue({ outcome: 'accepted', result: { id: 31, status: 'imported' }, proposal: export_.items[0] && (export_.items[0] as { proposal: unknown }).proposal, record: null })
    render(<ReviewPage patientId={PATIENT} />)

    const imported = await screen.findByRole('region', { name: 'C-CDA import · visit-summary.xml' })
    fireEvent.click(within(imported).getByRole('button', { name: 'Approve 2 selected' }))
    const dialog = await screen.findByRole('alertdialog')
    expect(within(dialog).getByText('Approve 2 items?')).toBeInTheDocument()

    mockGet.mockResolvedValueOnce(queue([group('records:ccda:', 'records', 'C-CDA import', [record(11, 'conditions', 'Synthetic rhinitis')], { name: 'visit-summary.xml' }), ai, export_]))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Approve 2' }))

    expect(await screen.findByText('Approved 1. 1 could not be approved; each says why below.')).toBeInTheDocument()
    expect(mockPatch).toHaveBeenCalledWith(`/api/phr/patients/${PATIENT}/conditions/11/review`, { review_status: 'confirmed', version: VERSION })
    expect(mockPatch).toHaveBeenCalledWith(`/api/phr/patients/${PATIENT}/medications/12/review`, { review_status: 'confirmed', version: VERSION })
    const refused = await screen.findByRole('region', { name: 'C-CDA import · visit-summary.xml' })
    expect(within(refused).getByRole('alert')).toHaveTextContent('This record changed since it was loaded.')
    expect(mockGet).toHaveBeenCalledTimes(2)
  })

  it('sends AI proposals and EHI proposals to their own review routes', async () => {
    mockGet.mockResolvedValue(queue([ai, export_]))
    mockPost.mockImplementation((url: string) => Promise.resolve(url.includes('/ehi/')
      ? { proposal: (export_.items[0] as { proposal: unknown }).proposal, record: null }
      : { outcome: 'rejected', result: { id: 31, status: 'skipped' } }))
    render(<ReviewPage patientId={PATIENT} />)

    fireEvent.click(within(await screen.findByRole('region', { name: 'AI extraction' })).getByRole('button', { name: 'Reject all' }))
    fireEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Reject 1' }))
    await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`/api/phr/patients/${PATIENT}/ai-imports/5/results/31/reject`, {}))

    await screen.findByText('Rejected 1.')
    fireEvent.click(within(section('EHI export')).getByRole('button', { name: 'Approve 2 selected' }))
    fireEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Approve 2' }))
    await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`/api/phr/patients/${PATIENT}/ehi/proposals/43/review`, { decision: 'approve_update', preview_digest: 'digest-43' }))
    expect(mockPost).toHaveBeenCalledWith(`/api/phr/patients/${PATIENT}/ehi/proposals/41/review`, { decision: 'accept', preview_digest: 'digest-41' })
    expect(mockPost).not.toHaveBeenCalledWith(`/api/phr/patients/${PATIENT}/ehi/proposals/42/review`, expect.anything())
  })

  it('lets a view-only reader see the queue without deciding', async () => {
    mockGet.mockResolvedValue(queue([ccda], false))
    render(<ReviewPage patientId={PATIENT} />)

    const imported = await screen.findByRole('region', { name: 'C-CDA import · visit-summary.xml' })
    expect(screen.getByRole('note')).toHaveTextContent('View only')
    expect(within(imported).getByRole('button', { name: 'Approve 2 selected' })).toBeDisabled()
    expect(within(imported).getByRole('button', { name: 'Reject all' })).toBeDisabled()
    expect(within(imported).getByRole('checkbox', { name: 'Select Synthetic rhinitis' })).toBeDisabled()
  })

  it('groups by section on request', async () => {
    mockGet.mockResolvedValue(queue([ccda, export_]))
    render(<ReviewPage patientId={PATIENT} />)
    await screen.findByRole('region', { name: 'EHI export' })

    fireEvent.click(screen.getByRole('radio', { name: 'By section' }))
    expect(screen.getByRole('radio', { name: 'By section' })).toHaveAttribute('aria-checked', 'true')
    const immunizations = section('Immunizations')
    expect(within(immunizations).getAllByRole('row')).toHaveLength(4)
    expect(within(immunizations).getAllByText('EHI export')).toHaveLength(3)
    expect(section('Conditions')).toBeInTheDocument()
  })

  it('says when nothing waits', async () => {
    mockGet.mockResolvedValue(queue([]))
    render(<ReviewPage patientId={PATIENT} />)

    expect(await screen.findByRole('heading', { name: 'Nothing waiting for review' })).toBeInTheDocument()
    expect(screen.queryByRole('table')).toBeNull()
  })

  it('approves an AI proposal only from its own view, after every value it adds is shown', async () => {
    mockGet.mockResolvedValue(queue([letter]))
    mockPost.mockResolvedValue({ outcome: 'accepted', result: { id: 32, status: 'imported' } })
    render(<ReviewPage patientId={PATIENT} />)

    const extracted = await screen.findByRole('region', { name: 'AI extraction · letter.pdf' })
    expect(within(extracted).getByText('2 medications')).toBeInTheDocument()
    // Not selectable for a batch, and nothing approves it before it is opened.
    expect(within(extracted).queryByRole('checkbox')).toBeNull()
    expect(within(extracted).queryByRole('button', { name: /^Approve/ })).toBeNull()
    expect(within(extracted).queryByText('Synthetic amoxicillin')).toBeNull()

    const open = within(extracted).getByRole('button', { name: 'Review Synthetic letter' })
    expect(open).toHaveAttribute('aria-expanded', 'false')
    fireEvent.click(open)
    expect(open).toHaveAttribute('aria-expanded', 'true')
    expect(within(extracted).getByText('Synthetic amoxicillin')).toBeInTheDocument()
    expect(within(extracted).getByText(/500 mg/)).toBeInTheDocument()
    expect(within(extracted).getByText('Synthetic ibuprofen')).toBeInTheDocument()

    fireEvent.click(within(extracted).getByRole('button', { name: 'Approve Synthetic letter' }))
    await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`/api/phr/patients/${PATIENT}/ai-imports/6/results/32/accept`, {}))
  })

  it('never includes an AI proposal in a batch approval', async () => {
    mockGet.mockResolvedValue(queue([ccda, ai]))
    mockPatch.mockResolvedValue({})
    render(<ReviewPage patientId={PATIENT} />)
    await screen.findByRole('region', { name: 'AI extraction' })

    // By section, the Medications group mixes a record and an AI proposal.
    fireEvent.click(screen.getByRole('radio', { name: 'By section' }))
    const medications = section('Medications')
    fireEvent.click(within(medications).getByRole('button', { name: 'Approve 1 selected' }))
    fireEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Approve 1' }))

    await screen.findByText('Approved 1.')
    expect(mockPatch).toHaveBeenCalledWith(`/api/phr/patients/${PATIENT}/medications/12/review`, { review_status: 'confirmed', version: VERSION })
    expect(mockPost).not.toHaveBeenCalled()
  })
})
