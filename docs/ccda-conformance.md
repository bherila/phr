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

The export is not yet fully C-CDA v5 conformant when it contains clinical rows. Eight
entries-required CCD sections still contain the existing human-readable narrative table
without the corresponding structured entry:

- Allergies: Allergy Concern Act
- Encounters: Encounter Activity
- Immunizations: Immunization Activity
- Medications: Medication Activity
- Problems: Problem Concern Act
- Procedures: Procedure Activity Procedure
- Results: Result Organizer
- Vital Signs: Vital Signs Organizer or Average Blood Pressure Organizer

Those are the exact expected Schematron errors in the validation gate. Any additional,
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

The lightweight Schematron engine cannot resolve two generated union-context value-set
assertions (`CDANullFlavor` and `HL7BasicConfidentialityKind`). Both ignored assertions
are frozen by pattern, rule, and description; any new ignored assertion also fails CI.
HL7's broader validation guidance is available on the official
[C-CDA validation page](https://hl7.org/cda/us/ccda/validation.html).
