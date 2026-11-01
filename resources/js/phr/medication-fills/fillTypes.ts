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
  ndc_as_captured: nullableString,
  ndc_10: nullableString,
  ndc_11: nullableString,
  ndc_status: z.string().nullable().optional().transform((value) => value ?? null),
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

export const MedicationFillResponseSchema = z.object({
  medication_fill: MedicationFillSchema,
})

export const MedicationFillsResponseSchema = z.object({
  medication_fills: z.array(MedicationFillSchema),
  can_manage: z.boolean().default(false),
})

export const MISSING = '—'

/** 2026-10-28 (or an ISO timestamp) becomes 26.10.28. Sliced, never parsed: no timezone shift. */
export function formatFillDate(value: string | null | undefined): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value ?? '')
  return match ? `${match[1]!.slice(2)}.${match[2]}.${match[3]}` : MISSING
}

/** Integer cents to $X.XX. Zero is a real price ($0.00); only null is missing. */
export function formatCents(cents: number | null | undefined): string {
  if (cents === null || cents === undefined) return MISSING
  const sign = cents < 0 ? '-' : ''
  const abs = Math.abs(cents)
  return `${sign}$${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`
}

/** The decimal string without trailing zeros: "90.000" is 90. */
export function formatQuantity(quantity: string | null, unit: string | null): string {
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
