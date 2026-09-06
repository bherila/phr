import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import type { ReactElement } from 'react'

import ImportsPage from '@/phr/imports/ImportsPage'

import {
  makeDicomFile,
  makeDicomUpload,
  makeDicomUploadFileResponse,
  MockUploadXMLHttpRequest,
  TEST_PATIENT_ID as PATIENT_ID,
} from './__tests__/uploadTestDoubles'
import { ConcurrencyBudget, UPLOAD_CONCURRENCY } from './dicomUpload'
import { DicomUploadProvider, preventUnloadDuringUpload } from './DicomUploadProvider'
import { DicomUploadTray } from './DicomUploadTray'

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

const OPEN_URL = `/api/phr/patients/${PATIENT_ID}/dicom/uploads`
const FINALIZE_URL = `${OPEN_URL}/501/finalize`
const CANCEL_URL = `${OPEN_URL}/501/cancel`

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

function Harness({ budget }: { budget?: ConcurrencyBudget }): ReactElement {
  return (
    <DicomUploadProvider {...(budget ? { budget } : {})}>
      <ImportsPage patientId={PATIENT_ID} />
      <DicomUploadTray />
    </DicomUploadProvider>
  )
}

async function renderImports(budget?: ConcurrencyBudget) {
  const result = render(<Harness {...(budget ? { budget } : {})} />)
  // The trigger enables once the patient list lands, which is when an upload can start.
  await waitFor(() => expect(screen.getByRole('button', { name: /upload dicom folder/i })).toBeEnabled())
  return result
}

function fileInput(container: HTMLElement): HTMLInputElement {
  const input = container.querySelector('input[type="file"]')
  if (!(input instanceof HTMLInputElement)) {
    throw new Error('Expected the DICOM folder input to render.')
  }
  return input
}

function tray(): HTMLElement {
  return screen.getByRole('region', { name: 'DICOM uploads' })
}

/** Expands the tray entry at `index` into its detail modal. */
function openTrayJob(index = 0): void {
  const entries = within(tray()).getAllByRole('button')
    .filter((button) => !button.getAttribute('aria-label')?.startsWith('Dismiss'))
  const entry = entries[index]
  if (!entry) {
    throw new Error(`Expected a tray entry at index ${index}.`)
  }
  fireEvent.click(entry)
}

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
  mockGet.mockImplementation(async (url: string) => {
    if (url === '/api/phr/patients') return { patients: [makePatient()] }
    return {}
  })
  mockPost.mockResolvedValue({ upload: makeDicomUpload('pending') })
})

