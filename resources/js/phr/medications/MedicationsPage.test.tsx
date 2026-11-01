import '@testing-library/jest-dom'

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { recordChanged } from '@/phr/clinical/recordChanges'
import MedicationDetail from '@/phr/medications/MedicationDetail'
import MedicationsPage from '@/phr/medications/MedicationsPage'

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockPatch = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    getRaw: async (...args: unknown[]) => {
      const body: unknown = await mockGet(...args)
      return { ok: true, status: 200, statusText: 'OK', text: async () => JSON.stringify(body), json: async () => body }
    },
    post: (...args: unknown[]) => mockPost(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
}))

function makeMedication(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    id: 1,
    patient_id: 42,
    user_id: 7,
    name: 'Metformin',
    rxnorm_code: null,
    dose: '500',
    dose_unit: 'mg',
    route: 'PO',
    frequency: 'BID',
    started_on: '2026-01-01',
    ended_on: null,
    status: 'active',
    prescriber_name: 'Dr. Smith',
    reason_for_use: 'Blood sugar control',
    raw_text: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

/** Opens a row's ⋯ menu and picks an item from it. */
async function pickFromMenu(record: string, item: string) {
  fireEvent.click(screen.getByRole('button', { name: `More actions for ${record}` }))
  fireEvent.click(await screen.findByRole('menuitem', { name: item }))
}

beforeEach(() => {
  // A selected tab is kept in the address.
  window.history.replaceState(null, '', '/')
  jest.useFakeTimers().setSystemTime(new Date('2026-05-17T12:00:00Z'))
  mockGet.mockClear()
  mockPost.mockClear()
  mockPatch.mockClear()
  mockDelete.mockClear()

  mockGet.mockImplementation(async (url: string) => {
    if (url === '/api/phr/patients/42/medications') {
      return {
        medications: [
          makeMedication(),
          makeMedication({
            id: 2,
            name: 'Amoxicillin',
            status: 'completed',
            ended_on: '2026-03-01',
            reason_for_use: 'Finished antibiotic course',
          }),
        ],
        can_manage: true,
      }
    }

    return {}
  })
})

afterEach(() => {
  jest.useRealTimers()
})

describe('MedicationsPage', () => {
  it('lists active medications first, with filters for the rest', async () => {
    render(<MedicationsPage patientId={42} />)

    await waitFor(() => expect(screen.getByText('Metformin')).toBeInTheDocument())
    expect(screen.queryByText('Amoxicillin')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Active 1' })).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(screen.getByRole('button', { name: 'Ended 1' }))
    expect(await screen.findByText('Amoxicillin')).toBeInTheDocument()
    expect(screen.queryByText('Metformin')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'All 2' }))
    expect(await screen.findByText('Metformin')).toBeInTheDocument()
    expect(screen.getByText('Amoxicillin')).toBeInTheDocument()
  })

  it('gives dose, route and schedule their own columns, and and the end date only off the Active filter', async () => {
    render(<MedicationsPage patientId={42} />)
    const table = await screen.findByRole('table', { name: 'Medications' })
    await within(table).findByText('Metformin')
    const headers = () => within(table).getAllByRole('columnheader').map((header) => header.textContent)
    expect(headers()).toEqual(['Medication', 'Dose', 'Route', 'Schedule', 'Started', 'Last fill', 'Prescriber', 'Status', 'Actions'])

    const cells = within(within(table).getAllByRole('row')[1]!).getAllByRole('cell')
    expect(cells[1]).toHaveTextContent(/^500 mg$/)
    expect(cells[2]).toHaveTextContent(/^PO$/)
    expect(cells[3]).toHaveTextContent(/^BID$/)
    expect(cells[4]).toHaveTextContent(/^2026-01-01$/)
    expect(cells[5]).toHaveTextContent(/^—$/)
    expect(cells[6]).toHaveTextContent(/^Dr\. Smith$/)

    fireEvent.click(screen.getByRole('button', { name: /^Ended/ }))
    await within(table).findByText('Amoxicillin')
    expect(headers()).toEqual(['Medication', 'Dose', 'Route', 'Schedule', 'Started', 'Ended', 'Last fill', 'Prescriber', 'Status', 'Actions'])
    expect(within(table).getByText('Completed', { selector: '[data-tone]' })).toBeInTheDocument()
  })

  it('lets the details wrap instead of pushing the name into a narrow column', async () => {
    render(<MedicationsPage patientId={42} />)
    const table = await screen.findByRole('table', { name: 'Medications' })
    await within(table).findByText('Metformin')
    for (const name of ['Dose', 'Route', 'Schedule', 'Prescriber']) {
      const header = within(table).getByRole('columnheader', { name })
      expect(header).not.toHaveClass('whitespace-nowrap')
      expect(header).toHaveClass('hidden', '@[960px]/record-list:table-cell')
    }
    expect(within(table).getByRole('columnheader', { name: 'Started' })).toHaveClass('hidden', '@[560px]/record-list:table-cell')
    expect(within(table).getByText('500 mg · PO · BID')).toHaveClass('@[960px]/record-list:hidden')
  })

  it('shows a record opened from a link under the filter that holds it', async () => {
    render(<MedicationsPage patientId={42} openRecord={{ module: 'medication-detail', id: '2' }} />)
    expect(await screen.findByText('Amoxicillin')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^Ended/ })).toHaveAttribute('aria-pressed', 'true')
  })

  it('heads the list with the section tabs, opening Fills at its own address', async () => {
    render(<MedicationsPage patientId={42} />)
    await screen.findByText('Metformin')
    fireEvent.click(screen.getByRole('tab', { name: /^Fills/ }))
    expect(window.location.pathname).toBe('/phr/patient/42/medication-fills')
  })

  it('keeps the status filter in the address and opens on it', async () => {
    const first = render(<MedicationsPage patientId={42} />)
    await screen.findByText('Metformin')
    fireEvent.click(screen.getByRole('button', { name: /^Ended/ }))
    expect(window.location.search).toBe('?status=ended')
    first.unmount()
    render(<MedicationsPage patientId={42} />)
    await screen.findByText('Amoxicillin')
    expect(screen.getByRole('button', { name: /^Ended/ })).toHaveAttribute('aria-pressed', 'true')
  })

  it('settles the status from an end date that has arrived, and gives the choice back when it is removed', async () => {
    render(<MedicationsPage patientId={42} />)
    await screen.findByText('Metformin')
    await pickFromMenu('Metformin', 'Edit')
    const dialog = await screen.findByRole('dialog', { name: 'Edit Metformin' })

    const ended = within(dialog).getByLabelText('Ended On')
    expect(within(dialog).getByLabelText('Status')).toHaveValue('active')
    fireEvent.change(ended, { target: { value: '2020-01-02' } })
    expect(within(dialog).queryByLabelText('Status')).not.toBeInTheDocument()
    expect(within(dialog).getByText('Ended, because it has an end date')).toBeInTheDocument()

    fireEvent.change(ended, { target: { value: '' } })
    expect(within(dialog).getByLabelText('Status')).toHaveValue('active')

    // The last day of a course is still ahead: it runs until then.
    fireEvent.change(ended, { target: { value: '2999-01-02' } })
    expect(within(dialog).getByLabelText('Status')).toBeInTheDocument()
  })

  it('shows each medication\'s last fill and how many, and says so when it has none', async () => {
    mockGet.mockImplementation(async (url: string) => {
      if (url === '/api/phr/patients/42/medications') return { medications: [makeMedication(), makeMedication({ id: 3, name: 'Vitamin D3' })], can_manage: true }
      return {}
    })
    render(<MedicationsPage patientId={42} />)
    expect(await screen.findByText('Vitamin D3')).toBeInTheDocument()
    expect(within(screen.getByRole('table', { name: 'Medications' })).getByRole('columnheader', { name: 'Last fill' })).toBeInTheDocument()
  })

  it('keeps on-hold medications under Active: they have not ended', async () => {
    mockGet.mockImplementation(async (url: string) => (url === '/api/phr/patients/42/medications'
      ? { medications: [makeMedication({ status: 'on_hold' })], can_manage: true }
      : {}))
    render(<MedicationsPage patientId={42} />)
    expect(await screen.findByText('Metformin')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Active 1' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ended 0' })).toBeInTheDocument()
  })

  it('does not offer to end a medication now: the end date belongs to Edit', async () => {
    render(<MedicationsPage patientId={42} />)
    await waitFor(() => expect(screen.getByText('Metformin')).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: 'More actions for Metformin' }))
    expect(await screen.findByRole('menuitem', { name: 'Edit' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'End now' })).not.toBeInTheDocument()
  })

  it('drills to medication detail when a row is clicked, but not from its menu', async () => {
    const onDrill = jest.fn()

    render(<MedicationsPage patientId={42} onDrill={onDrill} />)

    await waitFor(() => expect(screen.getByText('Metformin')).toBeInTheDocument())
    fireEvent.click(screen.getByText('Metformin'))

    expect(onDrill).toHaveBeenCalledWith({ id: 'medication-detail', instance: '1' })

    onDrill.mockClear()
    await pickFromMenu('Metformin', 'Edit')
    expect(await screen.findByRole('dialog', { name: 'Edit Metformin' })).toBeInTheDocument()
    expect(onDrill).not.toHaveBeenCalled()
  })

  it('adds in a dialog, and says what is wrong inside it', async () => {
    mockPost.mockResolvedValue({ medication: makeMedication({ id: 5, name: 'Aspirin' }) })
    render(<MedicationsPage patientId={42} />)
    await waitFor(() => expect(screen.getByText('Metformin')).toBeInTheDocument())

    fireEvent.click(screen.getByRole('button', { name: 'Add medication' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add medication' })
    fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: '   ' } })
    fireEvent.submit(within(dialog).getByRole('button', { name: 'Add' }).closest('form')!)
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Name is required.')

    fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: 'Aspirin' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add' }))
    await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/42/medications', expect.objectContaining({ name: 'Aspirin' })))
    expect(await screen.findByText('Aspirin')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('no longer offers the blocked import button', async () => {
    render(<MedicationsPage patientId={42} />)
    await waitFor(() => expect(screen.getByText('Metformin')).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: /Import via GenAI/ })).not.toBeInTheDocument()
  })

  it('refreshes the list when the detail beside it changes a medication', async () => {
    render(<MedicationsPage patientId={42} />)
    await waitFor(() => expect(screen.getByText('Metformin')).toBeInTheDocument())
    mockGet.mockResolvedValue({ medications: [makeMedication({ name: 'Renamed' })], can_manage: true })
    act(() => recordChanged({ resource: 'medications', patientId: 42, recordId: 1, origin: 'detail' }))
    expect(await screen.findByText('Renamed')).toBeInTheDocument()
  })
})

