import '@testing-library/jest-dom'

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { StrictMode, useState } from 'react'

import PhrNavbar from '@/components/phr/PhrNavbar'
import ImportsPage from '@/phr/imports/ImportsPage'
import PatientsManagePage from '@/phr/patients-manage/PatientsManagePage'
import type { PhrPatient } from '@/phr/types'

import PatientsPage from './PatientsPage'
import { PhrPatientsProvider, usePhrPatients } from './usePhrPatients'

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockPatch = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
  },
}))
jest.mock('@/phr/uploads', () => ({ useDicomUploads: () => ({ startUpload: jest.fn() }) }))
jest.mock('@/phr/imports/ehi/EhiUploadProvider', () => ({ useEhiUploads: () => ({ jobs: [], start: jest.fn(), cancel: jest.fn(), dismiss: jest.fn() }) }))
// The imports page also lists recent EHI imports; keep those off the patient-list GET counted here.
jest.mock('@/phr/imports/ehi/ehiApi', () => ({
  ...jest.requireActual('@/phr/imports/ehi/ehiApi'),
  listEhiUploads: () => Promise.resolve([]),
  listEhiPackages: () => Promise.resolve([]),
}))
jest.mock('@/phr/imports/ai/aiImportsApi', () => ({
  ...jest.requireActual('@/phr/imports/ai/aiImportsApi'),
  listAiImports: () => Promise.resolve([]),
}))

function patient(overrides: Partial<PhrPatient> = {}): PhrPatient {
  return {
    id: 42, owner_user_id: 7, display_name: 'Synthetic Profile', relationship: 'self',
    birth_date: null, sex_at_birth: null, notes: 'Full profile notes', archived_at: null,
    created_at: null, updated_at: null, access_level: 'owner', can_manage: true, can_share: true,
    access_grants: [{ id: 1, user_id: 7, user_name: 'Synthetic Owner', user_email: 'owner@example.test', access_level: 'owner', granted_at: null }],
    ...overrides,
  }
}

function seed(raw: unknown): void {
  const node = document.createElement('script')
  node.id = 'app-initial-data'
  node.type = 'application/json'
  node.textContent = JSON.stringify(raw)
  document.getElementById(node.id)?.remove()
  document.body.appendChild(node)
}

function Consumer({ id = 'list' }: { id?: string }) {
  const { patients, loaded, busy, error, reloadPatients, upsertPatient, removePatient } = usePhrPatients()
  return <section aria-label={id}>
    <output data-testid={id}>{JSON.stringify({ patients, loaded, busy, error })}</output>
    <button type="button" onClick={() => void reloadPatients()}>Reload {id}</button>
    <button type="button" onClick={() => void reloadPatients({ afterMutation: true })}>Refresh mutation {id}</button>
    <button type="button" onClick={() => upsertPatient(patient({ display_name: 'Renamed Profile' }))}>Rename {id}</button>
    <button type="button" onClick={() => upsertPatient(patient({ id: 99, display_name: 'Created Profile' }))}>Create {id}</button>
    <button type="button" onClick={() => removePatient(42)}>Remove {id}</button>
  </section>
}

function snapshot(id = 'list') {
  return JSON.parse(screen.getByTestId(id).textContent!) as { patients: PhrPatient[], loaded: boolean, busy: boolean, error: string | null }
}

beforeEach(() => {
  document.getElementById('app-initial-data')?.remove()
  mockGet.mockReset()
  mockPost.mockReset()
  mockPatch.mockReset()
  mockGet.mockResolvedValue({ patients: [] })
})
afterEach(() => { document.getElementById('app-initial-data')?.remove() })

it('uses the full validated bootstrap immediately without an initial request or loading flash', () => {
  seed({ phr: { patient_list: { patients: [patient()] } } })
  render(<StrictMode><PhrPatientsProvider><Consumer /></PhrPatientsProvider></StrictMode>)
  expect(snapshot()).toEqual({ patients: [patient()], loaded: true, busy: false, error: null })
  expect(mockGet).not.toHaveBeenCalled()
})

