import '@testing-library/jest-dom'

import { act, render, screen, waitFor } from '@testing-library/react'
import type { ReactElement } from 'react'
import { useEffect } from 'react'

import { MockUploadXMLHttpRequest } from '@/phr/uploads/__tests__/uploadTestDoubles'

import { EhiUploadProvider, useEhiUploads } from '../EhiUploadProvider'
import { EHI_PATIENT_ID, makeEhiUpload } from './ehiFixtures'

const mockGet = jest.fn()
const mockPost = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
  getCsrfToken: () => null,
}))

const base = `/api/phr/patients/${EHI_PATIENT_ID}/ehi/uploads`

const holder: { api: ReturnType<typeof useEhiUploads> | null } = { api: null }

function Harness(): ReactElement {
  const uploads = useEhiUploads()
  useEffect(() => {
    holder.api = uploads
  })
  return (
    <ul>
      {uploads.jobs.map((job) => <li key={job.id}>{`${job.phase} ${job.bytesSent}/${job.fileSizeBytes}${job.cancellable ? '' : ' committed'}${job.error ? ` ${job.error}` : ''}`}</li>)}
    </ul>
  )
}

function file(): File {
  return new File(['0123456789'], 'export.zip', { type: 'application/zip' })
}

/** Offsets of the chunk posts, in order. */
function chunkOffsets(): string[] {
  return mockPost.mock.calls
    .filter(([url]) => String(url).endsWith('/chunks'))
    .map(([, body]) => (body as FormData).get('offset') as string)
}

beforeEach(() => {
  holder.api = null
  mockGet.mockReset()
  mockPost.mockReset()
})

describe('EhiUploadProvider on the s3 transport', () => {
  const STORE_URL = 'https://objects.example.test/bucket/phr-documents/package.zip'

  /** Answers as the server does; the parts go to the XHR double. */
  function directApi(signFailures: Record<number, number> = {}): void {
    const remaining = { ...signFailures }
    mockPost.mockImplementation((url: string, body: { part_number?: number }) => {
      if (url === base) return Promise.resolve({ upload: makeEhiUpload({ file_size_bytes: 10, transport: 's3', part_size_bytes: 4 }) })
      if (url.endsWith('/parts')) {
        const partNumber = body.part_number ?? 0
        if ((remaining[partNumber] ?? 0) > 0) {
          remaining[partNumber] = (remaining[partNumber] ?? 0) - 1
          return Promise.reject('Network error')
        }
        return Promise.resolve({ part: { part_number: partNumber, byte_size: 4, url: `${STORE_URL}?partNumber=${partNumber}`, headers: {} } })
      }
      return Promise.resolve({ upload: makeEhiUpload({ status: 'queued', uploaded_bytes: 10, transport: 's3' }) })
    })
  }

  it('PUTs each part to the store, then finishes with the ETags it read', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.responseHeaders = { ETag: '"synthetic-etag"' }
    directApi()

    try {
      render(<EhiUploadProvider retryDelayMs={0}><Harness /></EhiUploadProvider>)
      act(() => {
        holder.api!.start(EHI_PATIENT_ID, file())
      })

      expect(await screen.findByText('queued 10/10 committed')).toBeInTheDocument()
      const requests = MockUploadXMLHttpRequest.instances
      expect(requests.map((request) => `${request.requestMethod} ${request.requestUrl}`)).toEqual([
        `PUT ${STORE_URL}?partNumber=1`,
        `PUT ${STORE_URL}?partNumber=2`,
        `PUT ${STORE_URL}?partNumber=3`,
      ])
      expect(requests.map((request) => request.requestBlob?.size)).toEqual([4, 4, 2])
      expect(requests.every((request) => !request.withCredentials)).toBe(true)
      expect(chunkOffsets()).toEqual([])
      expect(mockPost).toHaveBeenLastCalledWith(`${base}/5/intake`, {
        parts: [1, 2, 3].map((part_number) => ({ part_number, etag: '"synthetic-etag"' })),
      })
    } finally {
      restoreXhr()
    }
  })

  it('retries a failed part with a fresh signature and does not start over', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.responseHeaders = { ETag: '"synthetic-etag"' }
    directApi({ 2: 1 })

    try {
      render(<EhiUploadProvider retryDelayMs={0}><Harness /></EhiUploadProvider>)
      act(() => {
        holder.api!.start(EHI_PATIENT_ID, file())
      })

      expect(await screen.findByText('queued 10/10 committed')).toBeInTheDocument()
      const signed = mockPost.mock.calls.filter(([url]) => String(url).endsWith('/parts')).map(([, body]) => (body as { part_number: number }).part_number)
      expect(signed).toEqual([1, 2, 2, 3])
      expect(MockUploadXMLHttpRequest.instances).toHaveLength(3)
    } finally {
      restoreXhr()
    }
  })

  it('gives up after the attempt limit and reports the failure', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.status = 500
    MockUploadXMLHttpRequest.responseHeaders = { ETag: '"synthetic-etag"' }
    directApi()

    try {
      render(<EhiUploadProvider retryDelayMs={0}><Harness /></EhiUploadProvider>)
      act(() => {
        holder.api!.start(EHI_PATIENT_ID, file())
      })

      expect(await screen.findByText(/^failed/)).toBeInTheDocument()
      expect(MockUploadXMLHttpRequest.instances).toHaveLength(3)
      expect(mockPost).not.toHaveBeenCalledWith(`${base}/5/intake`, expect.anything())
    } finally {
      restoreXhr()
    }
  })

  it('stops at once, naming CORS, when the store hides the ETag', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    directApi()

    try {
      render(<EhiUploadProvider retryDelayMs={0}><Harness /></EhiUploadProvider>)
      act(() => {
        holder.api!.start(EHI_PATIENT_ID, file())
      })

      await screen.findByText(/^failed/)
      expect(MockUploadXMLHttpRequest.instances).toHaveLength(1)
      expect(holder.api!.jobs[0]?.error).toMatch(/CORS rules must expose ETag/)
    } finally {
      restoreXhr()
    }
  })

  it('aborts the part in flight on cancel and does not queue intake', async () => {
    const restoreXhr = MockUploadXMLHttpRequest.install()
    MockUploadXMLHttpRequest.autoComplete = false
    directApi()

    try {
      render(<EhiUploadProvider retryDelayMs={0}><Harness /></EhiUploadProvider>)
      let jobId = ''
      act(() => {
        jobId = holder.api!.start(EHI_PATIENT_ID, file())
      })
      await waitFor(() => expect(MockUploadXMLHttpRequest.instances).toHaveLength(1))

      act(() => {
        holder.api!.cancel(jobId)
      })

      expect(await screen.findByText(/^cancelled/)).toBeInTheDocument()
      expect(mockPost).not.toHaveBeenCalledWith(`${base}/5/intake`, expect.anything())
    } finally {
      restoreXhr()
    }
  })
})

