import { Archive, Database, Download, HardDrive, RefreshCw, RotateCcw, ShieldCheck, Trash2, Upload, Users } from 'lucide-react'
import type { ReactElement } from 'react'
import { useCallback, useEffect, useMemo, useState } from 'react'

import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { errorMessage } from '@/phr/shared'
import { type PhrExport, PhrExportResponseSchema, PhrExportsResponseSchema } from '@/phr/types'

import {
  DATA_HUB_CATEGORY_KEYS,
  DATA_HUB_CATEGORY_LABELS,
  type DataHubCategoryKey,
  DataHubResponseSchema,
  type NativeBackup,
  NativeBackupResponseSchema,
  NativeBackupsResponseSchema,
  type NativeRestore,
  NativeRestoreResponseSchema,
  NativeRestoresResponseSchema,
  type OwnedPatientInventory,
  type PatientDeletion,
  type PatientDeletionPreview,
  PatientDeletionPreviewResponseSchema,
  PatientDeletionResponseSchema,
  PatientDeletionsResponseSchema,
} from './dataHub'

const CLINICAL_CATEGORIES: DataHubCategoryKey[] = [
  'lab_results',
  'vitals',
  'office_visits',
  'medications',
  'conditions',
  'procedures',
  'immunizations',
  'allergies',
  'portal_messages',
  'negative_assertions',
  'health_logs',
  'health_log_entries',
  'respiratory_events',
  'sinus_settings',
  'sinus_enrollments',
]

const FILE_CATEGORIES: DataHubCategoryKey[] = [
  'documents',
  'dicom_studies',
  'dicom_series',
  'dicom_instances',
  'original_dicom_files',
]

const DELETION_MESSAGES: Record<string, string> = {
  active_shares_unacknowledged: 'Confirm that active shares will be revoked before deleting.',
  clinical_export_in_progress: 'A clinical export is still being generated. Wait for it to finish, then preview again.',
  dispatch_failed: 'Storage cleanup could not be queued. Retry the cleanup.',
  dicom_upload_in_progress: 'A DICOM upload is still in progress. Finish or cancel it, then preview again.',
  invalid_storage_reference: 'A stored-file reference needs administrator attention before deletion.',
  native_backup_in_progress: 'A native backup is still being generated. Wait for it to finish, then preview again.',
  preview_changed: 'The patient data changed after this preview. Generate a new deletion preview.',
  queue_failure: 'Storage cleanup stopped before it completed. Retry the cleanup.',
  shared_storage_reference: 'A stored file is referenced by another patient and cannot be deleted safely.',
  storage_cleanup_failed: 'One or more stored files could not be removed. Retry the cleanup.',
}

const DELETION_STATUS_LABELS: Record<PatientDeletion['status'], string> = {
  pending_cleanup: 'queued',
  cleanup_processing: 'in progress',
  cleanup_failed: 'failed',
  completed: 'complete',
}

function deletionMessage(value: unknown): string {
  const message = errorMessage(value)
  return DELETION_MESSAGES[message] ?? message
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let value = bytes / 1024
  let unit = units[0]!
  for (let index = 1; value >= 1024 && index < units.length; index += 1) {
    value /= 1024
    unit = units[index]!
  }
  return `${value.toFixed(value >= 10 ? 1 : 2)} ${unit}`
}

