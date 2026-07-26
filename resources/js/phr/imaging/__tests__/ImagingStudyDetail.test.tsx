import '@testing-library/jest-dom'

import { render, screen, waitFor } from '@testing-library/react'

import ImagingStudyDetail from '@/phr/imaging/ImagingStudyDetail'
import type { PhrDicomStudy } from '@/phr/types'

const PATIENT_ID = 101
const STUDY_ID = '7001'

const mockFetch = jest.fn()

function makeStudy(overrides: Partial<PhrDicomStudy> = {}): PhrDicomStudy {
  return {
    id: 7001,
    patient_id: PATIENT_ID,
    upload_id: 501,
    study_instance_uid: '1.2.840.113619.2.55.3.604688437.20260517.1',
    study_date: '2026-05-17',
    study_time: null,
    accession_number: 'ACC123',
    description: 'Cardiac CT',
    modalities: 'CT',
    series_count: 2,
    instance_count: 12,
    file_size_bytes: 2097152,
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

function makeViewerResponse(seriesCount = 1) {
  const series = Array.from({ length: seriesCount }, (_, i) => ({
    id: i + 1,
    SeriesInstanceUID: `1.2.3.${i + 1}`,
    SeriesNumber: i + 1,
    Modality: 'CT',
    SeriesDescription: `Series ${i + 1}`,
    instances: [{ metadata: {}, url: `wadors:http://example.com/wado/series/${i + 1}/instances/1` }],
  }))
  return {
    studies: [
      {
        StudyInstanceUID: '1.2.840.113619.2.55.3.604688437.20260517.1',
        StudyDate: '20260517',
        StudyTime: '',
        PatientName: 'Doe^John',
        PatientID: 'P101',
        AccessionNumber: 'ACC123',
        PatientAge: '045Y',
        PatientSex: 'M',
        StudyDescription: 'Cardiac CT',
        series,
        NumInstances: series.length,
        Modalities: 'CT',
      },
    ],
  }
}

function jsonResponse(body: unknown, init: { ok?: boolean; status?: number; statusText?: string } = {}): Response {
  return {
    ok: init.ok ?? true,
    status: init.status ?? 200,
    statusText: init.statusText ?? 'OK',
    text: async () => JSON.stringify(body),
  } as Response
}

beforeEach(() => {
  mockFetch.mockReset()
  globalThis.fetch = mockFetch as unknown as typeof fetch
  mockFetch.mockImplementation(async (url: string) => {
    if (url === `/api/phr/patients/${PATIENT_ID}/dicom/studies/${STUDY_ID}`) {
      return jsonResponse({ study: makeStudy() })
    }
    if (url === `/api/phr/patients/${PATIENT_ID}/dicom/studies/${STUDY_ID}/viewer-json`) {
      return jsonResponse(makeViewerResponse())
    }
    return jsonResponse({})
  })
})

describe('ImagingStudyDetail', () => {
  it('shows a loading state before data arrives', () => {
    // Never resolves so loading state stays visible
    mockFetch.mockReturnValue(new Promise(() => {}))
    render(<ImagingStudyDetail patientId={PATIENT_ID} recordId={STUDY_ID} />)
    expect(screen.getByText(/loading/i)).toBeInTheDocument()
  })

  it('renders study metadata after successful fetch', async () => {
    render(<ImagingStudyDetail patientId={PATIENT_ID} recordId={STUDY_ID} />)

    await waitFor(() => expect(screen.getByRole('heading', { name: 'Cardiac CT' })).toBeInTheDocument())

    expect(screen.getByText('CT')).toBeInTheDocument()
    expect(screen.getByText('2026-05-17')).toBeInTheDocument()
    expect(screen.getByText('ACC123')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /open viewer/i })).toBeInTheDocument()
  })

  it('renders series list', async () => {
    mockFetch.mockImplementation(async (url: string) => {
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/studies/${STUDY_ID}`) {
        return jsonResponse({ study: makeStudy() })
      }
      if (url === `/api/phr/patients/${PATIENT_ID}/dicom/studies/${STUDY_ID}/viewer-json`) {
        return jsonResponse(makeViewerResponse(2))
      }
      return jsonResponse({})
    })

    render(<ImagingStudyDetail patientId={PATIENT_ID} recordId={STUDY_ID} />)

    await waitFor(() => expect(screen.getByText('Series 1')).toBeInTheDocument())
    expect(screen.getByText('Series 2')).toBeInTheDocument()
  })

  it('renders PhrNotFoundColumn on 404', async () => {
    mockFetch.mockResolvedValue(jsonResponse({ message: 'Not Found' }, { ok: false, status: 404, statusText: 'Not Found' }))
    render(<ImagingStudyDetail patientId={PATIENT_ID} recordId={STUDY_ID} />)

    await waitFor(() => expect(screen.getByText(/record not found/i)).toBeInTheDocument())
  })

  it('renders an error message on unexpected fetch failure', async () => {
    mockFetch.mockRejectedValue(new Error('Network error'))
    render(<ImagingStudyDetail patientId={PATIENT_ID} recordId={STUDY_ID} />)

    await waitFor(() => expect(screen.getByText(/network error/i)).toBeInTheDocument())
  })

  it('opens OHIF viewer in new tab when Open Viewer button is clicked', async () => {
    const openSpy = jest.fn()
    const originalOpen = window.open
    window.open = openSpy as unknown as typeof window.open

    try {
      render(<ImagingStudyDetail patientId={PATIENT_ID} recordId={STUDY_ID} />)

      await waitFor(() => expect(screen.getByRole('button', { name: /open viewer/i })).toBeInTheDocument())
      screen.getByRole('button', { name: /open viewer/i }).click()

      expect(openSpy).toHaveBeenCalledWith(
        `/ohif/viewer/dicomjson?url=${encodeURIComponent(`/api/phr/patients/${PATIENT_ID}/dicom/studies/${STUDY_ID}/viewer-json`)}`,
        '_blank',
        'noopener,noreferrer',
      )
    } finally {
      window.open = originalOpen
    }
  })
})
