import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import HealthLogPage from './HealthLogPage'

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

function makeHealthLog(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 51,
    patient_id: 42,
    user_id: 7,
    created_by_user_id: 7,
    name: 'Symptom tracker',
    kind: 'symptom',
    description: 'Track symptoms over time.',
    archived_at: null,
    entries_count: 1,
    latest_entry_at: '2026-07-13T09:30:00Z',
    created_at: '2026-07-12T09:30:00Z',
    updated_at: '2026-07-13T09:30:00Z',
    ...overrides,
  }
}

function makeEntry(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 91,
    health_log_id: 51,
    patient_id: 42,
    user_id: 7,
    recorded_by_user_id: 7,
    occurred_at: '2026-07-13T09:30:00Z',
    title: 'Morning check-in',
    notes: 'Mild pressure after waking.',
    intensity: 3,
    tags: ['morning'],
    details: { duration_minutes: 20 },
    created_at: '2026-07-13T09:31:00Z',
    updated_at: '2026-07-13T09:31:00Z',
    ...overrides,
  }
}

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
  mockPatch.mockReset()
  mockDelete.mockReset()
})

describe('HealthLogPage', () => {
  it('renders a selected log and its entry timeline', async () => {
    mockGet.mockImplementation(async (url: string) => {
      if (url === '/api/phr/patients/42/health-logs') {
        return { health_logs: [makeHealthLog()], can_manage: true }
      }
      return { entries: [makeEntry()], can_manage: true }
    })

    render(<HealthLogPage patientId={42} />)

    expect(await screen.findByText('Morning check-in')).toBeInTheDocument()
    expect(screen.getByText('Intensity 3/10')).toBeInTheDocument()
    expect(screen.getByText('duration minutes')).toBeInTheDocument()
    expect(screen.getByText('20')).toBeInTheDocument()
  })

  it('creates an arbitrary named log', async () => {
    const createdLog = makeHealthLog({ id: 52, name: 'Daily observations', kind: 'custom', entries_count: 0, latest_entry_at: null })
    mockGet.mockImplementation(async (url: string) => {
      if (url === '/api/phr/patients/42/health-logs') {
        return { health_logs: [], can_manage: true }
      }
      return { entries: [], can_manage: true }
    })
    mockPost.mockResolvedValue({ health_log: createdLog })

    render(<HealthLogPage patientId={42} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Create a log' }))
    fireEvent.change(screen.getByLabelText('Log name'), { target: { value: 'Daily observations' } })
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'custom' } })
    fireEvent.change(screen.getByLabelText('Description'), { target: { value: 'Flexible notes.' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create log' }))

    await waitFor(() => {
      expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/42/health-logs', {
        name: 'Daily observations',
        kind: 'custom',
        description: 'Flexible notes.',
      })
    })
    expect(await screen.findByRole('heading', { name: 'Daily observations' })).toBeInTheDocument()
  })

  it('records a structured entry with intensity and tags', async () => {
    const healthLog = makeHealthLog({ entries_count: 0, latest_entry_at: null })
    const createdEntry = makeEntry({ id: 92, title: 'Afternoon check-in', intensity: 6, tags: ['afternoon', 'outdoors'] })
    mockGet.mockImplementation(async (url: string) => {
      if (url === '/api/phr/patients/42/health-logs') {
        return { health_logs: [healthLog], can_manage: true }
      }
      return { entries: [], can_manage: true }
    })
    mockPost.mockResolvedValue({ entry: createdEntry })

    render(<HealthLogPage patientId={42} />)

    const title = await screen.findByLabelText('Title')
    fireEvent.change(title, { target: { value: 'Afternoon check-in' } })
    fireEvent.change(screen.getByLabelText(/Intensity/), { target: { value: '6' } })
    fireEvent.change(screen.getByLabelText(/Tags/), { target: { value: 'afternoon, outdoors' } })
    fireEvent.click(screen.getByRole('button', { name: 'Add structured details' }))
    fireEvent.change(screen.getByLabelText(/Structured details/), { target: { value: '{"duration_minutes":45}' } })
    fireEvent.click(screen.getByRole('button', { name: 'Record entry' }))

    await waitFor(() => {
      expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/42/health-logs/51/entries', expect.objectContaining({
        title: 'Afternoon check-in',
        intensity: 6,
        tags: ['afternoon', 'outdoors'],
        details: { duration_minutes: 45 },
      }))
    })
  })

  it('keeps viewer access read-only', async () => {
    mockGet.mockImplementation(async (url: string) => {
      if (url === '/api/phr/patients/42/health-logs') {
        return { health_logs: [makeHealthLog()], can_manage: false }
      }
      return { entries: [makeEntry()], can_manage: false }
    })

    render(<HealthLogPage patientId={42} />)

    expect(await screen.findByText('You have read-only access to this health log.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Record entry' })).not.toBeInTheDocument()
    expect(screen.queryByTitle('Edit health log entry')).not.toBeInTheDocument()
  })
})
