import { Plus, Search } from 'lucide-react'
import { type FormEvent, useEffect, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { fetchWrapper } from '@/fetchWrapper'
import { cn } from '@/lib/utils'
import { KeepUnitCase, useLowercaseClass } from '@/phr/display/DisplayPreferences'
import { reviewStateChanged } from '@/phr/review/reviewSummary'
import { errorMessage } from '@/phr/shared'
import { PhrMedicationsResponseSchema } from '@/phr/types'

import {
  dollarsToCents,
  type MedicationFill,
  MedicationFillResponseSchema,
  type NdcCandidate,
  type NdcDrug,
  type NdcLookupResult,
  NdcLookupResultSchema,
  NdcSearchResultSchema,
} from './fillTypes'

export type FieldErrors = Record<string, string[]>

interface FillFormState {
  medicationId: string
  ndc: string
  ndcCandidate: string
  allowUnresolved: boolean
  drugAsPrinted: string
  filledOn: string
  quantity: string
  quantityUnit: string
  paid: string
  retail: string
  pharmacyName: string
  pharmacyLocation: string
  daysSupply: string
  rxNumber: string
  refillsRemaining: string
  prescriberName: string
  directions: string
}

const EMPTY_FORM: FillFormState = {
  medicationId: '',
  ndc: '',
  ndcCandidate: '',
  allowUnresolved: false,
  drugAsPrinted: '',
  filledOn: '',
  quantity: '',
  quantityUnit: '',
  paid: '',
  retail: '',
  pharmacyName: '',
  pharmacyLocation: '',
  daysSupply: '',
  rxNumber: '',
  refillsRemaining: '',
  prescriberName: '',
  directions: '',
}

type LookupState =
  | { phase: 'idle' }
  | { phase: 'loading' }
  | { phase: 'error', message: string }
  | { phase: 'done', result: NdcLookupResult }

/** Layouts of the segments of a hyphenated NDC: the three ten-digit ones and the eleven-digit 5-4-2. */
const NDC_LAYOUTS = ['4-4-2', '5-3-2', '5-4-1', '5-4-2']

export interface ParsedNdc {
  valid: boolean
  /** The normalised form: no label, ASCII hyphens, no spaces. Two spellings of one NDC share it. */
  key: string
}

/**
 * Mirrors the server's NdcNumber::parse, so the form accepts exactly what the server accepts: an
 * optional "NDC:" label, Unicode dashes and spaces, then ten or eleven digits (hyphenated only in
 * an NDC layout). Keep the two in step.
 */
export function parseNdc(input: string): ParsedNdc {
  const invalid: ParsedNdc = { valid: false, key: '' }
  const text = input
    .replace(/^\s*NDC\s*[:#]?\s*/i, '')
    .trim()
    .replace(/[\u2010-\u2014\u2212]/g, '-')
    .replace(/\s+/g, '')
  if (text === '' || !/^[0-9-]+$/.test(text)) return invalid
  if (text.includes('-')) {
    const segments = text.split('-')
    if (segments.length !== 3 || segments.some((segment) => segment === '')) return invalid
    return NDC_LAYOUTS.includes(segments.map((segment) => segment.length).join('-')) ? { valid: true, key: text } : invalid
  }
  return text.length === 10 || text.length === 11 ? { valid: true, key: text } : invalid
}

/** Whether the server will read this as an NDC. */
export function isLookupableNdc(value: string): boolean {
  return parseNdc(value).valid
}

/** How long typing must pause before the lookup is sent. */
export const NDC_LOOKUP_DEBOUNCE_MS = 350

const textOrNull = (value: string): string | null => (value.trim() === '' ? null : value.trim())
const intOrNull = (value: string): number | null => (value.trim() === '' ? null : Number(value))

/** The create body of the contract. Money is typed in dollars and sent as cents; drug fields are never sent. */
export function buildFillPayload(
  form: FillFormState,
  options: {
    sendNdc?: boolean
    version?: string | null
    /** Editing a fill whose NDC the person cleared: an explicit null removes it. */
    clearNdc?: boolean
    /** Whether to send medication_id. A create always does (null means match or create); an edit only when the person chose one. */
    sendMedication?: boolean
  } = {},
): Record<string, unknown> {
  const sendNdc = options.sendNdc ?? true
  const sendMedication = options.sendMedication ?? true
  return {
    ...(options.version ? { version: options.version } : {}),
    ...(options.clearNdc ? { ndc: null } : {}),
    ...(sendNdc && form.ndc.trim() ? { ndc: form.ndc.trim() } : {}),
    ...(sendNdc && form.ndcCandidate ? { ndc_candidate: form.ndcCandidate } : {}),
    ...(sendNdc && form.allowUnresolved ? { allow_unresolved: true } : {}),
    filled_on: form.filledOn,
    quantity: textOrNull(form.quantity),
    quantity_unit: textOrNull(form.quantityUnit),
    paid_cents: dollarsToCents(form.paid),
    retail_cents: dollarsToCents(form.retail),
    pharmacy_name: textOrNull(form.pharmacyName),
    pharmacy_location: textOrNull(form.pharmacyLocation),
    days_supply: intOrNull(form.daysSupply),
    rx_number: textOrNull(form.rxNumber),
    refills_remaining: intOrNull(form.refillsRemaining),
    prescriber_name: textOrNull(form.prescriberName),
    directions: textOrNull(form.directions),
    drug_as_printed: textOrNull(form.drugAsPrinted),
    ...(sendMedication ? { medication_id: form.medicationId ? Number(form.medicationId) : null } : {}),
  }
}

const centsToDollars = (cents: number | null): string => (cents === null ? '' : `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`)
const numberText = (value: number | null): string => (value === null ? '' : String(value))

function formFromFill(fill: MedicationFill): FillFormState {
  return {
    medicationId: fill.medication_id === null ? '' : String(fill.medication_id),
    // Shown in the ten-digit form; what was captured stays on the record as evidence.
    ndc: fill.ndc_10 ?? fill.ndc_as_captured ?? '',
    ndcCandidate: '',
    allowUnresolved: fill.ndc_status === 'unresolved',
    drugAsPrinted: fill.drug_as_printed ?? '',
    filledOn: fill.filled_on.slice(0, 10),
    quantity: fill.quantity ?? '',
    quantityUnit: fill.quantity_unit ?? '',
    paid: centsToDollars(fill.paid_cents),
    retail: centsToDollars(fill.retail_cents),
    pharmacyName: fill.pharmacy_name ?? '',
    pharmacyLocation: fill.pharmacy_location ?? '',
    daysSupply: numberText(fill.days_supply),
    rxNumber: fill.rx_number ?? '',
    refillsRemaining: numberText(fill.refills_remaining),
    prescriberName: fill.prescriber_name ?? '',
    directions: fill.directions ?? '',
  }
}

export const FILL_CONFLICT_MESSAGE = 'This fill changed since you opened it. Reload to see the latest'

function FieldError({ errors, field }: { errors: FieldErrors, field: string }) {
  const messages = errors[field]
  if (!messages || messages.length === 0) return null
  return <p role="alert" className="text-xs text-destructive" data-field-error={field}>{messages.join(' ')}</p>
}

function Field({ label, field, errors, className, children }: { label: string, field: string, errors: FieldErrors, className?: string, children: React.ReactNode }) {
  return (
    <label className={cn('grid gap-1 text-sm font-medium', className)}>
      {label}
      {children}
      <FieldError errors={errors} field={field} />
    </label>
  )
}

export function DrugPreview({ drug, ndc }: { drug: NdcDrug | null, ndc: string | null }) {
  const lc = useLowercaseClass()
  if (!drug) return null
  const name = drug.substance_names.join('; ')
  const rows: Array<[string, string | null]> = [
    ['Brand', drug.brand_name],
    ['Strength', drug.strengths.join('; ') || null],
    ['Form', drug.dosage_form],
    ['Route', drug.route],
    ['Manufacturer', drug.labeler_name],
    ['Package', drug.package_description],
 ]
  return (
    <div className={cn('rounded-md border border-border bg-muted/30 p-3 text-sm', lc)} aria-label="Drug preview">
      <div className="font-medium text-card-foreground">{name || 'Drug'}{ndc ? ` · NDC ${ndc}` : ''}</div>
      <dl className="mt-2 grid gap-x-4 gap-y-1 sm:grid-cols-2">
        {rows.filter(([, value]) => value).map(([term, value]) => (
          <div key={term}>
            <dt className="inline text-xs uppercase tracking-wide text-muted-foreground">{term}: </dt>
            <dd className="inline text-card-foreground"><KeepUnitCase text={value} /></dd>
          </div>
        ))}
      </dl>
    </div>
  )
}

function CandidateList({ candidates, name, selected, onSelect, action }: {
  candidates: NdcCandidate[]
  name: string
  selected: string
  onSelect: (candidate: NdcCandidate) => void
  action?: 'radio' | 'button'
}) {
  const lc = useLowercaseClass()
  return (
    <ul className={cn('space-y-2', lc)}>
      {candidates.map((candidate) => (
        <li key={candidate.ndc_10} className="rounded-md border border-border p-3 text-sm">
          <label className="flex cursor-pointer items-start gap-3">
            {action === 'button' ? null : (
              <input
                type="radio"
                name={name}
                value={candidate.ndc_10}
                checked={selected === candidate.ndc_10}
                onChange={() => onSelect(candidate)}
                className="mt-1"
              />
            )}
            <span className="grid gap-0.5">
              <span className="font-medium text-card-foreground">NDC {candidate.ndc_10}</span>
              {candidate.drug?.package_description && <span><KeepUnitCase text={candidate.drug.package_description} /></span>}
              {candidate.drug?.labeler_name && <span className="text-muted-foreground">{candidate.drug.labeler_name}</span>}
              {candidate.drug && (candidate.drug.substance_names.length > 0 || candidate.drug.strengths.length > 0) && (
                <span className="text-muted-foreground"><KeepUnitCase text={[candidate.drug.substance_names.join('; '), candidate.drug.strengths.join('; ')].filter(Boolean).join(' ')} /></span>
              )}
              {candidate.match_reasons.length > 0 && (
                <span className="text-xs text-muted-foreground">Matched: {candidate.match_reasons.join(', ')}</span>
              )}
            </span>
          </label>
          {action === 'button' && (
            <Button type="button" size="sm" variant="outline" className="mt-2" onClick={() => onSelect(candidate)}>
              Use NDC {candidate.ndc_10}
            </Button>
          )}
        </li>
      ))}
    </ul>
  )
}

interface NameSearchState { name: string, strength: string, form: string, package: string }

function NameSearch({ onPick }: { onPick: (candidate: NdcCandidate) => void }) {
  const [query, setQuery] = useState<NameSearchState>({ name: '', strength: '', form: '', package: '' })
  const [results, setResults] = useState<NdcCandidate[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function search(): Promise<void> {
    if (!query.name.trim()) {
      setError('Enter a drug name to search.')
      return
    }
    setBusy(true)
    setError(null)
    const params = new URLSearchParams()
    for (const [key, value] of [['name', query.name], ['strength', query.strength], ['dosage_form', query.form], ['package', query.package]] as const) {
      if (value.trim()) params.set(key, value.trim())
    }
    try {
      const raw: unknown = await fetchWrapper.get(`/api/ndc/search?${params.toString()}`)
      setResults(NdcSearchResultSchema.parse(raw).candidates)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }

  const set = (key: keyof NameSearchState) => (event: React.ChangeEvent<HTMLInputElement>) => setQuery({ ...query, [key]: event.target.value })

  return (
    <div className="space-y-3 rounded-md border border-border p-3">
      <div className="grid gap-3 md:grid-cols-4">
        <label className="grid gap-1 text-sm font-medium">Drug name<Input value={query.name} onChange={set('name')} placeholder="atorvastatin" /></label>
        <label className="grid gap-1 text-sm font-medium">Strength<Input value={query.strength} onChange={set('strength')} placeholder="20 mg" /></label>
        <label className="grid gap-1 text-sm font-medium">Dosage form<Input value={query.form} onChange={set('form')} placeholder="tablet" /></label>
        <label className="grid gap-1 text-sm font-medium">Package<Input value={query.package} onChange={set('package')} placeholder="90" /></label>
      </div>
      <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => void search()}>
        <Search className="size-4" />
        {busy ? 'Searching…' : 'Search FDA listings'}
      </Button>
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      {results && results.length === 0 && <p className="text-sm text-muted-foreground">No packages match that search.</p>}
      {results && results.length > 0 && (
        <CandidateList candidates={results} name="ndc-search-result" selected="" onSelect={onPick} action="button" />
      )}
    </div>
  )
}

interface FillFormProps {
  patientId: number
  /** An existing fill to edit; absent for a new one. */
  fill?: MedicationFill | undefined
  /** Called with the saved fill once the server accepts it. */
  onSaved: (fill: MedicationFill) => void
  onCancel: () => void
  /** Called when the fill changed elsewhere and the person asks to reload it. */
  onReload?: (() => void) | undefined
}

/** Add-a-fill entry point: a button that opens the form. */
export function FillEntryForm({ patientId, onCreated }: { patientId: number, onCreated: (fill: MedicationFill) => void }) {
  const [open, setOpen] = useState(false)
  if (!open) {
    return (
      <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
        <Plus className="size-4" />
        Add Fill
      </Button>
    )
  }
  return (
    <FillForm
      patientId={patientId}
      onSaved={(fill) => { onCreated(fill); setOpen(false) }}
      onCancel={() => setOpen(false)}
    />
  )
}

export function FillForm({ patientId, fill, onSaved, onCancel, onReload }: FillFormProps) {
  const editing = fill !== undefined
  const [form, setForm] = useState<FillFormState>(() => (fill ? formFromFill(fill) : EMPTY_FORM))
  const initialNdc = fill ? formFromFill(fill).ndc : ''
  const initialMedicationId = fill ? formFromFill(fill).medicationId : ''
  const [medications, setMedications] = useState<Array<{ id: number, name: string }>>([])
  const [lookup, setLookup] = useState<LookupState>({ phase: 'idle' })
  const [searchOpen, setSearchOpen] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [conflict, setConflict] = useState(false)
  const lookupSeqRef = useRef(0)
  // Answers by normalised NDC, so an NDC is looked up once however it is retyped.
  const lookupCacheRef = useRef(new Map<string, NdcLookupResult>())

  const update = (patch: Partial<FillFormState>) => setForm((current) => ({ ...current, ...patch }))

  // Editing keeps the stored drug until the NDC itself is changed.
  const ndcUnchanged = editing && form.ndc.trim() === initialNdc.trim()
  // An agent can save an NDC that matches several packages; its picker stays available on edit.
  const staleAmbiguous = ndcUnchanged && fill?.ndc_status === 'ambiguous'
  const lookupKey = (!ndcUnchanged || staleAmbiguous) ? (parseNdc(form.ndc).valid ? parseNdc(form.ndc).key : null) : null

  useEffect(() => {
    let cancelled = false
    fetchWrapper.get(`/api/phr/patients/${patientId}/medications`).then((raw: unknown) => {
      const parsed = PhrMedicationsResponseSchema.safeParse(raw)
      if (!cancelled && parsed.success) setMedications(parsed.data.medications.map((medication) => ({ id: medication.id, name: medication.name })))
    }).catch(() => {
      // The select then offers only automatic matching.
    })
    return () => {
      cancelled = true
    }
  }, [patientId])

  // The lookup runs by itself once the NDC is a plausible 10 or 11 digits and typing has paused,
  // and only once per normalised NDC.
  useEffect(() => {
    if (lookupKey === null) {
      lookupSeqRef.current += 1
      setLookup({ phase: 'idle' })
      return
    }
    const seq = ++lookupSeqRef.current
    const known = lookupCacheRef.current.get(lookupKey)
    if (known) {
      setLookup({ phase: 'done', result: known })
      return
    }
    setLookup({ phase: 'loading' })
    const timer = setTimeout(() => {
      fetchWrapper.get(`/api/ndc/lookup?ndc=${encodeURIComponent(lookupKey)}`).then((raw: unknown) => {
        if (seq !== lookupSeqRef.current) return
        const parsed = NdcLookupResultSchema.parse(raw)
        // A miss caused by an outage is not an answer; look again next time.
        if (parsed.reason !== 'lookup_unavailable') lookupCacheRef.current.set(lookupKey, parsed)
        setLookup({ phase: 'done', result: parsed })
      }).catch((caught: unknown) => {
        if (seq === lookupSeqRef.current) setLookup({ phase: 'error', message: errorMessage(caught) })
      })
    }, NDC_LOOKUP_DEBOUNCE_MS)
    return () => {
      clearTimeout(timer)
      lookupSeqRef.current += 1
    }
  }, [lookupKey])

  function changeNdc(value: string): void {
    update({ ndc: value, ndcCandidate: '', allowUnresolved: false })
  }

  const result = lookup.phase === 'done' ? lookup.result : null

  async function submit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    setFormError(null)
    setConflict(false)
    const local: FieldErrors = {}
    // An NDC left as stored is not re-checked: it was accepted when it was saved.
    if (!ndcUnchanged && form.ndc.trim() !== '' && !isLookupableNdc(form.ndc)) local['ndc'] = ['Enter a 10 or 11 digit NDC.']
    // Every fill needs a drug: an NDC, or its name as printed (also when an edit clears the NDC).
    if (form.ndc.trim() === '' && form.drugAsPrinted.trim() === '') local['drug_as_printed'] = ['Enter an NDC, or the drug name as printed.']
    if (!form.filledOn) local['filled_on'] = ['Enter the fill date.']
    if (form.quantity.trim() === '') local['quantity'] = ['Enter the quantity.']
    for (const [field, value] of [['paid_cents', form.paid], ['retail_cents', form.retail]] as const) {
      if (Number.isNaN(dollarsToCents(value))) local[field] = ['Enter a dollar amount such as 18.99.']
    }
    if (!ndcUnchanged && result?.status === 'ambiguous' && !form.ndcCandidate) local['ndc_candidate'] = ['Choose one of the matching packages'] 
    if (!ndcUnchanged && result?.status === 'unresolved' && !form.allowUnresolved) local['ndc'] = ['No FDA listing matches this NDC. Search by name, or choose to save it without a match.']
    setErrors(local)
    if (Object.keys(local).length > 0) return

    setSaving(true)
    try {
      const payload = buildFillPayload(form, {
        // Send the NDC when it changed, or when the person chose a package for an ambiguous one.
        sendNdc: !ndcUnchanged || form.ndcCandidate !== '' || (staleAmbiguous && result?.status === 'resolved'),
        version: fill?.version ?? null,
        clearNdc: editing && initialNdc.trim() !== '' && form.ndc.trim() === '',
        // An edit leaves the medication to the server unless the person picked one, so a changed
        // NDC can re-link; a create always says (null means match or create).
        sendMedication: !editing || (form.medicationId !== '' && form.medicationId !== initialMedicationId),
      })
      const response = fill
        ? await fetchWrapper.patchRaw(`/api/phr/patients/${patientId}/medication-fills/${fill.id}`, payload)
        : await fetchWrapper.postRaw(`/api/phr/patients/${patientId}/medication-fills`, payload)
      const text = await response.text()
      let body: unknown = null
      try {
        body = text ? JSON.parse(text) : null
      } catch {
        body = null
      }
      if (response.status === 409) {
        setConflict(true)
        return
      }
      if (response.status === 422) {
        const invalid = (body ?? {}) as { message?: string, errors?: FieldErrors }
        setErrors(invalid.errors ?? {})
        setFormError(invalid.message ?? 'Please correct the highlighted fields.')
        return
      }
      if (!response.ok) {
        const failed = (body ?? {}) as { message?: string, error?: string }
        setFormError(failed.message ?? failed.error ?? response.statusText ?? 'Request failed.')
        return
      }
      void reviewStateChanged(patientId)
      onSaved(MedicationFillResponseSchema.parse(body).medication_fill)
    } catch (caught) {
      setFormError(errorMessage(caught))
    } finally {
      setSaving(false)
    }
  }

  const selectedCandidate = result?.status === 'ambiguous'
    ? result.candidates.find((candidate) => candidate.ndc_10 === form.ndcCandidate) ?? null
    : result?.selected ?? null

  return (
    <div className="w-full rounded-lg border border-border bg-card p-4">
      <h2 className="mb-3 text-sm font-semibold text-card-foreground">{editing ? 'Edit Fill' : 'Add Fill'}</h2>
      <form onSubmit={(event) => void submit(event)} className="space-y-4" noValidate>
        <div className="space-y-2">
          <Field label="NDC *" field="ndc" errors={errors}>
            <Input value={form.ndc} onChange={(event) => changeNdc(event.target.value)} placeholder="00071-0155-23" inputMode="numeric" />
          </Field>
          <p className="text-xs text-muted-foreground">10 or 11 digits, with or without dashes. The drug fills in from the FDA listing. Without an NDC, type the drug name as printed instead.</p>
          {form.ndc.trim() === '' && (
            <Field label="Drug name as printed" field="drug_as_printed" errors={errors}>
              <Input value={form.drugAsPrinted} onChange={(event) => update({ drugAsPrinted: event.target.value })} placeholder="atorvastatin 20mg tab" />
            </Field>
          )}
          {ndcUnchanged && !staleAmbiguous && fill?.drug && fill.ndc_status === 'resolved' && <DrugPreview drug={fill.drug} ndc={fill.ndc_10} />}
          {lookup.phase === 'loading' && <p className="text-sm text-muted-foreground">Looking up NDC…</p>}
          {lookup.phase === 'error' && <p role="alert" className="text-sm text-destructive">{lookup.message}</p>}

          {result?.status === 'resolved' && (
            <DrugPreview drug={result.selected?.drug ?? null} ndc={result.selected?.ndc_10 ?? null} />
          )}

          {result?.status === 'ambiguous' && (
            <fieldset className="space-y-2">
              <legend className="text-sm font-medium">This NDC matches more than one package. Choose one.</legend>
              <CandidateList
                candidates={result.candidates}
                name="ndc-candidate"
                selected={form.ndcCandidate}
                onSelect={(candidate) => update({ ndcCandidate: candidate.ndc_10 })}
              />
              <FieldError errors={errors} field="ndc_candidate" />
              {selectedCandidate && <DrugPreview drug={selectedCandidate.drug} ndc={selectedCandidate.ndc_10} />}
            </fieldset>
          )}

          {result?.status === 'unresolved' && (
            <div className="space-y-3 rounded-md border border-yellow-500/40 bg-yellow-500/10 p-3 text-sm">
              <p className="font-medium text-foreground">No FDA listing matches this NDC.</p>
              <p className="text-muted-foreground">
                {result.next_step ?? 'Check the digits, search the FDA listings by drug name, or save the fill without a match. Discontinued products drop out of the current listing.'}
              </p>
              {result.reason === 'lookup_unavailable' && (
                <p className="text-muted-foreground">The FDA listing could not be reached just now, so this may not be a bad NDC. Try again later, or save it without a match.</p>
              )}
              <div className="flex flex-wrap items-center gap-3">
                <Button type="button" size="sm" variant="outline" aria-expanded={searchOpen} onClick={() => setSearchOpen((current) => !current)}>
                  <Search className="size-4" />
                  Search by name
                </Button>
                <label className="flex items-center gap-2">
                  <input type="checkbox" checked={form.allowUnresolved} onChange={(event) => update({ allowUnresolved: event.target.checked })} />
                  Save without an FDA match
                </label>
              </div>
              {searchOpen && (
                <NameSearch onPick={(candidate) => { changeNdc(candidate.ndc_10); setSearchOpen(false) }} />
              )}
              {form.allowUnresolved && (
                <Field label="Drug name as printed" field="drug_as_printed" errors={errors}>
                  <Input value={form.drugAsPrinted} onChange={(event) => update({ drugAsPrinted: event.target.value })} placeholder="atorvastatin 20mg tab" />
                </Field>
              )}
            </div>
          )}
        </div>

        <div className="grid gap-3 md:grid-cols-2">
          <Field label="Medication" field="medication_id" errors={errors} className="md:col-span-2">
            <select
              value={form.medicationId}
              onChange={(event) => update({ medicationId: event.target.value })}
              className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm"
            >
              <option value="">Match or create automatically</option>
              {medications.map((medication) => <option key={medication.id} value={String(medication.id)}>{medication.name}</option>)}
            </select>
          </Field>
          <Field label="Fill date *" field="filled_on" errors={errors}>
            <Input type="date" value={form.filledOn} onChange={(event) => update({ filledOn: event.target.value })} />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Quantity *" field="quantity" errors={errors}>
              <Input inputMode="decimal" value={form.quantity} onChange={(event) => update({ quantity: event.target.value })} placeholder="90" />
            </Field>
            <Field label="Unit" field="quantity_unit" errors={errors}>
              <Input value={form.quantityUnit} onChange={(event) => update({ quantityUnit: event.target.value })} placeholder="tablet" />
            </Field>
          </div>
          <Field label="Paid ($)" field="paid_cents" errors={errors}>
            <Input inputMode="decimal" value={form.paid} onChange={(event) => update({ paid: event.target.value })} placeholder="10.00" />
          </Field>
          <Field label="Retail price ($)" field="retail_cents" errors={errors}>
            <Input inputMode="decimal" value={form.retail} onChange={(event) => update({ retail: event.target.value })} placeholder="189.99" />
          </Field>
          <Field label="Pharmacy" field="pharmacy_name" errors={errors}>
            <Input value={form.pharmacyName} onChange={(event) => update({ pharmacyName: event.target.value })} />
          </Field>
          <Field label="Pharmacy location" field="pharmacy_location" errors={errors}>
            <Input value={form.pharmacyLocation} onChange={(event) => update({ pharmacyLocation: event.target.value })} placeholder="#1234, Seattle WA" />
          </Field>
          <Field label="Days supply" field="days_supply" errors={errors}>
            <Input inputMode="numeric" value={form.daysSupply} onChange={(event) => update({ daysSupply: event.target.value })} />
          </Field>
          <Field label="Refills remaining" field="refills_remaining" errors={errors}>
            <Input inputMode="numeric" value={form.refillsRemaining} onChange={(event) => update({ refillsRemaining: event.target.value })} />
          </Field>
          <Field label="Rx number" field="rx_number" errors={errors}>
            <Input value={form.rxNumber} onChange={(event) => update({ rxNumber: event.target.value })} />
          </Field>
          <Field label="Prescriber" field="prescriber_name" errors={errors}>
            <Input value={form.prescriberName} onChange={(event) => update({ prescriberName: event.target.value })} />
          </Field>
          <Field label="Directions" field="directions" errors={errors} className="md:col-span-2">
            <Textarea value={form.directions} onChange={(event) => update({ directions: event.target.value })} placeholder="take 1 tablet by mouth daily" />
          </Field>
        </div>

        {conflict && (
          <div role="alert" className="flex flex-wrap items-center gap-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            <span>{FILL_CONFLICT_MESSAGE}</span>
            {onReload && <Button type="button" size="sm" variant="outline" onClick={onReload}>Reload</Button>}
          </div>
        )}
        {formError && <p role="alert" className="text-sm text-destructive">{formError}</p>}
        <div className="flex gap-2">
          <Button type="submit" size="sm" disabled={saving}>{saving ? 'Saving…' : editing ? 'Save changes' : 'Save fill'}</Button>
          <Button type="button" variant="outline" size="sm" disabled={saving} onClick={onCancel}>Cancel</Button>
        </div>
      </form>
    </div>
  )
}