it('treats a present empty bootstrap list as loaded', () => {
  seed({ phr: { patient_list: { patients: [] } } })
  render(<PhrPatientsProvider><Consumer /><PatientsPage /></PhrPatientsProvider>)
  expect(snapshot().loaded).toBe(true)
  expect(snapshot().busy).toBe(false)
  expect(screen.getByText('No patient profiles yet')).toBeInTheDocument()
  expect(mockGet).not.toHaveBeenCalled()
})

it.each(['absent', 'invalid JSON', 'invalid DTO'])('deduplicates one fallback request across StrictMode and consumers for %s bootstrap', async (kind) => {
  if (kind === 'invalid JSON') {
    seed({})
    document.getElementById('app-initial-data')!.textContent = '{broken'
  } else if (kind === 'invalid DTO') {
    seed({ phr: { patient_list: { patients: [{ id: 42, display_name: 'Incomplete' }] } } })
  }
  let finish!: (value: unknown) => void
  mockGet.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
  render(<StrictMode><PhrPatientsProvider><Consumer /><Consumer id="other" /><PatientsPage /><ImportsPage /><PhrNavbar patientId={42} /></PhrPatientsProvider></StrictMode>)
  await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(1))
  expect(snapshot().busy).toBe(true)
  await act(async () => { finish({ patients: [patient()] }) })
  expect(snapshot('list').patients).toEqual([patient()])
  expect(snapshot('other').patients).toEqual([patient()])
  expect(screen.getByLabelText('Selected patient: Synthetic Profile')).toBeInTheDocument()
  expect(screen.getByLabelText('Import into')).toHaveValue('42')
  expect(mockGet).toHaveBeenCalledTimes(1)
})

it('reuses the list as sections unmount and mount inside the same provider', async () => {
  function Navigation() {
    const [page, setPage] = useState('patients')
    return <><button type="button" onClick={() => setPage('imports')}>Open imports</button><button type="button" onClick={() => setPage('manage')}>Open manage</button>
      {page === 'patients' ? <PatientsPage /> : page === 'imports' ? <ImportsPage /> : <PatientsManagePage />}</>
  }
  mockGet.mockResolvedValueOnce({ patients: [patient()] })
  render(<PhrPatientsProvider><Navigation /></PhrPatientsProvider>)
  await screen.findByText('Synthetic Profile')
  fireEvent.click(screen.getByRole('button', { name: 'Open imports' }))
  expect(screen.getByLabelText('Import into')).toHaveValue('42')
  fireEvent.click(screen.getByRole('button', { name: 'Open manage' }))
  expect(screen.getByText('Synthetic Profile')).toBeInTheDocument()
  expect(mockGet).toHaveBeenCalledTimes(1)
})

it('keeps seeded rows during background reload and merges completed mutations over stale GET data', async () => {
  seed({ phr: { patient_list: { patients: [patient()] } } })
  let finish!: (value: unknown) => void
  mockGet.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
  render(<PhrPatientsProvider><Consumer /><Consumer id="other" /></PhrPatientsProvider>)
  fireEvent.click(screen.getByRole('button', { name: 'Reload list' }))
  await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(1))
  expect(snapshot().patients).toEqual([patient()])
  fireEvent.click(screen.getByRole('button', { name: 'Rename list' }))
  fireEvent.click(screen.getByRole('button', { name: 'Create list' }))
  expect(snapshot('other').patients.map((item) => item.display_name)).toEqual(['Renamed Profile', 'Created Profile'])
  await act(async () => { finish({ patients: [patient(), patient({ id: 101, display_name: 'Unrelated profile' })] }) })
  expect(snapshot().patients.map((item) => item.display_name)).toEqual(['Unrelated profile', 'Renamed Profile', 'Created Profile'])
  expect(snapshot('other').patients).toEqual(snapshot().patients)
  expect(snapshot().busy).toBe(false)
})

it('does not resurrect a deleted patient from an older in-flight GET', async () => {
  seed({ phr: { patient_list: { patients: [patient()] } } })
  let finish!: (value: unknown) => void
  mockGet.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
  render(<PhrPatientsProvider><Consumer /><ImportsPage patientId={42} /></PhrPatientsProvider>)
  expect(screen.getByRole('button', { name: 'Choose DICOM folder' })).toBeEnabled()
  fireEvent.click(screen.getByRole('button', { name: 'Reload list' }))
  await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(1))
  fireEvent.click(screen.getByRole('button', { name: 'Remove list' }))
  expect(screen.getByRole('button', { name: 'Choose DICOM folder' })).toBeDisabled()
  expect(screen.getByLabelText('Import into')).toHaveValue('')
  await act(async () => { finish({ patients: [patient()] }) })
  expect(snapshot().patients).toEqual([])
  expect(screen.getByRole('button', { name: 'Choose DICOM folder' })).toBeDisabled()
})

