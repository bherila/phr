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

## Routing a section

A title is a vendor's wording, and a discharge summary's titles share words with the
sections PHR reads: "Hospital Discharge Medications" says "medication", "Procedure
Findings" says "procedure", "Reason for Visit" says "visit". So a section is routed by
what it is coded as (`PhrCcdaSectionCatalog`):

1. its template, then its LOINC section code, names the reader of a section PHR reads;
2. a template or code of a known section no reader reads (hospital medication lists,
   procedure-note sections, Reason for Visit, Encounter Diagnosis, Plan of Treatment,
   Social History) routes it to nothing: its entries, or else its table rows, are
   counted as `skipped` and never read by title;
3. only a section coded as nothing the catalog knows is matched by title: the exact
   title of a known unread section first ("Discharge Medications", "Reason for
   Visit"), then title words in this order: vital, results, medication, problem or
   condition, procedure, immunization, allergy, encounter or visit.

A section coded as something the catalog does not list is still matched by title.

| Section | LOINC | Entry | Reader |
|---|---|---|---|
| Results | 30954-2 | Result Observation | `PhrCcdaResultObservations` |
| Vital Signs | 8716-3 | Vital Sign Observation | `PhrCcdaVitalSignObservations` |
| Medications | 10160-0 | Medication Activity | `PhrCcdaMedicationActivities` |
| Problems | 11450-4 | Problem Observation (in a Problem Concern Act) | `PhrCcdaProblemObservations` |
| Procedures | 47519-4 | Procedure Activity Procedure, Observation or Act | `PhrCcdaProcedureActivities` |
| Immunizations | 11369-6 | Immunization Activity | `PhrCcdaImmunizationActivities` |
| Allergies | 48765-2 | Allergy - Intolerance Observation (in an Allergy Concern Act) | `PhrCcdaAllergyObservations` |
| Encounters | 46240-8 | Encounter Activity | `PhrCcdaEncounterActivities` |

Each section's fixture under `tests/Fixtures/ccda/` lists the entry shapes it
covers, and its test (`tests/Feature/PHR/PhrCcda*ImportTest.php`) the value every
field takes. Statuses are mapped to the app's own vocabularies (for example a
Medication Activity's `aborted` is `discontinued`, a Procedure Activity's `active`
is `in_progress`).

An Encounter Activity also gives the visit its ActCode class (from its code or a
translation), its state (`statusCode` `active` is `in-progress`, `completed` is `finished`),
its `sdtc:dischargeDispositionCode` (a NUBC status as the FHIR disposition of the same
meaning, with the source's words) and its diagnoses by name. With both a service location
and a performing organization, the location is the unit and the organization the facility.

Its performers are the visit's care team: each assigned person, with the name's suffixes
as credentials, the role its `sdtc:functionCode` states (an HL7 v3 ParticipationType code,
or the ParticipationFunction attending, admitting or discharging physician code; `PPRF`
beside another code marks a primary member), the code's original text as the role's label,
`sdtc:specialty` (else the assigned entity's code) as the specialty and `time` as the
period. A performer with no function code is a participant (`PART`). The visit's provider
is the primary attending, else the first performer as before.

## The document's own encounter

A single-encounter document (a discharge summary, a visit note) names its encounter in
the header, `componentOf/encompassingEncounter`. It is a visit: its class, period,
responsible party (else attending), `healthCareFacility` location as the unit and service
provider organization as the facility, admission referral source text and discharge
disposition. Its `encounterParticipant`s are the care team, each in the role its
`typeCode` names (ADM, ATND, CON, DIS; a referrer is a participant). An Encounter Activity
with the same id is the same visit: the activity's own fields stand and the header fills
only what it leaves unsaid, so the stay is never imported twice; the header's participants
join the activity's performers.

The lab results, vitals, procedures and medications read from such a document are linked
to that visit (#411; `PhrEncounterLinks`). Three limits keep the link honest: a CCD
(`2.16.840.1.113883.10.20.22.1.2`) links nothing, since its header encounter is when a
longitudinal summary was made, not where its history was recorded; a result, vital or
procedure the document dates more than a day outside the encounter's period is not linked
(the import warns with a count); and a record already linked to another encounter keeps
it. A link is evidence: it changes neither record's version nor its review status.

## Hospital medication lists

Admission Medications (42346-7), Medications Administered (29549-3) and Hospital
Discharge Medications (10183-2) are a stay's orders and administrations, not the
patient's home medications. PHR has no medication setting to keep them apart yet
(#413), so they are skipped and counted. A Medication Activity inside an Admission or
Discharge Medication act (`4.36`, `4.35`) is skipped wherever its section was routed.

Before #406 their titles made them active home medications. Those rows keep no mark
of the section they came from, so they are found by reading the source again
(`PhrCcdaMisroutedMedications`): the ids the old title routing gave a document's
medication rows, less the ids its own Medications sections give, are rows only the
misrouting made, under their entry ids and, for rows the narrative-table reader
stored, their table-row ids. Every document listing an id writes one row, which links
only to the first of them, so the patient's other stored documents are read before a
row is retracted: one whose own Medications section names the id keeps the row. They
are retracted (`retracted_at`): the C-CDA import withdrawing a
claim it made in error, neither a delete nor a rejection, and their review status is
kept. A row a person confirmed, and a row prescription fills are linked to, are left
as they are and reported for a person to check. A retracted row comes back when a
document's own Medications section names its id.

- **Re-importing** a document does this for rows linked to it, or to no stored
  document. A MyChart ZIP does it once over all its C-CDA members. The import result's
  warnings count what was retracted, restored or left.
- **`phr:ccda:retract-hospital-medications [--dry-run] [--patient=]`** does it for
  documents nobody will import again. Per patient it reads every stored document (an XML
  file or a MyChart ZIP's members; anything else is told apart by its first bytes),
  whichever rows link to it, and retracts the rows linked to those documents. A row imported without a stored document, or
  from one that can no longer be read, cannot be checked: it is counted and left, and
  re-importing its source file cleans it up. Running it again changes nothing.

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
