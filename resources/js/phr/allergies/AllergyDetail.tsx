import { Pencil, Trash2 } from 'lucide-react'
import type { FormEvent } from 'react'
import { useEffect, useId, useState } from 'react'

import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { type PhrReviewDecision, ReviewActions } from '@/phr/clinical/review'
import { reviewStatusBadge } from '@/phr/clinical/ui'
import type { PhrListPageProps } from '@/phr/miller'
import { PhrNotFoundColumn } from '@/phr/miller'
import { errorMessage, fetchPhrDetail, zodErrorMessage } from '@/phr/shared'
import {
  type PhrAllergy,
  type PhrAllergyFormData,
  PhrAllergyFormSchema,
  PhrAllergyResponseSchema,
} from '@/phr/types'

import { notifyAllergyChanged } from './allergyEvents'
import {
  AllergyFormFields,
  allergyFormFromRecord,
  allergyPayload,
  EMPTY_ALLERGY_FORM,
} from './AllergyForm'

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
  const formId = useId()
  const [allergy, setAllergy] = useState<PhrAllergy | null>(null)
  const [canManage, setCanManage] = useState(false)
  const [notFound, setNotFound] = useState(false)
  const [deleted, setDeleted] = useState(false)
  const [busy, setBusy] = useState(false)
  const [mutation, setMutation] = useState<'save' | 'delete' | 'review' | null>(null)
  const [editing, setEditing] = useState(false)
  const [confirmingDelete, setConfirmingDelete] = useState(false)
  const [form, setForm] = useState<PhrAllergyFormData>(EMPTY_ALLERGY_FORM)
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
    if (!allergy) return
    setForm(allergyFormFromRecord(allergy))
    setEditing(true)
    setConfirmingDelete(false)
    setError(null)
  }

  function cancelEditing(): void {
    setEditing(false)
    setForm(EMPTY_ALLERGY_FORM)
    setError(null)
  }

  async function saveAllergy(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    const parsed = PhrAllergyFormSchema.safeParse(form)
    if (!parsed.success) {
      setError(zodErrorMessage(parsed.error))
      return
    }

    setMutation('save')
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.patch(endpoint, allergyPayload(parsed.data))
      const updated = PhrAllergyResponseSchema.parse(raw).allergy
      setAllergy(updated)
      setEditing(false)
      setForm(EMPTY_ALLERGY_FORM)
      notifyAllergyChanged({ action: 'updated', allergy: updated, patientId })
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setMutation(null)
    }
  }

  async function reviewAllergy(decision: PhrReviewDecision): Promise<void> {
    if (!allergy) return

    setMutation('review')
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.patch(`${endpoint}/review`, { review_status: decision })
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
      {deleted && (
        <div className="rounded-md border border-border bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
          Allergy deleted.
        </div>
      )}
      {allergy && (
        <section className="rounded-lg border border-border bg-card p-4">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <h2 className="text-lg font-semibold text-card-foreground">{allergy.substance}</h2>
                {reviewStatusBadge(allergy.review_status)}
                {canManage && (
                  <ReviewActions
                    status={allergy.review_status}
                    label={allergy.substance}
                    busy={mutation === 'review'}
                    disabled={mutation !== null && mutation !== 'review'}
                    onReview={(decision) => void reviewAllergy(decision)}
                  />
                )}
              </div>
              <p className="mt-1 text-sm text-muted-foreground">Allergy #{allergy.id}</p>
            </div>
            {canManage && (
              <div className="flex flex-wrap gap-2">
                {editing ? (
                  <>
                    <Button type="submit" form={formId} size="sm" disabled={mutation !== null}>
                      {mutation === 'save' ? 'Saving...' : 'Save'}
                    </Button>
                    <Button type="button" variant="outline" size="sm" disabled={mutation !== null} onClick={cancelEditing}>
                      Cancel
                    </Button>
                  </>
                ) : (
                  <>
                    <Button type="button" variant="outline" size="sm" disabled={mutation !== null} onClick={startEditing}>
                      <Pencil className="size-4" />
                      Edit
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="text-destructive hover:text-destructive"
                      disabled={mutation !== null}
                      onClick={() => setConfirmingDelete(true)}
                    >
                      <Trash2 className="size-4" />
                      Delete
                    </Button>
                  </>
                )}
              </div>
            )}
          </div>

          {editing ? (
            <form id={formId} className="mt-4" onSubmit={(event) => void saveAllergy(event)}>
              <AllergyFormFields form={form} onChange={setForm} />
            </form>
          ) : (
            <>
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
            </>
          )}

          {confirmingDelete && !editing && (
            <div className="mt-4 rounded-md border border-destructive/40 bg-destructive/5 p-3">
              <p className="text-sm text-foreground">
                Delete <strong>{allergy.substance}</strong>? This cannot be undone.
              </p>
              <div className="mt-3 flex gap-2">
                <Button type="button" variant="destructive" size="sm" disabled={mutation !== null} onClick={() => void deleteAllergy()}>
                  {mutation === 'delete' ? 'Deleting...' : 'Delete permanently'}
                </Button>
                <Button type="button" variant="outline" size="sm" disabled={mutation !== null} onClick={() => setConfirmingDelete(false)}>
                  Cancel
                </Button>
              </div>
            </div>
          )}
        </section>
      )}
    </div>
  )
}
