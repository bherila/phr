import { FileStack, Images, UploadCloud } from 'lucide-react'
import type { ReactElement } from 'react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { errorMessage } from '@/phr/shared'
import { type PhrPatient, PhrPatientListResponseSchema } from '@/phr/types'
import { useDicomUploads } from '@/phr/uploads'

const SELECT_CLASS = 'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm'

interface DirectoryInputAttributes {
  webkitdirectory: string
  directory: string
}

// `webkitdirectory` is not in React's typed attribute set but every target browser honours it.
const directoryInputAttributes: DirectoryInputAttributes = {
  webkitdirectory: '',
  directory: '',
}

export interface ImportsPageProps {
  /** Patient in context when the tab was opened; used as the default upload target. */
  patientId?: number
}

/**
 * The Imports tab. Owns the DICOM folder picker but none of the upload state — jobs live
 * in {@link useDicomUploads}, mounted by the Miller shell, so navigating away from this
 * column does not interrupt an import.
 */
export default function ImportsPage({ patientId }: ImportsPageProps): ReactElement {
  const inputRef = useRef<HTMLInputElement | null>(null)
  const [patients, setPatients] = useState<PhrPatient[]>([])
  const [selectedPatientId, setSelectedPatientId] = useState<number | undefined>(patientId)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const { startUpload } = useDicomUploads()

  const loadPatients = useCallback(async () => {
    setLoading(true)
    try {
      const raw = await fetchWrapper.get('/api/phr/patients')
      setPatients(PhrPatientListResponseSchema.parse(raw).patients)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void loadPatients()
  }, [loadPatients])

  const manageablePatients = useMemo(
    () => patients.filter((patient) => patient.can_manage && patient.archived_at === null),
    [patients],
  )

  // Only reconcile once the list has arrived, so the patient passed in from the column's
  // shell state stays selected while the request is still in flight.
  useEffect(() => {
    if (manageablePatients.length === 0) {
      return
    }
    setSelectedPatientId((current) => (
      current !== undefined && manageablePatients.some((patient) => patient.id === current)
        ? current
        : manageablePatients[0]?.id
    ))
  }, [manageablePatients])

  const canUpload = selectedPatientId !== undefined

  function onFolderChosen(files: FileList | null): void {
    // Snapshot before touching the input: `input.value = ''` empties the very `FileList`
    // the change event handed us (it is the element's own live list, not a copy), so
    // clearing first would leave nothing to upload.
    const chosen = files ? Array.from(files) : []

    const input = inputRef.current
    if (input) {
      input.value = ''
    }

    if (selectedPatientId === undefined) {
      setError('Choose a patient to import into first.')
      return
    }

    const result = startUpload(selectedPatientId, chosen)
    if (!result.ok) {
      setError(result.error)
      return
    }

    // Deliberately does not open the detail modal: the job announces itself in the tray and
    // the user stays free to navigate. Clicking the tray entry expands it.
    setError(null)
  }

  return (
    <div className="p-6">
      <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
        <FileStack className="size-6 text-primary" />
        Imports
      </h1>
      <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
        Bring outside records into PHR. Imports keep running while you use the rest of the app —
        progress appears in the tray at the bottom right.
      </p>

      {error && (
        <div className="mt-4 max-w-2xl rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      <section className="mt-6 max-w-2xl rounded-lg border border-border bg-card p-4">
        <h2 className="flex items-center gap-2 text-lg font-medium text-card-foreground">
          <Images className="size-5 text-primary" />
          DICOM imaging
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Choose the folder from an imaging CD or export. Every DICOM file inside it is uploaded and
          grouped into studies; non-image files are ignored.
        </p>

        <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
          <label className="grid flex-1 gap-1 text-sm font-medium" htmlFor="import-patient">
            Import into
            <select
              id="import-patient"
              className={SELECT_CLASS}
              value={selectedPatientId === undefined ? '' : String(selectedPatientId)}
              disabled={manageablePatients.length === 0}
              onChange={(event) => setSelectedPatientId(Number.parseInt(event.target.value, 10))}
            >
              {manageablePatients.length === 0 && <option value="">No patients available</option>}
              {manageablePatients.map((patient) => (
                <option key={patient.id} value={String(patient.id)}>
                  {patient.display_name ?? `Patient ${patient.id}`}
                </option>
              ))}
            </select>
          </label>
          <Button type="button" disabled={!canUpload} onClick={() => inputRef.current?.click()}>
            <UploadCloud className="size-4" />
            Upload DICOM folder
          </Button>
        </div>

        {!loading && manageablePatients.length === 0 && (
          <p className="mt-3 text-sm text-muted-foreground">
            You need manage access to a patient before you can import imaging.
          </p>
        )}

        <input
          ref={inputRef}
          type="file"
          className="hidden"
          multiple
          aria-label="DICOM folder"
          onChange={(event) => onFolderChosen(event.target.files)}
          {...directoryInputAttributes}
        />
      </section>

      <section className="mt-4 max-w-2xl rounded-lg border border-dashed border-border p-4">
        <h2 className="text-lg font-medium text-foreground">Documents, C-CDA, FHIR and MyChart</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          These imports still run from the Documents tab. Moving them here is coming soon.
        </p>
      </section>
    </div>
  )
}
