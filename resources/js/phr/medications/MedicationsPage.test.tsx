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