describe('EhiUploadProvider', () => {
  it('sends the file in the server’s chunk size, then queues intake', async () => {
    mockPost.mockImplementation((url: string, body: unknown) => {
      if (url === base) return Promise.resolve({ upload: makeEhiUpload({ file_size_bytes: 10 }) })
      if (url.endsWith('/chunks')) {
        const form = body as FormData
        const end = Number(form.get('offset')) + (form.get('chunk') as Blob).size
        return Promise.resolve({ upload: makeEhiUpload({ uploaded_bytes: end }) })
      }
      return Promise.resolve({ upload: makeEhiUpload({ status: 'queued', uploaded_bytes: 10 }) })
    })
    render(<EhiUploadProvider retryDelayMs={0}><Harness /></EhiUploadProvider>)

    act(() => {
      holder.api!.start(EHI_PATIENT_ID, file())
    })

    expect(await screen.findByText('queued 10/10 committed')).toBeInTheDocument()
    expect(mockPost).toHaveBeenNthCalledWith(1, base, { file_size_bytes: 10 })
    expect(chunkOffsets()).toEqual(['0', '4', '8'])
    expect(mockPost).toHaveBeenLastCalledWith(`${base}/5/intake`, {})
  })

  it('continues from what the server kept after a failed chunk', async () => {
    let failedOnce = false
    mockPost.mockImplementation((url: string, body: unknown) => {
      if (url === base) return Promise.resolve({ upload: makeEhiUpload() })
      if (url.endsWith('/chunks')) {
        const form = body as FormData
        const offset = Number(form.get('offset'))
        if (offset === 4 && !failedOnce) {
          failedOnce = true
          return Promise.reject('Network error')
        }
        return Promise.resolve({ upload: makeEhiUpload({ uploaded_bytes: offset + (form.get('chunk') as Blob).size }) })
      }
      return Promise.resolve({ upload: makeEhiUpload({ status: 'queued' }) })
    })
    // The failed request reached the server after all: it kept bytes 4-7.
    mockGet.mockResolvedValue({ upload: makeEhiUpload({ uploaded_bytes: 8 }) })
    render(<EhiUploadProvider retryDelayMs={0}><Harness /></EhiUploadProvider>)

    act(() => {
      holder.api!.start(EHI_PATIENT_ID, file())
    })

    expect(await screen.findByText('queued 10/10 committed')).toBeInTheDocument()
    expect(chunkOffsets()).toEqual(['0', '4', '8'])
    expect(mockGet).toHaveBeenCalledWith(`${base}/5`)
  })

  it('keeps retrying when asking the server where it stands fails too', async () => {
    let chunkFailed = false
    mockPost.mockImplementation((url: string, body: unknown) => {
      if (url === base) return Promise.resolve({ upload: makeEhiUpload() })
      if (url.endsWith('/chunks')) {
        const form = body as FormData
        const offset = Number(form.get('offset'))
        if (offset === 4 && !chunkFailed) {
          chunkFailed = true
          return Promise.reject('Network error')
        }
        return Promise.resolve({ upload: makeEhiUpload({ uploaded_bytes: offset + (form.get('chunk') as Blob).size }) })
      }
      return Promise.resolve({ upload: makeEhiUpload({ status: 'queued' }) })
    })
    mockGet.mockRejectedValueOnce('Network error')
    render(<EhiUploadProvider retryDelayMs={0}><Harness /></EhiUploadProvider>)

    act(() => {
      holder.api!.start(EHI_PATIENT_ID, file())
    })

    expect(await screen.findByText('queued 10/10 committed')).toBeInTheDocument()
    expect(chunkOffsets()).toEqual(['0', '4', '4', '8'])
  })

  it('reports a refusal in words and stops', async () => {
    mockPost.mockRejectedValue('patient_archived')
    render(<EhiUploadProvider retryDelayMs={0}><Harness /></EhiUploadProvider>)

    act(() => {
      holder.api!.start(EHI_PATIENT_ID, file())
    })

    expect(await screen.findByText(/failed 0\/10 This patient is archived/)).toBeInTheDocument()
  })

  it('stops offering a cancel once every byte is acknowledged and intake is being queued', async () => {
    let releaseIntake: (() => void) | null = null
    mockPost.mockImplementation((url: string, body: unknown) => {
      if (url === base) return Promise.resolve({ upload: makeEhiUpload({ chunk_size_bytes: 16 }) })
      if (url.endsWith('/chunks')) return Promise.resolve({ upload: makeEhiUpload({ uploaded_bytes: ((body as FormData).get('chunk') as Blob).size }) })
      return new Promise((resolve) => {
        releaseIntake = () => resolve({ upload: makeEhiUpload({ status: 'queued', uploaded_bytes: 10 }) })
      })
    })
    render(<EhiUploadProvider retryDelayMs={0}><Harness /></EhiUploadProvider>)

    let jobId = ''
    act(() => {
      jobId = holder.api!.start(EHI_PATIENT_ID, file())
    })
    await waitFor(() => expect(releaseIntake).not.toBeNull())
    act(() => holder.api!.cancel(jobId))

    // The import cannot be stopped any more, so the job does not claim it was.
    expect(screen.getByText('uploading 10/10 committed')).toBeInTheDocument()
    await act(async () => {
      releaseIntake!()
    })
    expect(screen.getByText('queued 10/10 committed')).toBeInTheDocument()
  })

  it('stops sending when cancelled and never queues intake', async () => {
    let release: (() => void) | null = null
    mockPost.mockImplementation((url: string) => {
      if (url === base) return Promise.resolve({ upload: makeEhiUpload() })
      return new Promise((resolve) => {
        release = () => resolve({ upload: makeEhiUpload({ uploaded_bytes: 4 }) })
      })
    })
    render(<EhiUploadProvider retryDelayMs={0}><Harness /></EhiUploadProvider>)

    let jobId = ''
    act(() => {
      jobId = holder.api!.start(EHI_PATIENT_ID, file())
    })
    await waitFor(() => expect(release).not.toBeNull())
    act(() => holder.api!.cancel(jobId))
    await act(async () => {
      release!()
    })

    expect(screen.getByText(/^cancelled /)).toBeInTheDocument()
    expect(mockPost.mock.calls.some(([url]) => String(url).endsWith('/intake'))).toBe(false)
  })
})
