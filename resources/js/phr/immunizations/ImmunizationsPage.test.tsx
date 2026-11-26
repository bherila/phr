import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

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
    expect(within(dialog).getByText("Enter the vaccine's name.")).toBeInTheDocument()
    expect(mockPost).not.toHaveBeenCalled()

    fireEvent.change(within(dialog).getByRole('textbox', { name: /Vaccine Name/ }), { target: { value: 'Influenza' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add immunization' }))
    await waitFor(() => expect(onDrill).toHaveBeenCalledWith({ id: 'immunization-detail', instance: '6' }))
    expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/2/immunizations', expect.objectContaining({ vaccine_name: 'Influenza' }))
  })

  it('edits in a dialog with the record filled in', async () => {
    mockPatch.mockResolvedValue({ immunization: immunization({ lot_number: 'L42' }) })
    render(<ImmunizationsPage patientId={2} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit Tdap' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit immunization' })
    expect(within(dialog).getByRole('textbox', { name: /Vaccine Name/ })).toHaveValue('Tdap')
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Lot Number' }), { target: { value: 'L42' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('Lot L42')).toBeInTheDocument()
    expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/2/immunizations/5', expect.objectContaining({ lot_number: 'L42' }))
  })
})
