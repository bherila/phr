import '@testing-library/jest-dom'

import { render, screen, waitFor } from '@testing-library/react'

import type { PhrDicomVolumeManifestResponse } from '@/phr/types'

import { Explore3DStandalone } from '../Explore3DStandalone'

/* The viewer pulls in workers/WebGL that jsdom can't host; the standalone
 * page's own job (manifest fetch + eligibility gate) is what's under test. */
jest.mock('../Explore3DViewer', () => ({
  __esModule: true,
  Explore3DViewer: () => <div data-testid="mock-viewer" />,
}))

const PATIENT_ID = 7
const SERIES_ID = 35

function makeManifest(overrides: Partial<PhrDicomVolumeManifestResponse> = {}): PhrDicomVolumeManifestResponse {
  return {
    series: { id: 35, series_instance_uid: '1.2.3', modality: 'CT', description: 'COR 1.25MM' },
    eligible: true,
    reasons: [],
    warnings: [],
    excluded_instance_count: 0,
    volume: {
      rows: 512,
      columns: 512,
      pixel_spacing: [0.4277, 0.4277],
      slice_spacing: 1.25,
      slice_count: 159,
      orientation: [1, 0, 0, 0, 0, -1],
      origin: [-107.1, 73.9, 112.0],
      bits_allocated: 16,
      pixel_representation: 1,
      default_window: { center: 350, width: 2000 },
      rescale: null,
    },
    instances: [],
    cache: { available: false, url: null, pipeline_version: 1 },
    ...overrides,
  }
}

const mockFetch = jest.fn()

function jsonResponse(body: unknown, init: { ok?: boolean; status?: number } = {}): Response {
  return {
    ok: init.ok ?? true,
    status: init.status ?? 200,
    statusText: 'OK',
    text: async () => JSON.stringify(body),
  } as Response
}

beforeEach(() => {
  mockFetch.mockReset()
  globalThis.fetch = mockFetch as unknown as typeof fetch
})

describe('Explore3DStandalone', () => {
  it('renders the viewer for an eligible series', async () => {
    mockFetch.mockResolvedValue(jsonResponse(makeManifest()))
    render(<Explore3DStandalone patientId={PATIENT_ID} seriesId={SERIES_ID} />)

    await waitFor(() => expect(screen.getByTestId('mock-viewer')).toBeInTheDocument())
    expect(mockFetch).toHaveBeenCalledWith(
      `/api/phr/patients/${PATIENT_ID}/dicom/series/${SERIES_ID}/volume-manifest`,
      expect.anything(),
    )
  })

  it('shows friendly ineligibility copy with a fallback for unknown codes', async () => {
    mockFetch.mockResolvedValue(
      jsonResponse(makeManifest({ eligible: false, volume: null, reasons: ['too_few_slices', 'brand_new_code'] })),
    )
    render(<Explore3DStandalone patientId={PATIENT_ID} seriesId={SERIES_ID} />)

    await waitFor(() => expect(screen.getByText(/doesn’t have enough slices/)).toBeInTheDocument())
    expect(screen.getByText(/brand new code/)).toBeInTheDocument()
    expect(screen.queryByTestId('mock-viewer')).not.toBeInTheDocument()
  })

  it('shows an access/not-found message on 404', async () => {
    mockFetch.mockResolvedValue(jsonResponse({}, { ok: false, status: 404 }))
    render(<Explore3DStandalone patientId={PATIENT_ID} seriesId={SERIES_ID} />)

    await waitFor(() => expect(screen.getByText(/could not be found/)).toBeInTheDocument())
  })
})
