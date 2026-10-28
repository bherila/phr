import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactElement } from 'react'

import { makeEhiPackage, makeEhiProposal, makeEhiUpload } from '@/phr/imports/ehi/__tests__/ehiFixtures'
import { EhiUploadProvider } from '@/phr/imports/ehi/EhiUploadProvider'
import ImportsPage from '@/phr/imports/ImportsPage'
import { DicomUploadProvider } from '@/phr/uploads'
import { makeDicomFile, makeDicomUpload, MockUploadXMLHttpRequest, TEST_PATIENT_ID as PATIENT_ID } from '@/phr/uploads/__tests__/uploadTestDoubles'

const mockGet = jest.fn()
const mockPost = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    patch: jest.fn(),
    delete: jest.fn(),
  },
  getCsrfToken: () => null,
}))

function makePatient(overrides: Record<string, unknown> = {}) {
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
    ...overrides,
  }
}

interface Routes {
  patients?: unknown[]
  uploads?: unknown[]
  packages?: unknown[]
  [url: string]: unknown
}

/** Answers each GET by URL; anything unrouted fails the test loudly. */
function routeGets(routes: Routes = {}): void {
  mockGet.mockImplementation((url: string) => {
    if (url === '/api/phr/patients') return Promise.resolve({ patients: routes.patients ?? [makePatient()] })
    if (url.endsWith('/ehi/uploads')) return Promise.resolve({ uploads: routes.uploads ?? [] })
    if (url.endsWith('/ehi/packages')) return Promise.resolve({ packages: routes.packages ?? [] })
    if (url in routes) return Promise.resolve(routes[url])
    return Promise.reject(new Error(`unexpected GET ${url}`))
  })
}

function renderImports(patientId?: number): ReturnType<typeof render> {
  const page: ReactElement = <ImportsPage {...(patientId !== undefined ? { patientId } : {})} />
  return render(<DicomUploadProvider><EhiUploadProvider retryDelayMs={0}>{page}</EhiUploadProvider></DicomUploadProvider>)
}

function folderInput(): HTMLInputElement {
  return screen.getByLabelText('DICOM folder')
}

function chooseImportFile(file: File): void {
  fireEvent.change(screen.getByLabelText('File to import'), { target: { files: [file] } })
}

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
  routeGets()
  mockPost.mockResolvedValue({ upload: makeDicomUpload('pending') })
  window.history.replaceState({}, '', '/phr/imports#/imports')
})

