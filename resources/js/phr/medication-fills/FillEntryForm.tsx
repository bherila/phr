import { Plus, Search } from 'lucide-react'
import { type FormEvent, useEffect, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { fetchWrapper } from '@/fetchWrapper'
import { cn } from '@/lib/utils'
import { useLowercaseClass } from '@/phr/display/DisplayPreferences'
import { reviewStateChanged } from '@/phr/review/reviewSummary'
import { errorMessage } from '@/phr/shared'

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

/** Ten or eleven digits, with or without dashes or spaces. */
export function isLookupableNdc(value: string): boolean {
  if (!/^[\d\s-]+$/.test(value.trim())) return false
  const digits = value.replace(/\D/g, '').length
  return digits === 10 || digits === 11
}

const textOrNull = (value: string): string | null => (value.trim() === '' ? null : value.trim())
const intOrNull = (value: string): number | null => (value.trim() === '' ? null : Number(value))

/** The create body of the contract. Money is typed in dollars and sent as cents; drug fields are never sent. */
export function buildFillPayload(form: FillFormState): Record<string, unknown> {
  return {
    ...(form.ndc.trim() ? { ndc: form.ndc.trim() } : {}),
    ...(form.ndcCandidate ? { ndc_candidate: form.ndcCandidate } : {}),
    ...(form.allowUnresolved ? { allow_unresolved: true } : {}),
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
    medication_id: null,
  }
}

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
  const name = drug.substance_names.join(' / ')
  const rows: Array<[string, string | null]> = [
    ['Brand', drug.brand_name],
    ['Strength', drug.strengths.join(' / ') || null],
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
            <dd className="inline text-card-foreground">{value}</dd>
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
              {candidate.drug?.package_description && <span>{candidate.drug.package_description}</span>}
              {candidate.drug?.labeler_name && <span className="text-muted-foreground">{candidate.drug.labeler_name}</span>}
              {candidate.drug && (candidate.drug.substance_names.length > 0 || candidate.drug.strengths.length > 0) && (
                <span className="text-muted-foreground">{[candidate.drug.substance_names.join(' / '), candidate.drug.strengths.join(' / ')].filter(Boolean).join(' ')}</span>
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

interface FillEntryFormProps {
  patientId: number
  /** Called with the saved fill once the server accepts it. */
  onCreated: (fill: MedicationFill) => void
}

export function FillEntryForm({ patientId, onCreated }: FillEntryFormProps) {
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<FillFormState>(EMPTY_FORM)
  const [lookup, setLookup] = useState<LookupState>({ phase: 'idle' })
  const [searchOpen, setSearchOpen] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const lookupSeqRef = useRef(0)

  const update = (patch: Partial<FillFormState>) => setForm((current) => ({ ...current, ...patch }))

  // The lookup runs by itself once the NDC is a plausible 10 or 11 digits.
  useEffect(() => {
    if (!isLookupableNdc(form.ndc)) {
      lookupSeqRef.current += 1
      setLookup({ phase: 'idle' })
      return
    }
    const seq = ++lookupSeqRef.current
    setLookup({ phase: 'loading' })
    fetchWrapper.get(`/api/ndc/lookup?ndc=${encodeURIComponent(form.ndc.trim())}`).then((raw: unknown) => {
      if (seq === lookupSeqRef.current) setLookup({ phase: 'done', result: NdcLookupResultSchema.parse(raw) })
    }).catch((caught: unknown) => {
      if (seq === lookupSeqRef.current) setLookup({ phase: 'error', message: errorMessage(caught) })
    })
  }, [form.ndc])

  function changeNdc(value: string): void {
    update({ ndc: value, ndcCandidate: '', allowUnresolved: false })
  }

  const result = lookup.phase === 'done' ? lookup.result : null

  async function submit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    setFormError(null)
    const local: FieldErrors = {}
    if (form.ndc.trim() !== '' && !isLookupableNdc(form.ndc)) local['ndc'] = ['Enter a 10 or 11 digit NDC.']
    if (form.ndc.trim() === '' && form.drugAsPrinted.trim() === '') local['drug_as_printed'] = ['Enter an NDC, or the drug name as printed.']
    if (!form.filledOn) local['filled_on'] = ['Enter the fill date.']
    if (form.quantity.trim() === '') local['quantity'] = ['Enter the quantity.']
    for (const [field, value] of [['paid_cents', form.paid], ['retail_cents', form.retail]] as const) {
      if (Number.isNaN(dollarsToCents(value))) local[field] = ['Enter a dollar amount such as 18.99.']
    }
    if (result?.status === 'ambiguous' && !form.ndcCandidate) local['ndc_candidate'] = ['Choose one of the matching packages'] 
    if (result?.status === 'unresolved' && !form.allowUnresolved) local['ndc'] = ['No FDA listing matches this NDC. Search by name, or choose to save it without a match.']
    setErrors(local)
    if (Object.keys(local).length > 0) return

    setSaving(true)
    try {
      const response = await fetchWrapper.postRaw(`/api/phr/patients/${patientId}/medication-fills`, buildFillPayload(form))
      const text = await response.text()
      let body: unknown = null
      try {
        body = text ? JSON.parse(text) : null
      } catch {
        body = null
      }
      if (response.status === 422) {
        const payload = (body ?? {}) as { message?: string, errors?: FieldErrors }
        setErrors(payload.errors ?? {})
        setFormError(payload.message ?? 'Please correct the highlighted fields.')
        return
      }
      if (!response.ok) {
        const payload = (body ?? {}) as { message?: string, error?: string }
        setFormError(payload.message ?? payload.error ?? response.statusText ?? 'Request failed.')
        return
      }
      void reviewStateChanged(patientId)
      onCreated(MedicationFillResponseSchema.parse(body).medication_fill)
      setForm(EMPTY_FORM)
      setErrors({})
      setSearchOpen(false)
      setOpen(false)
    } catch (caught) {
      setFormError(errorMessage(caught))
    } finally {
      setSaving(false)
    }
  }

  if (!open) {
    return (
      <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
        <Plus className="size-4" />
        Add Fill
      </Button>
    )
  }

  const selectedCandidate = result?.status === 'ambiguous'
    ? result.candidates.find((candidate) => candidate.ndc_10 === form.ndcCandidate) ?? null
    : result?.selected ?? null

  return (
    <div className="w-full rounded-lg border border-border bg-card p-4">
      <h2 className="mb-3 text-sm font-semibold text-card-foreground">Add Fill</h2>
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

        {formError && <p role="alert" className="text-sm text-destructive">{formError}</p>}
        <div className="flex gap-2">
          <Button type="submit" size="sm" disabled={saving}>{saving ? 'Saving…' : 'Save fill'}</Button>
          <Button type="button" variant="outline" size="sm" disabled={saving} onClick={() => setOpen(false)}>Cancel</Button>
        </div>
      </form>
    </div>
  )
}
