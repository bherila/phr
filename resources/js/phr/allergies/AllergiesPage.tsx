import { AlertTriangle, ChevronDown, ChevronRight, Info, Plus } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'

import { RECORD_ONLY_BELOW_MEDIUM, RECORD_ONLY_WIDE_BLOCK, type RecordColumn, RecordTable } from '@/components/record-list/RecordTable'
import type { StatusTone } from '@/components/record-list/StatusDot'
import { Button } from '@/components/ui/button'
import { useClinicalCrud } from '@/phr/clinical/crud'
import { ShowRejectedToggle } from '@/phr/clinical/review'
import { ReviewDot } from '@/phr/clinical/ReviewDot'
import { codeChip, labelize, statusBadge } from '@/phr/clinical/ui'
import { useKnownPatientCanManage } from '@/phr/patients/usePhrPatients'
import { drillOpener, type PhrListPageProps } from '@/phr/shell/phrPages'
import { openRecordId } from '@/phr/shell/phrRoutes'
import {
  PhrAllergiesResponseSchema,
  type PhrAllergy,
  type PhrAllergyFormData,
  PhrAllergyResponseSchema,
} from '@/phr/types'

import { subscribeToAllergyChanges } from './allergyEvents'
import {
  AllergyFormDialog,
  allergyFormFromRecord,
  allergyPayload,
  EMPTY_ALLERGY_FORM,
} from './AllergyForm'

const CRITICALITY_TONE: Record<string, StatusTone> = {
  high: 'danger',
  low: 'warning',
  unable_to_assess: 'neutral',
}

const STATUS_TONE: Record<string, StatusTone> = {
  active: 'danger',
  inactive: 'neutral',
  resolved: 'success',
}

