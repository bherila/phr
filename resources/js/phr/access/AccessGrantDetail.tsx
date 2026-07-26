import { useEffect, useMemo, useState } from 'react'

import { PhrNotFoundColumn } from '@/phr/miller'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import { type PhrAccessGrant, PhrAccessGrantDetailResponseSchema, PhrPatientResponseSchema } from '@/phr/types'

interface AccessGrantDetailProps {
  patientId: number
  recordId: string
}

function detailValue(value: string | null | undefined, fallback = 'Not recorded'): string {
  return value && value.trim().length > 0 ? value : fallback
}

export default function AccessGrantDetail({ patientId, recordId }: AccessGrantDetailProps) {
  const [grant, setGrant] = useState<PhrAccessGrant | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const parsedRecordId = useMemo(() => Number.parseInt(recordId, 10), [recordId])

  useEffect(() => {
    let cancelled = false

    async function load(): Promise<void> {
      setBusy(true)
      setError(null)
      setNotFound(false)

      if (!Number.isFinite(parsedRecordId)) {
        setGrant(null)
        setNotFound(true)
        setBusy(false)
        return
      }

      try {
        const result = await fetchPhrDetail(
          `/api/phr/patients/${patientId}`,
          PhrPatientResponseSchema,
        )

        if (cancelled) return

        if (result.notFound || !result.data) {
          setGrant(null)
          setNotFound(true)
          return
        }

        const matchingGrant = result.data.patient.access_grants.find((access) => access.id === parsedRecordId) ?? null
        if (!matchingGrant) {
          setGrant(null)
          setNotFound(true)
          return
        }

        setGrant(PhrAccessGrantDetailResponseSchema.parse({ access: matchingGrant }).access)
        setNotFound(false)
      } catch (caught) {
        if (!cancelled) {
          setError(errorMessage(caught))
          setGrant(null)
        }
      } finally {
        if (!cancelled) setBusy(false)
      }
    }

    void load()

    return () => {
      cancelled = true
    }
  }, [patientId, parsedRecordId])

  if (notFound) {
    return <PhrNotFoundColumn />
  }

  return (
    <div className="space-y-4">
      {error && (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}
      {busy && <p className="text-sm text-muted-foreground">Loading...</p>}
      {grant && (
        <section className="rounded-lg border border-border bg-card p-4">
          <h2 className="text-lg font-semibold text-card-foreground">{detailValue(grant.user_name, grant.user_email ?? 'Unknown user')}</h2>
          <p className="mt-1 text-sm text-muted-foreground">Grant #{grant.id}</p>
          <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Grantee email</dt>
              <dd className="text-card-foreground">{detailValue(grant.user_email)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Scope</dt>
              <dd className="text-card-foreground">{detailValue(grant.access_level)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Granted at</dt>
              <dd className="text-card-foreground">{detailValue(grant.granted_at)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Expiry</dt>
              <dd className="text-card-foreground">No expiry set</dd>
            </div>
          </dl>
        </section>
      )}
    </div>
  )
}