function formatDate(value: string | null): string {
  if (!value) return 'No recorded changes'
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

function countRows(patient: OwnedPatientInventory, keys: DataHubCategoryKey[]): ReactElement {
  return (
    <dl className="grid grid-cols-2 gap-x-5 gap-y-2 text-sm sm:grid-cols-3">
      {keys.map((key) => (
        <div key={key} className="flex min-w-0 justify-between gap-2 border-b border-border/50 pb-1">
          <dt className="truncate text-muted-foreground">{DATA_HUB_CATEGORY_LABELS[key]}</dt>
          <dd className="font-medium tabular-nums text-foreground">{patient.record_counts[key]}</dd>
        </div>
      ))}
    </dl>
  )
}

interface PatientCardProps {
  patient: OwnedPatientInventory
  currentExport?: PhrExport
  currentBackup?: NativeBackup
  busy: boolean
  exportError?: string
  backupError?: string
  deletionPreview?: PatientDeletionPreview
  deletionError?: string
  onGenerate: (patientId: number) => Promise<void>
  onRefresh: (patientId: number) => Promise<void>
  onGenerateBackup: (patientId: number) => Promise<void>
  onRefreshBackup: (patientId: number) => Promise<void>
  onPreviewDeletion: (patientId: number) => Promise<void>
  onDelete: (patientId: number, preview: PatientDeletionPreview, acknowledgeShares: boolean) => Promise<void>
}

function PatientCard({ patient, currentExport, currentBackup, busy, exportError, backupError, deletionPreview, deletionError, onGenerate, onRefresh, onGenerateBackup, onRefreshBackup, onPreviewDeletion, onDelete }: PatientCardProps): ReactElement {
  const [confirmation, setConfirmation] = useState('')
  const [acknowledgeShares, setAcknowledgeShares] = useState(false)
  const totalRecords = useMemo(
    () => DATA_HUB_CATEGORY_KEYS.reduce((sum, key) => sum + patient.record_counts[key], 0),
    [patient.record_counts],
  )
  const exportInProgress = currentExport?.status === 'pending' || currentExport?.status === 'processing'
  const backupInProgress = currentBackup?.status === 'pending' || currentBackup?.status === 'processing'

  return (
    <article className="rounded-lg border border-border bg-card p-5 text-card-foreground shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="text-lg font-semibold">{patient.display_name ?? `Patient ${patient.id}`}</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            {patient.relationship ?? 'Patient profile'} · {totalRecords.toLocaleString()} authoritative records
          </p>
        </div>
        <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
          <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1">
            <Users className="size-3.5" /> {patient.active_share_count} active share{patient.active_share_count === 1 ? '' : 's'}
          </span>
          <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1">
            <HardDrive className="size-3.5" /> {formatBytes(patient.storage_bytes.total)}
          </span>
        </div>
      </div>

      <p className="mt-3 text-xs text-muted-foreground">Last changed {formatDate(patient.last_updated_at)}</p>

      <details className="mt-4 rounded-md border border-border p-3" open>
        <summary className="cursor-pointer text-sm font-medium">Clinical inventory</summary>
        <div className="mt-3">{countRows(patient, CLINICAL_CATEGORIES)}</div>
      </details>
      <details className="mt-3 rounded-md border border-border p-3">
        <summary className="cursor-pointer text-sm font-medium">Documents and imaging inventory</summary>
        <div className="mt-3">{countRows(patient, FILE_CATEGORIES)}</div>
        <dl className="mt-4 grid gap-2 rounded-md bg-muted/50 p-3 text-sm sm:grid-cols-3">
          <div><dt className="text-muted-foreground">Documents</dt><dd className="font-medium">{formatBytes(patient.storage_bytes.documents)}</dd></div>
          <div><dt className="text-muted-foreground">Original DICOM</dt><dd className="font-medium">{formatBytes(patient.storage_bytes.original_dicom)}</dd></div>
          <div><dt className="text-muted-foreground">Total source storage</dt><dd className="font-medium">{formatBytes(patient.storage_bytes.total)}</dd></div>
        </dl>
      </details>

      <section aria-label={`Data actions for ${patient.display_name ?? `patient ${patient.id}`}`} className="mt-5 grid gap-3 lg:grid-cols-2">
        <div className="rounded-md border border-emerald-500/30 bg-emerald-500/5 p-3">
          <div className="flex items-start gap-2">
            <Download className="mt-0.5 size-4 text-emerald-700 dark:text-emerald-300" />
            <div>
              <h3 className="text-sm font-semibold">Clinical interoperability export</h3>
              <p className="mt-1 text-xs text-muted-foreground">
                Generate this patient’s C-CDA XML clinical summary. This remains separate from the lossless native backup.
              </p>
            </div>
          </div>
          <div className="mt-3 flex flex-wrap items-center gap-2">
            <Button type="button" size="sm" disabled={busy || exportInProgress} onClick={() => void onGenerate(patient.id)}>
              <Download className="size-4" /> {busy ? 'Working…' : 'Generate C-CDA XML'}
            </Button>
            <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => void onRefresh(patient.id)}>
              <RefreshCw className={`size-4 ${busy ? 'animate-spin' : ''}`} /> Check status
            </Button>
            {currentExport?.download_url ? (
              <a className="text-sm font-medium text-primary underline underline-offset-4" href={currentExport.download_url}>Download XML</a>
            ) : null}
          </div>
          {currentExport ? (
            <p role="status" className="mt-2 text-xs text-muted-foreground">Latest C-CDA export: {currentExport.status}</p>
          ) : null}
          {exportError ? <p role="alert" className="mt-2 text-xs text-destructive">{exportError}</p> : null}
        </div>

        <div className="rounded-md border border-sky-500/30 bg-sky-500/5 p-3">
          <div className="flex items-start gap-2">
            <Archive className="mt-0.5 size-4 text-sky-700 dark:text-sky-300" />
            <div>
              <h3 className="text-sm font-semibold">Lossless native backup</h3>
              <p className="mt-1 text-xs text-muted-foreground">
                Create a private, per-patient phr-native-v1 archive with original documents, DICOM, and PHR-specific records. It expires after seven days.
              </p>
            </div>
          </div>
          <div className="mt-3 flex flex-wrap items-center gap-2">
            <Button type="button" size="sm" disabled={busy || backupInProgress} onClick={() => void onGenerateBackup(patient.id)}>
              <Archive className="size-4" /> {busy ? 'Working…' : 'Generate native backup'}
            </Button>
            <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => void onRefreshBackup(patient.id)}>
              <RefreshCw className={`size-4 ${busy ? 'animate-spin' : ''}`} /> Check backup status
            </Button>
            {currentBackup?.download_url ? (
              <a className="text-sm font-medium text-primary underline underline-offset-4" href={currentBackup.download_url}>Download backup</a>
            ) : null}
          </div>
          {currentBackup ? (
            <p role="status" className="mt-2 text-xs text-muted-foreground">Latest native backup: {currentBackup.status}</p>
          ) : null}
          {backupError ? <p role="alert" className="mt-2 text-xs text-destructive">{backupError}</p> : null}
        </div>

        <div className="rounded-md border border-destructive/30 bg-destructive/5 p-3 lg:col-span-2">
            <div className="flex items-center gap-2 text-sm font-medium"><Trash2 className="size-4" />Safe aggregate deletion</div>
            <p className="mt-1 text-xs text-muted-foreground">Preview database rows, active shares, and exclusively owned files before committing.</p>
            {!deletionPreview ? (
              <Button type="button" size="sm" variant="outline" className="mt-3" disabled={busy} onClick={() => void onPreviewDeletion(patient.id)}>
                Preview deletion
              </Button>
            ) : (
              <div className="mt-3 space-y-3 text-xs">
                <p>
                  {deletionPreview.database_row_count.toLocaleString()} database rows · {deletionPreview.artifact_count.toLocaleString()} files · {formatBytes(deletionPreview.artifact_bytes)} · {deletionPreview.active_share_count} active share{deletionPreview.active_share_count === 1 ? '' : 's'}
                </p>
                {deletionPreview.blockers.length > 0 ? (
                  <p role="alert" className="text-destructive">Deletion is blocked: {deletionPreview.blockers.map(deletionMessage).join(' ')}</p>
                ) : null}
                <label className="block font-medium">
                  Type DELETE to confirm
                  <input
                    aria-label={`Type DELETE to delete ${patient.display_name ?? `patient ${patient.id}`}`}
                    className="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    value={confirmation}
                    onChange={(event) => setConfirmation(event.target.value)}
                  />
                </label>
                {deletionPreview.active_share_count > 0 ? (
                  <label className="flex items-start gap-2">
                    <input type="checkbox" checked={acknowledgeShares} onChange={(event) => setAcknowledgeShares(event.target.checked)} />
                    I understand active shares will be revoked.
                  </label>
                ) : null}
                <Button
                  type="button"
                  size="sm"
                  variant="destructive"
                  disabled={busy || confirmation !== 'DELETE' || deletionPreview.blockers.length > 0 || (deletionPreview.active_share_count > 0 && !acknowledgeShares)}
                  onClick={() => void onDelete(patient.id, deletionPreview, acknowledgeShares)}
                >
                  Permanently delete patient data
                </Button>
              </div>
            )}
            {deletionError ? <p role="alert" className="mt-2 text-xs text-destructive">{deletionError}</p> : null}
        </div>
      </section>
    </article>
  )
}

