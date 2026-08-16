import '@testing-library/jest-dom'

import { fireEvent, render, screen } from '@testing-library/react'

import OfficeVisitDetail from './OfficeVisitDetail'

const VISIT = {
  id: 18,
  patient_id: 42,
  user_id: 7,
  visit_date: '2030-04-15',
  visit_started_at: null,
  visit_ended_at: null,
  visit_type: 'Example visit',
  provider_name: 'Example Provider',
  provider_specialty: null,
  facility_name: null,
  chief_complaint: null,
  assessment: null,
  plan: null,
  subjective: null,
  objective: null,
  icd10_codes: [],
  cpt_codes: [],
  review_status: 'pending_review',
  eobs: [],
  related_services: [
    {
      id: 31,
      procedure_code: 'D0000',
      code_type: 'CDT',
      description: 'Example imaging service',
      service_start: '2030-04-15',
      service_end: '2030-04-15',
    },
  ],
  imaging_studies: [
    {
      id: 73,
      study_date: '2030-04-15',
      description: 'Example scan',
      modalities: 'CT',
      accession_number: 'EXAMPLE-ACCESSION',
    },
  ],
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

describe('OfficeVisitDetail', () => {
  afterEach(() => {
    jest.restoreAllMocks()
  })

  it('shows related billed services and opens linked imaging in a detail column', async () => {
    const onDrill = jest.fn()
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ office_visit: VISIT, can_manage: false }))

    render(<OfficeVisitDetail patientId={42} recordId="18" onDrill={onDrill} />)

    expect(await screen.findByRole('heading', { name: 'Related services' })).toBeInTheDocument()
    expect(screen.getByText('pending review')).toBeInTheDocument()
    expect(screen.getByText('Example imaging service')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Related imaging studies' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /example scan/i }))

    expect(onDrill).toHaveBeenCalledWith({ id: 'imaging-study-detail', instance: '73' })
  })
})
