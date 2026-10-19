import '@testing-library/jest-dom'

import { render, screen } from '@testing-library/react'

import ImmunizationDetail from './ImmunizationDetail'

const IMMUNIZATION = {
  id: 31,
  patient_id: 42,
  user_id: 7,
  import_source: 'ehi:1',
  external_id: 'IMMUNE:9001',
  source_document_id: null,
  review_status: 'confirmed',
  vaccine_name: 'SYNTHETIC VACCINE A',
  cvx_code: null,
  manufacturer: null,
  lot_number: 'SYN-LOT-1',
  administered_on: '2030-03-05',
  administered_at_source: '2030-03-05|10:15',
  administered_at_precision: 'minute',
  administered_at_timezone: 'local',
  administration_status: 'given',
  dose_amount: '0.50',
  dose_unit: 'mL',
  dose_number: null,
  series_doses: null,
  site: null,
  route: null,
  administered_by: null,
  facility_name: null,
  notes: null,
  raw_text: null,
  created_at: null,
  updated_at: null,
}

function jsonResponse(payload: unknown): Response {
  return {
    ok: true,
    status: 200,
    statusText: 'OK',
    text: async () => JSON.stringify(payload),
  } as Response
}

function definition(term: string): HTMLElement | null {
  return screen.getByText(term, { selector: 'dt' }).nextElementSibling as HTMLElement | null
}

describe('ImmunizationDetail', () => {
  afterEach(() => {
    jest.restoreAllMocks()
  })

  it('shows an imported dose amount without inventing a series position', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ immunization: IMMUNIZATION, can_manage: false }))

    render(<ImmunizationDetail patientId={42} recordId="31" />)

    expect(await screen.findByRole('heading', { name: 'SYNTHETIC VACCINE A' })).toBeInTheDocument()
    expect(definition('Dose amount')).toHaveTextContent('0.50 mL')
    expect(definition('Dose in series')).toHaveTextContent('Not recorded')
    expect(definition('Administration status')).toHaveTextContent('Given')
  })

  it('shows the series position independently and omits an absent administration status', async () => {
    const record = {
      ...IMMUNIZATION,
      dose_amount: null,
      dose_unit: null,
      administration_status: null,
      dose_number: 2,
      series_doses: 3,
    }
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ immunization: record, can_manage: false }))

    render(<ImmunizationDetail patientId={42} recordId="31" />)

    expect(await screen.findByRole('heading', { name: 'SYNTHETIC VACCINE A' })).toBeInTheDocument()
    expect(definition('Dose in series')).toHaveTextContent('2 / 3')
    expect(definition('Dose amount')).toHaveTextContent('Not recorded')
    expect(screen.queryByText('Administration status')).not.toBeInTheDocument()
  })

  it('accepts a record from before the imported fields existed', async () => {
    const legacy: Record<string, unknown> = { ...IMMUNIZATION, dose_number: 1, series_doses: null }
    for (const field of ['administered_at_source', 'administered_at_precision', 'administered_at_timezone', 'administration_status', 'dose_amount', 'dose_unit']) {
      delete legacy[field]
    }
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ immunization: legacy, can_manage: false }))

    render(<ImmunizationDetail patientId={42} recordId="31" />)

    expect(await screen.findByRole('heading', { name: 'SYNTHETIC VACCINE A' })).toBeInTheDocument()
    expect(definition('Dose in series')).toHaveTextContent('1')
    expect(definition('Dose amount')).toHaveTextContent('Not recorded')
  })
})