const RESTORE_MESSAGES: Record<string, string> = {
  actor_mapping_missing: 'A required user reference cannot be mapped safely. Restore the archive with the original account mappings available.',
  ambiguous_patient_identity: 'The patient identity is ambiguous and needs administrator attention.',
  archive_changed: 'The uploaded archive changed after preview. Upload it again.',
  archive_hash_mismatch: 'The archive failed integrity verification.',
  artifact_conflict: 'A stored file with this identity differs from the archive.',
  artifact_write_failed: 'A source file could not be written. No patient records were committed.',
  current_identity_missing: 'Current data is missing required stable identity metadata.',
  invalid_archive: 'This file is not a valid phr-native-v1 archive.',
  invalid_upload: 'The selected archive could not be uploaded.',
  invalid_upload_chunk: 'An archive chunk exceeded the configured upload limit.',
  patient_not_owned: 'This archive maps to a patient profile owned by another account.',
  preview_changed: 'Current data changed after the dry run. Upload the archive to preview again.',
  preview_expired: 'This restore preview expired. Upload the archive again.',
  preview_queue_failed: 'Archive validation could not be queued. Upload the archive again.',
  record_conflict: 'A current record with the same stable identity has different content.',
  relationship_missing: 'The archive contains a relationship that cannot be resolved.',
  restore_blocked: 'Resolve every blocker before restoring.',
  restore_busy: 'Another restore of this archive is in progress. Try again shortly.',
  size_limit: 'The archive exceeds the configured restore size limit.',
  source_storage_failed: 'The archive upload could not be stored. Try again.',
  unsupported_schema: 'This archive schema version is not supported.',
  upload_incomplete: 'The archive upload is incomplete.',
  upload_state_invalid: 'The archive upload sequence changed. Start the upload again.',
}

