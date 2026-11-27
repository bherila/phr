import '@testing-library/jest-dom'

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { recordChanged } from '@/phr/clinical/recordChanges'

import ImmunizationsPage from './ImmunizationsPage'

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockPatch = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
    delete: jest.fn(),
  },
}))
jest.mock('@/phr/imports/ehi/EhiPendingBanner', () => ({ EhiPendingBanner: () => null }))

function immunization(overrides: Record<string, unknown> = {}) {
  return {
    id: 5, patient_id: 2, user_id: 1, vaccine_name: 'Tdap', cvx_code: null, manufacturer: null, lot_number: null,
    administered_on: '2024-01-10', dose_number: null, series_doses: null, site: null, route: null, administered_by: null,
    facility_name: null, notes: null, raw_text: null, review_status: 'confirmed', created_at: null, updated_at: null,
    ...overrides,
  }
}

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
  mockPatch.mockReset()
  mockGet.mockResolvedValue({ immunizations: [immunization()], can_manage: true })
})

describe('ImmunizationsPage editing', () => {
  it('adds in a dialog, asking for the vaccine on its field, then opens the new record', async () => {
    mockPost.mockResolvedValue({ immunization: immunization({ id: 6, vaccine_name: 'Influenza' }) })
    const onDrill = jest.fn()
    render(<ImmunizationsPage patientId={2} onDrill={onDrill} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Add immunization' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add immunization' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add immunization' }))
    expect(within(dialog).getByRole('textbox', { name: /Vaccine Name/ })).toHaveAttribute('aria-invalid', 'true')
    expect(within(dialog).getByRole('textbox', { name: /Vaccine Name/ })).toHaveAccessibleDescription("Enter the vaccine's name.")
    expect(mockPost).not.toHaveBeenCalled()

    fireEvent.change(within(dialog).getByRole('textbox', { name: /Vaccine Name/ }), { target: { value: 'Influenza' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add immunization' }))
    await waitFor(() => expect(onDrill).toHaveBeenCalledWith({ id: 'immunization-detail', instance: '6' }))
    expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/2/immunizations', expect.objectContaining({ vaccine_name: 'Influenza' }))
  })

  it('edits in a dialog with the record filled in', async () => {
    mockPatch.mockResolvedValue({ immunization: immunization({ lot_number: 'L42' }) })
    render(<ImmunizationsPage patientId={2} />)
    fireEvent.click(await screen.findByRole('button', { name: 'More actions for Tdap' }))
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Edit' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit immunization' })
    expect(within(dialog).getByRole('textbox', { name: /Vaccine Name/ })).toHaveValue('Tdap')
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Lot Number' }), { target: { value: 'L42' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('Lot L42')).toBeInTheDocument()
    expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/2/immunizations/5', expect.objectContaining({ lot_number: 'L42' }))
  })
})

const limitedFields = [
  ['vaccine_name', 'Vaccine Name *', 255],
  ['cvx_code', 'CVX Code', 20],
  ['manufacturer', 'Manufacturer', 100],
  ['lot_number', 'Lot Number', 100],
  ['site', 'Site', 100],
  ['route', 'Route', 100],
  ['administered_by', 'Administered By', 255],
  ['facility_name', 'Facility', 255],
  ['notes', 'Notes', 10000],
] as const

describe('Immunization dialog validation', () => {
  it.each(limitedFields)('reports an over-long %s, focuses it, then saves a corrected value', async (field, label, limit) => {
    mockPost.mockResolvedValue({ immunization: immunization({ id: 6 }) })
    render(<ImmunizationsPage patientId={2} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Add immunization' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add immunization' })
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Vaccine Name *' }), { target: { value: 'Synthetic vaccine' } })
    const input = within(dialog).getByRole('textbox', { name: label })
    expect(input).toHaveAttribute('maxLength', String(limit))
    // Programmatic changes bypass the browser's maxLength, as can an existing record.
    fireEvent.change(input, { target: { value: 'x'.repeat(limit + 1) } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add immunization' }))
    expect(mockPost).not.toHaveBeenCalled()
    expect(input).toHaveAttribute('aria-invalid', 'true')
    const message = document.getElementById(input.getAttribute('aria-describedby') ?? '')
    expect(message).toBeVisible()
    expect(message).toHaveTextContent(`at most ${limit} characters.`)
    expect(input).toHaveAccessibleDescription(message?.textContent ?? '')
    expect(input).toHaveFocus()
    expect(within(dialog).getByRole('alert')).toHaveTextContent(message?.textContent ?? '')

    const corrected = 'x'.repeat(limit)
    fireEvent.change(input, { target: { value: corrected } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add immunization' }))
    await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/2/immunizations', expect.objectContaining({ [field]: corrected })))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('keeps an over-long note visible when editing and focuses the first of multiple errors', async () => {
    const notes = 'n'.repeat(10001)
    mockGet.mockResolvedValue({ immunizations: [immunization({ cvx_code: 'c'.repeat(21), notes })], can_manage: true })
    render(<ImmunizationsPage patientId={2} />)
    fireEvent.click(await screen.findByRole('button', { name: 'More actions for Tdap' }))
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Edit' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit immunization' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(mockPatch).not.toHaveBeenCalled()
    const codeInput = within(dialog).getByRole('textbox', { name: 'CVX Code' })
    const notesInput = within(dialog).getByRole('textbox', { name: 'Notes' })
    expect(codeInput).toHaveAccessibleDescription('CVX codes are at most 20 characters.')
    expect(codeInput).toHaveFocus()
    expect(notesInput).toHaveValue(notes)
    expect(notesInput).toHaveAttribute('aria-invalid', 'true')
    expect(notesInput).toHaveAccessibleDescription('Notes are at most 10000 characters.')
    expect(within(dialog).getByRole('alert')).toHaveTextContent('Notes are at most 10000 characters.')
  })
})


it('reloads its list after matching detail writes and ignores another patient', async () => {
  render(<ImmunizationsPage patientId={2} />)
  await screen.findByText('Tdap')
  const before = mockGet.mock.calls.length
  act(() => recordChanged({ resource: 'immunizations', patientId: 99, recordId: 5, origin: 'detail' }))
  expect(mockGet).toHaveBeenCalledTimes(before)
  mockGet.mockResolvedValue({ immunizations: [immunization({ vaccine_name: 'Updated synthetic vaccine' })], can_manage: true })
  act(() => recordChanged({ resource: 'immunizations', patientId: 2, recordId: 5, origin: 'detail' }))
  await screen.findByText('Updated synthetic vaccine')
  expect(screen.queryByText('Tdap')).not.toBeInTheDocument()
})
