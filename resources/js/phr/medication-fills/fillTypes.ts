import { z } from 'zod'

import { PhrReviewStatusSchema } from '@/phr/types'

/**
 * Medication fills (phr#265): the wire shapes and the display formatting rules.
 * Contract: docs/medication-fills-contract.md on the backend branch.
 */
const nullableString = z.string().nullable().optional().transform((value) => value ?? null)
const nullableNumber = z.number().nullable().optional().transform((value) => value ?? null)
const stringList = z.array(z.string()).nullable().optional().transform((value) => value ?? [])

export const NdcDrugSchema = z.object({
  substance_names: stringList,
  strengths: stringList,
  brand_name: nullableString,
  dosage_form: nullableString,
  route: nullableString,
  labeler_name: nullableString,
  package_description: nullableString,
  rxcui: nullableString,
})
export type NdcDrug = z.infer<typeof NdcDrugSchema>

export const NdcCandidateSchema = z.object({
  ndc_10: z.string(),
  ndc_11: nullableString,
  product_ndc: nullableString,
  drug: NdcDrugSchema.nullable().optional().transform((value) => value ?? null),
  match_reasons: stringList,
  fetched_at: nullableString,
  /** openfda, or rxnav for a product openFDA no longer lists. */
  ndc_source: nullableString,
  /** The product is off the market, as RxNav's history says. */
  discontinued: z.boolean().nullable().optional().transform((value) => value ?? false),
})
export type NdcCandidate = z.infer<typeof NdcCandidateSchema>

export const NdcLookupResultSchema = z.object({
  status: z.enum(['resolved', 'ambiguous', 'unresolved']),
  input: nullableString,
  selected: NdcCandidateSchema.nullable().optional().transform((value) => value ?? null),
  candidates: z.array(NdcCandidateSchema).nullable().optional().transform((value) => value ?? []),
  reason: nullableString,
  next_step: nullableString,
})
export type NdcLookupResult = z.infer<typeof NdcLookupResultSchema>

export const NdcSearchResultSchema = z.object({
  candidates: z.array(NdcCandidateSchema),
})

/** Captured text with default display casing, derived by the server. Render these, never the raw fields. */
export const FillDisplaySchema = z.object({
  drug_as_printed: nullableString,
  pharmacy_name: nullableString,
  pharmacy_location: nullableString,
  prescriber_name: nullableString,
  directions: nullableString,
})
export type FillDisplay = z.infer<typeof FillDisplaySchema>

export const MedicationFillSchema = z.object({
  id: z.number(),
  patient_id: z.number(),
  user_id: z.number().optional(),
  medication_id: nullableNumber,
  medication: z.object({ id: z.number(), name: z.string() }).nullable().optional().transform((value) => value ?? null),
  version: nullableString,
  source_kind: nullableString,
  review_status: PhrReviewStatusSchema.optional(),
  source_document_id: nullableNumber,
  /** Other documents that show the same fill (receipts, labels). */
  evidence_document_ids: z.array(z.number()).nullable().optional().transform((value) => value ?? []),
  ndc_as_captured: nullableString,
  ndc_10: nullableString,
  ndc_11: nullableString,
  ndc_status: z.string().nullable().optional().transform((value) => value ?? null),
  ndc_source: nullableString,
  discontinued: z.boolean().nullable().optional().transform((value) => value ?? false),
  drug: NdcDrugSchema.nullable().optional().transform((value) => value ?? null),
  drug_as_printed: nullableString,
  filled_on: z.string(),
  quantity: z.union([z.string(), z.number()]).nullable().optional().transform((value) => (value === null || value === undefined ? null : String(value))),
  quantity_unit: nullableString,
  days_supply: nullableNumber,
  rx_number: nullableString,
  refills_remaining: nullableNumber,
  directions: nullableString,
  prescriber_name: nullableString,
  paid_cents: nullableNumber,
  retail_cents: nullableNumber,
  pharmacy_name: nullableString,
  pharmacy_location: nullableString,
  display: FillDisplaySchema.nullable().optional().transform((value) => value ?? null),
  /** Why a person should look closely: `possible_duplicate` names the existing fill this one may repeat. */
  review_flags: z.array(z.object({ code: z.string(), message: z.string(), fill_id: z.number().optional() })).nullable().optional().transform((value) => value ?? []),
})
export type MedicationFill = z.infer<typeof MedicationFillSchema>

