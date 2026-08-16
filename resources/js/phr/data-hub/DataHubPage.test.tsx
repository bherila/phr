import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import { DATA_HUB_CATEGORY_KEYS } from './dataHub'
import DataHubPage from './DataHubPage'

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
}))

function counts(overrides: Partial<Record<typeof DATA_HUB_CATEGORY_KEYS[number], number>> = {}) {
  return Object.fromEntries(DATA_HUB_CATEGORY_KEYS.map((key) => [key, overrides[key] ?? 0]))
}

function inventoryPayload() {
  return {
    owned_patients: [{
      id: 42,
      display_name: 'Synthetic Record Owner',
      relationship: 'self',
      record_counts: counts({ lab_results: 3, documents: 2, original_dicom_files: 4 }),
      storage_bytes: { documents: 1024, original_dicom: 2048, total: 3072 },
      last_updated_at: '2026-08-02T01:00:00+00:00',
      active_share_count: 1,
      operations: {
        clinical_export: { eligible: true, status: 'available', format: 'ccda' },
        native_backup: { eligible: true, status: 'available' },
        restore: { eligible: true, status: 'planned' },
        aggregate_delete: { eligible: true, status: 'available' },
      },
    }],
    shared_patients: [{
      id: 77,
      display_name: 'Synthetic Shared Profile',
      relationship: 'family',
      access_level: 'viewer',
      operations: {
        clinical_export: { eligible: false, status: 'owner_only' },
        native_backup: { eligible: false, status: 'owner_only' },
        restore: { eligible: false, status: 'owner_only' },
        aggregate_delete: { eligible: false, status: 'owner_only' },
      },
    }],
  }
}

const readyExport = {
  id: 9,
  patient_id: 42,
  formats: ['ccda'],
  format: 'ccda',
  status: 'ready',
  filename: 'patient-42-ccda.xml',
  file_size_bytes: 100,
  error_message: null,
  generated_at: '2026-08-02 01:01:00',
  expires_at: '2026-09-01 01:01:00',
  created_at: '2026-08-02 01:00:00',
  download_url: '/phr/exports/9/download?signature=synthetic',
}

const readyBackup = {
  id: 12,
  patient_id: 42,
  format: 'phr-native-v1',
  schema_version: 1,
  status: 'ready',
  file_size_bytes: 300,
  archive_sha256: 'a'.repeat(64),
  counts: { phr_patients: 1 },
  failure_category: null,
  generated_at: '2026-08-03T01:01:00+00:00',
  expires_at: '2026-08-10T01:01:00+00:00',
  created_at: '2026-08-03T01:00:00+00:00',
  download_url: '/phr/native-backups/12/download?signature=synthetic',
}

const deletionPreview = {
  patient_id: 42,
  record_counts: { phr_patients: 1, phr_documents: 2 },
  database_row_count: 3,
  active_share_count: 1,
  artifact_count: 2,
  artifact_bytes: 3072,
  blockers: [],
  preview_digest: 'b'.repeat(64),
  confirmation_text: 'DELETE',
}

const acceptedDeletion = {
  id: 18,
  patient_root_id: 42,
  status: 'pending_cleanup',
  record_counts: deletionPreview.record_counts,
  active_share_count: 1,
  artifact_count: 2,
  artifact_bytes: 3072,
  failure_category: null,
  deleted_at: '2026-08-16T01:00:00+00:00',
  completed_at: null,
}

const completedDeletion = {
  ...acceptedDeletion,
  status: 'completed',
  completed_at: '2026-08-16T01:01:00+00:00',
}

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
  mockDelete.mockReset()
  mockGet.mockImplementation(async (url: string) => {
    if (url === '/api/phr/data-hub') return inventoryPayload()
    if (url === '/api/phr/patients/42/exports') return { exports: [readyExport] }
    if (url === '/api/phr/patients/42/native-backups') return { backups: [readyBackup] }
    if (url === '/api/phr/data-hub/patients/42/deletion-preview') return { deletion_preview: deletionPreview }
    if (url === '/api/phr/data-hub/deletions/18') return { deletion: completedDeletion }
    throw new Error(`Unexpected GET ${url}`)
  })
  mockPost.mockImplementation(async (url: string) => {
    if (url === '/api/phr/patients/42/exports') return { export: readyExport }
    if (url === '/api/phr/patients/42/native-backups') return { backup: readyBackup }
    if (url === '/api/phr/data-hub/deletions/18/retry') return { deletion: acceptedDeletion }
    throw new Error(`Unexpected POST ${url}`)
  })
  mockDelete.mockResolvedValue({ deletion: acceptedDeletion })
})

