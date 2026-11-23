# Medication setting (#413)

A medication row says which list it is on, so a hospital stay's orders never read as
the patient's home medications.

## Columns

| Column | Meaning |
|---|---|
| `category` | FHIR `medicationrequest-category`: `community` (the home list), `inpatient` (a hospital stay's order), `outpatient` (a clinic's, an emergency department's), `discharge` (the list the patient went home with). Null is unknown and is never read as `community`. |
| `as_needed`, `as_needed_reason` | Taken only as needed, and for what ("pain", "temperature > 38.0 C"). Null when the source did not say. |
| `brand_name` | The brand; a medication a prescription fill creates takes the fill's. |
| `rate` | A continuous infusion's rate as it reads, "75 mL/h". |
| `started_at`, `ended_at` | An order's times, beside `started_on` / `ended_on`, when the source gives a time. An `ended_at` that has passed ends the medication like `ended_on`. |
| `review_flags` | Why a person should look closely at an imported row: `possible_duplicate` with the `external_id` of the medication it may be. Not writable through the API. |

The migration makes existing rows home medications where they are one: a row a person
entered (no import source), one a prescription fill created (`phr-fill`) and a C-CDA row
the import stands by (not retracted: since #406 only a Medications section writes them).
Every other row stays unknown. `PhrMedicationCategory::backfilled()` is the same rule, and
a native backup written before the column reads its medications by it.

## The home list

The home list is `community` together with unknown. It is what:

- the browser's medication list opens on (Home; Discharge, Hospital & clinic and All lists
  appear once the patient has a medication off the home list), and what the Medications
  tab counts;
- a prescription fill links to: `MedicationFillMedicationLinker` matches and
  `MedicationFillWriter` accepts an explicit `medication_id` only among home medications,
  and a medication a fill creates is `community`;
- the CCD's Medications section lists.

The PDF summary leaves inpatient orders out and labels a discharge or clinic medication.
`records.search`, `timeline.list` and `changes.list` name a medication's list in its
summary ("sodium chloride 0.9% injection (Hospital order)"), and search and timeline take
`medication_category[]` to restrict medications to some lists. The REST and MCP medication
list (`clinical.list`) takes `category[]` (a setting, or `unknown`); without it every
setting is listed, so a client syncing the record still sees every row.

## Identity

The importers' upsert matches on (patient, import source, external id). A row in any
setting but `community` (or unknown) is stored under its source's id scoped by the setting,
`<category>:<id>` (`PhrMedicationCategory::scopedExternalId`), so the same C-CDA activity
in a document's Medications and Discharge Medications sections is two rows and never one
row moved between the lists. A FHIR row an import before #413 stored under the unscoped id,
with no category, is moved onto the scoped one. A C-CDA home row is never adopted onto a
hospital list's scoped id: those are the rows `PhrCcdaMisroutedMedications` retracts.

## FHIR

**Import.** `MedicationStatement` and `MedicationRequest` are both read. A request's
`category` is its setting; a statement's is its own system's (`community`, `inpatient`,
`outpatient`; `patientspecified` is not a setting). From the first dosage: as-needed
(`asNeededBoolean`, or `asNeededCodeableConcept` and its text as the reason), the rate
(`doseAndRate.rateQuantity`, `rateRatio` or `rateRange`), and for a request the dose,
route, timing ("every 6 h", "2 times every 1 d") and `boundsPeriod`, else `authoredOn` and
a `boundsDuration` in days. A request's `encounter` stays in `raw_text`, which keeps the
resource as sent, for the visit link (#411). Statuses map to the app's (`stopped`,
`cancelled` and `not-taken` are `discontinued`, `on-hold` and `draft` `on_hold`);
`entered-in-error` is skipped.

US Core sends a home medication as both a statement and a request with category
community. They have different ids, so in one bundle:

- a statement that is `basedOn` or `derivedFrom` the request, or the only statement with
  the request's RxNorm code, is the same medication: one row, the statement's, with what
  the request adds where the statement says nothing (RxNorm, prescriber, route, as-needed),
  and `raw_text` holding both resources;
- a request that only may be one (the same name without a shared code, or one of several
  statements with its code) is written as its own row with `review_flags`
  `possible_duplicate` naming the statement, for a person to keep one and reject the other;
- a statement or request whose counterpart of the other kind was imported from another
  bundle, under the same name, is flagged the same way.

**Export.** Inpatient, outpatient and discharge medications are `MedicationRequest`s,
`intent` `order`, with their `medicationrequest-category`; `on_hold` is `on-hold`,
`discontinued` `stopped`; the dates or times are the dosage's `boundsPeriod` and the start
the `authoredOn`; the rate is `dosageInstruction.doseAndRate.rateQuantity` (UCUM) when it
is a number and a unit, else only in the dosage text. A home medication stays a
`MedicationStatement`, with the statement system's `community` (an unknown one has no
category). An as-needed medication carries `asNeededCodeableConcept` with its reason, or
`asNeededBoolean`.

## C-CDA

See [ccda-import.md](ccda-import.md#hospital-medication-lists) for import: the Medications
section is the home list and Hospital Discharge Medications the discharge list;
Admission Medications (what the patient took before admission, the home list again under
other ids) and Medications Administered stay skipped, so `inpatient` comes only from a
source that states it, a FHIR category. Precondition
`4.25` is as-needed. On export ([ccda-conformance.md](ccda-conformance.md)) the
Medications section lists the home list, a Discharge Medications section the discharge
list, and inpatient and outpatient orders are omitted.

## EHI (plan, not mapped)

PHR does not map EHI medications yet. When it does, a medication order's setting comes
from `ORDER_MED`'s ordering mode (`ORDERING_MODE_C_NAME`: inpatient orders are
`inpatient`, outpatient orders `outpatient` or, for a prescription the patient fills,
`community`), checked against the package's own data dictionary before the mapping ships,
since the category list is the vendor's. The mapping gets its own `VERSION`, and an order's
row its scoped id like any non-community row.
