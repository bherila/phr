import { Archive, Database, Download, HardDrive, RefreshCw, RotateCcw, ShieldCheck, Trash2, Users } from 'lucide-react'
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
  type OwnedPatientInventory,
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
  busy: boolean
  actionError?: string
  onGenerate: (patientId: number) => Promise<void>
  onRefresh: (patientId: number) => Promise<void>
}

function PatientCard({ patient, currentExport, busy, actionError, onGenerate, onRefresh }: PatientCardProps): ReactElement {
  const totalRecords = useMemo(
    () => DATA_HUB_CATEGORY_KEYS.reduce((sum, key) => sum + patient.record_counts[key], 0),
    [patient.record_counts],
  )
  const exportInProgress = currentExport?.status === 'pending' || currentExport?.status === 'processing'

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
                Generate this patient’s C-CDA XML clinical summary. This is separate from the future lossless native backup.
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
          {actionError ? <p role="alert" className="mt-2 text-xs text-destructive">{actionError}</p> : null}
        </div>

        <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3">
          <PlannedAction icon={<Archive className="size-4" />} label="Native backup" phase="Phase 2" />
          <PlannedAction icon={<RotateCcw className="size-4" />} label="Dry-run restore" phase="Phase 4" />
          <PlannedAction icon={<Trash2 className="size-4" />} label="Safe deletion" phase="Phase 3" />
        </div>
      </section>
    </article>
  )
}

function PlannedAction({ icon, label, phase }: { icon: ReactElement, label: string, phase: string }): ReactElement {
  return (
    <div className="rounded-md border border-border bg-muted/30 p-3">
      <div className="flex items-center gap-2 text-sm font-medium">{icon}{label}</div>
      <p className="mt-1 text-xs text-muted-foreground">Owner eligible · planned for {phase}</p>
      <Button type="button" size="sm" variant="outline" className="mt-3" disabled>Not yet available</Button>
    </div>
  )
}

export default function DataHubPage(): ReactElement {
  const [inventory, setInventory] = useState<ReturnType<typeof DataHubResponseSchema.parse> | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [exports, setExports] = useState<Record<number, PhrExport>>({})
  const [busyPatientId, setBusyPatientId] = useState<number | null>(null)
  const [actionErrors, setActionErrors] = useState<Record<number, string>>({})

  const loadInventory = useCallback(async (): Promise<void> => {
    setLoading(true)
    setLoadError(null)
    try {
      const raw: unknown = await fetchWrapper.get('/api/phr/data-hub')
      setInventory(DataHubResponseSchema.parse(raw))
    } catch (caught) {
      setLoadError(errorMessage(caught))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void loadInventory()
  }, [loadInventory])

  function setPatientError(patientId: number, message?: string): void {
    setActionErrors((current) => {
      const next = { ...current }
      if (message) next[patientId] = message
      else delete next[patientId]
      return next
    })
  }

  async function generateExport(patientId: number): Promise<void> {
    setBusyPatientId(patientId)
    setPatientError(patientId)
    try {
      const raw: unknown = await fetchWrapper.post(`/api/phr/patients/${patientId}/exports`, { formats: ['ccda'] })
      const created = PhrExportResponseSchema.parse(raw).export
      setExports((current) => ({ ...current, [patientId]: created }))
    } catch (caught) {
      setPatientError(patientId, errorMessage(caught))
    } finally {
      setBusyPatientId(null)
    }
  }

  async function refreshExport(patientId: number): Promise<void> {
    setBusyPatientId(patientId)
    setPatientError(patientId)
    try {
      const raw: unknown = await fetchWrapper.get(`/api/phr/patients/${patientId}/exports`)
      const latest = PhrExportsResponseSchema.parse(raw).exports.find((item) => item.formats.includes('ccda'))
      if (!latest) {
        setPatientError(patientId, 'No C-CDA export has been generated for this patient yet.')
      } else {
        setExports((current) => ({ ...current, [patientId]: latest }))
      }
    } catch (caught) {
      setPatientError(patientId, errorMessage(caught))
    } finally {
      setBusyPatientId(null)
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
              Review each patient independently. Clinical XML exports are available now; lossless backup, previewed deletion, and dry-run restore remain separate safety phases.
            </p>
          </div>
        </header>

        <div className="mt-4 flex items-start gap-2 rounded-md border border-sky-500/30 bg-sky-500/5 p-3 text-sm text-foreground">
          <ShieldCheck className="mt-0.5 size-4 shrink-0 text-sky-700 dark:text-sky-300" />
          <p>Only patient owners can export, back up, restore, or delete an aggregate. Shared records are listed separately without exposing their inventory.</p>
        </div>

        {loading ? <p role="status" aria-live="polite" className="mt-6 text-sm text-muted-foreground">Loading private inventory…</p> : null}
        {loadError ? (
          <div role="alert" className="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
            <span>{loadError}</span>
            <Button type="button" variant="outline" size="sm" onClick={() => void loadInventory()}>Retry</Button>
          </div>
        ) : null}

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
              key={patient.id}
              patient={patient}
              {...(exports[patient.id] ? { currentExport: exports[patient.id] } : {})}
              busy={busyPatientId === patient.id}
              {...(actionErrors[patient.id] ? { actionError: actionErrors[patient.id] } : {})}
              onGenerate={generateExport}
              onRefresh={refreshExport}
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
