import '@testing-library/jest-dom'

import { render, screen, waitFor } from '@testing-library/react'

import VitalsReadingDetail from '@/phr/vitals/VitalsReadingDetail'

describe('VitalsReadingDetail', () => {
  afterEach(() => {
    jest.restoreAllMocks()
  })

  it('renders fetched vital reading details', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      status: 200,
      statusText: 'OK',
      text: async () => JSON.stringify({
        vital: {
          id: 21,
          patient_id: 42,
          user_id: 7,
          vital_name: 'Heart Rate',
          vital_date: '2026-05-01',
          observed_at: '2026-05-01 08:00:00',
          vital_value: '72',
          value_numeric: '72',
          value_numeric_secondary: null,
          unit: 'bpm',
          secondary_unit: null,
          body_site: null,
          source: 'manual',
          notes: 'Morning reading',
          created_at: null,
          updated_at: null,
        },
      }),
    } as Response)

    render(<VitalsReadingDetail patientId={42} recordId="21" />)

    expect(await screen.findByRole('heading', { name: 'Heart Rate' })).toBeInTheDocument()
    expect(screen.getByText('Morning reading')).toBeInTheDocument()
    expect(screen.getByText(/manual/i)).toBeInTheDocument()
  })

  it('renders not-found column for 404 responses', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: false,
      status: 404,
      statusText: 'Not Found',
      text: async () => JSON.stringify({ message: 'Not Found' }),
    } as Response)

    render(<VitalsReadingDetail patientId={42} recordId="999" />)

    await waitFor(() => {
      expect(screen.getByText('Record not found. It may belong to a different patient.')).toBeInTheDocument()
    })
  })
})
