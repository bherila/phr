import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import AccessPage from '@/phr/access/AccessPage'
import AllergiesPage from '@/phr/allergies/AllergiesPage'
import ConditionsPage from '@/phr/conditions/ConditionsPage'
import DocumentsPage from '@/phr/documents/DocumentsPage'
import HealthLogPage from '@/phr/health-log/HealthLogPage'
import ImagingPage from '@/phr/imaging/ImagingPage'
import ImmunizationsPage from '@/phr/immunizations/ImmunizationsPage'
import LabsPage from '@/phr/labs/LabsPage'
import MedicationsPage from '@/phr/medications/MedicationsPage'
import OfficeVisitsPage from '@/phr/office-visits/OfficeVisitsPage'
import PatientsPage from '@/phr/patients/PatientsPage'
import ProceduresPage from '@/phr/procedures/ProceduresPage'
import type { PhrDicomStudy } from '@/phr/types'
import VitalsPage from '@/phr/vitals/VitalsPage'

const PATIENT_ID = 101

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockPatch = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
  getCsrfToken: () => null,
}))

function makePatient() {
  return {
    id: PATIENT_ID,
    owner_user_id: 2,
    display_name: 'Primary',
    relationship: 'self',
    birth_date: null,
    sex_at_birth: null,
    notes: null,
    archived_at: null,
    created_at: null,
    updated_at: null,
    access_level: 'owner',
    can_manage: true,
    can_share: true,
    access_grants: [],
  }
}

