import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { useState } from 'react'

import type { PhrPatient } from '@/phr/types'

import { PatientProfileDialog } from './PatientProfileDialog'
import { EMPTY_PATIENT_FORM, PatientProfileFields, sexAtBirthOption } from './PatientProfileFields'
import PatientsPage from './PatientsPage'
import { PhrPatientsProvider } from './usePhrPatients'

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockPatch = jest.fn()
const mockPush = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
  },
}))
jest.mock('@/phr/shell/phrLocation', () => ({
  ...jest.requireActual('@/phr/shell/phrLocation'),
  pushPhrLocation: (url: string) => mockPush(url),
}))
jest.mock('./patientOverview', () => ({
  ...jest.requireActual('./patientOverview'),
  getPatientOverview: () => new Promise(() => {}),
}))

function patient(id: number, name: string, overrides: Partial<PhrPatient> = {}): PhrPatient {
  return {
    id, owner_user_id: 1, display_name: name, relationship: 'self', birth_date: null, sex_at_birth: null, notes: null,
    archived_at: null, created_at: null, updated_at: null, access_level: 'owner', can_manage: true, can_share: true, access_grants: [],
    ...overrides,
  }
}

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
  mockPatch.mockReset()
  mockPush.mockReset()
})

function renderDialog(target: PhrPatient | null = null) {
  const onSaved = jest.fn()
  const onOpenChange = jest.fn()
  mockGet.mockResolvedValue({ patients: [] })
  render(<PhrPatientsProvider><PatientProfileDialog open patient={target} onOpenChange={onOpenChange} onSaved={onSaved} /></PhrPatientsProvider>)
  return { onSaved, onOpenChange, dialog: screen.getByRole('dialog') }
}

