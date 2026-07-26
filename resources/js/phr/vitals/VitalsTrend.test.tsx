import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type React from 'react'

import VitalsTrend from '@/phr/vitals/VitalsTrend'

jest.mock('recharts', () => ({
  ResponsiveContainer: ({ children }: React.PropsWithChildren) => <div data-testid="trend-chart">{children}</div>,
  LineChart: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
  CartesianGrid: () => null,
  XAxis: () => null,
  YAxis: () => null,
  Tooltip: () => null,
  Line: () => null,
}))

describe('VitalsTrend', () => {
  afterEach(() => {
    jest.restoreAllMocks()
  })

  it('loads and renders trend data with range controls', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      status: 200,
      statusText: 'OK',
      text: async () => JSON.stringify({
        metric_key: 'systolic_bp',
        metric_label: 'Systolic BP',
        unit: 'mmHg',
        points: [
          { reading_id: 1, recorded_at: '2026-05-01 08:00:00', value: 120 },
          { reading_id: 2, recorded_at: '2026-05-15 08:00:00', value: 124 },
        ],
      }),
    } as Response)

    render(<VitalsTrend patientId={42} recordId="systolic_bp" />)

    expect(await screen.findByText('Systolic BP')).toBeInTheDocument()
    expect(screen.getByTestId('trend-chart')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: '30d' }))
    expect(screen.getByText('Metric key: systolic_bp · mmHg')).toBeInTheDocument()
  })

  it('renders not found for missing metric', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: false,
      status: 404,
      statusText: 'Not Found',
      text: async () => JSON.stringify({ message: 'Not Found' }),
    } as Response)

    render(<VitalsTrend patientId={42} recordId="unknown_metric" />)

    await waitFor(() => {
      expect(screen.getByText('Record not found. It may belong to a different patient.')).toBeInTheDocument()
    })
  })
})
