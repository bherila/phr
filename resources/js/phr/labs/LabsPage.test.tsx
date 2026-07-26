import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import LabsPage from '@/phr/labs/LabsPage'

const mockGet = jest.fn()
const mockPost = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}))

function makeLabResult(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 321,
    patient_id: 42,
    user_id: 7,
    test_name: 'Comprehensive Metabolic Panel',
    collection_datetime: '2026-05-19 08:00:00',
    result_datetime: '2026-05-19 09:00:00',
    result_status: null,
    ordering_provider: null,
    resulting_lab: null,
    analyte: 'Glucose',
    value: '111',
    value_numeric: '111',
    unit: 'mg/dL',
    range_min: '70.0000000000',
    range_max: '9999999.0000000000',
    range_unit: 'mg/dL',
    reference_range_text: null,
    normal_value: null,
    abnormal_flag: 'H',
    message_from_provider: null,
    result_comment: null,
    lab_director: null,
    source: null,
    notes: null,
    created_at: '2026-05-19 09:05:00',
    updated_at: '2026-05-19 09:05:00',
    ...overrides,
  }
}

describe('LabsPage', () => {
  beforeEach(() => {
    mockGet.mockReset()
    mockPost.mockReset()
    mockGet.mockResolvedValue({
      lab_results: [makeLabResult()],
      can_manage: false,
    })
  })

  it('drills to lab panel detail when a row is clicked', async () => {
    const onDrill = jest.fn()

    render(<LabsPage patientId={42} onDrill={onDrill} />)

    expect(await screen.findByText('70–∞ mg/dL')).toBeInTheDocument()

    fireEvent.click(screen.getByText('Glucose'))

    await waitFor(() => {
      expect(onDrill).toHaveBeenCalledWith({ id: 'lab-panel-detail', instance: '321' })
    })
  })

  it('groups repeated panels and keeps singleton panels in Other', async () => {
    mockGet.mockResolvedValueOnce({
      lab_results: [
        makeLabResult({
          id: 401,
          test_name: 'Basic Metabolic Panel',
          analyte: 'Sodium',
          value: '139',
          value_numeric: '139',
          unit: 'mmol/L',
          range_min: '135.0000000000',
          range_max: '145.0000000000',
          range_unit: 'mmol/L',
          abnormal_flag: null,
        }),
        makeLabResult({
          id: 402,
          test_name: 'Basic Metabolic Panel',
          analyte: 'Glucose',
          value: '92',
          value_numeric: '92',
          unit: 'mg/dL',
          range_min: '70.0000000000',
          range_max: '99.0000000000',
          range_unit: 'mg/dL',
          abnormal_flag: null,
        }),
        makeLabResult({
          id: 403,
          test_name: 'HIV Antibody',
          analyte: 'HIV Ag/Ab Combo',
          value: 'NEG',
          value_numeric: null,
          unit: null,
          range_min: null,
          range_max: null,
          range_unit: null,
          abnormal_flag: null,
        }),
      ],
      can_manage: false,
    })

    render(<LabsPage patientId={42} />)

    const panelSection = await screen.findByRole('region', { name: 'Basic Metabolic Panel lab results' })
    expect(within(panelSection).getByText('Sodium')).toBeInTheDocument()
    expect(within(panelSection).getByText('Glucose')).toBeInTheDocument()
    expect(within(panelSection).queryByText('Panel')).not.toBeInTheDocument()
    expect(within(panelSection).getAllByText('Basic Metabolic Panel')).toHaveLength(1)

    const otherSection = screen.getByRole('region', { name: 'Other lab results' })
    expect(within(otherSection).getByText('HIV Ag/Ab Combo')).toBeInTheDocument()
    expect(within(otherSection).getByText('HIV Antibody')).toBeInTheDocument()
    expect(within(otherSection).getByText('Panel')).toBeInTheDocument()
  })

  it('orders sections by the active date sort, not always last for Other', async () => {
    mockGet.mockResolvedValueOnce({
      lab_results: [
        makeLabResult({
          id: 501,
          test_name: 'Lipid Panel',
          analyte: 'Total Cholesterol',
          result_datetime: '2026-01-10 09:00:00',
          collection_datetime: '2026-01-10 08:00:00',
        }),
        makeLabResult({
          id: 502,
          test_name: 'Lipid Panel',
          analyte: 'HDL',
          result_datetime: '2026-01-10 09:00:00',
          collection_datetime: '2026-01-10 08:00:00',
        }),
        makeLabResult({
          id: 503,
          test_name: 'Hemoglobin A1c',
          analyte: 'HbA1c',
          result_datetime: '2026-06-01 09:00:00',
          collection_datetime: '2026-06-01 08:00:00',
        }),
      ],
      can_manage: false,
    })

    render(<LabsPage patientId={42} />)

    await screen.findByRole('region', { name: 'Other lab results' })
    const sectionNames = screen.getAllByRole('region').map((region) => region.getAttribute('aria-label'))

    expect(sectionNames).toEqual(['Other lab results', 'Lipid Panel lab results'])
  })
})
