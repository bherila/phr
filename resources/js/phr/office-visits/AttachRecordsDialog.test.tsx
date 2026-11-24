import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { visitDateRange } from './AttachRecordsDialog'
import OfficeVisitLinkedRecords from './OfficeVisitLinkedRecords'

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
  getCsrfToken: () => null,
}))

const BASE = '/api/phr/patients/42/office-visits/18'

function page(records: unknown[], next: string | null = null) {
  return { office_visit_id: 18, records, pagination: { limit: 25, has_more: next !== null, next_cursor: next } }
}

function row(id: number, name: string, fields: Record<string, unknown> = {}) {
  return { id, type: 'lab-result', name, date: '2026-03-03 06:00:00', detail: '1.8 mg/dL', review_status: 'pending_review', linked_visit: null, ...fields }
}

const LABS = page([
  row(5, 'Creatinine'),
  row(6, 'Potassium', { review_status: 'confirmed' }),
  row(7, 'Glucose', { date: '2026-01-12 10:00:00', linked_visit: { id: 3, date: '2026-01-12', visit_type: 'Synthetic hospital admission' } }),
], 'next-page')

const NOTES = page([
  { id: 9, type: 'document', name: 'Daily Progress Note', date: '2026-03-04 07:00:00', detail: 'progress_note', review_status: 'confirmed', linked_visit: null },
])

function editing(onChanged = jest.fn().mockResolvedValue(undefined)) {
  return { patientId: 42, visitId: '18', defaultRange: { from: '2026-03-02', to: '2026-03-06' }, canAttach: true, onChanged }
}

function linkableUrl(params: Record<string, string>): string {
  return `${BASE}/linkable-records?${new URLSearchParams(params).toString()}`
}

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
  mockDelete.mockReset()
  mockGet.mockImplementation((url: string) => Promise.resolve(url.includes('type=document') ? NOTES : LABS))
})

describe('visitDateRange', () => {
  it('runs from a stay\'s start through its end, or its one day, and leaves an open stay open', () => {
    expect(visitDateRange({ visit_date: '2026-03-02', visit_started_at: '2026-03-02 14:20:00', visit_ended_at: '2026-03-06 11:00:00' })).toEqual({ from: '2026-03-02', to: '2026-03-06' })
    expect(visitDateRange({ visit_date: '2026-03-02', visit_started_at: '2026-03-02 14:20:00', visit_ended_at: null })).toEqual({ from: '2026-03-02', to: '' })
    expect(visitDateRange({ visit_date: '2026-04-15', visit_started_at: null, visit_ended_at: null })).toEqual({ from: '2026-04-15', to: '2026-04-15' })
    expect(visitDateRange({ visit_date: null, visit_started_at: null, visit_ended_at: null })).toEqual({ from: '', to: '' })
  })
})