function restoreMessage(value: unknown): string {
  const message = errorMessage(value)
  return RESTORE_MESSAGES[message] ?? message
}

function RestorePanel({ onCompleted }: { onCompleted: () => Promise<void> }): ReactElement {
  const [archive, setArchive] = useState<File | null>(null)
  const [restoreShares, setRestoreShares] = useState(false)
  const [confirmation, setConfirmation] = useState('')
  const [restore, setRestore] = useState<NativeRestore | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    void fetchWrapper.get('/api/phr/data-hub/native-restores')
      .then((raw: unknown) => {
        const latest = NativeRestoresResponseSchema.parse(raw).restores[0]
        if (latest) setRestore(latest)
      })
      .catch((caught: unknown) => setError(restoreMessage(caught)))
  }, [])

  async function preview(): Promise<void> {
    if (!archive) return
    setBusy(true)
    setError(null)
    try {
      const startedRaw: unknown = await fetchWrapper.post('/api/phr/data-hub/native-restores/uploads', {
        source_file_size_bytes: archive.size,
        restore_access_grants: restoreShares,
      })
      let current = NativeRestoreResponseSchema.parse(startedRaw).restore
      setRestore(current)
      for (let offset = 0; offset < archive.size; offset += current.chunk_size_bytes) {
        const form = new FormData()
        form.append('offset', String(offset))
        form.append('chunk', archive.slice(offset, Math.min(offset + current.chunk_size_bytes, archive.size)), 'chunk.bin')
        const chunkRaw: unknown = await fetchWrapper.post(`/api/phr/data-hub/native-restores/${current.id}/chunks`, form)
        current = NativeRestoreResponseSchema.parse(chunkRaw).restore
        setRestore(current)
      }
      const queuedRaw: unknown = await fetchWrapper.post(`/api/phr/data-hub/native-restores/${current.id}/preview`, {})
      setRestore(NativeRestoreResponseSchema.parse(queuedRaw).restore)
      setConfirmation('')
    } catch (caught) {
      setError(restoreMessage(caught))
    } finally {
      setBusy(false)
    }
  }

  async function applyRestore(): Promise<void> {
    if (!restore || !restore.plan_digest) return
    setBusy(true)
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.post(`/api/phr/data-hub/native-restores/${restore.id}/apply`, {
        confirmation: 'RESTORE',
        plan_digest: restore.plan_digest,
        restore_access_grants: restore.restore_access_grants,
      })
      setRestore(NativeRestoreResponseSchema.parse(raw).restore)
    } catch (caught) {
      setError(restoreMessage(caught))
    } finally {
      setBusy(false)
    }
  }

  async function refresh(): Promise<void> {
    if (!restore) return
    setBusy(true)
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.get(`/api/phr/data-hub/native-restores/${restore.id}`)
      const next = NativeRestoreResponseSchema.parse(raw).restore
      setRestore(next)
      if (next.status === 'completed') await onCompleted()
    } catch (caught) {
      setError(restoreMessage(caught))
    } finally {
      setBusy(false)
    }
  }

  const totals = restore ? Object.values(restore.tables).reduce(
    (sum, counts) => ({ create: sum.create + counts.create, skip: sum.skip + counts.skip, block: sum.block + counts.block }),
    { create: 0, skip: 0, block: 0 },
  ) : null
  const ready = restore?.status === 'preview_ready'

  return (
    <section className="mt-6 rounded-lg border border-violet-500/30 bg-violet-500/5 p-5" aria-labelledby="native-restore-heading">
      <div className="flex items-start gap-3">
        <RotateCcw className="mt-0.5 size-5 text-violet-700 dark:text-violet-300" />
        <div>
          <h2 id="native-restore-heading" className="font-semibold">Dry-run native restore</h2>
          <p className="mt-1 text-sm text-muted-foreground">Upload one phr-native-v1 patient archive. The dry run creates no patient data and blocks every non-identical conflict.</p>
        </div>
      </div>
      <div className="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
        <label className="text-sm font-medium">
          Native backup archive
          <input type="file" accept=".zip,application/zip" className="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm" onChange={(event) => setArchive(event.target.files?.[0] ?? null)} />
        </label>
        <Button type="button" disabled={busy || archive === null} onClick={() => void preview()}><Upload className="size-4" />{busy ? 'Checking…' : 'Preview restore'}</Button>
      </div>
      <label className="mt-3 flex items-start gap-2 text-sm">
        <input type="checkbox" checked={restoreShares} onChange={(event) => setRestoreShares(event.target.checked)} />
        Include archived shares when every opaque user identity can be mapped. Owner access is always restored.
      </label>

      {restore && totals ? (
        <div className="mt-4 rounded-md border border-border bg-background/70 p-3 text-sm">
          <p role="status">Status: <span className="font-medium">{restore.status.replaceAll('_', ' ')}</span>{restore.target ? ` · target: ${restore.target.replaceAll('_', ' ')}` : ''}</p>
          {restore.status === 'uploading' ? <p className="mt-1 text-muted-foreground">Uploaded {formatBytes(restore.uploaded_bytes)} of {formatBytes(restore.source_file_size_bytes)}.</p> : null}
          <p className="mt-1 text-muted-foreground">Records: {totals.create} create, {totals.skip} skip, {totals.block} block. Files: {restore.artifacts.create} create, {restore.artifacts.skip} skip, {restore.artifacts.block} block ({formatBytes(restore.artifacts.bytes)}).</p>
          <p className="mt-1 text-muted-foreground">Archived non-owner shares: {restore.access_grant_count}; {restore.restore_access_grants ? 'explicitly included' : 'not selected'}.</p>
          {restore.blockers.length > 0 ? <ul role="alert" className="mt-2 list-disc pl-5 text-destructive">{restore.blockers.map((blocker) => <li key={blocker}>{restoreMessage(blocker)}</li>)}</ul> : null}
          {restore.failure_category ? <p role="alert" className="mt-2 text-destructive">{restoreMessage(restore.failure_category)}</p> : null}
          {ready ? (
            <div className="mt-3 flex flex-wrap items-end gap-2">
              <label className="text-sm font-medium">Type RESTORE to confirm<input className="mt-1 block rounded-md border border-input bg-background px-3 py-2" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} /></label>
              <Button type="button" disabled={busy || confirmation !== 'RESTORE' || restore.blockers.length > 0} onClick={() => void applyRestore()}>Restore patient data</Button>
            </div>
          ) : null}
          {['preview_pending', 'preview_processing', 'pending_restore', 'restore_processing'].includes(restore.status) ? <Button type="button" size="sm" variant="outline" className="mt-3" disabled={busy} onClick={() => void refresh()}><RefreshCw className={`size-4 ${busy ? 'animate-spin' : ''}`} />Check restore status</Button> : null}
        </div>
      ) : null}
      {error ? <p role="alert" className="mt-3 text-sm text-destructive">{error}</p> : null}
    </section>
  )
}