describe('PatientProfileDialog', () => {
  it('asks for a name on the field instead of sending an empty one', () => {
    const { dialog } = renderDialog()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add and open record' }))
    expect(within(dialog).getByText('Enter a name.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Name *')).toHaveAttribute('aria-invalid', 'true')
    expect(mockPost).not.toHaveBeenCalled()
  })

  it('adds a patient from the short form, filling the relationship from a suggestion', async () => {
    mockPost.mockResolvedValueOnce({ patient: patient(5, 'Riley') })
    const { dialog, onSaved, onOpenChange } = renderDialog()
    // Names, sex at birth and notes wait under More details.
    expect(within(dialog).getByLabelText('Notes')).not.toBeVisible()
    fireEvent.change(within(dialog).getByLabelText('Name *'), { target: { value: 'Riley' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Child' }))
    expect(within(dialog).getByLabelText('Relationship')).toHaveValue('Child')
    expect(within(dialog).getByRole('button', { name: 'Child' })).toHaveAttribute('aria-pressed', 'true')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add and open record' }))
    await waitFor(() => expect(onSaved).toHaveBeenCalledWith(patient(5, 'Riley'), true))
    expect(mockPost).toHaveBeenCalledWith('/api/phr/patients', expect.objectContaining({ display_name: 'Riley', relationship: 'Child', birth_date: null, sex_at_birth: null }))
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it('edits every field at once and keeps a sex at birth none of the choices names', async () => {
    const target = patient(3, 'Sam', { sex_at_birth: 'X', real_names: ['RIVERA, SAM'], notes: 'Allergic to cats' })
    mockPatch.mockResolvedValueOnce({ patient: { ...target, sex_at_birth: 'Male' } })
    const { dialog, onSaved } = renderDialog(target)
    expect(within(dialog).getByLabelText('Notes')).toBeVisible()
    expect(within(dialog).getByRole('radio', { name: 'Other: X' })).toBeChecked()
    // The same name twice is refused before the server sees it.
    fireEvent.change(within(dialog).getByLabelText('Names on documents'), { target: { value: 'rivera, sam' } })
    expect(within(dialog).getByText('That name is already listed.')).toBeInTheDocument()
    fireEvent.change(within(dialog).getByLabelText('Names on documents'), { target: { value: '' } })
    fireEvent.click(within(dialog).getByRole('radio', { name: 'Male' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(onSaved).toHaveBeenCalledWith(expect.objectContaining({ id: 3 }), false))
    expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/3', expect.objectContaining({ sex_at_birth: 'Male', real_names: ['RIVERA, SAM'], notes: 'Allergic to cats' }))
  })

  it('shows a refused save in the dialog and keeps what was typed', async () => {
    mockPost.mockRejectedValueOnce({ message: 'The display name field must not be greater than 255 characters.' })
    const { dialog, onOpenChange } = renderDialog()
    fireEvent.change(within(dialog).getByLabelText('Name *'), { target: { value: 'Riley' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add and open record' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('must not be greater than 255 characters')
    expect(within(dialog).getByLabelText('Name *')).toHaveValue('Riley')
    expect(onOpenChange).not.toHaveBeenCalledWith(false)
  })
})

describe('sex at birth', () => {
  function Harness() {
    const [form, setForm] = useState(EMPTY_PATIENT_FORM)
    return <>
      <button type="button" onClick={() => setForm({ ...EMPTY_PATIENT_FORM, display_name: 'Sam', sex_at_birth: 'X' })}>Load</button>
      <PatientProfileFields form={form} onChange={setForm} />
    </>
  }

  it('offers a stored value none of the choices names even when it arrives after the field first renders', () => {
    render(<Harness />)
    fireEvent.click(screen.getByRole('button', { name: 'Load' }))
    expect(screen.getByRole('radio', { name: 'Other: X' })).toBeChecked()
    fireEvent.click(screen.getByRole('radio', { name: 'Female' }))
    expect(screen.getByRole('radio', { name: 'Other: X' })).not.toBeChecked()
    fireEvent.click(screen.getByRole('radio', { name: 'Other: X' }))
    expect(screen.getByRole('radio', { name: 'Other: X' })).toBeChecked()
  })
})

describe('sexAtBirthOption', () => {
  it.each([['M', 'Male'], ['female', 'Female'], ['', ''], ['  F ', 'Female'], ['Intersex', 'Intersex'], ['X', null]])('reads %p as %p', (stored, option) => {
    expect(sexAtBirthOption(stored)).toBe(option)
  })
})

describe('PatientsPage card actions', () => {
  beforeEach(() => {
    mockGet.mockResolvedValue({
      patients: [
        patient(1, 'Owned'),
        patient(2, 'Managed', { owner_user_id: 9, access_level: 'manager', can_share: false }),
        patient(3, 'Viewed', { owner_user_id: 9, access_level: 'viewer', can_manage: false, can_share: false }),
      ],
    })
  })

  it('offers what each person may do, and sends sharing, export and deletion to their own pages', async () => {
    render(<PhrPatientsProvider><PatientsPage onPatientSelect={(id, page) => mockPush(`patient:${id}:${page ?? ''}`)} /></PhrPatientsProvider>)
    fireEvent.click(await screen.findByRole('button', { name: 'More actions for Owned' }))
    expect((await screen.findAllByRole('menuitem')).map((item) => item.textContent)).toEqual(['Edit profile', 'Sharing and access', 'Export or back up', 'Delete patient…'])
    fireEvent.click(screen.getByRole('menuitem', { name: 'Delete patient…' }))
    expect(mockPush).toHaveBeenCalledWith('/phr/data-hub?patient_id=1')
    await waitFor(() => expect(screen.queryAllByRole('menuitem')).toHaveLength(0))

    fireEvent.click(screen.getByRole('button', { name: 'More actions for Managed' }))
    expect((await screen.findAllByRole('menuitem')).map((item) => item.textContent)).toEqual(['Edit profile'])
    expect(screen.queryByRole('button', { name: 'More actions for Viewed' })).toBeNull()
  })

  it('no longer links to a separate Manage Patients page', async () => {
    render(<PhrPatientsProvider><PatientsPage /></PhrPatientsProvider>)
    await screen.findAllByText('Owned')
    expect(document.querySelector('a[href="/phr/patients/manage"]')).toBeNull()
    expect(screen.getByRole('button', { name: 'Add patient' })).toBeInTheDocument()
  })
})
