import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import VitalsPage from '@/phr/vitals/VitalsPage'

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

describe('VitalsPage', () => {
  beforeEach(() => {
    mockGet.mockClear()
    mockPost.mockClear()
    mockPatch.mockClear()
    mockDelete.mockClear()

    mockGet.mockResolvedValue({
      vitals: [
        {
          id: 12,
          patient_id: 42,
          user_id: 7,
          vital_name: 'Blood Pressure',
          vital_date: '2026-05-01',
          observed_at: null,
          vital_value: '120/80',
          value_numeric: '120',
          value_numeric_secondary: '80',
          unit: 'mmHg',
          secondary_unit: 'mmHg',
          body_site: null,
          source: null,
          notes: null,
          created_at: null,
          updated_at: null,
        },
      ],
      can_manage: false,
    })
  })

  it('drills into vital reading detail when a row is clicked', async () => {
    const onDrill = jest.fn()

    render(<VitalsPage patientId={42} onDrill={onDrill} />)

    fireEvent.click(await screen.findByText('Blood Pressure'))

    expect(onDrill).toHaveBeenCalledWith({ id: 'vitals-reading-detail', instance: '12' })
  })

  it('drills into trend pane with derived metric keys', async () => {
    const onDrill = jest.fn()

    render(<VitalsPage patientId={42} onDrill={onDrill} />)

    const trendButton = await screen.findByRole('button', { name: /systolic bp trend/i })
    fireEvent.click(trendButton)

    await waitFor(() => {
      expect(onDrill).toHaveBeenCalledWith({ id: 'vitals-trend', instance: 'systolic_bp' })
    })
  })

  it('adds a reading in a dialog, asking for its name on the field, then opens it', async () => {
    // The reading beforeEach lists, now for someone who can add one.
    const listed = await (mockGet() as Promise<{ vitals: Array<Record<string, unknown>> }>)
    mockGet.mockResolvedValue({ vitals: listed.vitals, can_manage: true })
    mockPost.mockResolvedValue({ vital: { ...listed.vitals[0], id: 13, vital_name: 'Weight', vital_value: '70', value_numeric: '70', value_numeric_secondary: null, unit: 'kg', secondary_unit: null } })
    const onDrill = jest.fn()

    render(<VitalsPage patientId={42} onDrill={onDrill} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Add vital' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add vital' })

    fireEvent.click(within(dialog).getByRole('button', { name: 'Add vital' }))
    const name = within(dialog).getByLabelText('Vital Name *')
    expect(name).toHaveAttribute('aria-invalid', 'true')
    await waitFor(() => expect(name).toHaveFocus())
    expect(mockPost).not.toHaveBeenCalled()

    fireEvent.change(name, { target: { value: 'Weight' } })
    fireEvent.change(within(dialog).getByLabelText('Numeric (systolic / primary)'), { target: { value: '70' } })
    fireEvent.change(within(dialog).getByLabelText('Unit'), { target: { value: 'kg' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add vital' }))

    await waitFor(() => expect(onDrill).toHaveBeenCalledWith({ id: 'vitals-reading-detail', instance: '13' }))
    expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/42/vitals', expect.objectContaining({ vital_name: 'Weight', value_numeric: 70, unit: 'kg' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })
})
