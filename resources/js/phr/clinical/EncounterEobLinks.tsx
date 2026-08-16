import { ExternalLink, FileText, Link2, Pencil, Search, Unlink } from 'lucide-react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { errorMessage } from '@/phr/shared'
import {
  PhrEobLinkResponseSchema,
  PhrEobSearchResponseSchema,
  type PhrEobSummary,
} from '@/phr/types'

type LinkableRecordType = 'office-visits' | 'procedures'

interface EncounterEobLinksProps {
  patientId: number
  recordType: LinkableRecordType
  recordId: string
  serviceDate: string | null
  eobs: PhrEobSummary[]
  canManage: boolean
  onChange: (eobs: PhrEobSummary[]) => void
}

function eobLabel(eob: PhrEobSummary): string {
  return eob.provider_name ?? eob.administrator ?? 'Explanation of Benefits'
}

function serviceDateLabel(eob: PhrEobSummary): string {
  if (!eob.service_start) return 'Service date not recorded'
  if (eob.service_end && eob.service_end !== eob.service_start) {
    return `${eob.service_start}–${eob.service_end}`
  }
  return eob.service_start
}

export default function EncounterEobLinks({
  patientId,
  recordType,
  recordId,
  serviceDate,
  eobs,
  canManage,
  onChange,
}: EncounterEobLinksProps) {
  const [editing, setEditing] = useState(false)
  const [candidates, setCandidates] = useState<PhrEobSummary[] | null>(null)
  const [busyKey, setBusyKey] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  async function search(): Promise<void> {
    if (!serviceDate) return
    setBusyKey('search')
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.get(
        `/api/phr/patients/${patientId}/eobs?service_date=${encodeURIComponent(serviceDate)}`,
      )
      setCandidates(PhrEobSearchResponseSchema.parse(raw).eobs)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusyKey(null)
    }
  }

  async function link(eob: PhrEobSummary): Promise<void> {
    setBusyKey(`link-${eob.id}`)
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.post(
        `/api/phr/patients/${patientId}/${recordType}/${recordId}/eobs/${eob.id}`,
        {},
      )
      const linked = PhrEobLinkResponseSchema.parse(raw).eob
      onChange([...eobs.filter((candidate) => candidate.id !== linked.id), linked])
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusyKey(null)
    }
  }

  async function unlink(eob: PhrEobSummary): Promise<void> {
    setBusyKey(`unlink-${eob.id}`)
    setError(null)
    try {
      await fetchWrapper.delete(
        `/api/phr/patients/${patientId}/${recordType}/${recordId}/eobs/${eob.id}`,
        {},
      )
      onChange(eobs.filter((candidate) => candidate.id !== eob.id))
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusyKey(null)
    }
  }

  const linkedIds = new Set(eobs.map((eob) => eob.id))
  const unlinkedCandidates = candidates?.filter((candidate) => !linkedIds.has(candidate.id)) ?? []

  return (
    <section className="rounded-lg border border-border bg-card p-4">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 font-semibold text-card-foreground">
            <FileText className="size-4 text-primary" />
            Explanation of Benefits
          </h2>
          <p className="mt-1 text-sm text-muted-foreground">
            {eobs.length === 0 ? 'No EOB linked.' : `${eobs.length} linked EOB${eobs.length === 1 ? '' : 's'}.`}
          </p>
        </div>
        {canManage && (
          <Button
            type="button"
            size="sm"
            variant="ghost"
            aria-label={editing ? 'Finish editing EOB links' : 'Edit EOB links'}
            aria-expanded={editing}
            onClick={() => {
              setEditing((open) => !open)
              setError(null)
            }}
          >
            <Pencil className="size-4" />
            {editing ? 'Done' : 'Edit'}
          </Button>
        )}
      </div>

      {eobs.length > 0 && (
        <ul className="mt-4 space-y-2">
          {eobs.map((eob) => (
            <li key={eob.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-card-foreground">{eobLabel(eob)}</p>
                <p className="text-xs text-muted-foreground">
                  {serviceDateLabel(eob)}{eob.claim_number ? ` · Claim ${eob.claim_number}` : ''}
                </p>
              </div>
              <div className="flex items-center gap-2">
                {eob.source_document_url ? (
                  <Button asChild type="button" size="sm" variant="outline">
                    <a href={eob.source_document_url} target="_blank" rel="noreferrer">
                      View EOB <ExternalLink className="size-3.5" />
                    </a>
                  </Button>
                ) : (
                  <span className="text-xs text-muted-foreground">Document unavailable</span>
                )}
                {editing && (
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={busyKey !== null}
                    onClick={() => void unlink(eob)}
                  >
                    <Unlink className="size-3.5" />
                    {busyKey === `unlink-${eob.id}` ? 'Unlinking…' : 'Unlink'}
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {editing && (
        <div className="mt-4 border-t border-border pt-4">
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={!serviceDate || busyKey !== null}
            onClick={() => void search()}
          >
            <Search className="size-4" />
            {busyKey === 'search' ? 'Searching…' : 'Search for EOB'}
          </Button>
          <p className="mt-2 text-xs text-muted-foreground">
            {serviceDate
              ? `Searches this patient’s EOBs for date of service ${serviceDate}.`
              : 'Add a date of service before searching for an EOB.'}
          </p>

          {candidates !== null && unlinkedCandidates.length === 0 && (
            <p className="mt-3 text-sm text-muted-foreground">No additional EOBs match this date of service.</p>
          )}
          {unlinkedCandidates.length > 0 && (
            <ul className="mt-3 space-y-2">
              {unlinkedCandidates.map((eob) => (
                <li key={eob.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md bg-muted/40 px-3 py-2">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-card-foreground">{eobLabel(eob)}</p>
                    <p className="text-xs text-muted-foreground">
                      {serviceDateLabel(eob)}{eob.claim_number ? ` · Claim ${eob.claim_number}` : ''}
                    </p>
                  </div>
                  <Button
                    type="button"
                    size="sm"
                    disabled={busyKey !== null}
                    onClick={() => void link(eob)}
                  >
                    <Link2 className="size-3.5" />
                    {busyKey === `link-${eob.id}` ? 'Linking…' : 'Link EOB'}
                  </Button>
                </li>
              ))}
            </ul>
          )}
          {error && <p className="mt-3 text-sm text-destructive">{error}</p>}
        </div>
      )}
    </section>
  )
}