describe('DataHubPage', () => {
  it('shows owned inventory and keeps shared patient inventory private', async () => {
    render(<DataHubPage />)

    const ownerHeading = await screen.findByRole('heading', { name: 'Synthetic Record Owner' })
    expect(ownerHeading).toBeInTheDocument()
    expect(ownerHeading.parentElement).toHaveTextContent('9 authoritative records')
    expect(screen.getAllByText('3.00 KB')).toHaveLength(2)
    expect(screen.getByText('Synthetic Shared Profile')).toBeInTheDocument()
    expect(screen.getByText(/viewer access · owner operations unavailable/i)).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'Not yet available' })).toHaveLength(1)
    expect(screen.queryByText('Undisclosed health value')).not.toBeInTheDocument()
  })

  it('generates a patient-specific C-CDA XML export and exposes its signed download', async () => {
    render(<DataHubPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Generate C-CDA XML' }))

    await waitFor(() => {
      expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/42/exports', { formats: ['ccda'] })
    })
    expect(await screen.findByRole('link', { name: 'Download XML' }))
      .toHaveAttribute('href', '/phr/exports/9/download?signature=synthetic')
    expect(screen.getByText('Latest C-CDA export: ready')).toBeInTheDocument()
  })

  it('refreshes per-patient export status without requesting another patient', async () => {
    render(<DataHubPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Check status' }))

    await waitFor(() => {
      expect(mockGet).toHaveBeenCalledWith('/api/phr/patients/42/exports')
    })
    expect(await screen.findByRole('link', { name: 'Download XML' })).toBeInTheDocument()
  })

  it('creates and refreshes a native backup only for the selected patient', async () => {
    render(<DataHubPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Generate native backup' }))

    await waitFor(() => {
      expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/42/native-backups', {})
    })
    expect(await screen.findByRole('link', { name: 'Download backup' }))
      .toHaveAttribute('href', '/phr/native-backups/12/download?signature=synthetic')

    fireEvent.click(screen.getByRole('button', { name: 'Check backup status' }))
    await waitFor(() => {
      expect(mockGet).toHaveBeenCalledWith('/api/phr/patients/42/native-backups')
    })
  })

  it('renders native backup failures in the native backup panel', async () => {
    mockPost.mockRejectedValueOnce(new Error('Synthetic backup failure'))
    render(<DataHubPage />)

    fireEvent.click(await screen.findByRole('button', { name: 'Generate native backup' }))

    const alert = await screen.findByRole('alert')
    const backupPanel = screen.getByRole('heading', { name: 'Lossless native backup' }).closest('.rounded-md')
    const exportPanel = screen.getByRole('heading', { name: 'Clinical interoperability export' }).closest('.rounded-md')
    expect(backupPanel).toContainElement(alert)
    expect(exportPanel).not.toContainElement(alert)
  })

  it('previews and confirms patient deletion with a separate share acknowledgement', async () => {
    render(<DataHubPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Preview deletion' }))

    await waitFor(() => {
      expect(mockGet).toHaveBeenCalledWith('/api/phr/data-hub/patients/42/deletion-preview')
    })
    expect(await screen.findByText(/3 database rows · 2 files · 3.00 KB · 1 active share/)).toBeInTheDocument()
    const commit = screen.getByRole('button', { name: 'Permanently delete patient data' })
    expect(commit).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Type DELETE to delete Synthetic Record Owner'), { target: { value: 'DELETE' } })
    fireEvent.click(screen.getByLabelText('I understand active shares will be revoked.'))
    expect(commit).toBeEnabled()
    fireEvent.click(commit)

    await waitFor(() => {
      expect(mockDelete).toHaveBeenCalledWith('/api/phr/patients/42', {
        confirmation: 'DELETE',
        preview_digest: deletionPreview.preview_digest,
        acknowledge_active_shares: true,
      })
    })
    expect(await screen.findByText(/Storage cleanup: queued/)).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Synthetic Record Owner' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Check cleanup status' }))
    await waitFor(() => {
      expect(mockGet).toHaveBeenCalledWith('/api/phr/data-hub/deletions/18')
    })
    expect(await screen.findByText(/Storage cleanup: complete/)).toBeInTheDocument()
  })

  it('reports failed storage cleanup and exposes a retry without restoring the patient card', async () => {
    mockDelete.mockResolvedValueOnce({
      deletion: { ...acceptedDeletion, status: 'cleanup_failed', failure_category: 'storage_cleanup_failed' },
    })
    render(<DataHubPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Preview deletion' }))
    await screen.findByText(/3 database rows/)
    fireEvent.change(screen.getByLabelText('Type DELETE to delete Synthetic Record Owner'), { target: { value: 'DELETE' } })
    fireEvent.click(screen.getByLabelText('I understand active shares will be revoked.'))
    fireEvent.click(screen.getByRole('button', { name: 'Permanently delete patient data' }))

    expect(await screen.findByText(/Storage cleanup: failed/)).toBeInTheDocument()
    expect(screen.getByText(/One or more stored files could not be removed/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Retry storage cleanup' }))
    await waitFor(() => {
      expect(mockPost).toHaveBeenCalledWith('/api/phr/data-hub/deletions/18/retry', {})
    })
  })
})