export default function DataHubPage(): ReactElement {
  const [inventory, setInventory] = useState<ReturnType<typeof DataHubResponseSchema.parse> | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [exports, setExports] = useState<Record<number, PhrExport>>({})
  const [backups, setBackups] = useState<Record<number, NativeBackup>>({})
  const [busyPatientId, setBusyPatientId] = useState<number | null>(null)
  const [exportErrors, setExportErrors] = useState<Record<number, string>>({})
  const [backupErrors, setBackupErrors] = useState<Record<number, string>>({})
  const [deletionPreviews, setDeletionPreviews] = useState<Record<number, PatientDeletionPreview>>({})
  const [deletionErrors, setDeletionErrors] = useState<Record<number, string>>({})
  const [deletionResults, setDeletionResults] = useState<PatientDeletion[]>([])
  const [deletionStatusErrors, setDeletionStatusErrors] = useState<Record<number, string>>({})

  const loadInventory = useCallback(async (): Promise<void> => {
    setLoading(true)
    setLoadError(null)
    try {
      const [rawInventory, rawDeletions]: [unknown, unknown] = await Promise.all([
        fetchWrapper.get('/api/phr/data-hub'),
        fetchWrapper.get('/api/phr/data-hub/deletions'),
      ])
      setInventory(DataHubResponseSchema.parse(rawInventory))
      setDeletionResults(PatientDeletionsResponseSchema.parse(rawDeletions).deletions)
    } catch (caught) {
      setLoadError(errorMessage(caught))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void loadInventory()
  }, [loadInventory])

  function setExportError(patientId: number, message?: string): void {
    setExportErrors((current) => {
      const next = { ...current }
      if (message) next[patientId] = message
      else delete next[patientId]
      return next
    })
  }

  function setBackupError(patientId: number, message?: string): void {
    setBackupErrors((current) => {
      const next = { ...current }
      if (message) next[patientId] = message
      else delete next[patientId]
      return next
    })
  }

  function setDeletionError(patientId: number, message?: string): void {
    setDeletionErrors((current) => {
      const next = { ...current }
      if (message) next[patientId] = message
      else delete next[patientId]
      return next
    })
  }

  function upsertDeletion(deletion: PatientDeletion): void {
    setDeletionResults((current) => [deletion, ...current.filter((item) => item.id !== deletion.id)])
  }

  function setDeletionStatusError(deletionId: number, message?: string): void {
    setDeletionStatusErrors((current) => {
      const next = { ...current }
      if (message) next[deletionId] = message
      else delete next[deletionId]
      return next
    })
  }

  async function generateExport(patientId: number): Promise<void> {
    setBusyPatientId(patientId)
    setExportError(patientId)
    try {
      const raw: unknown = await fetchWrapper.post(`/api/phr/patients/${patientId}/exports`, { formats: ['ccda'] })
      const created = PhrExportResponseSchema.parse(raw).export
      setExports((current) => ({ ...current, [patientId]: created }))
    } catch (caught) {
      setExportError(patientId, errorMessage(caught))
    } finally {
      setBusyPatientId(null)
    }
  }

  async function refreshExport(patientId: number): Promise<void> {
    setBusyPatientId(patientId)
    setExportError(patientId)
    try {
      const raw: unknown = await fetchWrapper.get(`/api/phr/patients/${patientId}/exports`)
      const latest = PhrExportsResponseSchema.parse(raw).exports.find((item) => item.formats.includes('ccda'))
      if (!latest) {
        setExportError(patientId, 'No C-CDA export has been generated for this patient yet.')
      } else {
        setExports((current) => ({ ...current, [patientId]: latest }))
      }
    } catch (caught) {
      setExportError(patientId, errorMessage(caught))
    } finally {
      setBusyPatientId(null)
    }
  }

  async function generateBackup(patientId: number): Promise<void> {
    setBusyPatientId(patientId)
    setBackupError(patientId)
    try {
      const raw: unknown = await fetchWrapper.post(`/api/phr/patients/${patientId}/native-backups`, {})
      const created = NativeBackupResponseSchema.parse(raw).backup
      setBackups((current) => ({ ...current, [patientId]: created }))
    } catch (caught) {
      setBackupError(patientId, errorMessage(caught))
    } finally {
      setBusyPatientId(null)
    }
  }

  async function refreshBackup(patientId: number): Promise<void> {
    setBusyPatientId(patientId)
    setBackupError(patientId)
    try {
      const raw: unknown = await fetchWrapper.get(`/api/phr/patients/${patientId}/native-backups`)
      const latest = NativeBackupsResponseSchema.parse(raw).backups[0]
      if (!latest) {
        setBackupError(patientId, 'No native backup has been generated for this patient yet.')
      } else {
        setBackups((current) => ({ ...current, [patientId]: latest }))
      }
    } catch (caught) {
      setBackupError(patientId, errorMessage(caught))
    } finally {
      setBusyPatientId(null)
    }
  }

  async function previewDeletion(patientId: number): Promise<void> {
    setBusyPatientId(patientId)
    setDeletionError(patientId)
    try {
      const raw: unknown = await fetchWrapper.get(`/api/phr/data-hub/patients/${patientId}/deletion-preview`)
      const preview = PatientDeletionPreviewResponseSchema.parse(raw).deletion_preview
      setDeletionPreviews((current) => ({ ...current, [patientId]: preview }))
    } catch (caught) {
      setDeletionError(patientId, deletionMessage(caught))
    } finally {
      setBusyPatientId(null)
    }
  }

  async function deletePatient(patientId: number, preview: PatientDeletionPreview, acknowledgeShares: boolean): Promise<void> {
    setBusyPatientId(patientId)
    setDeletionError(patientId)
    try {
      const raw: unknown = await fetchWrapper.delete(`/api/phr/patients/${patientId}`, {
        confirmation: 'DELETE',
        preview_digest: preview.preview_digest,
        acknowledge_active_shares: acknowledgeShares,
      })
      const deletion = PatientDeletionResponseSchema.parse(raw).deletion
      setInventory((current) => current ? {
        ...current,
        owned_patients: current.owned_patients.filter((item) => item.id !== patientId),
      } : current)
      upsertDeletion(deletion)
      setDeletionStatusError(deletion.id)
    } catch (caught) {
      const category = errorMessage(caught)
      if (category === 'preview_changed') {
        setDeletionPreviews((current) => {
          const next = { ...current }
          delete next[patientId]
          return next
        })
      }
      setDeletionError(patientId, deletionMessage(category))
    } finally {
      setBusyPatientId(null)
    }
  }

  async function refreshDeletion(deletionId: number): Promise<void> {
    setDeletionStatusError(deletionId)
    try {
      const raw: unknown = await fetchWrapper.get(`/api/phr/data-hub/deletions/${deletionId}`)
      upsertDeletion(PatientDeletionResponseSchema.parse(raw).deletion)
    } catch (caught) {
      setDeletionStatusError(deletionId, deletionMessage(caught))
    }
  }

  async function retryDeletion(deletionId: number): Promise<void> {
    setDeletionStatusError(deletionId)
    try {
      const raw: unknown = await fetchWrapper.post(`/api/phr/data-hub/deletions/${deletionId}/retry`, {})
      upsertDeletion(PatientDeletionResponseSchema.parse(raw).deletion)
    } catch (caught) {
      setDeletionStatusError(deletionId, deletionMessage(caught))
    }
  }

  return (
    <div className="h-full overflow-y-auto p-6">
      <div className="mx-auto max-w-6xl">
        <header className="flex items-start gap-3">
          <div className="rounded-lg bg-primary/10 p-2 text-primary"><Database className="size-5" /></div>
          <div>
            <h1 className="text-2xl font-semibold text-foreground">PHR Data Hub</h1>
            <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
              Review each patient independently. Clinical XML exports, lossless backup, and previewed aggregate deletion are available; dry-run restore remains a separate safety phase.
            </p>
          </div>
        </header>

        <div className="mt-4 flex items-start gap-2 rounded-md border border-sky-500/30 bg-sky-500/5 p-3 text-sm text-foreground">
          <ShieldCheck className="mt-0.5 size-4 shrink-0 text-sky-700 dark:text-sky-300" />
          <p>Only patient owners can export, back up, restore, or delete an aggregate. Shared records are listed separately without exposing their inventory.</p>
        </div>

        <RestorePanel onCompleted={loadInventory} />

        {loading ? <p role="status" aria-live="polite" className="mt-6 text-sm text-muted-foreground">Loading private inventory…</p> : null}
        {loadError ? (
          <div role="alert" className="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
            <span>{loadError}</span>
            <Button type="button" variant="outline" size="sm" onClick={() => void loadInventory()}>Retry</Button>
          </div>
        ) : null}
        {deletionResults.map((deletion) => (
          <section key={deletion.id} role="status" className="mt-4 rounded-md border border-emerald-500/30 bg-emerald-500/5 p-3 text-sm">
            <p>Patient database data was deleted. Storage cleanup: {DELETION_STATUS_LABELS[deletion.status]}.</p>
            {deletion.failure_category ? <p role="alert" className="mt-1 text-destructive">{deletionMessage(deletion.failure_category)}</p> : null}
            {deletionStatusErrors[deletion.id] ? <p role="alert" className="mt-1 text-destructive">{deletionStatusErrors[deletion.id]}</p> : null}
            <div className="mt-2 flex gap-2">
              {deletion.status === 'cleanup_failed' ? (
                <Button type="button" size="sm" variant="outline" onClick={() => void retryDeletion(deletion.id)}>Retry storage cleanup</Button>
              ) : null}
              {deletion.status !== 'completed' ? (
                <Button type="button" size="sm" variant="outline" onClick={() => void refreshDeletion(deletion.id)}>Check cleanup status</Button>
              ) : null}
            </div>
          </section>
        ))}

        {!loading && inventory?.owned_patients.length === 0 ? (
          <div className="mt-6 rounded-lg border border-dashed border-border p-10 text-center">
            <Database className="mx-auto size-8 text-muted-foreground/60" />
            <h2 className="mt-3 font-semibold text-foreground">No owned patient profiles</h2>
            <p className="mt-1 text-sm text-muted-foreground">Only profiles you own can be managed through the Data Hub.</p>
          </div>
        ) : null}

        <div className="mt-6 space-y-5">
          {inventory?.owned_patients.map((patient) => (
            <PatientCard
              key={`${patient.id}:${deletionPreviews[patient.id]?.preview_digest ?? 'no-preview'}`}
              patient={patient}
              {...(exports[patient.id] ? { currentExport: exports[patient.id] } : {})}
              {...(backups[patient.id] ? { currentBackup: backups[patient.id] } : {})}
              busy={busyPatientId === patient.id}
              {...(exportErrors[patient.id] ? { exportError: exportErrors[patient.id] } : {})}
              {...(backupErrors[patient.id] ? { backupError: backupErrors[patient.id] } : {})}
              {...(deletionPreviews[patient.id] ? { deletionPreview: deletionPreviews[patient.id] } : {})}
              {...(deletionErrors[patient.id] ? { deletionError: deletionErrors[patient.id] } : {})}
              onGenerate={generateExport}
              onRefresh={refreshExport}
              onGenerateBackup={generateBackup}
              onRefreshBackup={refreshBackup}
              onPreviewDeletion={previewDeletion}
              onDelete={deletePatient}
            />
          ))}
        </div>

        {inventory && inventory.shared_patients.length > 0 ? (
          <section className="mt-8" aria-labelledby="shared-patients-heading">
            <h2 id="shared-patients-heading" className="text-lg font-semibold text-foreground">Shared with you</h2>
            <p className="mt-1 text-sm text-muted-foreground">Read-only listing; inventory and owner operations are intentionally unavailable.</p>
            <div className="mt-3 divide-y divide-border rounded-lg border border-border bg-card">
              {inventory.shared_patients.map((patient) => (
                <div key={patient.id} className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                  <div>
                    <p className="font-medium text-card-foreground">{patient.display_name ?? `Patient ${patient.id}`}</p>
                    <p className="text-muted-foreground">{patient.relationship ?? 'Shared patient profile'}</p>
                  </div>
                  <span className="rounded-full bg-muted px-2.5 py-1 text-xs font-medium capitalize text-muted-foreground">{patient.access_level} access · owner operations unavailable</span>
                </div>
              ))}
            </div>
          </section>
        ) : null}
      </div>
    </div>
  )
}
