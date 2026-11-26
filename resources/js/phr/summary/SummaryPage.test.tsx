import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { PhrPatientsProvider } from '@/phr/patients/usePhrPatients'
import type { PhrPatient } from '@/phr/types'

import SummaryPage from './SummaryPage'

const mockGet = jest.fn()
const mockPatch = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
  },
}))
jest.mock('@/phr/review/reviewSummary', () => ({
  ...jest.requireActual('@/phr/review/reviewSummary'),
  useReviewSummary: () => null,
}))

function patient(overrides: Partial<PhrPatient> = {}): PhrPatient {
  return {
    id: 7, owner_user_id: 1, display_name: 'Sam Rivera', relationship: 'Child', birth_date: '2016-04-09', sex_at_birth: 'Male', notes: null, // sensitive-scan-ignore: synthetic fixture
    real_names: ['RIVERA, SAM'], archived_at: null, created_at: null, updated_at: null, access_level: 'owner', can_manage: true, can_share: true, access_grants: [],
    ...overrides,
  }
}

function serve(target: PhrPatient): void {
  mockGet.mockImplementation((url: string) => {
    if (url === '/api/phr/patients/7') return Promise.resolve({ patient: target })
    if (url === '/api/phr/patients') return Promise.resolve({ patients: [target] })
    if (url.endsWith('/lab-results')) return Promise.resolve({ lab_results: [] })
    if (url.endsWith('/vitals')) return Promise.resolve({ vitals: [] })
    if (url.endsWith('/dicom/studies')) return Promise.resolve({ studies: [] })
    if (url.endsWith('/exports')) return Promise.resolve({ exports: [] })
    return Promise.reject(new Error(`Unexpected GET ${url}`))
  })
}

beforeEach(() => {
  mockGet.mockReset()
  mockPatch.mockReset()
})

describe('SummaryPage profile', () => {
  it('shows who the patient is and edits the profile without leaving the record', async () => {
    serve(patient())
    mockPatch.mockResolvedValueOnce({ patient: patient({ display_name: 'Samuel Rivera', relationship: 'Son' }) })
    render(<PhrPatientsProvider><SummaryPage patientId={7} /></PhrPatientsProvider>)

    expect(await screen.findByRole('heading', { name: 'Sam Rivera' })).toBeInTheDocument()
    expect(screen.getByText(/^Child · born 2016-04-09 \(\d+ y\) · Male · 1 name on documents$/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Edit profile' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit profile' })
    fireEvent.change(within(dialog).getByLabelText('Name *'), { target: { value: 'Samuel Rivera' } })
    fireEvent.change(within(dialog).getByLabelText('Relationship'), { target: { value: 'Son' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    expect(await screen.findByRole('heading', { name: 'Samuel Rivera' })).toBeInTheDocument()
    expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/7', expect.objectContaining({ display_name: 'Samuel Rivera', relationship: 'Son' }))
    expect(screen.getByText(/^Son · born/)).toBeInTheDocument()
  })

  it('offers sharing, export and deletion to the owner beside Edit profile', async () => {
    serve(patient())
    render(<PhrPatientsProvider><SummaryPage patientId={7} /></PhrPatientsProvider>)
    fireEvent.click(await screen.findByRole('button', { name: 'More actions for Sam Rivera' }))
    expect((await screen.findAllByRole('menuitem')).map((item) => item.textContent)).toEqual(['Sharing and access', 'Export or back up', 'Delete patient…'])
  })

  it('lets a manager edit the profile but not reach owner actions, and a viewer neither', async () => {
    serve(patient({ access_level: 'manager', can_share: false }))
    const { unmount } = render(<PhrPatientsProvider><SummaryPage patientId={7} /></PhrPatientsProvider>)
    expect(await screen.findByRole('button', { name: 'Edit profile' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /More actions/ })).toBeNull()
    unmount()

    serve(patient({ access_level: 'viewer', can_manage: false, can_share: false }))
    render(<PhrPatientsProvider><SummaryPage patientId={7} /></PhrPatientsProvider>)
    await screen.findByRole('heading', { name: 'Sam Rivera' })
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Edit profile' })).toBeNull())
  })
})
