import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { AiImportReview, aiResultView } from '../AiImportReview'

const mockGet = jest.fn()
const mockPost = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
  getCsrfToken: () => null,
}))

const PATIENT = 7
const base = `/api/phr/patients/${PATIENT}/ai-imports/5`

function detail(results: { id: number, status: string, data: Record<string, unknown> }[]) {
  return {
    import: {
      id: 5, document_id: 77, job_type: 'phr_lab_result', status: 'parsed', retry_count: 0, can_retry: false,
      result_count: results.length, pending_result_count: results.length, parsed_at: null, failure_code: null,
      created_at: null, execution_mode: 'external', document_title: 'Synthetic labs', document_filename: 'labs.pdf',
      results: results.map((result, index) => ({ result_index: index, ...result })),
    },
  }
}

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
})

describe('aiResultView', () => {
  it('titles a proposal by its naming field and hides provenance', () => {
    const view = aiResultView({ id: 1, status: 'pending_review', result_index: 0, data: { analyte: 'Synthetic sodium', value: '140', collected_at: '2026-01-15', external_id: 'x', raw_text: 'raw' } })
    expect(view.title).toBe('Synthetic sodium')
    expect(view.fields).toEqual([{ key: 'value', label: 'value', value: '140' }, { key: 'collected_at', label: 'collected at', value: 'Jan 15, 2026' }])
  })

  it('formats only whole dates, keeping text that merely begins with one', () => {
    const view = aiResultView({ id: 3, status: 'pending_review', result_index: 0, data: {
      name: 'Synthetic follow-up',
      note: '2023-10-01 follow-up for an abnormal synthetic result',
      seen_at: '2023-10-01T09:30:00Z',
    } })
    expect(view.fields).toEqual([
      { key: 'note', label: 'note', value: '2023-10-01 follow-up for an abnormal synthetic result' },
      { key: 'seen_at', label: 'seen at', value: 'Oct 1, 2023' },
    ])
  })

  it('shows every record nested in a document proposal, since accepting adds them all', () => {
    const view = aiResultView({ id: 2, status: 'pending_review', result_index: 0, data: {
      title: 'Synthetic visit note',
      lab_results: [{ analyte: 'Synthetic sodium', value: '140' }, { analyte: 'Synthetic potassium', value: '4.1' }],
      medications: [{ name: 'Synthetic lisinopril', dose: '10 mg' }],
      tags: ['visit'],
    } })
    expect(view.title).toBe('Synthetic visit note')
    expect(view.groups.map((group) => [group.label, group.records.map((record) => record.title)])).toEqual([
      ['lab results', ['Synthetic sodium', 'Synthetic potassium']],
      ['medications', ['Synthetic lisinopril']],
    ])
    expect(view.groups[1]!.records[0]!.fields).toEqual([{ key: 'dose', label: 'dose', value: '10 mg' }])
  })
})

describe('AiImportReview', () => {
  it('records accept and reject decisions through the patient-scoped review', async () => {
    mockGet.mockResolvedValue(detail([
      { id: 30, status: 'pending_review', data: { analyte: 'Synthetic sodium' } },
      { id: 31, status: 'pending_review', data: { analyte: 'Synthetic potassium' } },
    ]))
    mockPost.mockImplementation((url: string) => Promise.resolve({ outcome: 'x', result: { id: url.includes('/30/') ? 30 : 31, status: url.endsWith('/accept') ? 'imported' : 'skipped' } }))

    render(<AiImportReview patientId={PATIENT} importId={5} onBack={jest.fn()} />)

    expect(await screen.findByRole('heading', { name: 'Review Synthetic labs' })).toBeInTheDocument()
    expect(screen.getByRole('note')).toHaveTextContent('AI output can be wrong')
    expect(screen.getByText(/proposed by your agent, through the AI Agent Inbox/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Accept: Synthetic sodium' }))
    await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`${base}/results/30/accept`, {}))
    fireEvent.click(await screen.findByRole('button', { name: 'Reject: Synthetic potassium' }))
    await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`${base}/results/31/reject`, {}))

    const list = screen.getByRole('region', { name: 'Proposed records' })
    expect(await within(list).findByText('Accepted')).toBeInTheDocument()
    expect(await within(list).findByText('Rejected')).toBeInTheDocument()
  })

  it('accepts all in turn and reports the ones that failed', async () => {
    mockGet.mockResolvedValue(detail([
      { id: 30, status: 'pending_review', data: { analyte: 'One' } },
      { id: 31, status: 'pending_review', data: { analyte: 'Two' } },
    ]))
    mockPost.mockImplementation((url: string) => (url.includes('/30/')
      ? Promise.resolve({ outcome: 'accepted', result: { id: 30, status: 'imported' } })
      : Promise.reject('This import proposal has already been reviewed.')))

    render(<AiImportReview patientId={PATIENT} importId={5} onBack={jest.fn()} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Accept all 2' }))

    expect(await screen.findByText(/Accepted 1\. 1 could not be accepted/)).toBeInTheDocument()
    expect(screen.getByText('This import proposal has already been reviewed.')).toBeInTheDocument()
  })
})