interface AllergiesTableProps {
  loading?: boolean
  title: string
  description: string
  allergies: PhrAllergy[]
  emptyMessage: string
  onDrill?: PhrListPageProps['onDrill']
  openRowId: string | null
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

function AllergiesTable({
  loading = false,
  title,
  description,
  allergies,
  emptyMessage,
  onDrill,
  openRowId,
}: AllergiesTableProps) {
  const columns: RecordColumn<PhrAllergy>[] = [
    {
      id: 'allergy',
      header: 'Allergy',
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3',
      cell: (allergy) => (
        <>
          <div className="flex items-start gap-2">
            <span className="shrink-0 pt-1.5"><ReviewDot status={allergy.review_status} /></span>
            <span className="font-medium text-card-foreground [overflow-wrap:anywhere]">{allergy.substance}</span>
          </div>
          <div className={`${RECORD_ONLY_BELOW_MEDIUM} mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground [overflow-wrap:anywhere] [&_span]:whitespace-normal`}>
            <span>{allergy.reaction ?? 'Reaction not recorded'}</span>
            {statusBadge(allergy.criticality, CRITICALITY_TONE)}
            {statusBadge(allergy.severity, { severe: 'danger' })}
            {statusBadge(allergy.clinical_status, STATUS_TONE)}
          </div>
          <div className={RECORD_ONLY_WIDE_BLOCK}>
            <div className="mt-2 flex flex-wrap gap-1.5">
              {allergy.category && (
                <span className="inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                  {allergy.category}
                </span>
              )}
              {codeChip('RxNorm', allergy.rxnorm_code)}
              {codeChip('SNOMED', allergy.snomed_code)}
            </div>
            {allergy.notes && <p className="mt-2 text-xs text-muted-foreground [overflow-wrap:anywhere]">{allergy.notes}</p>}
          </div>
        </>
      ),
    },
    {
      id: 'reaction',
      showFrom: 'medium',
      header: 'Reaction',
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3 text-muted-foreground [overflow-wrap:anywhere] [&_span]:whitespace-normal',
      cell: (allergy) => (
        <>
          <div>{allergy.reaction ?? 'Reaction not recorded'}</div>
          <div className="mt-2 flex flex-wrap gap-1.5">
            {statusBadge(allergy.criticality, CRITICALITY_TONE)}
            {statusBadge(allergy.severity, { severe: 'danger' })}
          </div>
        </>
      ),
    },
    {
      id: 'status',
      header: 'Status',
      showFrom: 'medium',
      headerClassName: 'px-4 py-3 font-medium',
      className: 'px-4 py-3',
      cell: (allergy) => (
        <div className="flex flex-wrap items-center gap-2 [overflow-wrap:anywhere] [&_span]:whitespace-normal">
          {statusBadge(allergy.clinical_status, STATUS_TONE)}
          <span className="text-xs text-muted-foreground">{labelize(allergy.verification_status)}</span>
        </div>
      ),
    },
  ]

  return (
    <section className="rounded-lg border border-border bg-card">
      <div className="border-b border-border px-4 py-3">
        <h2 className="font-semibold text-card-foreground">{title}</h2>
        <p className="text-sm text-muted-foreground">{description}</p>
      </div>
      <RecordTable
        label={title}
        loading={loading}
        loadingLabel="Loading allergies"
        rows={allergies}
        rowId={(allergy) => String(allergy.id)}
        columns={columns}
        openRowId={openRowId}
        onOpen={drillOpener(onDrill, (allergy: PhrAllergy) => ({ id: 'allergy-detail', instance: String(allergy.id) }))}
        empty={<div className="px-4 py-8 text-sm text-muted-foreground">{emptyMessage}</div>}
        tableClassName="min-w-full divide-y divide-border text-sm"
        headClassName="text-left text-muted-foreground"
        bodyClassName="divide-y divide-border"
        rowClassName={(allergy) => `align-top hover:bg-muted/30 ${isHighRisk(allergy) ? 'bg-destructive/5' : ''}`}
      />
    </section>
  )
}

export default function AllergiesPage({ patientId, onDrill, openRecord }: PhrListPageProps) {
  const openRowId = openRecordId(openRecord, 'allergy-detail')
  const [historicalOpen, setHistoricalOpen] = useState(false)
  const [adding, setAdding] = useState(false)
  const endpoint = `/api/phr/patients/${patientId}/allergies`
  const knownCanManage = useKnownPatientCanManage(patientId)
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
    initialCanManage: knownCanManage,
    sortRecords: sortAllergies,
  })
  // Skeleton rows on the first load only; a refetch keeps the current rows on screen.
  const firstLoad = crud.busy && crud.records.length === 0
  const { setRecords, showRejected } = crud

  useEffect(() => subscribeToAllergyChanges((change) => {
    if (change.patientId !== patientId) return

    setRecords((current) => {
      if (change.action === 'deleted') {
        return current.filter((allergy) => allergy.id !== change.allergyId)
      }

      // Review happens in the detail column. A rejection there must take the
      // record out of this list too, or the list would contradict the server.
      if (change.allergy.review_status === 'rejected' && !showRejected) {
        return current.filter((allergy) => allergy.id !== change.allergy.id)
      }

      const exists = current.some((allergy) => allergy.id === change.allergy.id)
      const next = exists
        ? current.map((allergy) => allergy.id === change.allergy.id ? change.allergy : allergy)
        : [...current, change.allergy]
      return sortAllergies(next)
    })
  }), [patientId, setRecords, showRejected])

  const activeAllergies = useMemo(
    () => crud.records.filter((allergy) => allergy.clinical_status === 'active'),
    [crud.records],
  )
  const historicalAllergies = useMemo(
    () => crud.records.filter((allergy) => allergy.clinical_status !== 'active'),
    [crud.records],
  )

  function openAdd(): void {
    crud.setError(null)
    setAdding(true)
  }

  function closeAdd(): void {
    crud.setError(null)
    setAdding(false)
  }

  async function addAllergy(form: PhrAllergyFormData): Promise<boolean> {
    const added = await crud.addRecord(form)
    if (!added) return false
    closeAdd()
    // A new allergy opens, so its detail shows what was saved.
    onDrill?.({ id: 'allergy-detail', instance: String(added.id) })
    return true
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
        <ShowRejectedToggle showRejected={crud.showRejected} onChange={crud.setShowRejected} disabled={crud.busy} />
      </div>

      {crud.error && !adding && (
        <div role="alert" className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {crud.error}
        </div>
      )}

      {crud.canManage && (
        <div className="mb-6 grid gap-3">
          <div className="flex flex-wrap items-start gap-2">
            <Button type="button" size="sm" variant="outline" onClick={openAdd}>
              <Plus className="size-4" aria-hidden="true" />
              Add allergy
            </Button>
          </div>
          <div className="flex items-start gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
            <Info className="mt-0.5 size-4 shrink-0" />
            Allergies are imported from CCDA/FHIR data or extracted as part of office-visit review.
          </div>
        </div>
      )}

      {!crud.busy && crud.records.length === 0 && (
        <div className="rounded-lg border border-dashed border-border py-12 text-center text-sm text-muted-foreground">
          No allergies recorded.
        </div>
      )}

      {(firstLoad || crud.records.length > 0) && (
        <div className="space-y-4">
          <AllergiesTable
            loading={firstLoad}
            title="Active Allergies"
            description="High criticality and severe reactions are highlighted."
            allergies={activeAllergies}
            emptyMessage="No active allergies recorded."
            onDrill={onDrill}
            openRowId={openRowId}
          />

          {!firstLoad && (
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
                    openRowId={openRowId}
                  />
                </div>
              )}
            </section>
          )}
        </div>
      )}
      {adding && (
        <AllergyFormDialog allergy={null} busy={crud.isMutating('add')} error={crud.error} onSubmit={addAllergy} onClose={closeAdd} />
      )}
    </div>
  )
}
