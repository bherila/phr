import '@testing-library/jest-dom'

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { recordChanged } from '@/phr/clinical/recordChanges'

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


describe('Immunization detail actions remain reachable when list actions disappear', () => {
  beforeEach(() => { jest.clearAllMocks() })
  afterEach(() => { jest.restoreAllMocks() })

  it('reuses the edit dialog, keeps refused edits, and notifies the list after saving', async () => {
    let refused = true
    const listener = jest.fn()
    window.addEventListener('phr:record-changed', listener)
    const fetchMock = jest.spyOn(globalThis, 'fetch').mockImplementation(async (_url, options) => {
      if (options?.method === 'PATCH') {
        if (refused) {
          refused = false
          return { ...jsonResponse({ message: 'Synthetic save conflict' }), ok: false, status: 409 }
        }
        return jsonResponse({ immunization: { ...IMMUNIZATION, lot_number: 'UPDATED-LOT' } })
      }
      return jsonResponse({ immunization: IMMUNIZATION, can_manage: true })
    })
    render(<ImmunizationDetail patientId={42} recordId="31" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit SYNTHETIC VACCINE A' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit immunization' })
    fireEvent.change(within(dialog).getByLabelText('Lot Number'), { target: { value: 'UPDATED-LOT' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Synthetic save conflict')
    expect(within(dialog).getByLabelText('Lot Number')).toHaveValue('UPDATED-LOT')
    expect(listener).not.toHaveBeenCalled()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.getByText('UPDATED-LOT')).toBeInTheDocument()
    expect(listener).toHaveBeenCalledWith(expect.objectContaining({ detail: { resource: 'immunizations', patientId: 42, recordId: 31, origin: 'detail' } }))
    expect(fetchMock.mock.calls.filter(([, options]) => options?.method === 'PATCH')).toHaveLength(2)
    window.removeEventListener('phr:record-changed', listener)
  })

  it('asks before deleting and returns to the immunizations list after success', async () => {
    const onDrill = jest.fn()
    const fetchMock = jest.spyOn(globalThis, 'fetch').mockImplementation(async (_url, options) => options?.method === 'DELETE'
      ? jsonResponse({}) : jsonResponse({ immunization: IMMUNIZATION, can_manage: true }))
    render(<ImmunizationDetail patientId={42} recordId="31" onDrill={onDrill} />)
    fireEvent.click(await screen.findByRole('button', { name: 'More actions for SYNTHETIC VACCINE A' }))
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Delete' }))
    const dialog = await screen.findByRole('alertdialog', { name: 'Delete this immunization?' })
    expect(fetchMock.mock.calls.some(([, options]) => options?.method === 'DELETE')).toBe(false)
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(onDrill).toHaveBeenCalledWith({ id: 'immunizations' }))
    expect(fetchMock.mock.calls.find(([, options]) => options?.method === 'DELETE')?.[0]).toBe('/api/phr/patients/42/immunizations/31')
  })

  it('reviews through the existing endpoint and reloads after a matching list edit', async () => {
    const fetchMock = jest.spyOn(globalThis, 'fetch').mockImplementation(async (_url, options) => jsonResponse({
      immunization: { ...IMMUNIZATION, review_status: options?.method === 'PATCH' ? 'confirmed' : 'pending_review' }, can_manage: true,
    }))
    render(<ImmunizationDetail patientId={42} recordId="31" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Approve SYNTHETIC VACCINE A' }))
    await waitFor(() => expect(screen.queryByTestId('record-review-bar')).not.toBeInTheDocument())
    expect(fetchMock.mock.calls.find(([, options]) => options?.method === 'PATCH')?.[0]).toBe('/api/phr/patients/42/immunizations/31/review')
    fetchMock.mockResolvedValue(jsonResponse({ immunization: { ...IMMUNIZATION, vaccine_name: 'UPDATED VACCINE' }, can_manage: true }))
    act(() => recordChanged({ resource: 'immunizations', patientId: 99, recordId: 31, origin: 'list' }))
    expect(screen.queryByRole('heading', { name: 'UPDATED VACCINE' })).not.toBeInTheDocument()
    act(() => recordChanged({ resource: 'immunizations', patientId: 42, recordId: 31, origin: 'list' }))
    await screen.findByRole('heading', { name: 'UPDATED VACCINE' })
  })

  it('keeps read-only details free of mutation controls', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ immunization: { ...IMMUNIZATION, review_status: 'pending_review' }, can_manage: false }))
    render(<ImmunizationDetail patientId={42} recordId="31" />)
    await screen.findByRole('heading', { name: 'SYNTHETIC VACCINE A' })
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })
})
