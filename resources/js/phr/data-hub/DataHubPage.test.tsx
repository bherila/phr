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
        restore: { eligible: true, status: 'available' },
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

const restorePreview = {
  id: 31,
  format: 'phr-native-v1',
  schema_version: 1,
  status: 'preview_ready',
  source_file_size_bytes: 3072,
  uploaded_bytes: 3072,
  chunk_size_bytes: 8388608,
  target: 'new_patient',
  tables: { phr_patients: { create: 1, skip: 0, block: 0 } },
  artifacts: { create: 2, skip: 0, block: 0, bytes: 3072 },
  access_grant_count: 1,
  restore_access_grants: false,
  blockers: [],
  plan_digest: 'c'.repeat(64),
  confirmation_text: 'RESTORE',
  failure_category: null,
  completed_at: null,
  expires_at: '2026-08-23T01:00:00+00:00',
}

const queuedRestorePreview = {
  ...restorePreview,
  schema_version: null,
  status: 'preview_pending',
  target: null,
  tables: {},
  artifacts: { create: 0, skip: 0, block: 0, bytes: 0 },
  access_grant_count: 0,
  blockers: [],
  plan_digest: null,
}

const uploadingRestore = {
  ...queuedRestorePreview,
  status: 'uploading',
  source_file_size_bytes: 23,
  uploaded_bytes: 0,
}

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
  mockDelete.mockReset()
  mockGet.mockImplementation(async (url: string) => {
    if (url === '/api/phr/data-hub') return inventoryPayload()
    if (url === '/api/phr/data-hub/deletions') return { deletions: [] }
    if (url === '/api/phr/data-hub/native-restores') return { restores: [] }
    if (url === '/api/phr/data-hub/native-restores/31') return { restore: restorePreview }
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
    if (url === '/api/phr/data-hub/native-restores/uploads') return { restore: uploadingRestore }
    if (url === '/api/phr/data-hub/native-restores/31/chunks') return { restore: { ...uploadingRestore, uploaded_bytes: 23 } }
    if (url === '/api/phr/data-hub/native-restores/31/preview') return { restore: queuedRestorePreview }
    if (url === '/api/phr/data-hub/native-restores/31/apply') return { restore: { ...restorePreview, status: 'pending_restore' } }
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
    expect(screen.getByRole('heading', { name: 'Dry-run native restore' })).toBeInTheDocument()
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

  it('previews a native archive and requires typed confirmation before applying it', async () => {
    render(<DataHubPage />)
    const file = new File(['synthetic archive bytes'], 'synthetic-native.zip', { type: 'application/zip' })
    fireEvent.change(await screen.findByLabelText('Native backup archive'), { target: { files: [file] } })
    fireEvent.click(screen.getByRole('button', { name: 'Preview restore' }))

    await waitFor(() => {
      expect(mockPost).toHaveBeenCalledWith('/api/phr/data-hub/native-restores/uploads', {
        source_file_size_bytes: file.size,
        restore_access_grants: false,
      })
      expect(mockPost).toHaveBeenCalledWith('/api/phr/data-hub/native-restores/31/chunks', expect.any(FormData))
      expect(mockPost).toHaveBeenCalledWith('/api/phr/data-hub/native-restores/31/preview', {})
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Check restore status' }))
    await waitFor(() => {
      expect(mockGet).toHaveBeenCalledWith('/api/phr/data-hub/native-restores/31')
    })
    expect(await screen.findByText(/Records: 1 create, 0 skip, 0 block/)).toBeInTheDocument()
    const apply = screen.getByRole('button', { name: 'Restore patient data' })
    expect(apply).toBeDisabled()
    fireEvent.change(screen.getByLabelText('Type RESTORE to confirm'), { target: { value: 'RESTORE' } })
    fireEvent.click(apply)

    await waitFor(() => {
      expect(mockPost).toHaveBeenCalledWith('/api/phr/data-hub/native-restores/31/apply', {
        confirmation: 'RESTORE',
        plan_digest: restorePreview.plan_digest,
        restore_access_grants: false,
      })
    })
    expect(await screen.findByRole('button', { name: 'Check restore status' })).toBeInTheDocument()
  })

  it('invalidates a ready restore when the archive or archived-share choice changes', async () => {
    render(<DataHubPage />)
    const archiveInput = await screen.findByLabelText('Native backup archive')
    const first = new File(['synthetic archive one'], 'synthetic-one.zip', { type: 'application/zip' })
    fireEvent.change(archiveInput, { target: { files: [first] } })
    fireEvent.click(screen.getByRole('button', { name: 'Preview restore' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Check restore status' }))
    expect(await screen.findByRole('button', { name: 'Restore patient data' })).toBeInTheDocument()

    const second = new File(['synthetic archive two'], 'synthetic-two.zip', { type: 'application/zip' })
    fireEvent.change(archiveInput, { target: { files: [second] } })
    expect(screen.queryByRole('button', { name: 'Restore patient data' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Preview restore' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Check restore status' }))
    expect(await screen.findByRole('button', { name: 'Restore patient data' })).toBeInTheDocument()
    fireEvent.click(screen.getByLabelText(/Include archived shares/))
    expect(screen.queryByRole('button', { name: 'Restore patient data' })).not.toBeInTheDocument()
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

  it('restores unfinished cleanup controls from the actor deletion list after reload', async () => {
    mockGet.mockImplementation(async (url: string) => {
      if (url === '/api/phr/data-hub') return inventoryPayload()
      if (url === '/api/phr/data-hub/deletions') {
        return { deletions: [{ ...acceptedDeletion, status: 'cleanup_failed', failure_category: 'queue_failure' }] }
      }
      if (url === '/api/phr/data-hub/native-restores') return { restores: [] }
      throw new Error(`Unexpected GET ${url}`)
    })

    render(<DataHubPage />)

    expect(await screen.findByText(/Storage cleanup: failed/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Retry storage cleanup' })).toBeInTheDocument()
  })

  it('discards a stale preview when the commit reports preview_changed', async () => {
    mockDelete.mockRejectedValueOnce('preview_changed')
    render(<DataHubPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Preview deletion' }))
    await screen.findByText(/3 database rows/)
    fireEvent.change(screen.getByLabelText('Type DELETE to delete Synthetic Record Owner'), { target: { value: 'DELETE' } })
    fireEvent.click(screen.getByLabelText('I understand active shares will be revoked.'))
    fireEvent.click(screen.getByRole('button', { name: 'Permanently delete patient data' }))

    const previewAgain = await screen.findByRole('button', { name: 'Preview deletion' })
    expect(screen.getByText(/patient data changed/i)).toBeInTheDocument()
    fireEvent.click(previewAgain)
    expect(await screen.findByLabelText('Type DELETE to delete Synthetic Record Owner')).toHaveValue('')
    expect(screen.getByLabelText('I understand active shares will be revoked.')).not.toBeChecked()
  })
})