describe('DicomUploadProvider', () => {
  it('tracks a job to completion and shows it in the tray', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    mockPost.mockImplementation(async (url: string) => {
      if (url === OPEN_URL) return { upload: makeDicomUpload('pending') }
      if (url === FINALIZE_URL) return { upload: makeDicomUpload('processed') }
      return {}
    })

    try {
      const { container } = await renderImports()
      fireEvent.change(fileInput(container), { target: { files: [makeDicomFile('CARDIAC_CT/IM0001')] } })

      // The tray is the non-blocking surface: it appears without any modal being opened.
      expect(within(tray()).getByText('0 / 1 files — CARDIAC_CT')).toBeInTheDocument()
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

      await waitFor(() => expect(within(tray()).getByText('Upload complete')).toBeInTheDocument())
      expect(MockUploadXMLHttpRequest.instances).toHaveLength(1)
      expect(MockUploadXMLHttpRequest.instances[0]?.requestUrl).toBe(`${OPEN_URL}/501/files`)
      expect(MockUploadXMLHttpRequest.instances[0]?.requestBody?.get('file')).toBeInstanceOf(File)
      expect(MockUploadXMLHttpRequest.instances[0]?.requestBody?.get('relative_path')).toBe('CARDIAC_CT/IM0001')
      expect(mockPost).toHaveBeenCalledWith(FINALIZE_URL, {})
    } finally {
      restoreXhr()
    }
  })

  it('uploads every file in the folder with one multipart POST each', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.responseTextFor = (body) => JSON.stringify(
      makeDicomUploadFileResponse(String(body.get('relative_path'))),
    )
    mockPost.mockImplementation(async (url: string) => {
      if (url === OPEN_URL) return { upload: makeDicomUpload('pending') }
      if (url === FINALIZE_URL) return { upload: makeDicomUpload('processed') }
      return {}
    })

    try {
      const { container } = await renderImports()
      fireEvent.change(fileInput(container), {
        target: { files: [makeDicomFile('CARDIAC_CT/IM0001'), makeDicomFile('CARDIAC_CT/IM0002', 'dicom2')] },
      })

      await waitFor(() => expect(within(tray()).getByText('Upload complete')).toBeInTheDocument())
      expect(MockUploadXMLHttpRequest.instances).toHaveLength(2)
      expect(
        MockUploadXMLHttpRequest.instances.map((instance) => instance.requestBody?.get('relative_path')).sort(),
      ).toEqual(['CARDIAC_CT/IM0001', 'CARDIAC_CT/IM0002'])
    } finally {
      restoreXhr()
    }
  })

  it('runs two jobs concurrently and tracks each independently', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.autoComplete = false
    MockUploadXMLHttpRequest.responseTextFor = (body) => JSON.stringify(
      makeDicomUploadFileResponse(String(body.get('relative_path'))),
    )
    mockPost.mockImplementation(async (url: string) => {
      if (url === OPEN_URL) return { upload: makeDicomUpload('pending') }
      if (url === FINALIZE_URL) return { upload: makeDicomUpload('processed') }
      return {}
    })

    try {
      const { container } = await renderImports()
      const input = fileInput(container)

      fireEvent.change(input, { target: { files: [makeDicomFile('STUDY_A/IM0001')] } })
      await waitFor(() => expect(MockUploadXMLHttpRequest.instances).toHaveLength(1))

      // Job B starts while job A's request is still genuinely in flight.
      fireEvent.change(input, { target: { files: [makeDicomFile('STUDY_B/IM0001')] } })
      await waitFor(() => expect(MockUploadXMLHttpRequest.instances).toHaveLength(2))

      expect(within(tray()).getByText('0 / 1 files — STUDY_A')).toBeInTheDocument()
      expect(within(tray()).getByText('0 / 1 files — STUDY_B')).toBeInTheDocument()
      expect(mockPost.mock.calls.filter(([url]) => url === OPEN_URL)).toHaveLength(2)

      // Finish B first: the two jobs' lifecycles do not depend on each other.
      MockUploadXMLHttpRequest.instances[1]?.complete()
      await waitFor(() => expect(within(tray()).getByText('1 / 1 files — STUDY_B')).toBeInTheDocument())
      expect(within(tray()).getByText('0 / 1 files — STUDY_A')).toBeInTheDocument()

      MockUploadXMLHttpRequest.instances[0]?.complete()
      await waitFor(() => expect(within(tray()).getAllByText('Upload complete')).toHaveLength(2))
    } finally {
      restoreXhr()
    }
  })

  it('caps in-flight file requests across all jobs at the shared budget', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.autoComplete = false
    MockUploadXMLHttpRequest.responseTextFor = (body) => JSON.stringify(
      makeDicomUploadFileResponse(String(body.get('relative_path'))),
    )
    mockPost.mockImplementation(async (url: string) => {
      if (url === OPEN_URL) return { upload: makeDicomUpload('pending') }
      if (url === FINALIZE_URL) return { upload: makeDicomUpload('processed') }
      return {}
    })

    // A dedicated budget rather than the process-wide default: requests this test leaves
    // hanging must not starve the permits later tests need.
    const budget = new ConcurrencyBudget(UPLOAD_CONCURRENCY)

    try {
      const { container } = await renderImports(budget)
      const input = fileInput(container)
      const filesFor = (root: string) => Array.from({ length: 6 }, (_, i) => makeDicomFile(`${root}/IM000${i}`))

      fireEvent.change(input, { target: { files: filesFor('STUDY_A') } })
      fireEvent.change(input, { target: { files: filesFor('STUDY_B') } })

      await waitFor(() => expect(MockUploadXMLHttpRequest.instances.length).toBeGreaterThan(0))
      // Two jobs of six files each would be 12 simultaneous POSTs without a shared budget.
      await new Promise((resolve) => setTimeout(resolve, 20))
      expect(MockUploadXMLHttpRequest.instances).toHaveLength(UPLOAD_CONCURRENCY)
      expect(budget.availablePermits).toBe(0)

      const firstWave = MockUploadXMLHttpRequest.instances.slice()
      firstWave.forEach((instance) => instance.complete())
      await waitFor(() => expect(MockUploadXMLHttpRequest.instances.length).toBe(2 * UPLOAD_CONCURRENCY))
    } finally {
      restoreXhr()
    }
  })

  it('cancels an in-flight job and discards the server session', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.autoComplete = false
    mockPost.mockImplementation(async (url: string) => {
      if (url === OPEN_URL) return { upload: makeDicomUpload('pending') }
      if (url === FINALIZE_URL) throw new Error('Finalize should not be called.')
      if (url === CANCEL_URL) return { upload: makeDicomUpload('failed') }
      return {}
    })

    try {
      const { container } = await renderImports()
      fireEvent.change(fileInput(container), { target: { files: [makeDicomFile('CARDIAC_CT/IM0001')] } })
      await waitFor(() => expect(MockUploadXMLHttpRequest.instances).toHaveLength(1))

      openTrayJob()
      const dialog = await screen.findByRole('dialog')
      fireEvent.click(within(dialog).getByRole('button', { name: /cancel upload/i }))

      // The modal stays open through the abort, so assert inside it: Base UI marks the rest
      // of the page inert while a dialog is up, which hides the tray from role queries.
      await waitFor(() => expect(within(dialog).getByText('Upload cancelled')).toBeInTheDocument())
      expect(mockPost).toHaveBeenCalledWith(CANCEL_URL, {})
      expect(mockPost).not.toHaveBeenCalledWith(FINALIZE_URL, {})
    } finally {
      restoreXhr()
    }
  })

  it('keeps a job failed when finalize fails and cancels the session', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    mockPost.mockImplementation(async (url: string) => {
      if (url === OPEN_URL) return { upload: makeDicomUpload('pending') }
      if (url === FINALIZE_URL) throw new Error('Finalize failed.')
      if (url === CANCEL_URL) return { upload: makeDicomUpload('failed') }
      return {}
    })

    try {
      const { container } = await renderImports()
      fireEvent.change(fileInput(container), { target: { files: [makeDicomFile('CARDIAC_CT/IM0001')] } })

      await waitFor(() => expect(within(tray()).getByText('Upload failed')).toBeInTheDocument())
      expect(within(tray()).queryByText('Upload complete')).not.toBeInTheDocument()

      openTrayJob()
      expect(await screen.findAllByText(/Finalize failed\./)).not.toHaveLength(0)
      expect(mockPost).toHaveBeenCalledWith(CANCEL_URL, {})
    } finally {
      restoreXhr()
    }
  })

  it('cancels the session when the server rejects a file', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.status = 403
    MockUploadXMLHttpRequest.statusText = 'Forbidden'
    MockUploadXMLHttpRequest.responseText = '<!DOCTYPE html><html lang="en"><head><title>Redirected</title></head></html>'
    mockPost.mockImplementation(async (url: string) => {
      if (url === OPEN_URL) return { upload: makeDicomUpload('pending') }
      if (url === FINALIZE_URL) throw new Error('Finalize should not be called.')
      if (url === CANCEL_URL) return { upload: makeDicomUpload('failed') }
      return {}
    })

    try {
      const { container } = await renderImports()
      fireEvent.change(fileInput(container), { target: { files: [makeDicomFile('CARDIAC_CT/IM0001')] } })

      await waitFor(() => expect(within(tray()).getByText('Upload failed')).toBeInTheDocument())
      openTrayJob()
      expect(await screen.findAllByText(/Forbidden/)).not.toHaveLength(0)
      expect(mockPost).toHaveBeenCalledWith(CANCEL_URL, {})
      expect(mockPost).not.toHaveBeenCalledWith(FINALIZE_URL, {})
    } finally {
      restoreXhr()
    }
  })

  it('fails oversized files before sending them to the server', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    mockPost.mockImplementation(async (url: string) => {
      if (url === OPEN_URL) {
        return {
          upload: makeDicomUpload('pending'),
          limits: { max_file_bytes: 3, max_file_size_label: '3 B' },
        }
      }
      if (url === FINALIZE_URL) throw new Error('Finalize should not be called.')
      if (url === CANCEL_URL) return { upload: makeDicomUpload('failed') }
      return {}
    })

    try {
      const { container } = await renderImports()
      fireEvent.change(fileInput(container), { target: { files: [makeDicomFile('CARDIAC_CT/IM0001')] } })

      await waitFor(() => expect(within(tray()).getByText('Upload failed')).toBeInTheDocument())
      expect(MockUploadXMLHttpRequest.instances).toHaveLength(0)
      openTrayJob()
      expect(await screen.findAllByText(/exceeds the server upload limit of 3 B/)).not.toHaveLength(0)
      expect(mockPost).toHaveBeenCalledWith(CANCEL_URL, {})
    } finally {
      restoreXhr()
    }
  })

  it('reports a duplicate study instead of a failure when finalize de-duplicates', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.responseText = JSON.stringify(makeDicomUploadFileResponse('CARDIAC_CT/IM0001', {
      stored: false,
      skipped_reason: 'duplicate_sop_instance',
      study_id: null,
    }))
    mockPost.mockImplementation(async (url: string) => {
      if (url === OPEN_URL) return { upload: makeDicomUpload('pending') }
      if (url === FINALIZE_URL) {
        return { upload: makeDicomUpload('failed', { stored_files: 0, skipped_files: 1 }), duplicate_upload: true }
      }
      if (url === CANCEL_URL) throw new Error('Cancel should not be called.')
      return {}
    })

    try {
      const { container } = await renderImports()
      fireEvent.change(fileInput(container), { target: { files: [makeDicomFile('CARDIAC_CT/IM0001')] } })

      await waitFor(() => expect(within(tray()).getByText('Duplicate study skipped')).toBeInTheDocument())
      openTrayJob()
      expect(await screen.findByText('No new images were added. Stored 0 · Skipped 1')).toBeInTheDocument()
      expect(screen.getByText('This study is already available in the imaging library.')).toBeInTheDocument()
      expect(mockPost).not.toHaveBeenCalledWith(CANCEL_URL, {})
    } finally {
      restoreXhr()
    }
  })

  it('rejects a folder that holds no DICOM-compatible files', async () => {
    const { container } = await renderImports()
    fireEvent.change(fileInput(container), { target: { files: [makeDicomFile('CD/AUTORUN.INF'), makeDicomFile('CD/readme.txt')] } })

    expect(screen.getByText('No DICOM-compatible files were found in the chosen folder.')).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'DICOM uploads' })).not.toBeInTheDocument()
    expect(mockPost).not.toHaveBeenCalled()
  })

  it('dismisses a finished job from the tray', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    mockPost.mockImplementation(async (url: string) => {
      if (url === OPEN_URL) return { upload: makeDicomUpload('pending') }
      if (url === FINALIZE_URL) return { upload: makeDicomUpload('processed') }
      return {}
    })

    try {
      const { container } = await renderImports()
      fireEvent.change(fileInput(container), { target: { files: [makeDicomFile('CARDIAC_CT/IM0001')] } })
      await waitFor(() => expect(within(tray()).getByText('Upload complete')).toBeInTheDocument())

      fireEvent.click(screen.getByRole('button', { name: 'Dismiss Upload complete' }))
      expect(screen.queryByRole('region', { name: 'DICOM uploads' })).not.toBeInTheDocument()
    } finally {
      restoreXhr()
    }
  })
})

