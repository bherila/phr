import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import { DATA_HUB_CATEGORY_KEYS } from './dataHub'
import DataHubPage from './DataHubPage'

const mockGet = jest.fn()
const mockPost = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
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
        aggregate_delete: { eligible: true, status: 'planned' },
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

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
  mockGet.mockImplementation(async (url: string) => {
    if (url === '/api/phr/data-hub') return inventoryPayload()
    if (url === '/api/phr/patients/42/exports') return { exports: [readyExport] }
    if (url === '/api/phr/patients/42/native-backups') return { backups: [readyBackup] }
    throw new Error(`Unexpected GET ${url}`)
  })
  mockPost.mockImplementation(async (url: string) => {
    if (url === '/api/phr/patients/42/exports') return { export: readyExport }
    if (url === '/api/phr/patients/42/native-backups') return { backup: readyBackup }
    throw new Error(`Unexpected POST ${url}`)
  })
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
    expect(screen.getAllByRole('button', { name: 'Not yet available' })).toHaveLength(2)
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
})
