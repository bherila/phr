import { Plus, Share2, Shield } from 'lucide-react'
import type { FormEvent } from 'react'
import { useCallback, useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import type { PhrListPageProps } from '@/phr/miller'
import { errorMessage } from '@/phr/shared'
import {
  type PhrAccessGrant,
  PhrAccessResponseSchema,
  type PhrPatient,
  PhrPatientResponseSchema,
} from '@/phr/types'

const LEVEL_LABEL: Record<string, string> = {
  owner: 'Owner',
  manager: 'Manager',
  viewer: 'Viewer',
}

const LEVEL_CLASS: Record<string, string> = {
  owner: 'bg-primary/10 text-primary',
  manager: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300',
  viewer: 'bg-muted text-muted-foreground',
}

export default function AccessPage({ patientId, onDrill }: PhrListPageProps) {
  const [patient, setPatient] = useState<PhrPatient | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [email, setEmail] = useState('')
  const [accessLevel, setAccessLevel] = useState<'manager' | 'viewer'>('viewer')
  const [formError, setFormError] = useState<string | null>(null)
  const [formBusy, setFormBusy] = useState(false)

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.get(`/api/phr/patients/${patientId}`)
      setPatient(PhrPatientResponseSchema.parse(raw).patient)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }, [patientId])

  useEffect(() => {
    void load()
  }, [load])

  async function handleGrant(e: FormEvent<HTMLFormElement>): Promise<void> {
    e.preventDefault()
    setFormError(null)
    setFormBusy(true)
    try {
      const raw: unknown = await fetchWrapper.post(`/api/phr/patients/${patientId}/access`, {
        email,
        access_level: accessLevel,
      })
      const response = PhrAccessResponseSchema.parse(raw)
      setPatient(response.patient)
      setEmail('')
    } catch (err) {
      setFormError(errorMessage(err))
    } finally {
      setFormBusy(false)
    }
  }

  async function handleRevoke(access: PhrAccessGrant): Promise<void> {
    if (access.access_level === 'owner') {
      return
    }
    setFormBusy(true)
    setFormError(null)
    try {
      await fetchWrapper.delete(`/api/phr/patients/${patientId}/access/${access.id}`, {})
      await load()
    } catch (err) {
      setFormError(errorMessage(err))
    } finally {
      setFormBusy(false)
    }
  }

  const grants = patient?.access_grants ?? []
  const ownerGrant = grants.find((g) => g.access_level === 'owner')
  const sharedGrants = grants.filter((g) => g.access_level !== 'owner')

  return (
    <div>
      <div className="mb-6">
        <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
          <Share2 className="size-6 text-primary" />
          Access
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">Manage who can view or edit this patient's records.</p>
      </div>

      {(error ?? formError) && (
        <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error ?? formError}
        </div>
      )}

      {busy && <p className="text-sm text-muted-foreground">Loading…</p>}

      {!busy && patient && (
        <>
          {ownerGrant && (
            <div
              className={`mb-4 flex items-center gap-3 rounded-lg border border-border bg-card px-4 py-3 ${onDrill ? 'cursor-pointer transition-colors hover:bg-muted/30' : ''}`}
              onClick={() => onDrill?.({ id: 'access-grant-detail', instance: String(ownerGrant.id) })}
            >
              <Shield className="size-4 shrink-0 text-primary" />
              <div className="min-w-0">
                <p className="text-sm font-medium text-foreground">{ownerGrant.user_name ?? ownerGrant.user_email}</p>
                <p className="text-xs text-muted-foreground">{ownerGrant.user_email}</p>
              </div>
              <span className={`ml-auto shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${LEVEL_CLASS.owner}`}>
                Owner
              </span>
            </div>
          )}

          {patient.can_share && (
            <div className="mb-6 rounded-lg border border-border bg-card p-4">
              <h2 className="mb-3 text-sm font-semibold text-card-foreground">Grant access</h2>
              <form
                className="flex flex-wrap items-end gap-3"
                onSubmit={(e) => void handleGrant(e)}
              >
                <label className="grid gap-1 text-sm font-medium text-foreground">
                  Email
                  <input
                    className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    required
                  />
                </label>
                <label className="grid gap-1 text-sm font-medium text-foreground">
                  Level
                  <select
                    className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    value={accessLevel}
                    onChange={(e) => setAccessLevel(e.target.value === 'manager' ? 'manager' : 'viewer')}
                  >
                    <option value="viewer">Viewer</option>
                    <option value="manager">Manager</option>
                  </select>
                </label>
                <Button type="submit" size="sm" disabled={formBusy}>
                  <Plus className="size-4" />
                  {formBusy ? 'Granting…' : 'Grant'}
                </Button>
              </form>
            </div>
          )}

          {sharedGrants.length === 0 && patient?.can_share ? (
            <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
              Not shared with anyone else.
            </div>
          ) : (
            <div className="flex flex-col gap-2">
              {sharedGrants.map((access) => (
                <div
                  key={access.id}
                  className={`flex items-center justify-between gap-3 rounded-lg border border-border bg-card px-4 py-3 ${onDrill ? 'cursor-pointer transition-colors hover:bg-muted/30' : ''}`}
                  onClick={() => onDrill?.({ id: 'access-grant-detail', instance: String(access.id) })}
                >
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-foreground">
                      {access.user_name ?? access.user_email}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                      {access.user_email}
                      {access.granted_at && ` · granted ${access.granted_at.slice(0, 10)}`}
                    </p>
                  </div>
                  <div className="flex shrink-0 items-center gap-2" onClick={(event) => event.stopPropagation()}>
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${LEVEL_CLASS[access.access_level] ?? LEVEL_CLASS.viewer}`}>
                      {LEVEL_LABEL[access.access_level] ?? access.access_level}
                    </span>
                    {patient.can_share && (
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={formBusy}
                        onClick={() => void handleRevoke(access)}
                      >
                        Remove
                      </Button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </>
      )}
    </div>
  )
}
