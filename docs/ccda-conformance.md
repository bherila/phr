# C-CDA export conformance

## Target profile

The `ccda.xml` export targets the C-CDA 5.0 **Continuity of Care Document (CCD)**
profile (`2.16.840.1.113883.10.20.22.1.2:2024-05-01`) with both the US Realm Header
and Patient Generated Document Header. CCD is the right document-level profile for a
longitudinal patient summary. The patient-generated template is an additional header
that identifies the app-authored provenance; [HL7 explicitly says it is not a separate
document type](https://hl7.org/cda/us/ccda/StructureDefinition-USRealmHeaderforPatientGeneratedDocument.html).

C-CDA 5.0 is based on CDA R2.0. CDA R2.1 is retired, so the validation gate uses the
official [CDA R2 core schema](https://github.com/HL7/CDA-core-2.0) plus the generated
[C-CDA 5.0 Schematron](https://github.com/HL7/CDA-ccda/blob/5.0-validation/validation/CCDA-5.0.sch).

## Current boundary

The document is valid against the CDA R2 SDTC XML Schema. Its v5 header, document
template, required section inventory, section template IDs, and LOINC section codes are
explicit. Empty standardized sections carry `nullFlavor="NI"`; the export does not
invent absent demographics, assigning authorities, contact information, or clinical
data. A deployment-local patient database ID is not emitted as an interoperable patient
identifier.

The export is not yet fully C-CDA v5 conformant when it contains clinical rows. Seven
entries-required CCD sections still contain the existing human-readable narrative table
without the corresponding structured entry:

- Allergies: Allergy Concern Act
- Encounters: Encounter Activity
- Immunizations: Immunization Activity
- Medications: Medication Activity
- Problems: Problem Concern Act
- Procedures: Procedure Activity Procedure
- Vital Signs: Vital Signs Organizer or Average Blood Pressure Organizer

Those are the exact expected Schematron errors in the validation gate. The eighth,
Results, is structured (see below) and no longer in the baseline. Any additional,
removed, or changed error fails CI, so a future structured-entry implementation must
update the baseline and this document together. The app-specific Portal Messages,
Negative Assertions, Imaging Studies, and Documents sections remain untemplated CDA
narrative sections rather than claiming an incompatible standard template.

The gate also freezes all current `SHOULD` recommendations. They cover clinical context
the app does not have and must not invent: a legal authenticator, related-person
participant, provider NPI and role codes, service-event performer, marital status,
patient language, smoking status, and plan-of-treatment section. Repeated warnings are
emitted through more than one declared header template; their exact counts are part of
the baseline.

This boundary preserves the established consumer contract: `ccda.xml` remains an
artifact beside FHIR/PDF exports, and existing section titles, populated narrative table
columns, row order, and clinical import behavior remain intact. The changes add standard
header/section metadata and replace schema-invalid empty table bodies with an explicit
"No information available" paragraph.

The Immunizations table appends a trailing Dose column (amount and unit exactly as stored,
e.g. "0.50 mL") only when at least one exported immunization records a dose amount, so
exports without dose data keep the original Vaccine/Date/Lot table byte for byte.

## Results: Result Organizers

The Results section carries structured entries beside its narrative table: Result
Organizers (`2.16.840.1.113883.10.20.22.4.1:2023-05-01`) holding Result Observations
(`2.16.840.1.113883.10.20.22.4.2:2023-05-01`). The table is unchanged (same columns and
row order), each row now has an `ID` (`labresult-N`), and every observation's `text`
references its own row.

- **Organizers** group labs sharing a test name, effective time and status, and the
  organizer's `code` is that test name. A lab with no test name is its own organizer.
- **Ids** are version 5 UUIDs derived from the lab's patient and row identity under a
  namespace keyed by the deployment's application key, so they are never the database key
  itself, two installations' "patient 1, lab 1" do not collide, and they stay the same
  across exports (rotating the application key renames them). The importer keys results on the
  observation id, so importing an export again updates the same rows. A document first
  imported from its narrative table alone is adopted in place by the narrative-row match
  the importer already performs.
- **Codes.** PHR stores no LOINC code for a lab, so each observation and organizer `code`
  is `nullFlavor="UNK"` with the analyte or test name in `originalText`, which the importer
  reads back. This leaves the Schematron's two "SHOULD be a LOINC code" recommendations
  in the baseline; the app does not invent codes.
- **Status** maps to the Result Status value set: `preliminary`, `pending` and similar are
  `active`, `cancelled` and `entered-in-error` are `cancelled`, and anything else a result
  carries (`final`, `corrected`) is `completed`.
- **effectiveTime** is the narrative Date's moment: the result time, else the collection
  time. A value at midnight is written as a day, and a lab with no time is
  `nullFlavor="UNK"`.
- **interpretationCode** is the flag when it is a code of the Observation Interpretation
  value set (`H`, `L`, `HH`, `A`, `N`, ...), compared case-insensitively. Any other flag
  is `nullFlavor="OTH"` with the text as written in `originalText`, never guessed into a
  code. A lab with no flag has none.
- **Value.** A number with a unit is a `PQ`; a comparison with a unit (`<5 mg/dL`,
  `>=60 mL/min`) is an `IVL_PQ` open on one side, with `inclusive` on its bound; anything
  else is an `ST`. Numbers are written as plain decimals (".5" as 0.5, "1,234" as 1234).
  A number with no unit is an `ST`, not a `PQ` with an invented unit.
  A qualitative value with a unit keeps the unit only in the narrative.
- **Reference range** is an `IVL_PQ` in `observationRange/value`, marked `N` (normal).
  `low` and `high` are the bounds in the range's own unit, each with an `inclusive`
  attribute when `range_min_inclusive` / `range_max_inclusive` is known, so a two-sided
  range with an exclusive end and a one-sided range both read exactly. An unknown
  inclusiveness writes no attribute; HL7 would read an absent one as inclusive, which is
  not what unknown means. `observationRange/text` is written only when the source wrote
  range text, so a range kept as bounds is never read back as text. Text with no bounds
  is that text beside a null-flavored `IVL_PQ`.
- **Units that are not a single token.** A `PQ` or bound `unit` attribute cannot contain
  whitespace. Such a unit (for example "thousand per uL") travels as the
  `translation/originalText` of a `nullFlavor="OTH"` quantity, which the importer reads
  back. A result `PQ` written that way lacks the `@unit` the Schematron requires of a
  Result Observation value, so a strict validator flags it. The validation fixture
  keeps that form to reference ranges, where the Schematron does not check units.

The Results table's Reference cell is still the range text the source wrote, or else the
range built from its bounds and their unit ("13.6 - 17.5 g/dL", "<200 mg/dL"). A single
bound the source stated as inclusive reads "<=5 mg/dL" or ">=60 mL/min"; one whose
inclusiveness is unknown keeps the strict comparator. The narrative can show a two-sided
range with an exclusive end only as a span; the `IVL_PQ` carries the `inclusive`
attribute for each end.

Reimporting PHR's own export therefore stores a range as `range_min` / `range_max`, their
inclusiveness and `range_unit`, and `reference_range_text` only where the source wrote
text. Rows another document or source created for the same results are not matched by this
id: a lab first imported from a different document, or entered by hand, is not recognised
as the same result when this export is imported into the same patient.

## Validation gate

Run:

```bash
pnpm run validate:ccda
```

The gate generates a synthetic document at runtime through the real exporter; it never
reads or uploads a patient export, and no clinical-payload fixture is committed. The
generator freezes time and UUID generation so the validation input is deterministic.
The validator downloads official HL7 artifacts from immutable commits into the operating
system's temporary directory and verifies every file by SHA-256 before use:

- CDA core commit `e922fc35586fd2629f0c8a021080bca9ab424e18`
- C-CDA commit `af05b6bde9409182428580148ce41c8b3c4da3ab`
- generated Schematron identifies `@hl7/fhir-cda-validation` 1.2.0

The lightweight Schematron engine cannot resolve generated union-context value-set
assertions: `CDANullFlavor` and `HL7BasicConfidentialityKind` in the header, and the
nullFlavor, status (`ActStatus`, `ResultStatus`), class code and interpretation value
sets on Result Organizers and Result Observations. Each ignored assertion is frozen by
pattern, rule, and description; any new ignored assertion also fails CI. The Result
value sets are checked by `PhrCcdaResultEntriesTest` instead, which checks the emitted
status codes and interpretation codes against the value sets.
HL7's broader validation guidance is available on the official
[C-CDA validation page](https://hl7.org/cda/us/ccda/validation.html).
