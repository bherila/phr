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
| `started_at`, `ended_at` | An order's times, beside `started_on` / `ended_on`, when the source gives a time. The end that has passed ends the medication: `ended_at` when there is one, so an order ending this evening is active until then, else `ended_on` from its first moment. |
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
a `boundsDuration` in days. A request whose dosage is only text ("Take two tablets every
evening": no dose, route, timing or rate) keeps the text as its `frequency`, which the
export's dosage text reads back. A statement's `category` is read whether it is sent as
R4's single CodeableConcept or as a list, and so is a request's. A request's `encounter`
stays in `raw_text`, which keeps the
resource as sent, for the visit link (#411). Statuses map to the app's (`stopped`,
`cancelled` and `not-taken` are `discontinued`, `on-hold` and `draft` `on_hold`);
`entered-in-error` is skipped.

US Core sends a home medication as both a statement and a request with category
community. They have different ids, so in one bundle:

- a statement that is `basedOn` or `derivedFrom` the request, and names no other request
  that no other statement names, is the same medication: one row, the statement's, with
  what the request adds where the statement says nothing (RxNorm, prescriber, route,
  as-needed), and `raw_text` holding both resources. These explicit links are resolved
  across the whole bundle first, so a request earlier in the bundle with the same RxNorm
  code never takes the statement from the request it names;
- among the statements and requests no link names, a statement and a request that alone
  share an RxNorm code are the same medication in the same way;
- a request that only may be one (named by a statement that names several, one of several
  named by a statement, one of several sharing a code, or the same name without a shared
  code) is written as its own row with `review_flags` `possible_duplicate` naming the
  statement, for a person to keep one and reject the other;
- a statement or request whose counterpart of the other kind was imported from another
  bundle, under the same name, is flagged the same way.

**Export.** Inpatient, outpatient and discharge medications are `MedicationRequest`s,
`intent` `order`, with their `medicationrequest-category`; `on_hold` is `on-hold`,
`discontinued` `stopped`; the dates or times are the dosage's `boundsPeriod` and the start
the `authoredOn`; the rate is `dosageInstruction.doseAndRate.rateQuantity` (UCUM) when it
is a number and a unit, else only in the dosage text. A home medication stays a
`MedicationStatement`, with the statement system's `community` as its one `category`
CodeableConcept (R4's 0..1; a request's `category` is a list), and an unknown one has no
category. An as-needed medication carries `asNeededCodeableConcept` with its reason, or
`asNeededBoolean`.

## Medication transitions (#414)

`phr_medication_transitions` records what happened to each home medication during a stay
(it is not the EOB `reconciliations.*` tools). A row names its visit, a phase
(`admission` or `discharge`) and an action:

| action | home medication | order |
| --- | --- | --- |
| `continued`, `changed` | required | required |
| `held`, `stopped` | required | none |
| `new` | none | required |

The home medication is on the home list (`community`, or no category); the order is an
`inpatient` medication at admission and a `discharge` medication at discharge. Every record
named is the transition's own patient's. The database checks the action against the
columns (a check constraint; triggers on SQLite) and the patient (composite foreign keys on
`(id, patient_id)`); the model checks the settings and that the named records still stand
before any write that changes them. Transitions are clinical records: `review_status`,
retraction, soft delete, provenance, and the Review page, where each opens its visit.

REST and MCP write them with `medication_transitions.upsert` (idempotent by
`external_id`), read them with `clinical.list` (`office_visit_id` filters by stay) and
`clinical.get`, and `office_visits.get_stay` shows them grouped: `home_medications` is each
home medication a transition of the stay names, with its transitions (admission first), and
`new_medications` each order a `new` transition names. They are not in `records.search` or
`changes.list`: a transition is read with its stay, and those keep their `resource_type`
bound; a sync reads them with `clinical.list` and `updated_after`. A transition is never
inferred from similar names.

**FHIR export.** Only a confirmed, standing transition whose visit and medications the
export also carries is exported; one naming a record it leaves out is dropped. R4
`MedicationRequest.priorPrescription` and `basedOn` reference only a `MedicationRequest`
(or a CarePlan, ServiceRequest or ImmunizationRecommendation), and a home medication is a
`MedicationStatement`, so neither can name it. The transition is the complex extension
`urn:phr:fhir:medication-transition` (sub-extensions `id`, `phase`, `action`, `encounter`,
`homeMedication` and `note`), on the order's `MedicationRequest` for `continued`,
`changed` and `new`, and on the home medication's `MedicationStatement` for `held` and
`stopped`. An order that continues or changes a home medication also names it in
`supportingInformation` (Reference(Any)), so a reader that ignores the extension still sees
the two are related.

**FHIR import.** The extension is read back as the same transitions. From another source,
a `MedicationRequest` whose `priorPrescription` or `basedOn` names a `MedicationRequest`
imported as a home medication is a transition: at admission for an `inpatient` order and at
discharge for a `discharge` one, in the one stay its `encounter` names, `continued` when the
two state the same drug (RxNorm code, else name), dose, route and frequency and `changed`
otherwise. A link whose stay or medication is not imported from the bundle is skipped and
counted in a warning. Imported transitions wait for review.

**C-CDA.** None. A Discharge Medications entry does not reference the home medication it
continues by an id the importer could follow, and matching by name would be inference, so
a C-CDA import writes no transitions; a person (or an agent reading the document) records
them.

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
