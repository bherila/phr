import { AlertTriangle, ChevronDown, ChevronRight, Info, Plus } from 'lucide-react'
import type { FormEvent } from 'react'
import { useEffect, useMemo, useState } from 'react'

import { Button } from '@/components/ui/button'
import { useClinicalCrud } from '@/phr/clinical/crud'
import { classBadge, codeChip, labelize } from '@/phr/clinical/ui'
import type { PhrListPageProps } from '@/phr/miller'
import { zodErrorMessage } from '@/phr/shared'
import {
  PhrAllergiesResponseSchema,
  type PhrAllergy,
  type PhrAllergyFormData,
  PhrAllergyFormSchema,
  PhrAllergyResponseSchema,
} from '@/phr/types'

import { subscribeToAllergyChanges } from './allergyEvents'
import {
  AllergyFormFields,
  allergyFormFromRecord,
  allergyPayload,
  EMPTY_ALLERGY_FORM,
} from './AllergyForm'

const CRITICALITY_CLASS: Record<string, string> = {
  high: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300',
  low: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300',
  unable_to_assess: 'bg-muted text-muted-foreground',
}

const STATUS_CLASS: Record<string, string> = {
  active: 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300',
  inactive: 'bg-muted text-muted-foreground',
  resolved: 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-300',
}

interface AddFormProps {
  busy: boolean
  onSubmit: (form: PhrAllergyFormData) => Promise<boolean>
}

interface AllergiesTableProps {
  title: string
  description: string
  allergies: PhrAllergy[]
  emptyMessage: string
  onDrill?: PhrListPageProps['onDrill']
}

function sortAllergies(allergies: PhrAllergy[]): PhrAllergy[] {
  return [...allergies].sort((left, right) => {
    const statusCompare = allergyStatusOrder(left) - allergyStatusOrder(right)
    if (statusCompare !== 0) {
      return statusCompare
    }

    const riskCompare = allergyRiskOrder(left) - allergyRiskOrder(right)
    if (riskCompare !== 0) {
      return riskCompare
    }

    return left.substance.localeCompare(right.substance)
  })
}

function allergyStatusOrder(allergy: PhrAllergy): number {
  return allergy.clinical_status === 'active' ? 0 : 1
}

function allergyRiskOrder(allergy: PhrAllergy): number {
  if (isHighRisk(allergy)) {
    return 0
  }

  if (allergy.criticality === 'low' || allergy.severity === 'moderate') {
    return 1
  }

  return 2
}

function isHighRisk(allergy: PhrAllergy): boolean {
  return allergy.criticality === 'high' || allergy.severity === 'severe'
}

function AddForm({ busy, onSubmit }: AddFormProps) {
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<PhrAllergyFormData>(EMPTY_ALLERGY_FORM)

  async function handleSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    const added = await onSubmit(form)
    if (added) {
      setForm(EMPTY_ALLERGY_FORM)
      setOpen(false)
    }
  }

  if (!open) {
    return (
      <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
        <Plus className="size-4" />
        Add Allergy
      </Button>
    )
  }

  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <h2 className="mb-3 text-sm font-semibold text-card-foreground">Add Allergy</h2>
      <form onSubmit={(event) => void handleSubmit(event)} className="space-y-3">
        <AllergyFormFields form={form} onChange={setForm} />
        <div className="flex gap-2">
          <Button type="submit" size="sm" disabled={busy}>{busy ? 'Adding...' : 'Add'}</Button>
          <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => setOpen(false)}>Cancel</Button>
        </div>
      </form>
    </div>
  )
}