describe('beforeunload guard', () => {
  it('is registered only while a job is active', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.autoComplete = false
    const addSpy = jest.spyOn(window, 'addEventListener')
    const removeSpy = jest.spyOn(window, 'removeEventListener')
    mockPost.mockImplementation(async (url: string) => {
      if (url === OPEN_URL) return { upload: makeDicomUpload('pending') }
      if (url === FINALIZE_URL) return { upload: makeDicomUpload('processed') }
      return {}
    })

    try {
      const { container } = await renderImports()
      expect(addSpy).not.toHaveBeenCalledWith('beforeunload', preventUnloadDuringUpload)

      fireEvent.change(fileInput(container), { target: { files: [makeDicomFile('CARDIAC_CT/IM0001')] } })
      await waitFor(() => expect(addSpy).toHaveBeenCalledWith('beforeunload', preventUnloadDuringUpload))
      expect(removeSpy).not.toHaveBeenCalledWith('beforeunload', preventUnloadDuringUpload)

      MockUploadXMLHttpRequest.instances[0]?.complete()
      await waitFor(() => expect(within(tray()).getByText('Upload complete')).toBeInTheDocument())
      await waitFor(() => expect(removeSpy).toHaveBeenCalledWith('beforeunload', preventUnloadDuringUpload))
    } finally {
      addSpy.mockRestore()
      removeSpy.mockRestore()
      restoreXhr()
    }
  })

  it('asks the browser to confirm the unload', () => {
    const event = { preventDefault: jest.fn(), returnValue: undefined } as unknown as BeforeUnloadEvent

    preventUnloadDuringUpload(event)

    expect(event.preventDefault).toHaveBeenCalled()
    expect(event.returnValue).toBe('')
  })
})

describe('ConcurrencyBudget', () => {
  it('hands a released permit to the longest-waiting caller', async () => {
    const budget = new ConcurrencyBudget(1)
    const order: string[] = []

    await budget.acquire()
    const second = budget.acquire().then(() => order.push('second'))
    const third = budget.acquire().then(() => order.push('third'))

    expect(budget.availablePermits).toBe(0)
    budget.release()
    await second
    budget.release()
    await third

    expect(order).toEqual(['second', 'third'])
  })
})