describe('ImportsPage', () => {
  it('offers one drop zone and the DICOM folder picker, with no coming-soon placeholder', async () => {
    renderImports(PATIENT_ID)

    expect(await screen.findByRole('heading', { name: 'Imports' })).toBeInTheDocument()
    expect(screen.getByText('Drop a file here')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByRole('button', { name: /choose dicom folder/i })).toBeEnabled())
    expect(screen.queryByText(/coming soon/i)).not.toBeInTheDocument()
    expect(await screen.findByText(/No EHI exports imported/)).toBeInTheDocument()
  })

  it('defaults the import target to the patient in context', async () => {
    routeGets({ patients: [makePatient({ id: 55, display_name: 'Other' }), makePatient()] })

    renderImports(PATIENT_ID)

    const select = await screen.findByLabelText('Import into')
    await waitFor(() => expect(select).toHaveValue(String(PATIENT_ID)))
  })

  it('falls back to the first manageable patient when opened without one', async () => {
    routeGets({
      patients: [
        makePatient({ id: 55, display_name: 'View only', can_manage: false }),
        makePatient({ id: 56, display_name: 'Archived', archived_at: '2026-01-01T00:00:00Z' }),
        makePatient({ id: 57, display_name: 'Manageable' }),
      ],
    })

    renderImports()

    const select = await screen.findByLabelText('Import into')
    await waitFor(() => expect(select).toHaveValue('57'))
    expect(screen.queryByRole('option', { name: 'View only' })).not.toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Archived' })).not.toBeInTheDocument()
  })

  it('disables importing when no patient can be managed', async () => {
    routeGets({ patients: [makePatient({ can_manage: false })] })

    renderImports()

    await waitFor(() => expect(screen.getByRole('button', { name: /choose dicom folder/i })).toBeDisabled())
    expect(screen.getByRole('button', { name: 'Choose file' })).toBeDisabled()
    expect(screen.getByText(/need manage access to a patient/i)).toBeInTheDocument()
  })

  it('opens an upload session for the selected patient', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.autoComplete = false

    try {
      renderImports(PATIENT_ID)
      await waitFor(() => expect(screen.getByRole('button', { name: /choose dicom folder/i })).toBeEnabled())

      const input = folderInput()
      fireEvent.change(input, { target: { files: [makeDicomFile('CARDIAC_CT/IM0001')] } })

      await waitFor(() => expect(mockPost).toHaveBeenCalledWith(
        `/api/phr/patients/${PATIENT_ID}/dicom/uploads`,
        { root_name: 'CARDIAC_CT' },
      ))
      // The folder input is cleared so re-picking the same folder starts another job.
      expect(input.value).toBe('')
    } finally {
      restoreXhr()
      MockUploadXMLHttpRequest.instances.forEach((instance) => instance.abort())
    }
  })

  it('reads the chosen files before clearing the folder input', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.autoComplete = false

    try {
      renderImports(PATIENT_ID)
      await waitFor(() => expect(screen.getByRole('button', { name: /choose dicom folder/i })).toBeEnabled())

      const input = folderInput()

      // A real browser hands the change handler the input's *own* live FileList and empties
      // it in place when `value` is cleared, so the handler has to snapshot first. Model that
      // here: clearing the input drops the file, and a read-after-clear would upload nothing.
      const files = { length: 1, 0: makeDicomFile('CARDIAC_CT/IM0001') }
      Object.defineProperty(input, 'files', { configurable: true, get: () => files })
      Object.defineProperty(input, 'value', {
        configurable: true,
        get: () => '',
        set: () => {
          files.length = 0
        },
      })

      fireEvent.change(input)

      await waitFor(() => expect(mockPost).toHaveBeenCalledWith(
        `/api/phr/patients/${PATIENT_ID}/dicom/uploads`,
        { root_name: 'CARDIAC_CT' },
      ))
      expect(screen.queryByText(/No DICOM-compatible files were found/)).not.toBeInTheDocument()
    } finally {
      restoreXhr()
      MockUploadXMLHttpRequest.instances.forEach((instance) => instance.abort())
    }
  })

  it('refuses a file it cannot import here and says where it goes instead', async () => {
    renderImports(PATIENT_ID)
    await waitFor(() => expect(screen.getByRole('button', { name: 'Choose file' })).toBeEnabled())

    chooseImportFile(new File(['<ClinicalDocument/>'], 'summary.xml', { type: 'text/xml' }))

    expect(screen.getByRole('heading', { name: 'PHR can’t import this file here yet' })).toBeInTheDocument()
    expect(screen.getByText(/still go through the Documents tab/)).toBeInTheDocument()
    expect(mockPost).not.toHaveBeenCalled()
  })

  it('checks a ZIP with the person before uploading it, then follows the import', async () => {
    const base = `/api/phr/patients/${PATIENT_ID}/ehi/uploads`
    mockPost.mockImplementation((url: string, body: unknown) => {
      if (url === base) return Promise.resolve({ upload: makeEhiUpload({ patient_id: PATIENT_ID, file_size_bytes: 6, chunk_size_bytes: 8 }) })
      if (url.endsWith('/chunks')) return Promise.resolve({ upload: makeEhiUpload({ patient_id: PATIENT_ID, uploaded_bytes: (body as FormData).get('chunk') instanceof Blob ? 6 : 0 }) })
      return Promise.resolve({ upload: makeEhiUpload({ patient_id: PATIENT_ID, status: 'queued', uploaded_bytes: 6 }) })
    })
    routeGets({ [`${base}/5`]: { upload: makeEhiUpload({ patient_id: PATIENT_ID, status: 'processing', uploaded_bytes: 6, file_size_bytes: 6 }) } })
    renderImports(PATIENT_ID)
    await waitFor(() => expect(screen.getByRole('button', { name: 'Choose file' })).toBeEnabled())

    chooseImportFile(new File(['PK\u0003\u0004xx'], 'ehi-export.zip', { type: 'application/zip' }))

    expect(screen.getByRole('heading', { name: 'Epic EHI Tables export' })).toBeInTheDocument()
    const start = screen.getByRole('button', { name: 'Start import' })
    expect(start).toBeDisabled()
    fireEvent.click(screen.getByLabelText('This file belongs to Primary'))
    fireEvent.click(start)

    expect(await screen.findByText('Storing and checking every file in the export…')).toBeInTheDocument()
    expect(mockPost).toHaveBeenCalledWith(base, { file_size_bytes: 6 })
    expect(mockPost).toHaveBeenCalledWith(`${base}/5/intake`, {})
  })

  it('asks the owner where an accounted export came from, then prepares its review', async () => {
    const packageUrl = `/api/phr/patients/${PATIENT_ID}/ehi/packages/9`
    const unbound = makeEhiPackage({ patient_id: PATIENT_ID })
    const bound = { ...unbound, binding_status: 'bound', source: { id: 3, label: 'Synthetic Clinic' } }
    routeGets({
      packages: [unbound],
      [packageUrl]: { package: unbound },
      [`${packageUrl}/binding`]: { suggestions: [] },
    })
    mockPost.mockImplementation((url: string) => (url.endsWith('/binding')
      ? Promise.resolve({ package: bound })
      : Promise.resolve({ preview_run: null, package: { ...bound, preview_run: { id: 1, package_id: 9, status: 'queued', failure_reason: null, counts: null, statuses: null, findings: null, preview_digest: null, replayed: null, requested_at: null, started_at: null, completed_at: null } } })))
    renderImports(PATIENT_ID)

    fireEvent.click(await screen.findByRole('button', { name: 'View' }))
    expect(await screen.findByText('Every row accounted for')).toBeInTheDocument()
    // Each source row lands in exactly one disposition, so the listed rows add up to the total.
    expect(screen.getByText('Kept, not mapped yet').closest('dt')?.nextElementSibling).toHaveTextContent('100')
    expect(screen.getByText('Outside PHR’s scope').closest('dt')?.nextElementSibling).toHaveTextContent('40')
    expect(screen.getByText('Every row accounted for').nextElementSibling).toHaveTextContent('140')
    expect(screen.getByText('****1234')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('New source name'), { target: { value: 'Synthetic Clinic' } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirm source' }))

    await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`${packageUrl}/binding`, { new_source_label: 'Synthetic Clinic' }))
    await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`${packageUrl}/preview`, {}))
  })

  it('tells a manager that only the owner can confirm the source', async () => {
    const packageUrl = `/api/phr/patients/${PATIENT_ID}/ehi/packages/9`
    const unbound = makeEhiPackage({ patient_id: PATIENT_ID })
    routeGets({ patients: [makePatient({ access_level: 'manager' })], packages: [unbound], [packageUrl]: { package: unbound } })
    renderImports(PATIENT_ID)

    fireEvent.click(await screen.findByRole('button', { name: 'View' }))

    expect(await screen.findByText(/owner needs to confirm where this export came from/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Confirm source' })).not.toBeInTheDocument()
  })

  it('opens a linked review for the linked patient, even one the viewer cannot import into', async () => {
    window.history.replaceState({}, '', '/phr/imports?patient_id=55&ehi_package=9#/imports')
    routeGets({
      patients: [makePatient({ id: 55, display_name: 'Read only', can_manage: false, access_level: 'viewer' }), makePatient()],
      '/api/phr/patients/55/ehi/packages/9/proposals?limit=500&after_id=0': { proposals: [makeEhiProposal()], next_after_id: null },
    })
    render(<DicomUploadProvider><EhiUploadProvider><ImportsPage /></EhiUploadProvider></DicomUploadProvider>)

    expect(await screen.findByRole('button', { name: 'Accept: Synthetic Vaccine A' })).toBeInTheDocument()
    await waitFor(() => expect(screen.getByLabelText('Import into')).toHaveValue(String(PATIENT_ID)))
    expect(mockGet).not.toHaveBeenCalledWith(`/api/phr/patients/${PATIENT_ID}/ehi/packages/9/proposals?limit=500&after_id=0`)
  })

  it('opens a package’s review straight from a record page’s link', async () => {
    window.history.replaceState({}, '', `/phr/imports?patient_id=${PATIENT_ID}&ehi_package=9#/imports`)
    routeGets({
      [`/api/phr/patients/${PATIENT_ID}/ehi/packages/9/proposals?limit=500&after_id=0`]: { proposals: [makeEhiProposal()], next_after_id: null },
    })
    render(<DicomUploadProvider><EhiUploadProvider><ImportsPage /></EhiUploadProvider></DicomUploadProvider>)

    expect(await screen.findByRole('heading', { name: 'Review EHI export' })).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'Accept: Synthetic Vaccine A' })).toBeInTheDocument()
  })
})
