import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import OfficeVisitLinkedRecords from './OfficeVisitLinkedRecords'

const mockGet = jest.fn()
const mockPost = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    delete: jest.fn(),
  },
  getCsrfToken: () => null,
}))

const BASE = '/api/phr/patients/42/office-visits/18'

type Row = Record<string, unknown>

function row(id: number, name: string, fields: Row = {}): Row {
  return { id, type: 'lab-result', name, date: '2026-03-03 06:00:00', detail: '1.8 mg/dL', review_status: 'pending_review', linked_visit: null, ...fields }
}

function list(records: Row[], total = records.length, next: string | null = null) {
  return { records, total, next_cursor: next }
}

const CLOSED = { from: '2026-03-02', to: '2026-03-06', open: false, margin_days: 0 }

function suggestions(groups: Record<string, unknown>, window: unknown = CLOSED) {
  return { office_visit_id: 18, window, groups }
}

const EMPTY = suggestions({ 'lab-result': { suggested: list([]), on_other_visits: list([]) } })

const STAY = suggestions({
  'lab-result': {
    suggested: list([row(5, 'Creatinine'), row(6, 'Potassium', { review_status: 'confirmed' })]),
    on_other_visits: list([row(7, 'Glucose', { date: '2026-03-04 06:00:00', linked_visit: { id: 3, date: '2026-03-04', visit_type: 'Synthetic consult' } })]),
  },
  vital: { suggested: list([row(8, 'Heart rate', { type: 'vital', detail: '88 bpm' })]), on_other_visits: list([]) },
  document: { suggested: list([]), on_other_visits: list([]) },
})

function suggestionsUrl(params: string): string {
  return `${BASE}/link-suggestions?${params}`
}

function editing(onChanged = jest.fn().mockResolvedValue(undefined), canAttach = true) {
  return { patientId: 42, visitId: '18', defaultRange: { from: '2026-03-02', to: '2026-03-06' }, canAttach, onChanged }
}

function panel(): HTMLElement {
  return screen.getByRole('region', { name: 'Suggested from these dates' })
}

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
})