const linkSchema = z.string().nullable().optional().transform((value) => value ?? null)

/** What a fill's stored FDA/RxNav snapshot says about the drug (GET .../drug-detail). */
export const DrugDetailSchema = z.object({
  ndc_source: nullableString,
  discontinued: z.boolean().nullable().optional().transform((value) => value ?? false),
  ndc_10: nullableString,
  ingredients: z.array(z.object({ name: nullableString, strength: nullableString })).nullable().optional().transform((value) => value ?? []),
  dosage_form: nullableString,
  route: nullableString,
  pharm_class: stringList,
  application_number: nullableString,
  marketing_category: nullableString,
  manufacturer: nullableString,
  package_description: nullableString,
  dea_schedule: nullableString,
  imprint: nullableString,
  color: nullableString,
  shape: nullableString,
  spl_set_id: nullableString,
  links: z.object({ dailymed: linkSchema, ndc_directory: linkSchema }).nullable().optional().transform((value) => value ?? { dailymed: null, ndc_directory: null }),
})
export type DrugDetail = z.infer<typeof DrugDetailSchema>
export const DrugDetailResponseSchema = z.object({ drug_detail: DrugDetailSchema })

/** The FDA drug label, fetched on demand (GET /api/ndc/label). */
export const NdcLabelSchema = z.object({
  status: z.enum(['found', 'not_found', 'unavailable', 'invalid']),
  spl_set_id: nullableString,
  effective_date: nullableString,
  version: nullableString,
  source: nullableString,
  sections: z.array(z.object({ key: z.string(), title: z.string(), text: z.string(), truncated: z.boolean().optional() })).nullable().optional().transform((value) => value ?? []),
})
export type NdcLabel = z.infer<typeof NdcLabelSchema>

export const MedicationFillResponseSchema = z.object({
  medication_fill: MedicationFillSchema,
  can_manage: z.boolean().default(false),
})

export const MedicationFillsResponseSchema = z.object({
  medication_fills: z.array(MedicationFillSchema),
  can_manage: z.boolean().default(false),
})

export const MISSING = '—'

/** A date or ISO timestamp as YYYY-MM-DD. Sliced, never parsed: no timezone shift. */
export function formatFillDate(value: string | null | undefined): string {
  const match = /^\d{4}-\d{2}-\d{2}/.exec(value ?? '')
  return match ? match[0] : MISSING
}

/** Integer cents to $X.XX. Zero is a real price ($0.00); only null is missing. */
export function formatCents(cents: number | null | undefined): string {
  if (cents === null || cents === undefined) return MISSING
  const sign = cents < 0 ? '-' : ''
  const abs = Math.abs(cents)
  return `${sign}$${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`
}

/** The decimal string without trailing zeros: "90.000" is 90. */
export function formatQuantity(quantity: string | null, unit: string | null = null): string {
  if (quantity === null || quantity.trim() === '') return MISSING
  const trimmed = quantity.includes('.') ? quantity.replace(/\.?0+$/, '') : quantity
  return [trimmed || '0', unit].filter(Boolean).join(' ')
}

/** Dollars typed by a person ("18.99", "$1,000") to integer cents; null when blank, NaN when invalid. */
export function dollarsToCents(input: string): number | null {
  const cleaned = input.trim().replace(/^\$/, '').replaceAll(',', '')
  if (cleaned === '') return null
  if (!/^\d+(\.\d{0,2})?$/.test(cleaned)) return Number.NaN
  return Math.round(Number(cleaned) * 100)
}

export function fillDrugName(fill: MedicationFill): string {
  const substances = fill.drug?.substance_names.join('; ')
  // A medication created from the printed text carries that text verbatim as its name: show the derived form.
  const linked = fill.medication?.name === fill.drug_as_printed ? (fill.display?.drug_as_printed ?? fill.medication?.name) : fill.medication?.name
  return substances || linked || fill.display?.drug_as_printed || fill.drug_as_printed || MISSING
}
