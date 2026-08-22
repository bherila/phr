import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import MedicationsPage from '@/phr/medications/MedicationsPage'

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockPatch = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
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

beforeEach(() => {
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
  it('splits active and historical medications', async () => {
    render(<MedicationsPage patientId={42} />)

    await waitFor(() => expect(screen.getByText('Metformin')).toBeInTheDocument())
    expect(screen.getByText('Active Medications')).toBeInTheDocument()
    expect(screen.queryByText('Amoxicillin')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /historical medications/i }))

    expect(await screen.findByText('Amoxicillin')).toBeInTheDocument()
    expect(screen.getByText('Finished antibiotic course')).toBeInTheDocument()
  })

  it('ends an active medication now and moves it into history', async () => {
    mockPatch.mockResolvedValue({
      medication: makeMedication({
        id: 1,
        status: 'discontinued',
        ended_on: '2026-05-17',
      }),
    })

    render(<MedicationsPage patientId={42} />)

    await waitFor(() => expect(screen.getByRole('button', { name: /end now/i })).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: /end now/i }))

    await waitFor(() => {
      expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/42/medications/1', {
        ended_on: '2026-05-17',
        status: 'discontinued',
      })
    })

    expect(await screen.findByText('No active medications match the current filter.')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Status filter'), { target: { value: 'discontinued' } })
    fireEvent.click(screen.getByRole('button', { name: /historical medications/i }))

    expect(await screen.findByText('Metformin')).toBeInTheDocument()
    expect(screen.getByText('discontinued')).toBeInTheDocument()
  })

  it('drills to medication detail when a row is clicked', async () => {
    const onDrill = jest.fn()

    mockPatch.mockResolvedValue({
      medication: makeMedication({
        id: 1,
      }),
    })

    render(<MedicationsPage patientId={42} onDrill={onDrill} />)

    await waitFor(() => expect(screen.getByText('Metformin')).toBeInTheDocument())
    fireEvent.click(screen.getByText('Metformin'))

    expect(onDrill).toHaveBeenCalledWith({ id: 'medication-detail', instance: '1' })

    onDrill.mockClear()
    fireEvent.click(screen.getByTitle('Edit medication'))
    expect(onDrill).not.toHaveBeenCalled()
  })
})

it('confirms a medication, drops a rejected one from the list, and can reveal rejected rows', async () => {
  mockGet.mockImplementation(async (url: string) => {
    if (url === '/api/phr/patients/42/medications') {
      return {
        medications: [makeMedication({ id: 1, name: 'Metformin', review_status: 'pending_review' })],
        can_manage: true,
      }
    }

    if (url === '/api/phr/patients/42/medications?include_rejected=1') {
      return {
        medications: [
          makeMedication({ id: 1, name: 'Metformin', review_status: 'pending_review' }),
          makeMedication({ id: 3, name: 'Lisinopril', review_status: 'rejected' }),
        ],
        can_manage: true,
      }
    }

    return {}
  })

  render(<MedicationsPage patientId={42} />)
  await waitFor(() => expect(screen.getByText('Metformin')).toBeInTheDocument())

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

  // Revealing rejected rows re-fetches with the flag and shows the hidden record.
  fireEvent.click(screen.getByRole('button', { name: 'Show rejected' }))
  await waitFor(() => expect(screen.getByText('Lisinopril')).toBeInTheDocument())
  expect(mockGet).toHaveBeenCalledWith('/api/phr/patients/42/medications?include_rejected=1')
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
