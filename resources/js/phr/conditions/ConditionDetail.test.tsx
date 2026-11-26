import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { PhrPatientsProvider } from '@/phr/patients/usePhrPatients'

import ConditionDetail from './ConditionDetail'

const mockGetRaw = jest.fn()
const mockGet = jest.fn()
const mockPatch = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    getRaw: (...args: unknown[]) => mockGetRaw(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
}))
jest.mock('@/phr/review/reviewSummary', () => ({
  ...jest.requireActual('@/phr/review/reviewSummary'),
  reviewStateChanged: jest.fn(() => Promise.resolve()),
}))

function condition(overrides: Record<string, unknown> = {}) {
  return {
    id: 21, patient_id: 2, user_id: 1, import_source: null, external_id: null, source_document_id: null, review_status: 'confirmed',
    name: 'Asthma', icd10_code: 'J45.20', snomed_code: null, onset_date: '2019-03-01', abated_date: null, clinical_status: 'active',
    verification_status: 'confirmed', severity: null, notes: null, raw_text: null, created_at: null, updated_at: null,
    ...overrides,
  }
}

function patients(canManage: boolean) {
  return {
    patients: [{
      id: 2, owner_user_id: 1, display_name: 'Sam', relationship: null, birth_date: null, sex_at_birth: null, notes: null,
      archived_at: null, created_at: null, updated_at: null, access_level: canManage ? 'owner' : 'viewer', can_manage: canManage, can_share: canManage, access_grants: [],
    }],
  }
}

function jsonResponse(payload: unknown): Response {
  return { ok: true, status: 200, statusText: 'OK', text: async () => JSON.stringify(payload) } as Response
}

function serve(canManage = true): void {
  mockGet.mockResolvedValue(patients(canManage))
  mockGetRaw.mockImplementation(() => Promise.resolve(jsonResponse({ condition: condition() })))
}

beforeEach(() => {
  mockGet.mockReset()
  mockGetRaw.mockReset()
  mockPatch.mockReset()
  mockDelete.mockReset()
})

describe('ConditionDetail', () => {
  it('edits the condition from its panel and shows what was saved', async () => {
    serve()
    mockPatch.mockResolvedValue({ condition: condition({ name: 'Asthma, mild intermittent', icd10_code: 'J45.20' }) })
    render(<PhrPatientsProvider><ConditionDetail patientId={2} recordId="21" /></PhrPatientsProvider>)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit Asthma' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit condition' })
    expect(within(dialog).getByRole('textbox', { name: 'ICD-10 Code' })).toHaveValue('J45.20')
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Name *' }), { target: { value: 'Asthma, mild intermittent' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('heading', { name: 'Asthma, mild intermittent' })).toBeInTheDocument()
    expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/2/conditions/21', expect.objectContaining({ name: 'Asthma, mild intermittent' }))
  })

  it('deletes after asking, then returns to the list', async () => {
    serve()
    mockDelete.mockResolvedValue({})
    const onDrill = jest.fn()
    render(<PhrPatientsProvider><ConditionDetail patientId={2} recordId="21" onDrill={onDrill} /></PhrPatientsProvider>)
    fireEvent.click(await screen.findByRole('button', { name: 'More actions for Asthma' }))
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Delete' }))
    const dialog = await screen.findByRole('alertdialog', { name: 'Delete this condition?' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(onDrill).toHaveBeenCalledWith({ id: 'conditions' }))
    expect(mockDelete).toHaveBeenCalledWith('/api/phr/patients/2/conditions/21', {})
  })

  it('offers no edit or delete to someone who can only read the record', async () => {
    serve(false)
    render(<PhrPatientsProvider><ConditionDetail patientId={2} recordId="21" /></PhrPatientsProvider>)
    await screen.findByRole('heading', { name: 'Asthma' })
    await waitFor(() => expect(mockGet).toHaveBeenCalled())
    expect(screen.queryByRole('button', { name: 'Edit Asthma' })).toBeNull()
    expect(screen.queryByRole('button', { name: /More actions/ })).toBeNull()
  })
})