function AllergiesTable({
  title,
  description,
  allergies,
  emptyMessage,
  onDrill,
}: AllergiesTableProps) {
  return (
    <section className="rounded-lg border border-border bg-card">
      <div className="border-b border-border px-4 py-3">
        <h2 className="font-semibold text-card-foreground">{title}</h2>
        <p className="text-sm text-muted-foreground">{description}</p>
      </div>
      {allergies.length === 0 ? (
        <div className="px-4 py-8 text-sm text-muted-foreground">{emptyMessage}</div>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead>
              <tr className="text-left text-muted-foreground">
                <th className="px-4 py-3 font-medium">Allergy</th>
                <th className="px-4 py-3 font-medium">Reaction</th>
                <th className="px-4 py-3 font-medium">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {allergies.map((allergy) => {
                const rowClass = isHighRisk(allergy) ? 'bg-destructive/5' : ''

                return (
                  <tr
                    key={allergy.id}
                    className={`align-top ${rowClass} ${onDrill ? 'cursor-pointer hover:bg-muted/30' : ''}`}
                    onClick={() => onDrill?.({ id: 'allergy-detail', instance: String(allergy.id) })}
                  >
                    <td className="px-4 py-3">
                      {onDrill ? (
                        <button
                          type="button"
                          className="rounded-sm text-left font-medium text-card-foreground underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                          onClick={(event) => {
                            event.stopPropagation()
                            onDrill({ id: 'allergy-detail', instance: String(allergy.id) })
                          }}
                        >
                          {allergy.substance}
                        </button>
                      ) : (
                        <div className="font-medium text-card-foreground">{allergy.substance}</div>
                      )}
                      <div className="mt-2 flex flex-wrap gap-1.5">
                        {allergy.category && (
                          <span className="inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                            {allergy.category}
                          </span>
                        )}
                        {codeChip('RxNorm', allergy.rxnorm_code)}
                        {codeChip('SNOMED', allergy.snomed_code)}
                      </div>
                      {allergy.notes && <p className="mt-2 text-xs text-muted-foreground">{allergy.notes}</p>}
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">
                      <div>{allergy.reaction ?? 'Reaction not recorded'}</div>
                      <div className="mt-2 flex flex-wrap gap-1.5">
                        {classBadge(allergy.criticality, CRITICALITY_CLASS)}
                        {allergy.severity && (
                          <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${allergy.severity === 'severe' ? 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300' : 'bg-muted text-muted-foreground'}`}>
                            {allergy.severity}
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-col items-start gap-1.5">
                        {classBadge(allergy.clinical_status, STATUS_CLASS)}
                        <span className="text-xs text-muted-foreground">{labelize(allergy.verification_status)}</span>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

export default function AllergiesPage({ patientId, onDrill }: PhrListPageProps) {
  const [historicalOpen, setHistoricalOpen] = useState(false)
  const endpoint = `/api/phr/patients/${patientId}/allergies`
  const crud = useClinicalCrud<PhrAllergy, PhrAllergyFormData>({
    endpoint,
    emptyForm: EMPTY_ALLERGY_FORM,
    formFromRecord: allergyFormFromRecord,
    parseItem: (raw) => PhrAllergyResponseSchema.parse(raw).allergy,
    parseList: (raw) => {
      const parsed = PhrAllergiesResponseSchema.parse(raw)
      return { records: parsed.allergies, canManage: parsed.can_manage }
    },
    payloadFromForm: allergyPayload,
    sortRecords: sortAllergies,
  })
  const { setRecords } = crud

  useEffect(() => subscribeToAllergyChanges((change) => {
    if (change.patientId !== patientId) return

    setRecords((current) => {
      if (change.action === 'deleted') {
        return current.filter((allergy) => allergy.id !== change.allergyId)
      }

      const exists = current.some((allergy) => allergy.id === change.allergy.id)
      const next = exists
        ? current.map((allergy) => allergy.id === change.allergy.id ? change.allergy : allergy)
        : [...current, change.allergy]
      return sortAllergies(next)
    })
  }), [patientId, setRecords])

  const activeAllergies = useMemo(
    () => crud.records.filter((allergy) => allergy.clinical_status === 'active'),
    [crud.records],
  )
  const historicalAllergies = useMemo(
    () => crud.records.filter((allergy) => allergy.clinical_status !== 'active'),
    [crud.records],
  )

  async function addAllergy(form: PhrAllergyFormData): Promise<boolean> {
    const parsed = PhrAllergyFormSchema.safeParse(form)
    if (!parsed.success) {
      crud.setError(zodErrorMessage(parsed.error))
      return false
    }

    return (await crud.addRecord(parsed.data)) !== null
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
            <AlertTriangle className="size-6 text-primary" />
            Allergies
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">Track active allergies and historical resolved or inactive reactions.</p>
        </div>
      </div>

      {crud.error && (
        <div className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {crud.error}
        </div>
      )}

      {crud.canManage && (
        <div className="mb-6 grid gap-3">
          <div className="flex flex-wrap items-start gap-2">
            <AddForm busy={crud.isMutating('add')} onSubmit={addAllergy} />
          </div>
          <div className="flex items-start gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
            <Info className="mt-0.5 size-4 shrink-0" />
            Allergies are imported from CCDA/FHIR data or extracted as part of office-visit review.
          </div>
        </div>
      )}

      {crud.busy && <p className="text-sm text-muted-foreground">Loading...</p>}

      {!crud.busy && crud.records.length === 0 && (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          No allergies recorded.
        </div>
      )}

      {!crud.busy && crud.records.length > 0 && (
        <div className="space-y-4">
          <AllergiesTable
            title="Active Allergies"
            description="High criticality and severe reactions are highlighted."
            allergies={activeAllergies}
            emptyMessage="No active allergies recorded."
            onDrill={onDrill}
          />

          <section className="rounded-lg border border-border bg-card">
            <button
              type="button"
              className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left"
              onClick={() => setHistoricalOpen((current) => !current)}
              aria-expanded={historicalOpen}
            >
              <div>
                <h2 className="font-semibold text-card-foreground">Resolved and Inactive</h2>
                <p className="text-sm text-muted-foreground">Historical allergies retained for clinical context.</p>
              </div>
              <span className="flex items-center gap-2 text-sm text-muted-foreground">
                {historicalAllergies.length}
                {historicalOpen ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
              </span>
            </button>
            {historicalOpen && (
              <div className="border-t border-border">
                <AllergiesTable
                  title="Historical Allergies"
                  description="Resolved or inactive allergy records."
                  allergies={historicalAllergies}
                  emptyMessage="No historical allergies recorded."
                  onDrill={onDrill}
                />
              </div>
            )}
          </section>
        </div>
      )}
    </div>
  )
}
