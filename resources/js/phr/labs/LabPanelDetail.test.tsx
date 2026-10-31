import '@testing-library/jest-dom'

import { render, screen, within } from '@testing-library/react'

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
    const table = within(screen.getByRole('table'))
    expect(table.getByText('70–∞ mg/dL')).toBeInTheDocument()
    expect(table.getByText('H')).toBeInTheDocument()
    expect(table.getByText('↑')).toBeInTheDocument()
  })

  it('stacks each result on two lines below 520px of panel width and keeps the sized table above it', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      status: 200,
      statusText: 'OK',
      text: async () => JSON.stringify({
        panel: {
          id: 5, panel_name: 'Synthetic Respiratory Panel', collection_datetime: null, ordering_provider: null, resulting_lab: null,
          source: null, source_document_id: null, source_document_url: null,
          rows: [
            { id: 1, analyte: 'Influenza A RNA', value: 'Not Detected', value_numeric: null, unit: null, range_min: null, range_max: null, range_unit: null, reference_range_text: 'Not Detected', abnormal_flag: 'N', result_datetime: null, collection_datetime: null, trend: null },
            { id: 2, analyte: 'Potassium', value: '3.3', value_numeric: '3.3', unit: 'mmol/L', range_min: '3.5', range_max: '5.1', range_unit: 'mmol/L', reference_range_text: null, abnormal_flag: 'L', result_datetime: null, collection_datetime: null, trend: 'down' },
          ],
        },
      }),
    } as Response)

    const { container } = render(<LabPanelDetail patientId={42} recordId="5" />)
    const stacked = await screen.findByRole('list', { name: 'Results' })

    // The component is its own size container, so it adapts in a panel or on a page.
    expect(container.firstElementChild).toHaveClass('@container')
    expect(stacked).toHaveClass('@min-[520px]:hidden')
    expect(container.querySelector('[data-layout="table"]')).toHaveClass('hidden', '@min-[520px]:block')
    const [first, second] = within(stacked).getAllByRole('listitem')
    const [firstLine, secondLine] = Array.from(second!.children)
    expect(firstLine).toHaveTextContent('Potassium3.3mmol/L')
    expect(secondLine).toHaveTextContent('3.5–5.1 mmol/L')
    expect(secondLine).toHaveTextContent('L')
    expect(within(first!).getByText('Not Detected', { selector: 'span.shrink-0' })).not.toHaveClass('tabular-nums')

    const value = within(screen.getByRole('table')).getByText('3.3').closest('td')
    expect(value).toHaveClass('w-[1%]', 'whitespace-nowrap', 'text-right', 'tabular-nums')
    const [textValue] = within(screen.getByRole('table')).getAllByText('Not Detected', { selector: 'td' })
    expect(textValue).not.toHaveClass('text-right')
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
