import { Pencil, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'

import { DetailSkeleton } from '@/components/record-list/Skeleton'
import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { ConfirmDeleteDialog } from '@/phr/clinical/ConfirmDeleteDialog'
import { RecordReviewBar } from '@/phr/clinical/RecordDetailParts'
import type { PhrReviewDecision } from '@/phr/clinical/review'
import { recordReviewDecision } from '@/phr/clinical/reviewDecision'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import { reviewStateChanged } from '@/phr/review/reviewSummary'
import { errorMessage, fetchPhrDetail } from '@/phr/shared'
import type { PhrListPageProps } from '@/phr/shell/phrPages'
import { PhrRecordNotFound } from '@/phr/shell/PhrRecordNotFound'
import {
  type PhrAllergy,
  type PhrAllergyFormData,
  PhrAllergyResponseSchema,
} from '@/phr/types'

import { notifyAllergyChanged } from './allergyEvents'
import { AllergyFormDialog, allergyPayload } from './AllergyForm'

interface AllergyDetailProps {
  patientId: number
  recordId: string
  onDrill?: PhrListPageProps['onDrill']
}

function detailValue(value: string | null | undefined, fallback = 'Not recorded'): string {
  return value && value.trim().length > 0 ? value : fallback
}

export default function AllergyDetail({ patientId, recordId, onDrill }: AllergyDetailProps) {
  const endpoint = `/api/phr/patients/${patientId}/allergies/${recordId}`
  const [allergy, setAllergy] = useState<PhrAllergy | null>(null)
  const [canManage, setCanManage] = useState(false)
  const [notFound, setNotFound] = useState(false)
  const [deleted, setDeleted] = useState(false)
  const [busy, setBusy] = useState(false)
  const [mutation, setMutation] = useState<'save' | 'delete' | 'review' | null>(null)
  const [editing, setEditing] = useState(false)
  const [confirmingDelete, setConfirmingDelete] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    async function load(): Promise<void> {
      setBusy(true)
      setError(null)
      setNotFound(false)
      setDeleted(false)
      setEditing(false)
      setConfirmingDelete(false)

      try {
        const result = await fetchPhrDetail(endpoint, PhrAllergyResponseSchema)

        if (cancelled) return

        setAllergy(result.data?.allergy ?? null)
        setCanManage(result.data?.can_manage ?? false)
        setNotFound(result.notFound)
      } catch (caught) {
        if (!cancelled) {
          setError(errorMessage(caught))
          setAllergy(null)
          setCanManage(false)
        }
      } finally {
        if (!cancelled) setBusy(false)
      }
    }

    void load()

    return () => {
      cancelled = true
    }
  }, [endpoint])

  function startEditing(): void {
    setConfirmingDelete(false)
    setError(null)
    setEditing(true)
  }

  function cancelEditing(): void {
    setEditing(false)
    setError(null)
  }

  async function saveAllergy(form: PhrAllergyFormData): Promise<boolean> {
    setMutation('save')
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.patch(endpoint, allergyPayload(form))
      const updated = PhrAllergyResponseSchema.parse(raw).allergy
      setAllergy(updated)
      setEditing(false)
      notifyAllergyChanged({ action: 'updated', allergy: updated, patientId })
      return true
    } catch (caught) {
      setError(errorMessage(caught))
      return false
    } finally {
      setMutation(null)
    }
  }

  async function reviewAllergy(decision: PhrReviewDecision): Promise<void> {
    if (!allergy) return

    setMutation('review')
    setError(null)
    try {
      const raw: unknown = await recordReviewDecision(endpoint, decision)
      const updated = PhrAllergyResponseSchema.parse(raw).allergy
      setAllergy(updated)
      notifyAllergyChanged({ action: 'updated', allergy: updated, patientId })
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setMutation(null)
    }
  }

  async function deleteAllergy(): Promise<void> {
    if (!allergy) return

    setMutation('delete')
    setError(null)
    try {
      await fetchWrapper.delete(endpoint, {})
      void reviewStateChanged(patientId)
      notifyAllergyChanged({ action: 'deleted', allergyId: allergy.id, patientId })
      setAllergy(null)
      setDeleted(true)
      setConfirmingDelete(false)
      onDrill?.({ id: 'allergies' })
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setMutation(null)
    }
  }

  if (notFound) {
    return <PhrRecordNotFound />
  }

  return (
    <div className="flex flex-1 flex-col gap-4">
      {error && !editing && !confirmingDelete && (
        <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}
      {busy && !allergy && <DetailSkeleton label="Loading allergy" facts={4} rows={0} />}
      {deleted && (
        <div className="rounded-md border border-border bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
          Allergy deleted.
        </div>
      )}
      {allergy && canManage && <RecordReviewBar status={allergy.review_status} label={allergy.substance} busy={mutation === 'review'} disabled={mutation !== null} onReview={(decision: PhrReviewDecision) => void reviewAllergy(decision)} />}
      {allergy && (
        <section className="rounded-lg border border-border bg-card p-4 [overflow-wrap:anywhere]">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <h2 className="text-lg font-semibold text-card-foreground">{allergy.substance}</h2>
                {reviewStatusBadge(allergy.review_status)}
              </div>
              <p className="mt-1 text-sm text-muted-foreground">Allergy #{allergy.id}</p>
            </div>
            {canManage && (
              <div className="flex flex-wrap gap-2">
                <Button type="button" variant="outline" size="sm" className="max-lg:h-11" disabled={mutation !== null} onClick={startEditing}>
                  <Pencil className="size-4" />
                  Edit
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="text-destructive hover:text-destructive max-lg:h-11"
                  disabled={mutation !== null}
                  onClick={() => { setError(null); setConfirmingDelete(true) }}
                >
                  <Trash2 className="size-4" />
                  Delete
                </Button>
              </div>
            )}
          </div>

          <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Reaction</dt>
              <dd className="text-card-foreground">{detailValue(allergy.reaction)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Severity</dt>
              <dd className="text-card-foreground">{detailValue(allergy.severity)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Criticality</dt>
              <dd className="text-card-foreground">{detailValue(allergy.criticality)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Category</dt>
              <dd className="text-card-foreground">{detailValue(allergy.category)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Clinical status</dt>
              <dd className="text-card-foreground">{detailValue(allergy.clinical_status)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Verification</dt>
              <dd className="text-card-foreground">{detailValue(allergy.verification_status)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">RxNorm</dt>
              <dd className="text-card-foreground">{detailValue(allergy.rxnorm_code)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">SNOMED</dt>
              <dd className="text-card-foreground">{detailValue(allergy.snomed_code)}</dd>
            </div>
          </dl>
          <div className="mt-4 border-t border-border pt-4">
            <h3 className="text-sm font-medium text-card-foreground">Notes</h3>
            <p className="mt-1 text-sm text-muted-foreground">{detailValue(allergy.notes, 'No notes recorded.')}</p>
          </div>

          {editing && (
            <AllergyFormDialog
              allergy={allergy}
              busy={mutation === 'save'}
              error={error}
              onSubmit={saveAllergy}
              onClose={cancelEditing}
            />
          )}
          <ConfirmDeleteDialog
            open={confirmingDelete}
            title="Delete this allergy?"
            description={<><strong>{allergy.substance}</strong> will be removed from this record. You can't undo this.</>}
            busy={mutation === 'delete'}
            error={confirmingDelete ? error : null}
            onConfirm={() => void deleteAllergy()}
            onCancel={() => { setError(null); setConfirmingDelete(false) }}
          />
        </section>
      )}

    </div>
  )
}