it('preserves loaded patients after a failed reload and permits a retry', async () => {
  seed({ phr: { patient_list: { patients: [patient()] } } })
  mockGet.mockRejectedValueOnce('Unavailable').mockResolvedValueOnce({ patients: [patient({ display_name: 'Fresh profile' })] })
  render(<PhrPatientsProvider><Consumer /></PhrPatientsProvider>)
  fireEvent.click(screen.getByRole('button', { name: 'Reload list' }))
  await waitFor(() => expect(snapshot().error).toBe('Unavailable'))
  expect(snapshot().patients).toEqual([patient()])
  fireEvent.click(screen.getByRole('button', { name: 'Reload list' }))
  await waitFor(() => expect(snapshot().patients[0]?.display_name).toBe('Fresh profile'))
  expect(snapshot().error).toBeNull()
})

it('starts a fresh scoped store for a new provider rather than retaining another account’s data', () => {
  seed({ phr: { patient_list: { patients: [patient()] } } })
  const first = render(<PhrPatientsProvider><Consumer /></PhrPatientsProvider>)
  first.unmount()
  seed({ phr: { patient_list: { patients: [patient({ id: 88, display_name: 'Second account profile' })] } } })
  render(<PhrPatientsProvider><Consumer /></PhrPatientsProvider>)
  expect(snapshot().patients.map((item) => item.display_name)).toEqual(['Second account profile'])
  expect(mockGet).not.toHaveBeenCalled()
})

it('publishes successful create and edit responses to the navbar, patient cards and import picker', async () => {
  seed({ phr: { patient_list: { patients: [patient()] } } })
  mockPost.mockResolvedValueOnce({ patient: patient({ id: 99, display_name: 'Created profile', notes: 'Created full notes' }) })
  mockPatch.mockResolvedValueOnce({ patient: patient({ display_name: 'Edited profile', notes: 'Updated full notes' }) })
  render(<PhrPatientsProvider><PhrNavbar patientId={42} /><div data-testid="cards"><PatientsPage /></div><ImportsPage /><div data-testid="manage"><PatientsManagePage /></div><Consumer /></PhrPatientsProvider>)
  const manage = within(screen.getByTestId('manage'))
  const createForm = manage.getByRole('button', { name: 'Add Profile' }).closest('form')!
  fireEvent.change(within(createForm).getByLabelText('Name *'), { target: { value: 'Created profile' } })
  fireEvent.submit(createForm)
  await waitFor(() => expect(within(screen.getByTestId('cards')).getByText('Created profile')).toBeInTheDocument())
  expect(screen.getByRole('option', { name: 'Created profile' })).toBeInTheDocument()
  expect(snapshot().patients.find((item) => item.id === 99)?.notes).toBe('Created full notes')
  fireEvent.click(manage.getAllByTitle('Edit')[0]!)
  const editForm = manage.getByRole('button', { name: 'Save' }).closest('form')!
  expect(within(editForm).getByLabelText('Notes')).toHaveValue('Full profile notes')
  fireEvent.change(within(editForm).getByLabelText('Name *'), { target: { value: 'Edited profile' } })
  fireEvent.submit(editForm)
  await waitFor(() => expect(screen.getByLabelText('Selected patient: Edited profile')).toBeInTheDocument())
  expect(within(screen.getByTestId('cards')).getByText('Edited profile')).toBeInTheDocument()
  expect(screen.getByRole('option', { name: 'Edited profile' })).toBeInTheDocument()
  expect(snapshot().patients.find((item) => item.id === 42)?.notes).toBe('Updated full notes')
  expect(snapshot().patients.find((item) => item.id === 42)?.access_grants).toEqual(patient().access_grants)
  expect(mockGet).not.toHaveBeenCalled()
})