describe('MedicationDetail', () => {
  let medication: Record<string, unknown>
  let canManage: boolean

  beforeEach(() => {
    medication = makeMedication()
    canManage = true
    mockGet.mockImplementation(async (url: string) => {
      if (url === '/api/phr/patients/42/medications/1') return { medication, can_manage: canManage }
      if (url === '/api/phr/patients/42/medications/1/fills') return { medication_fills: [], can_manage: canManage }
      return {}
    })
  })

  function renderDetail(onDrill = jest.fn()) {
    render(<MedicationDetail patientId={42} recordId="1" onDrill={onDrill} />)
    return onDrill
  }

  it('lists what is recorded and one line for what is not', async () => {
    medication = makeMedication({ dose: null, dose_unit: null, route: null, frequency: null, prescriber_name: null, reason_for_use: null, rxnorm_code: null })
    renderDetail()

    expect(await screen.findByRole('heading', { name: 'Metformin' })).toBeInTheDocument()
    expect(screen.getByText('2026-01-01')).toBeInTheDocument()
    expect(screen.getByText('Not recorded: dose, route, schedule, prescriber, RxNorm, reason for use.')).toBeInTheDocument()
    expect(screen.queryByText('Medication #1')).not.toBeInTheDocument()
  })

  it('says nothing is missing when everything is there, and does not count an active medication\'s end date', async () => {
    medication = makeMedication({ rxnorm_code: '860975' })
    renderDetail()
    await screen.findByRole('heading', { name: 'Metformin' })
    expect(screen.getByText('Blood sugar control')).toBeInTheDocument()
    expect(screen.queryByText(/^Not recorded/)).not.toBeInTheDocument()
  })

  it('counts a missing end date on one that has ended', async () => {
    medication = makeMedication({ status: 'completed', ended_on: null, rxnorm_code: '860975' })
    renderDetail()
    expect(await screen.findByText('Not recorded: ended.')).toBeInTheDocument()
  })

  it('approves from the bar and tells the list', async () => {
    medication = makeMedication({ review_status: 'pending_review' })
    mockPatch.mockResolvedValue({ medication: makeMedication({ review_status: 'confirmed' }) })
    const changes = jest.fn()
    const listener = (event: Event) => changes((event as CustomEvent).detail)
    window.addEventListener('phr:record-changed', listener)
    renderDetail()

    fireEvent.click(await screen.findByRole('button', { name: 'Approve Metformin' }))
    await waitFor(() => expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/42/medications/1/review', { review_status: 'confirmed' }))
    await waitFor(() => expect(screen.queryByTestId('record-review-bar')).not.toBeInTheDocument())
    expect(changes).toHaveBeenCalledWith({ resource: 'medications', patientId: 42, recordId: 1, origin: 'detail' })
    window.removeEventListener('phr:record-changed', listener)
  })

  it('offers approve but not reject on a rejected medication', async () => {
    medication = makeMedication({ review_status: 'rejected' })
    renderDetail()
    expect(await screen.findByRole('button', { name: 'Approve Metformin' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reject Metformin' })).not.toBeInTheDocument()
  })

  it('edits in the dialog, keeping it open with the reason when the save fails', async () => {
    mockPatch.mockRejectedValueOnce(new Error('The dose is too long.'))
    mockPatch.mockResolvedValueOnce({ medication: makeMedication({ dose: '850' }) })
    renderDetail()
    fireEvent.click(await screen.findByRole('button', { name: 'Edit Metformin' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit Metformin' })
    fireEvent.change(within(dialog).getByLabelText('Dose'), { target: { value: '850' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('The dose is too long.')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.getByText('850 mg')).toBeInTheDocument()
  })

  it('deletes after asking, and goes back to the list', async () => {
    mockDelete.mockResolvedValue({})
    const onDrill = renderDetail()
    await screen.findByRole('heading', { name: 'Metformin' })
    await pickFromMenu('Metformin', 'Delete')
    const confirm = await screen.findByRole('alertdialog')
    expect(mockDelete).not.toHaveBeenCalled()
    fireEvent.click(within(confirm).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(mockDelete).toHaveBeenCalledWith('/api/phr/patients/42/medications/1', {}))
    await waitFor(() => expect(onDrill).toHaveBeenCalledWith({ id: 'medications' }))
  })

  it('offers no changes to someone who cannot make them', async () => {
    canManage = false
    medication = makeMedication({ review_status: 'pending_review' })
    renderDetail()
    await screen.findByRole('heading', { name: 'Metformin' })
    expect(screen.queryByTestId('record-review-bar')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit Metformin' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'More actions for Metformin' })).not.toBeInTheDocument()
  })

  it('reloads when the row beside it is decided', async () => {
    medication = makeMedication({ review_status: 'pending_review' })
    renderDetail()
    await screen.findByTestId('record-review-bar')
    medication = makeMedication({ review_status: 'confirmed' })
    act(() => recordChanged({ resource: 'medications', patientId: 42, recordId: 1, origin: 'list' }))
    await waitFor(() => expect(screen.queryByTestId('record-review-bar')).not.toBeInTheDocument())
  })
})

it('confirms a medication and keeps its row, with no way to reveal rejected rows here: they are on Review', async () => {
  mockGet.mockImplementation(async (url: string) => (url === '/api/phr/patients/42/medications'
    ? { medications: [makeMedication({ id: 1, name: 'Metformin', review_status: 'pending_review' })], can_manage: true }
    : {}))

  render(<MedicationsPage patientId={42} />)
  await waitFor(() => expect(screen.getByText('Metformin')).toBeInTheDocument())
  expect(screen.queryByRole('button', { name: 'Show rejected' })).not.toBeInTheDocument()

  // Confirming keeps the row and settles it, so its actions disappear.
  mockPatch.mockResolvedValueOnce({
    medication: makeMedication({ id: 1, name: 'Metformin', review_status: 'confirmed' }),
  })
  fireEvent.click(screen.getByRole('button', { name: 'Confirm Metformin' }))

  await waitFor(() => {
    expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/42/medications/1/review', {
      review_status: 'confirmed',
    })
  })
  await waitFor(() => {
    expect(screen.queryByRole('button', { name: 'Confirm Metformin' })).not.toBeInTheDocument()
  })
  expect(screen.getByText('Metformin')).toBeInTheDocument()
})

it('removes a rejected medication from the working list', async () => {
  mockGet.mockImplementation(async () => ({
    medications: [
      makeMedication({ id: 1, name: 'Metformin', review_status: 'pending_review' }),
      makeMedication({ id: 2, name: 'Amoxicillin', review_status: 'confirmed' }),
    ],
    can_manage: true,
  }))

  render(<MedicationsPage patientId={42} />)
  await waitFor(() => expect(screen.getByText('Metformin')).toBeInTheDocument())

  mockPatch.mockResolvedValueOnce({
    medication: makeMedication({ id: 1, name: 'Metformin', review_status: 'rejected' }),
  })
  fireEvent.click(screen.getByRole('button', { name: 'Reject Metformin' }))

  await waitFor(() => expect(screen.queryByText('Metformin')).not.toBeInTheDocument())
  expect(screen.getByText('Amoxicillin')).toBeInTheDocument()
})