describe('Suggested from these dates', () => {
  it('stays out of the way when nothing is dated inside the stay', async () => {
    mockGet.mockResolvedValue(EMPTY)
    render(<OfficeVisitLinkedRecords records={undefined} editing={editing()} />)

    await waitFor(() => expect(mockGet).toHaveBeenCalledWith(suggestionsUrl('margin_days=0')))
    expect(screen.queryByRole('region', { name: 'Suggested from these dates' })).not.toBeInTheDocument()
    expect(screen.getByText('No records are linked to this visit.')).toBeInTheDocument()
  })

  it('is not offered to a viewer or on a rejected visit, and a failed read shows nothing', async () => {
    mockGet.mockResolvedValue(STAY)
    const { rerender } = render(<OfficeVisitLinkedRecords records={undefined} />)
    rerender(<OfficeVisitLinkedRecords records={undefined} editing={editing(undefined, false)} />)
    expect(mockGet).not.toHaveBeenCalled()

    mockGet.mockRejectedValue({ message: 'Server error' })
    rerender(<OfficeVisitLinkedRecords records={undefined} editing={editing()} />)
    await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(1))
    expect(screen.queryByRole('region', { name: 'Suggested from these dates' })).not.toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('preselects every suggestion but those on another visit, and attaches exactly what stays ticked', async () => {
    const onChanged = jest.fn().mockResolvedValue(undefined)
    mockGet.mockResolvedValueOnce(STAY).mockResolvedValue(EMPTY)
    mockPost.mockResolvedValue({
      office_visit_id: 18,
      results: [
        { type: 'lab-result', id: 5, outcome: 'linked' },
        { type: 'vital', id: 8, outcome: 'refused', reason: 'linked_elsewhere', message: 'Unlink it there first.', linked_visit: { id: 4, date: '2026-03-05', visit_type: null } },
      ],
      summary: { linked: 1, unchanged: 0, refused: 1 },
    })
    render(<OfficeVisitLinkedRecords records={undefined} editing={editing(onChanged)} />)

    const region = await screen.findByRole('region', { name: 'Suggested from these dates' })
    expect(within(region).getByText(/^Dated .*2026.* through .*2026.*\. Records on no visit yet\./)).toBeInTheDocument()
    expect(within(region).getByRole('heading', { name: 'Lab results · 2 suggested' })).toBeInTheDocument()
    expect(within(region).getByRole('heading', { name: 'Vitals · 1 suggested' })).toBeInTheDocument()
    expect(within(region).queryByRole('heading', { name: /Documents/ })).not.toBeInTheDocument()
    expect(within(region).getByRole('checkbox', { name: 'Select Creatinine' })).toBeChecked()
    expect(within(region).getByRole('checkbox', { name: 'Select Potassium' })).toBeChecked()
    expect(within(region).getByRole('checkbox', { name: 'Select Heart rate' })).toBeChecked()
    const elsewhere = within(region).getByRole('checkbox', { name: 'Select Glucose' })
    expect(elsewhere).not.toBeChecked()
    expect(elsewhere).toBeDisabled()
    expect(elsewhere).toHaveAccessibleDescription(/On visit of .*2026.* Remove it there first\./)
    expect(within(region).getByText('3 selected.')).toBeInTheDocument()

    fireEvent.click(within(region).getByRole('checkbox', { name: 'Select Potassium' }))
    expect(within(region).getByText('2 selected.')).toBeInTheDocument()
    fireEvent.click(within(region).getByRole('button', { name: 'Attach selected' }))

    await waitFor(() => expect(onChanged).toHaveBeenCalledTimes(1))
    expect(mockPost).toHaveBeenCalledTimes(1)
    expect(mockPost).toHaveBeenCalledWith(`${BASE}/links`, { links: [{ type: 'lab-result', id: 5 }, { type: 'vital', id: 8 }] })
    const status = screen.getByRole('status')
    expect(status).toHaveTextContent('Attached 1 record. 1 not attached.')
    expect(status).toHaveTextContent(/Heart rate is on visit of .*2026.*\. Remove it there first\./)
    // The suggestions are read again, and with nothing left the panel goes away.
    await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(2))
    await waitFor(() => expect(screen.queryByRole('region', { name: 'Suggested from these dates' })).not.toBeInTheDocument())
  })

  it('widens by a margin and stays open to say when nothing more is found', async () => {
    mockGet.mockImplementation((url: string) => Promise.resolve(
      url.includes('margin_days=0') ? STAY : url.includes('margin_days=1')
        ? suggestions({ 'lab-result': { suggested: list([row(4, 'Sodium', { date: '2026-03-01 23:30:00' }), row(5, 'Creatinine')]), on_other_visits: list([]) } }, { ...CLOSED, from: '2026-03-01', to: '2026-03-07', margin_days: 1 })
        : suggestions({ 'lab-result': { suggested: list([]), on_other_visits: list([]) } }, { ...CLOSED, margin_days: 2 }),
    ))
    render(<OfficeVisitLinkedRecords records={undefined} editing={editing()} />)
    await screen.findByRole('region', { name: 'Suggested from these dates' })

    fireEvent.change(within(panel()).getByLabelText('Include'), { target: { value: '1' } })
    expect(await within(panel()).findByRole('checkbox', { name: 'Select Sodium' })).toBeChecked()
    expect(mockGet).toHaveBeenLastCalledWith(suggestionsUrl('margin_days=1'))
    expect(within(panel()).queryByRole('checkbox', { name: 'Select Heart rate' })).not.toBeInTheDocument()

    fireEvent.change(within(panel()).getByLabelText('Include'), { target: { value: '2' } })
    expect(await within(panel()).findByText('Nothing else is dated within these days.')).toBeInTheDocument()
    expect(within(panel()).queryByRole('button', { name: 'Attach selected' })).not.toBeInTheDocument()
  })

  it('runs an open stay to today, pages a list on, and preselects what it brings', async () => {
    const open = suggestions({
      'lab-result': { suggested: list([row(5, 'Creatinine')], 2, 'next-lab'), on_other_visits: list([]) },
    }, { from: '2026-03-02', to: '2026-03-21', open: true, margin_days: 0 })
    mockGet.mockImplementation((url: string) => Promise.resolve(url.includes('cursor=')
      ? suggestions({ 'lab-result': { suggested: list([row(9, 'Lactate', { date: '2026-03-20 08:00:00' })], 2) } })
      : open))
    render(<OfficeVisitLinkedRecords records={undefined} editing={editing()} />)

    const region = await screen.findByRole('region', { name: 'Suggested from these dates' })
    expect(within(region).getByText(/through today, while the stay is under way\./)).toBeInTheDocument()
    fireEvent.click(within(region).getByRole('button', { name: 'Show more (1 of 2)' }))
    expect(await within(region).findByRole('checkbox', { name: 'Select Lactate' })).toBeChecked()
    expect(mockGet).toHaveBeenLastCalledWith(suggestionsUrl('margin_days=0&types%5B%5D=lab-result&list=suggested&cursor=next-lab'))
    expect(within(region).getByRole('checkbox', { name: 'Select Creatinine' })).toBeChecked()
    expect(within(region).queryByRole('button', { name: /Show more/ })).not.toBeInTheDocument()
    expect(within(region).getByText('2 selected.')).toBeInTheDocument()
  })

  it('sends a selection larger than one request takes in several', async () => {
    const labs = Array.from({ length: 50 }, (_, index) => row(100 + index, `Synthetic lab ${index}`))
    const vitals = Array.from({ length: 50 }, (_, index) => row(200 + index, `Synthetic vital ${index}`, { type: 'vital' }))
    const procedures = Array.from({ length: 5 }, (_, index) => row(300 + index, `Synthetic procedure ${index}`, { type: 'procedure' }))
    mockGet.mockResolvedValueOnce(suggestions({
      'lab-result': { suggested: list(labs), on_other_visits: list([]) },
      vital: { suggested: list(vitals), on_other_visits: list([]) },
      procedure: { suggested: list(procedures), on_other_visits: list([]) },
    })).mockResolvedValue(EMPTY)
    mockPost.mockImplementation((_url: string, body: { links: Array<{ type: string, id: number }> }) => Promise.resolve({
      office_visit_id: 18,
      results: body.links.map((link) => ({ ...link, outcome: 'linked' })),
      summary: { linked: body.links.length, unchanged: 0, refused: 0 },
    }))
    render(<OfficeVisitLinkedRecords records={undefined} editing={editing()} />)

    const region = await screen.findByRole('region', { name: 'Suggested from these dates' })
    expect(within(region).getByText('105 selected.')).toBeInTheDocument()
    fireEvent.click(within(region).getByRole('button', { name: 'Attach selected' }))

    expect(await screen.findByText('Attached 105 records.')).toBeInTheDocument()
    expect(mockPost).toHaveBeenCalledTimes(2)
    expect((mockPost.mock.calls[0]![1] as { links: unknown[] }).links).toHaveLength(100)
    expect((mockPost.mock.calls[1]![1] as { links: unknown[] }).links).toEqual(procedures.map((procedure) => ({ type: 'procedure', id: procedure.id })))
  })

  it('keeps the selection and shows the error when the attach fails', async () => {
    mockGet.mockResolvedValue(STAY)
    mockPost.mockRejectedValue({ message: 'A rejected visit cannot take new links.' })
    render(<OfficeVisitLinkedRecords records={undefined} editing={editing()} />)

    const region = await screen.findByRole('region', { name: 'Suggested from these dates' })
    fireEvent.click(within(region).getByRole('button', { name: 'Attach selected' }))

    expect(await within(region).findByRole('alert')).toHaveTextContent('A rejected visit cannot take new links.')
    expect(within(region).getByText('3 selected.')).toBeInTheDocument()
    expect(within(region).getByRole('button', { name: 'Attach selected' })).toBeEnabled()
  })
})