it('starts a fresh refresh after a pending pre-mutation request for restore and share writes', async () => {
  seed({ phr: { patient_list: { patients: [patient()] } } })
  let finish!: (value: unknown) => void
  mockGet.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
    .mockResolvedValueOnce({ patients: [patient(), patient({ id: 123, display_name: 'Restored profile' })] })
  render(<PhrPatientsProvider><Consumer /></PhrPatientsProvider>)
  fireEvent.click(screen.getByRole('button', { name: 'Reload list' }))
  await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(1))
  fireEvent.click(screen.getByRole('button', { name: 'Refresh mutation list' }))
  expect(mockGet).toHaveBeenCalledTimes(1)
  await act(async () => { finish({ patients: [patient()] }) })
  await waitFor(() => expect(snapshot().patients.map((item) => item.display_name)).toEqual(['Synthetic Profile', 'Restored profile']))
  expect(mockGet).toHaveBeenCalledTimes(2)
})


it.each(['patients', 'manage', 'imports'])('recovers a failed fallback from the real %s page Retry action', async (page) => {
  mockGet.mockRejectedValueOnce('Patient list unavailable').mockResolvedValueOnce({ patients: [patient()] })
  render(<PhrPatientsProvider>
    {page === 'patients' ? <PatientsPage /> : page === 'manage' ? <PatientsManagePage /> : <ImportsPage />}
  </PhrPatientsProvider>)
  expect(await screen.findByRole('alert')).toHaveTextContent('Patient list unavailable')
  expect(mockGet).toHaveBeenCalledTimes(1)
  fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
  if (page === 'imports') {
    expect(await screen.findByRole('option', { name: 'Synthetic Profile' })).toBeInTheDocument()
    expect(screen.getByLabelText('Import into')).toHaveValue('42')
  } else {
    expect(await screen.findByText('Synthetic Profile')).toBeInTheDocument()
  }
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  expect(mockGet).toHaveBeenCalledTimes(2)
})


it('keeps a resolved import target through edits and reordering, replacing it only when unavailable', async () => {
  seed({ phr: { patient_list: { patients: [patient(), patient({ id: 99, display_name: 'Second Profile' })] } } })
  render(<PhrPatientsProvider><Consumer /><ImportsPage /></PhrPatientsProvider>)
  expect(screen.getByLabelText('Import into')).toHaveValue('42')
  fireEvent.click(screen.getByRole('button', { name: 'Rename list' }))
  expect(snapshot().patients.map((item) => item.id)).toEqual([99, 42])
  expect(screen.getByLabelText('Import into')).toHaveValue('42')
  fireEvent.click(screen.getByRole('button', { name: 'Remove list' }))
  expect(screen.getByLabelText('Import into')).toHaveValue('99')
  mockGet.mockResolvedValueOnce({ patients: [patient(), patient({ id: 99, display_name: 'Second Profile' })] })
  fireEvent.click(screen.getByRole('button', { name: 'Reload list' }))
  await waitFor(() => expect(snapshot().patients.map((item) => item.id)).toEqual([42, 99]))
  expect(screen.getByLabelText('Import into')).toHaveValue('99')
})

it('keeps a failed initial list retryable after a successful profile creation', async () => {
  mockGet.mockRejectedValueOnce('Patient list unavailable')
    .mockResolvedValueOnce({ patients: [patient(), patient({ id: 99, display_name: 'Created Profile' })] })
  mockPost.mockResolvedValueOnce({ patient: patient({ id: 99, display_name: 'Created Profile' }) })
  render(<PhrPatientsProvider><Consumer /><PatientsManagePage /></PhrPatientsProvider>)
  expect(await screen.findByRole('alert')).toHaveTextContent('Patient list unavailable')
  const createForm = screen.getByRole('button', { name: 'Add Profile' }).closest('form')!
  fireEvent.change(within(createForm).getByLabelText('Name *'), { target: { value: 'Created Profile' } })
  fireEvent.submit(createForm)
  await screen.findByText('Created Profile')
  expect(snapshot().loaded).toBe(false)
  expect(snapshot().error).toBe('Patient list unavailable')
  fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
  await screen.findByText('Synthetic Profile')
  expect(snapshot().patients.map((item) => item.id)).toEqual([42, 99])
  expect(snapshot().loaded).toBe(true)
  expect(snapshot().error).toBeNull()
  expect(mockGet).toHaveBeenCalledTimes(2)
})
