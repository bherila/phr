import '@testing-library/jest-dom'

import { render, screen, within } from '@testing-library/react'

import type { PhrPatient } from '@/phr/types'

import PatientsPage, { relationshipLabel } from './PatientsPage'
import { PhrPatientsProvider } from './usePhrPatients'

const mockGet = jest.fn()

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: (...args: unknown[]) => mockGet(...args) } }))

function patient(id: number, name: string, overrides: Partial<PhrPatient> = {}): PhrPatient {
  return {
    id, owner_user_id: 1, display_name: name, relationship: 'self', birth_date: null, sex_at_birth: null, notes: null,
    archived_at: null, created_at: null, updated_at: null, access_level: 'owner', can_manage: true, can_share: true, access_grants: [],
    ...overrides,
  }
}

const PATIENTS = [
  patient(1, 'Sample Patient'),
  patient(2, 'Family Member', { relationship: 'child' }),
  patient(3, 'Shared Patient', { owner_user_id: 9, access_level: 'viewer', can_manage: false, can_share: false }),
]

const OVERVIEW = {
  patients: [
    { id: 1, records: 142, last_updated: '2026-03-02T10:00:00+00:00', pending: 9 },
    { id: 2, records: 37, last_updated: null, pending: 0 },
    { id: 3, records: 64, last_updated: '2026-02-01T10:00:00+00:00', pending: 0 },
  ],
  attention: [
    { kind: 'ready', origin: 'records', patient_id: 1, count: 9, name: null, date: '2026-03-02T10:00:00+00:00' },
    { kind: 'ready', origin: 'ai', patient_id: 1, count: 2, name: 'Synthetic letter', date: null },
    { kind: 'failed', origin: 'upload', patient_id: 2, count: 0, name: null, date: null },
  ],
}

beforeEach(() => {
  mockGet.mockReset()
  mockGet.mockImplementation((url: string) => Promise.resolve(url === '/api/phr/patients' ? { patients: PATIENTS } : OVERVIEW))
})

describe('PatientsPage', () => {
  it('shows each patient with your relationship, counts and what waits, from one counts request', async () => {
    render(<PhrPatientsProvider><PatientsPage /></PhrPatientsProvider>)

    const own = (await screen.findAllByText('Sample Patient'))[0]!.closest('a')!
    expect(own).toHaveAttribute('href', '/phr/patient/1')
    expect(within(own).getByText('You')).toBeInTheDocument()
    expect(await within(own).findByText('142')).toBeInTheDocument()
    expect(within(own).getByText('2026-03-02')).toBeInTheDocument()
    expect(within(own).getByText('9 waiting for review')).toBeInTheDocument()

    const family = screen.getAllByText('Family Member')[0]!.closest('a')!
    expect(within(family).getByText('You manage this record')).toBeInTheDocument()
    expect(within(family).queryByText(/waiting for review/)).toBeNull()
    expect(within(family).queryByText('View only')).toBeNull()

    const shared = screen.getByText('Shared Patient').closest('a')!
    expect(within(shared).getByText('Shared with you')).toBeInTheDocument()
    expect(within(shared).getByText('View only')).toBeInTheDocument()

    expect(mockGet.mock.calls.filter(([url]) => url === '/api/phr/patients/overview')).toHaveLength(1)
  })

  it('lists what needs attention across patients, failures last', async () => {
    render(<PhrPatientsProvider><PatientsPage /></PhrPatientsProvider>)

    const list = within(await screen.findByRole('region', { name: 'Needs your attention' }))
    const links = list.getAllByRole('link')
    expect(links.map((link) => link.getAttribute('href'))).toEqual(['/phr/patient/1/review', '/phr/patient/1/review', '/phr/imports?patient_id=2'])
    expect(list.getByText('Records ready to review: 9 records')).toBeInTheDocument()
    expect(list.getByText('AI extraction finished for Synthetic letter: 2 proposals')).toBeInTheDocument()
    expect(list.getByText('Import failed: the file could not be read')).toBeInTheDocument()
    expect(links[2]).toHaveTextContent('See details')
  })

  it('still lists patients when the counts cannot be read', async () => {
    mockGet.mockImplementation((url: string) => (url === '/api/phr/patients' ? Promise.resolve({ patients: PATIENTS }) : Promise.reject(new Error('offline'))))
    render(<PhrPatientsProvider><PatientsPage /></PhrPatientsProvider>)

    expect(await screen.findByText('Shared Patient')).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Needs your attention' })).toBeNull()
  })
})

describe('relationshipLabel', () => {
  it('names your own profile, one you own for someone else, and one shared with you', () => {
    expect(PATIENTS.map((candidate) => relationshipLabel(candidate))).toEqual(['You', 'You manage this record', 'Shared with you'])
    expect(relationshipLabel(patient(4, 'Managed', { access_level: 'manager', can_share: false }))).toBe('Shared with you')
  })
})
