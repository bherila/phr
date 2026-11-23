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
    expect(screen.getByText('Pending review')).toBeInTheDocument()
    expect(screen.getByText('Example imaging service')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Related imaging studies' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /example scan/i }))

    expect(onDrill).toHaveBeenCalledWith({ id: 'imaging-study-detail', instance: '73' })
  })

  it('lists diagnoses recorded on an imported encounter, marked as not conditions', async () => {
    const encounter = { ...VISIT, encounter_diagnoses: [{ line: '1', name: 'Synthetic diagnosis', primary: true, annotation: 'Synthetic note' }, { line: '2', name: 'Synthetic other', primary: false }] }
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ office_visit: encounter, can_manage: false }))

    render(<OfficeVisitDetail patientId={42} recordId="18" onDrill={jest.fn()} />)

    expect(await screen.findByRole('heading', { name: 'Diagnoses on this encounter' })).toBeInTheDocument()
    expect(screen.getByText('They are not added to Conditions.', { exact: false })).toBeInTheDocument()
    expect(screen.getByText(/Synthetic diagnosis \(primary\) · Synthetic note/)).toBeInTheDocument()
    expect(screen.getByText('Synthetic other')).toBeInTheDocument()
  })

  it('shows an inpatient stay\'s class, state, unit, admit source and disposition', async () => {
    const stay = {
      ...VISIT,
      encounter_class: 'IMP',
      encounter_state: 'in-progress',
      location: 'Synthetic unit',
      admit_source: 'emd',
      admit_source_text: null,
      discharge_disposition: 'home',
      discharge_disposition_text: 'Synthetic home with family',
      encounter_diagnoses: [{ name: 'Synthetic pneumonia', code: 'J18.9', code_system: 'http://hl7.org/fhir/sid/icd-10-cm' }],
    }
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ office_visit: stay, can_manage: false }))

    render(<OfficeVisitDetail patientId={42} recordId="18" onDrill={jest.fn()} />)

    expect(await screen.findByText('Inpatient')).toBeInTheDocument()
    expect(screen.getByText('In progress')).toBeInTheDocument()
    expect(screen.getByText('Synthetic unit')).toBeInTheDocument()
    expect(screen.getByText('Emergency department')).toBeInTheDocument()
    expect(screen.getByText('Synthetic home with family')).toBeInTheDocument()
    expect(screen.getByText(/Synthetic pneumonia \(J18\.9\)/)).toBeInTheDocument()
  })

  it('lists the records made during a stay, grouped by kind', async () => {
    const stay = {
      ...VISIT,
      linked_records: {
        lab_results: [{ id: 5, name: 'Creatinine', value: '1.8', unit: 'mg/dL', abnormal_flag: 'H', observed_at: '2026-02-11 06:00:00' }],
        vitals: [],
        medications: [],
        procedures: [{ id: 6, name: 'Synthetic chest radiograph', performed_on: '2026-02-10', status: 'completed' }],
        documents: [{ id: 7, title: 'Daily Progress Note', document_type: 'progress_note' }],
      },
    }
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ office_visit: stay, can_manage: false }))

    render(<OfficeVisitDetail patientId={42} recordId="18" onDrill={jest.fn()} />)

    expect(await screen.findByRole('heading', { name: 'Records from this visit' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Lab results' })).toBeInTheDocument()
    expect(screen.getByText('Creatinine')).toBeInTheDocument()
    expect(screen.getByText(/1\.8 mg\/dL · H/)).toBeInTheDocument()
    expect(screen.getByText('Synthetic chest radiograph')).toBeInTheDocument()
    expect(screen.getByText('Daily Progress Note')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Vitals' })).not.toBeInTheDocument()
  })

  it('lists what happened to each home medication during the stay', async () => {
    const medication = (id: number, name: string, category: string) => ({ id, name, category })
    const lisinopril = medication(1, 'lisinopril 10 mg oral tablet', 'community')
    const metformin = medication(2, 'metformin 500 mg oral tablet', 'community')
    const stay = {
      ...VISIT,
      medication_transitions: [
        { id: 11, phase: 'discharge', action: 'continued', review_status: 'confirmed', home_medication: lisinopril, order_medication: medication(6, 'lisinopril 10 mg (resume)', 'discharge') },
        { id: 10, phase: 'admission', action: 'held', note: 'Held for acute kidney injury', review_status: 'confirmed', home_medication: lisinopril, order_medication: null },
        { id: 12, phase: 'admission', action: 'changed', review_status: 'pending_review', home_medication: metformin, order_medication: medication(4, 'insulin lispro sliding scale', 'inpatient') },
        { id: 13, phase: 'admission', action: 'new', review_status: 'confirmed', home_medication: null, order_medication: medication(5, 'ceftriaxone 1 g IV every 24 hours', 'inpatient') },
      ],
    }
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ office_visit: stay, can_manage: false }))

    render(<OfficeVisitDetail patientId={42} recordId="18" onDrill={jest.fn()} />)

    expect(await screen.findByRole('heading', { name: 'Medication changes' })).toBeInTheDocument()
    expect(screen.getByText('lisinopril 10 mg oral tablet')).toBeInTheDocument()
    expect(screen.getByText('Admission: held · Discharge: continued')).toBeInTheDocument()
    expect(screen.getByText('Held for acute kidney injury')).toBeInTheDocument()
    expect(screen.getByText('Admission: changed to insulin lispro sliding scale (waiting for review)')).toBeInTheDocument()
    expect(screen.getByText('ceftriaxone 1 g IV every 24 hours')).toBeInTheDocument()
    expect(screen.getByText('Admission: new')).toBeInTheDocument()
    expect(screen.queryByText('No records are linked to this visit.')).not.toBeInTheDocument()
  })

  it('shows the home and order regimens of a change that keeps the drug and changes its dose', async () => {
    const regimen = { route: 'oral', frequency: 'every 6 hours', as_needed: true, rate: null }
    const home = { id: 3, name: 'acetaminophen', category: 'community', dose: '500', dose_unit: 'mg', ...regimen, as_needed_reason: 'pain' }
    const order = { id: 7, name: 'acetaminophen', category: 'inpatient', dose: '650', dose_unit: 'mg', ...regimen, as_needed_reason: 'temp > 38.0 C' }
    const discharge = { id: 8, name: 'acetaminophen', category: 'discharge', dose: '500', dose_unit: 'mg', ...regimen, as_needed_reason: 'pain' }
    const heparin = { id: 9, name: 'heparin', category: 'inpatient', dose: '1000', dose_unit: 'U', route: 'IV', frequency: null, as_needed: false, as_needed_reason: null, rate: '18 mL/h' }
    const heparinHome = { ...heparin, id: 4, category: 'community', rate: '12 mL/h' }
    const stay = {
      ...VISIT,
      medication_transitions: [
        { id: 21, phase: 'admission', action: 'changed', review_status: 'confirmed', home_medication: home, order_medication: order },
        { id: 22, phase: 'discharge', action: 'continued', review_status: 'confirmed', home_medication: home, order_medication: discharge },
        { id: 23, phase: 'admission', action: 'changed', review_status: 'confirmed', home_medication: heparinHome, order_medication: heparin },
      ],
    }
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ office_visit: stay, can_manage: false }))

    render(<OfficeVisitDetail patientId={42} recordId="18" onDrill={jest.fn()} />)

    const row = (await screen.findByText('acetaminophen')).closest('li') as HTMLElement
    expect(row).toHaveTextContent('Admission: changed · Discharge: continued')
    expect(row).toHaveTextContent('Home500 mg · oral · every 6 hours, as needed for pain')
    expect(row).toHaveTextContent('Admission order650 mg · oral · every 6 hours, as needed for temp > 38.0 C')
    expect(row).toHaveTextContent('Discharge500 mg · oral · every 6 hours, as needed for pain')
    const infusion = screen.getByText('heparin').closest('li') as HTMLElement
    expect(infusion).toHaveTextContent('Home1000 U · IV · at 12 mL/h')
    expect(infusion).toHaveTextContent('Admission order1000 U · IV · at 18 mL/h')
  })

  it('says when no records are linked to a visit', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ office_visit: VISIT, can_manage: false }))

    render(<OfficeVisitDetail patientId={42} recordId="18" onDrill={jest.fn()} />)

    expect(await screen.findByText('No records are linked to this visit.')).toBeInTheDocument()
  })

  it('shows a stay\'s care team with roles, specialties, primary and inactive members', async () => {
    const member = (id: number, fields: Record<string, unknown>) => ({
      id, external_id: null, credentials: null, role_code: 'PART', role_label: null, specialty: null,
      is_primary: false, period_start: null, period_end: null, ...fields,
    })
    const stay = {
      ...VISIT,
      provider_name: 'Alex Attending, MD',
      participants: [
        member(1, { name: 'Alex Attending', credentials: 'MD', role_code: 'ATND', is_primary: true }),
        member(2, { name: 'Robin Nurse', credentials: 'RN', role_label: 'Nurse', is_primary: true }),
        member(3, { name: 'Casey Consultant', credentials: 'MD', role_code: 'CON', specialty: 'Nephrology' }),
        member(4, { name: 'Taylor Tech', role_label: 'Technician', period_end: '2026-02-10 07:00:00' }),
      ],
    }
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ office_visit: stay, can_manage: false }))

    render(<OfficeVisitDetail patientId={42} recordId="18" onDrill={jest.fn()} />)

    expect(await screen.findByRole('heading', { name: 'Care team' })).toBeInTheDocument()
    expect(screen.getAllByText('Alex Attending, MD')).toHaveLength(2)
    expect(screen.getByText('Attending')).toBeInTheDocument()
    expect(screen.getByText('Robin Nurse, RN')).toBeInTheDocument()
    expect(screen.getByText('Consultant · Nephrology')).toBeInTheDocument()
    expect(screen.getAllByText('Primary')).toHaveLength(2)
    expect(screen.getByText('Inactive')).toBeInTheDocument()
  })

  it('leaves out encounter facts an office visit does not state', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ office_visit: VISIT, can_manage: false }))

    render(<OfficeVisitDetail patientId={42} recordId="18" onDrill={jest.fn()} />)

    expect(await screen.findByRole('heading', { name: 'Related services' })).toBeInTheDocument()
    expect(screen.queryByText('Encounter class')).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Care team' })).not.toBeInTheDocument()
    expect(screen.queryByText('Discharged to')).not.toBeInTheDocument()
  })
})
