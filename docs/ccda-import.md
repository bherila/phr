# C-CDA import

`PhrCcdaImporter` reads a C-CDA document into the clinical model. Every record it
writes waits for review (`pending_review`); a re-import that leaves a row unchanged
keeps the review it has.

## Structured entries first

A section's narrative table is a rendering. Vendors order its columns differently
("Vital Sign, Reading, Time Taken", "Problem, Noted Date, Diagnosed Date",
"Immunization, Administration Dates, Next Due"), so reading cells by position puts
values in the wrong fields. Each section is therefore read from its structured
entries when it has any, and from its table, by position, only when it has none
(PHR's own export, for one, carries tables without entries).

A section is matched to its reader by its template, and only a section without a
known template by its title. Titles are matched in this order: vital, results,
medication, problem or condition, procedure, immunization, allergy, encounter or
visit.

| Section | Entry | Reader |
|---|---|---|
| Results | Result Observation | `PhrCcdaResultObservations` |
| Vital Signs | Vital Sign Observation | `PhrCcdaVitalSignObservations` |
| Medications | Medication Activity | `PhrCcdaMedicationActivities` |
| Problems | Problem Observation (in a Problem Concern Act) | `PhrCcdaProblemObservations` |
| Procedures | Procedure Activity Procedure, Observation or Act | `PhrCcdaProcedureActivities` |
| Immunizations | Immunization Activity | `PhrCcdaImmunizationActivities` |
| Allergies | Allergy - Intolerance Observation (in an Allergy Concern Act) | `PhrCcdaAllergyObservations` |
| Encounters | Encounter Activity | `PhrCcdaEncounterActivities` |

Each section's fixture under `tests/Fixtures/ccda/` lists the entry shapes it
covers, and its test (`tests/Feature/PHR/PhrCcda*ImportTest.php`) the value every
field takes. Statuses are mapped to the app's own vocabularies (for example a
Medication Activity's `aborted` is `discontinued`, a Procedure Activity's `active`
is `in_progress`).

## Text

Every text that may be held by reference (ED and ST values, `text`,
`originalText`) resolves its narrative `<reference>` first, and no element's whole
text content is concatenated, so a reference-only element never reads as empty and
a value written twice is never stored twice. A name falls back from the code's
display name, to its original text, to a translation's display name, to the
element's own text or name, to the code itself (`codeSystemName|code`).

## Entries that are not records

- **Negated** entries (`negationInd="true"`: no known allergies, medications or
  problems; a vaccine not given; a procedure not done) are negative assertions,
  never records of what they negate.
- **Planned** immunizations, procedures and encounters (any mood but `EVN`) are
  skipped. A medication list's activities are intents (`INT`), so every mood is a
  medication.
- An entry **nothing names** is read from the table row it references, the way the
  narrative-table reader reads it, rather than dropped, so the structured path does
  not import fewer records than that reader.

## Re-import

The upserter matches rows on (patient, import source, external id). Before
importing a section, `PhrCcdaLegacyRows` (`PhrCcdaLegacyLabRows` for results) moves
rows that earlier imports made of the same entries onto the entry's id, so they
update in place instead of being duplicated:

- rows the narrative-table reader made, keyed by a hash of the table row the entry
  references;
- rows an earlier load keyed `<label>:<kind>:<key>`, where the key is the entry id's
  `root|extension` or its extension alone.

Ids are compared byte for byte. When one entry matches several earlier rows, one is
moved and the others are set to pending review for a person to decide; withdrawn
rows are not adopted.