describe('Attach records', () => {
  it('searches within the visit\'s dates, keeps a record on another visit unselectable, and attaches across types', async () => {
    const onChanged = jest.fn().mockResolvedValue(undefined)
    mockPost.mockResolvedValue({
      office_visit_id: 18,
      results: [
        { type: 'lab-result', id: 5, outcome: 'linked' },
        { type: 'document', id: 9, outcome: 'refused', reason: 'linked_elsewhere', message: 'Unlink it there first.', linked_visit: { id: 4, date: '2026-02-01', visit_type: null } },
      ],
      summary: { linked: 1, unchanged: 0, refused: 1 },
    })
    render(<OfficeVisitLinkedRecords records={undefined} editing={editing(onChanged)} />)

    fireEvent.click(screen.getByRole('button', { name: 'Attach records' }))
    const dialog = await screen.findByRole('dialog', { name: 'Attach records' })
    expect(await within(dialog).findByText('Creatinine')).toBeInTheDocument()
    expect(mockGet).toHaveBeenCalledWith(linkableUrl({ type: 'lab-result', from: '2026-03-02', to: '2026-03-06' }))
    expect(within(dialog).getByLabelText('From')).toHaveValue('2026-03-02')
    expect(within(dialog).getByLabelText('To')).toHaveValue('2026-03-06')
    expect(within(dialog).getAllByText('Pending review')).toHaveLength(2)

    const elsewhere = within(dialog).getByRole('checkbox', { name: 'Select Glucose' })
    expect(elsewhere).toBeDisabled()
    expect(elsewhere).toHaveAccessibleDescription(/On visit of .*2026.* Remove it there first\./)

    fireEvent.click(within(dialog).getByRole('checkbox', { name: 'Select Creatinine' }))
    expect(within(dialog).getByText('1 selected.')).toBeInTheDocument()

    // The selection is kept when the type changes.
    fireEvent.change(within(dialog).getByLabelText('Record type'), { target: { value: 'document' } })
    fireEvent.click(await within(dialog).findByRole('checkbox', { name: 'Select Daily Progress Note' }))
    expect(within(dialog).getByText(/· Progress Note$/)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Attach 2 selected' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(mockPost).toHaveBeenCalledWith(`${BASE}/links`, { links: [{ type: 'lab-result', id: 5 }, { type: 'document', id: 9 }] })
    expect(onChanged).toHaveBeenCalledTimes(1)
    const status = screen.getByRole('status')
    expect(status).toHaveTextContent('Attached 1 record. 1 not attached.')
    expect(status).toHaveTextContent(/Daily Progress Note is on visit of .*2026.*\. Remove it there first\./)
  })

  it('narrows by name, clears the dates, and pages on', async () => {
    render(<OfficeVisitLinkedRecords records={undefined} editing={editing()} />)
    fireEvent.click(screen.getByRole('button', { name: 'Attach records' }))
    const dialog = await screen.findByRole('dialog', { name: 'Attach records' })
    await within(dialog).findByText('Creatinine')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Clear dates' }))
    await waitFor(() => expect(mockGet).toHaveBeenLastCalledWith(linkableUrl({ type: 'lab-result' })))
    expect(within(dialog).getByText('Any date, undated records included.')).toBeInTheDocument()

    fireEvent.change(within(dialog).getByRole('searchbox'), { target: { value: ' creat ' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Search' }))
    await waitFor(() => expect(mockGet).toHaveBeenLastCalledWith(linkableUrl({ type: 'lab-result', q: 'creat' })))

    fireEvent.click(within(dialog).getByRole('button', { name: 'Use visit dates' }))
    await waitFor(() => expect(mockGet).toHaveBeenLastCalledWith(linkableUrl({ type: 'lab-result', q: 'creat', from: '2026-03-02', to: '2026-03-06' })))

    mockGet.mockResolvedValueOnce(page([row(11, 'Sodium')]))
    fireEvent.click(await within(dialog).findByRole('button', { name: 'Show more' }))
    expect(await within(dialog).findByText('Sodium')).toBeInTheDocument()
    expect(mockGet).toHaveBeenLastCalledWith(linkableUrl({ type: 'lab-result', q: 'creat', from: '2026-03-02', to: '2026-03-06', cursor: 'next-page' }))
    expect(within(dialog).getByText('Creatinine')).toBeInTheDocument()
    expect(within(dialog).queryByRole('button', { name: 'Show more' })).not.toBeInTheDocument()
  })

  it('keeps the dialog open with the error when the attach fails', async () => {
    mockPost.mockRejectedValue({ message: 'A rejected visit cannot take new links.' })
    render(<OfficeVisitLinkedRecords records={undefined} editing={editing()} />)
    fireEvent.click(screen.getByRole('button', { name: 'Attach records' }))
    const dialog = await screen.findByRole('dialog', { name: 'Attach records' })
    fireEvent.click(await within(dialog).findByRole('checkbox', { name: 'Select Creatinine' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Attach 1 selected' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('A rejected visit cannot take new links.')
    expect(within(dialog).getByRole('checkbox', { name: 'Select Creatinine' })).toBeChecked()
  })
})

describe('Remove from this visit', () => {
  const records = {
    lab_results: [{ id: 5, name: 'Creatinine', value: '1.8', unit: 'mg/dL' }],
    vitals: [],
    medications: [],
    procedures: [],
    documents: [{ id: 9, title: 'Daily Progress Note', document_type: 'progress_note' }],
  }

  it('unlinks one record and reads the visit again', async () => {
    const onChanged = jest.fn().mockResolvedValue(undefined)
    mockDelete.mockResolvedValue({ office_visit_id: 18, type: 'document', id: 9, outcome: 'unlinked' })
    render(<OfficeVisitLinkedRecords records={records} editing={editing(onChanged)} />)

    fireEvent.click(screen.getByRole('button', { name: 'Remove Daily Progress Note from this visit' }))

    expect(await screen.findByText('Removed Daily Progress Note from this visit.')).toBeInTheDocument()
    expect(mockDelete).toHaveBeenCalledWith(`${BASE}/links/document/9`, {})
    expect(onChanged).toHaveBeenCalledTimes(1)
  })

  it('says why a removal failed', async () => {
    mockDelete.mockRejectedValue({ message: 'Request failed.' })
    render(<OfficeVisitLinkedRecords records={records} editing={editing()} />)

    fireEvent.click(screen.getByRole('button', { name: 'Remove Creatinine from this visit' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Request failed.')
  })

  it('offers neither attach nor remove to a viewer, and only remove on a rejected visit', () => {
    const { rerender } = render(<OfficeVisitLinkedRecords records={records} />)
    expect(screen.getByText('Creatinine')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Attach records' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Remove / })).not.toBeInTheDocument()

    rerender(<OfficeVisitLinkedRecords records={records} editing={{ ...editing(), canAttach: false }} />)
    expect(screen.queryByRole('button', { name: 'Attach records' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Remove Creatinine from this visit' })).toBeInTheDocument()
  })
})