function makeProcedure(overrides: Record<string, unknown> = {}) {
  return {
    id: 7001,
    patient_id: PATIENT_ID,
    user_id: 2,
    name: 'Allergy immunotherapy administration',
    cpt_code: '95117',
    snomed_code: null,
    performed_at: null,
    performed_on: '2026-06-15',
    performer_name: 'Allergy clinic',
    performer_specialty: null,
    facility_name: null,
    status: 'completed',
    reason: null,
    outcome: null,
    notes: null,
    raw_text: null,
    review_status: 'confirmed',
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

function makeOfficeVisit(overrides: Record<string, unknown> = {}) {
  return {
    id: 8101,
    patient_id: PATIENT_ID,
    user_id: 2,
    visit_date: '2026-06-01',
    visit_started_at: null,
    visit_ended_at: null,
    visit_type: 'Follow-up',
    provider_name: 'Example clinician',
    provider_specialty: null,
    facility_name: 'Example clinic',
    chief_complaint: null,
    assessment: null,
    plan: null,
    subjective: null,
    objective: null,
    icd10_codes: [],
    cpt_codes: [],
    review_status: 'pending_review',
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

function makeImmunization(overrides: Record<string, unknown> = {}) {
  return {
    id: 8201,
    patient_id: PATIENT_ID,
    user_id: 2,
    vaccine_name: 'Example vaccine',
    cvx_code: null,
    manufacturer: 'Example manufacturer',
    lot_number: null,
    administered_on: '2026-07-04',
    dose_number: 2,
    series_doses: 2,
    site: null,
    route: null,
    administered_by: 'Example clinician',
    facility_name: null,
    notes: null,
    raw_text: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

beforeEach(() => {
  mockGet.mockClear()
  mockPost.mockClear()
  mockPatch.mockClear()
  mockDelete.mockClear()
  const patient = makePatient()
  mockGet.mockImplementation(async (url: string) => {
    if (url === '/api/phr/patients') return { patients: [patient] }
    if (url === `/api/phr/patients/${PATIENT_ID}`) return { patient }
    if (url.includes('/lab-results')) return { lab_results: [], can_manage: true }
    if (url.includes('/vitals')) return { vitals: [], can_manage: true }
    if (url.includes('/documents')) return { documents: [], can_manage: true }
    if (url.includes('/health-logs')) return { health_logs: [], can_manage: true }
    if (url.includes('/exports')) return { exports: [] }
    if (url.includes('/dicom/studies')) return { studies: [] }
    if (url.includes('/access')) return { access_grants: [] }
    if (url.includes('/conditions')) return { conditions: [], can_manage: true }
    if (url.includes('/office-visits')) return { office_visits: [], can_manage: true }
    if (url.includes('/procedures')) return { procedures: [], can_manage: true }
    if (url.includes('/immunizations')) return { immunizations: [], can_manage: true }
    if (url.includes('/allergies')) return { allergies: [], can_manage: true }
    return {}
  })
  mockPost.mockResolvedValue({ patient })
  mockPatch.mockResolvedValue({ patient })
  mockDelete.mockResolvedValue({})
})

describe('PHR page mounts', () => {
  it('mounts patients page and shows Add Patient button', async () => {
    render(<PatientsPage />)
    await waitFor(() => expect(mockGet).toHaveBeenCalledWith('/api/phr/patients'))
    expect(screen.getByRole('link', { name: /add patient/i })).toBeInTheDocument()
  })

  it('mounts patients page and shows patient card', async () => {
    render(<PatientsPage />)
    await waitFor(() => expect(screen.getByText('Primary')).toBeInTheDocument())
  })

  it('mounts labs page without crash', () => {
    render(<LabsPage patientId={PATIENT_ID} />)
    expect(document.body).toBeTruthy()
  })

  it('mounts vitals page without crash', () => {
    render(<VitalsPage patientId={PATIENT_ID} />)
    expect(document.body).toBeTruthy()
  })

  it('mounts health log page without crash', () => {
    render(<HealthLogPage patientId={PATIENT_ID} />)
    expect(document.body).toBeTruthy()
  })

  it('mounts imaging page without crash', () => {
    render(<ImagingPage patientId={PATIENT_ID} />)
    expect(document.body).toBeTruthy()
  })

  it('opens imaging studies with the OHIF DICOM JSON route', async () => {
    const openSpy = jest.fn()
    const originalOpen = window.open
    window.open = openSpy as unknown as typeof window.open

    mockGet.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}`) return { patient: makePatient() }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/studies`) {
        return { studies: [makeDicomStudy()] }
      }
      return {}
    })

    try {
      render(<ImagingPage patientId={PATIENT_ID} />)

      fireEvent.click(await screen.findByRole('button', { name: /viewer/i }))

      expect(openSpy).toHaveBeenCalledWith(
        `/ohif/viewer/dicomjson?url=${encodeURIComponent(`/api/phr/patients/${PATIENT_ID}/dicom/studies/7001/viewer-json`)}`,
        '_blank',
        'noopener,noreferrer',
      )
    } finally {
      window.open = originalOpen
    }
  })

  it('renders imaging studies newest first with file sizes', async () => {
    mockGet.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}`) return { patient: makePatient() }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/studies`) {
        return {
          studies: [
            makeDicomStudy({
              id: 7001,
              description: 'Older CT',
              study_date: '2026-05-17',
              study_time: '120000',
              file_size_bytes: 1024 * 1024,
            }),
            makeDicomStudy({
              id: 7002,
              description: 'Recent MR',
              modalities: 'MR',
              study_date: '2026-05-18',
              study_time: '090000',
              file_size_bytes: 2 * 1024 * 1024,
            }),
          ],
        }
      }
      return {}
    })

    const { container } = render(<ImagingPage patientId={PATIENT_ID} />)

    await waitFor(() => expect(screen.getByText('Recent MR')).toBeInTheDocument())
    expect(screen.getByText(/2\.0 MB/)).toBeInTheDocument()
    expect(screen.getByText(/1\.0 MB/)).toBeInTheDocument()

    const text = container.textContent ?? ''
    expect(text.indexOf('Recent MR')).toBeGreaterThanOrEqual(0)
    expect(text.indexOf('Recent MR')).toBeLessThan(text.indexOf('Older CT'))
  })

  it('mounts access page without crash', () => {
    render(<AccessPage patientId={PATIENT_ID} />)
    expect(document.body).toBeTruthy()
  })

  it('mounts stub pages without crash', () => {
    render(<AllergiesPage patientId={PATIENT_ID} />)
    render(<ConditionsPage patientId={PATIENT_ID} />)
    render(<DocumentsPage patientId={PATIENT_ID} />)
    render(<ImmunizationsPage patientId={PATIENT_ID} />)
    render(<MedicationsPage patientId={PATIENT_ID} />)
    render(<OfficeVisitsPage patientId={PATIENT_ID} />)
    render(<ProceduresPage patientId={PATIENT_ID} />)
    expect(document.body).toBeTruthy()
  })

  it('combines visit-like records in a filterable timeline with cadence labels', async () => {
    mockGet.mockImplementation(async (url: string) => {
      if (url.includes('/office-visits')) return { office_visits: [makeOfficeVisit()], can_manage: true }
      if (url.includes('/procedures')) {
        return {
          procedures: [
            makeProcedure({ id: 7001, cpt_code: '95115', performed_on: '2026-05-01' }),
            makeProcedure({ id: 7002, cpt_code: '95117', performed_on: '2026-06-15' }),
            makeProcedure({ id: 7003, name: 'Allergen extract preparation', cpt_code: '95165', performed_on: '2026-06-15' }),
            makeProcedure({ id: 7004, name: 'Dental restoration', cpt_code: 'D2392', performed_on: '2026-04-01' }),
          ],
          can_manage: true,
        }
      }
      if (url.includes('/immunizations')) {
        return { immunizations: [makeImmunization()], can_manage: true }
      }
      return {}
    })

    const onDrill = jest.fn()
    render(<OfficeVisitsPage patientId={PATIENT_ID} onDrill={onDrill} />)

    const allFilter = await screen.findByRole('button', { name: /^all 4$/i })
    expect(allFilter).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByText('Follow-up')).toBeInTheDocument()
    expect(screen.getByText('pending review')).toBeInTheDocument()
    expect(screen.getByText('Example vaccine')).toBeInTheDocument()
    expect(screen.queryByText('Allergen extract preparation')).not.toBeInTheDocument()
    expect(screen.queryByText('Dental restoration')).not.toBeInTheDocument()

    const allergyShotsFilter = screen.getByRole('button', { name: /allergy shots 2/i })
    fireEvent.click(allergyShotsFilter)

    expect(screen.getByText('Multiple allergy injections')).toBeInTheDocument()
    expect(screen.getByText('Single allergy injection')).toBeInTheDocument()
    expect(screen.getByText('45 days since previous allergy shot')).toBeInTheDocument()
    expect(screen.queryByText('Follow-up')).not.toBeInTheDocument()
    expect(screen.queryByText('Example vaccine')).not.toBeInTheDocument()
    expect(screen.getByText(/Allergen extract preparation is excluded/i)).toBeInTheDocument()

    fireEvent.click(screen.getByText('Multiple allergy injections'))
    expect(onDrill).toHaveBeenCalledWith({ id: 'procedure-detail', instance: '7002' })

    fireEvent.click(screen.getByRole('button', { name: /immunizations 1/i }))
    fireEvent.click(screen.getByText('Example vaccine'))
    expect(onDrill).toHaveBeenCalledWith({ id: 'immunization-detail', instance: '8201' })
  })

  it('renders condition actions including GenAI import handoff', async () => {
    const onDrill = jest.fn()
    render(<ConditionsPage patientId={PATIENT_ID} onDrill={onDrill} />)

    await waitFor(() => expect(screen.getByRole('button', { name: /add condition/i })).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: /import via genai/i }))
    expect(onDrill).toHaveBeenCalledWith({ id: 'documents' })
  })

  it('renders procedure manual entry with import guidance', async () => {
    render(<ProceduresPage patientId={PATIENT_ID} />)

    await waitFor(() => expect(screen.getByRole('button', { name: /add procedure/i })).toBeInTheDocument())
    expect(screen.getByText(/CCDA or FHIR record imports/i)).toBeInTheDocument()
  })

  it('renders immunization actions including GenAI import handoff', async () => {
    const onDrill = jest.fn()
    render(<ImmunizationsPage patientId={PATIENT_ID} onDrill={onDrill} />)

    await waitFor(() => expect(screen.getByRole('button', { name: /add immunization/i })).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: /import via genai/i }))
    expect(onDrill).toHaveBeenCalledWith({ id: 'documents' })
  })

  it('renders allergy manual entry with import guidance', async () => {
    render(<AllergiesPage patientId={PATIENT_ID} />)

    await waitFor(() => expect(screen.getByRole('button', { name: /add allergy/i })).toBeInTheDocument())
    expect(screen.getByText(/extracted as part of office-visit review/i)).toBeInTheDocument()
  })

  it('calls onDrill when an imaging study row is clicked', async () => {
    mockGet.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}`) return { patient: makePatient() }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/studies`) {
        return { studies: [makeDicomStudy()] }
      }
      return {}
    })

    const onDrill = jest.fn()
    render(<ImagingPage patientId={PATIENT_ID} onDrill={onDrill} />)

    // Click on the study title — the click bubbles up to the card's onClick handler
    const studyTitle = await screen.findByText('Cardiac CT')
    fireEvent.click(studyTitle)

    expect(onDrill).toHaveBeenCalledWith({ id: 'imaging-study-detail', instance: '7001' })
  })

  it('Viewer button does not trigger onDrill when study row is clicked via Viewer button', async () => {
    mockGet.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}`) return { patient: makePatient() }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/studies`) {
        return { studies: [makeDicomStudy()] }
      }
      return {}
    })

    const openSpy = jest.fn()
    const originalOpen = window.open
    window.open = openSpy as unknown as typeof window.open

    const onDrill = jest.fn()
    try {
      render(<ImagingPage patientId={PATIENT_ID} onDrill={onDrill} />)

      fireEvent.click(await screen.findByRole('button', { name: /viewer/i }))

      expect(openSpy).toHaveBeenCalled()
      expect(onDrill).not.toHaveBeenCalled()
    } finally {
      window.open = originalOpen
    }
  })
})

function makeDicomStudy(overrides: Partial<PhrDicomStudy> = {}): PhrDicomStudy {
  return {
    id: 7001,
    patient_id: PATIENT_ID,
    upload_id: 501,
    study_instance_uid: '1.2.840.113619.2.55.3.604688437.20260517.1',
    study_date: '2026-05-17',
    study_time: null,
    accession_number: null,
    description: 'Cardiac CT',
    modalities: 'CT',
    series_count: 1,
    instance_count: 1,
    file_size_bytes: 2 * 1024 * 1024,
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}
