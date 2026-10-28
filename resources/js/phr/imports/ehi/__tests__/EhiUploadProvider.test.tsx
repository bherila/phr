import '@testing-library/jest-dom'

import { act, render, screen, waitFor } from '@testing-library/react'
import type { ReactElement } from 'react'
import { useEffect } from 'react'

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
      {uploads.jobs.map((job) => <li key={job.id}>{`${job.phase} ${job.bytesSent}/${job.fileSizeBytes}${job.error ? ` ${job.error}` : ''}`}</li>)}
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
    .map(([, body]) => String((body as FormData).get('offset')))
}

beforeEach(() => {
  holder.api = null
  mockGet.mockReset()
  mockPost.mockReset()
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

    expect(await screen.findByText('queued 10/10')).toBeInTheDocument()
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

    expect(await screen.findByText('queued 10/10')).toBeInTheDocument()
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

    expect(await screen.findByText('queued 10/10')).toBeInTheDocument()
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
