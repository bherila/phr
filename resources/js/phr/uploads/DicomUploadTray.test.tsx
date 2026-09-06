import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import type { ReactElement } from 'react'

import {
  makeDicomFile,
  makeDicomUpload,
  MockUploadXMLHttpRequest,
  TEST_PATIENT_ID as PATIENT_ID,
} from './__tests__/uploadTestDoubles'
import { DicomUploadProvider, useDicomUploads } from './DicomUploadProvider'
import { DicomUploadTray } from './DicomUploadTray'

const mockPost = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: jest.fn(),
    post: (...args: unknown[]) => mockPost(...args),
    patch: jest.fn(),
    delete: jest.fn(),
  },
  getCsrfToken: () => null,
}))

const OPEN_URL = `/api/phr/patients/${PATIENT_ID}/dicom/uploads`

/** Minimal consumer: kicks off a job without dragging the Imports column into the test. */
function StartButton({ root }: { root: string }): ReactElement {
  const { startUpload } = useDicomUploads()
  return (
    <button type="button" onClick={() => startUpload(PATIENT_ID, [makeDicomFile(`${root}/IM0001`)])}>
      start {root}
    </button>
  )
}

function renderTray(): void {
  render(
    <DicomUploadProvider>
      <StartButton root="CARDIAC_CT" />
      <StartButton root="HEAD_MR" />
      <DicomUploadTray />
    </DicomUploadProvider>,
  )
}

function tray(): HTMLElement {
  return screen.getByRole('region', { name: 'DICOM uploads' })
}

beforeEach(() => {
  mockPost.mockReset()
  mockPost.mockImplementation(async (url: string) => {
    if (url === OPEN_URL) return { upload: makeDicomUpload('pending') }
    return {}
  })
})

describe('DicomUploadTray', () => {
  it('renders nothing until a job exists', () => {
    renderTray()
    expect(screen.queryByRole('region', { name: 'DICOM uploads' })).not.toBeInTheDocument()
  })

  it('shows live per-job progress without opening a modal', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.autoComplete = false

    try {
      renderTray()
      fireEvent.click(screen.getByRole('button', { name: 'start CARDIAC_CT' }))

      await waitFor(() => expect(within(tray()).getByText('Uploading…')).toBeInTheDocument())
      expect(within(tray()).getByText('0 / 1 files — CARDIAC_CT')).toBeInTheDocument()
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

      fireEvent.click(screen.getByRole('button', { name: 'start HEAD_MR' }))
      await waitFor(() => expect(within(tray()).getByText('0 / 1 files — HEAD_MR')).toBeInTheDocument())
      expect(within(tray()).getAllByText('Uploading…')).toHaveLength(2)
    } finally {
      restoreXhr()
      MockUploadXMLHttpRequest.instances.forEach((instance) => instance.abort())
    }
  })

  it('reopens the detailed modal for the clicked job', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.autoComplete = false

    try {
      renderTray()
      fireEvent.click(screen.getByRole('button', { name: 'start CARDIAC_CT' }))
      fireEvent.click(screen.getByRole('button', { name: 'start HEAD_MR' }))
      await waitFor(() => expect(within(tray()).getByText('0 / 1 files — HEAD_MR')).toBeInTheDocument())

      // Clicking the *second* entry must expand that job, not the first.
      fireEvent.click(within(tray()).getByText('0 / 1 files — HEAD_MR'))

      const dialog = await screen.findByRole('dialog')
      expect(within(dialog).getByText('Uploading…')).toBeInTheDocument()
      expect(within(dialog).getByText(/0 of 1 files .* HEAD_MR/)).toBeInTheDocument()
      expect(within(dialog).queryAllByText(/CARDIAC_CT/)).toHaveLength(0)
      expect(within(dialog).getByRole('button', { name: /cancel upload/i })).toBeInTheDocument()

      // Backgrounding it again leaves the job running and the tray entry in place.
      fireEvent.click(within(dialog).getByRole('button', { name: /run in background/i }))
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
      expect(within(tray()).getByText('0 / 1 files — HEAD_MR')).toBeInTheDocument()
    } finally {
      restoreXhr()
      MockUploadXMLHttpRequest.instances.forEach((instance) => instance.abort())
    }
  })
})
