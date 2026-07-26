import '@testing-library/jest-dom'

import { render, screen } from '@testing-library/react'

import LabPanelDetail from '@/phr/labs/LabPanelDetail'

const mockFetch = jest.fn()

beforeEach(() => {
  mockFetch.mockReset()
  globalThis.fetch = mockFetch as unknown as typeof fetch
})

describe('LabPanelDetail', () => {
  it('renders panel metadata, row table, abnormal flag, trend, and source link', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      status: 200,
      statusText: 'OK',
      text: async () => JSON.stringify({
        panel: {
          id: 321,
          panel_name: 'Comprehensive Metabolic Panel',
          collection_datetime: '2026-05-19 08:00:00',
          ordering_provider: 'Dr. Rivera',
          resulting_lab: 'Quest Diagnostics',
          source: 'MyChart',
          source_document_id: 77,
          source_document_url: '/api/phr/patients/42/documents/77/file',
          rows: [
            {
              id: 1,
              analyte: 'Glucose',
              value: '111',
              value_numeric: '111',
              unit: 'mg/dL',
              range_min: '70.0000000000',
              range_max: '9999999.0000000000',
              range_unit: 'mg/dL',
              reference_range_text: null,
              abnormal_flag: 'H',
              result_datetime: '2026-05-19 09:00:00',
              collection_datetime: '2026-05-19 08:00:00',
              trend: 'up',
            },
          ],
        },
      }),
    } as Response)

    render(<LabPanelDetail patientId={42} recordId="321" />)

    expect(await screen.findByText('Comprehensive Metabolic Panel')).toBeInTheDocument()
    expect(screen.getByText(/Ordering provider:\s*Dr. Rivera/)).toBeInTheDocument()
    expect(screen.getByText(/Lab\/source:\s*Quest Diagnostics · MyChart/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'View source document' })).toHaveAttribute(
      'href',
      '/api/phr/patients/42/documents/77/file',
    )
    expect(screen.getByText('70–∞ mg/dL')).toBeInTheDocument()
    expect(screen.getByText('H')).toBeInTheDocument()
    expect(screen.getByText('↑')).toBeInTheDocument()
  })

  it('renders shared not-found column on 404', async () => {
    mockFetch.mockResolvedValue({
      ok: false,
      status: 404,
      statusText: 'Not Found',
      text: async () => JSON.stringify({ message: 'Not Found' }),
    } as Response)

    render(<LabPanelDetail patientId={42} recordId="999" />)

    expect(
      await screen.findByText('Record not found. It may belong to a different patient.'),
    ).toBeInTheDocument()
  })
})
