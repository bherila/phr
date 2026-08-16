import '@testing-library/jest-dom'

import { render, screen } from '@testing-library/react'

import ProcedureDetail from './ProcedureDetail'

const PROCEDURE = {
  id: 27,
  patient_id: 42,
  user_id: 7,
  name: 'Synthetic procedure',
  cpt_code: null,
  snomed_code: null,
  performed_at: null,
  performed_on: '2030-04-15',
  performer_name: null,
  performer_specialty: null,
  facility_name: null,
  status: 'completed',
  reason: null,
  outcome: null,
  notes: null,
  raw_text: null,
  review_status: 'pending_review',
  eobs: [],
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

describe('ProcedureDetail', () => {
  afterEach(() => {
    jest.restoreAllMocks()
  })

  it('shows when an agent-written procedure is pending review', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ procedure: PROCEDURE, can_manage: false }))

    render(<ProcedureDetail patientId={42} recordId="27" />)

    expect(await screen.findByRole('heading', { name: 'Synthetic procedure' })).toBeInTheDocument()
    expect(screen.getByText('pending review')).toBeInTheDocument()
  })
})
