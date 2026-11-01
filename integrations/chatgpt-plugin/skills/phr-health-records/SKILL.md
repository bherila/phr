---
name: phr-health-records
description: Use when the user asks about their personal health records in PHR - patients, visits, medications, prescription fills, conditions, allergies, labs, vitals, immunizations, procedures, documents, imaging, health logs, or imports - or wants to add or correct a record.
---

# Working with PHR

PHR holds health records for one or more patients the user can access. Every
tool acts through the user's own PHR permissions; a missing tool means the
connection was not granted that permission, not that the data is absent.

## Find the patient first

1. Call `identity.get`, then `patients.list`.
2. Use only a patient ID that PHR returned. Never infer one from a name or an
   earlier conversation. Confirm it with `patients.get` before reading or
   writing anything else.

## Reading records

- `records.search` and `timeline.list` search across clinical types.
- `clinical.list` and `clinical.get` read one type, chosen by `resource_type`
  (`office-visits`, `procedures`, `immunizations`, `medications`, `medication-fills`,
  `conditions`, `allergies`, `lab-results`, `vitals`, `health-logs`).
- `eobs.get`, `eob_lines.list` and `eob_lines.get` read EOBs and their lines.
- `documents.list` and `documents.get` return metadata only. Call
  `documents.download_access.create` only when the user needs the file itself.

## Writing records

Read the patient's existing records before every write.

- **Adding records you are syncing from a source:** give each one a stable,
  deterministic `external_id`. Call `clinical.resolve` first to see which
  external IDs already exist, then the type's `<type>.upsert` tool. Repeating an
  upsert with the same `external_id` updates instead of duplicating.
- **Correcting an existing record** (including one a person entered): read it,
  then call the type's `<type>.update` tool with its record ID and the current
  `version` you just read. A conflict means it changed; read it again.
- **Retracting:** use `clinical.retract` only when the source is taking back a
  claim it made in error. A record missing from a newer export is not a reason
  to retract; institutions age data out.
- Every effective change returns the record to human review. Keep records
  `pending_review` unless the user explicitly approves the clinical facts.

## Getting a file into PHR

For photos and PDFs prefer a signed upload: `documents.upload_access_create`
(`kind` is `eob`, `pharmacy_receipt`, `rx_label`, `statement` or `other`, plus
`filename`, `content_type`, the exact `byte_size`, and `sha256` if you have it),
then PUT the raw file to the returned `url` with the returned headers and no
Authorization header (it expires in minutes), then
`documents.upload_finalize`, which verifies size and hash and returns
`document_id`. Use `documents.upload` only for small files. PHR runs no AI on a
document for you: you read every label, receipt, statement and every EOB that
`eobs.import` cannot read, and write what it shows with the upserts, with the
document as `source_document_id`.

## Prescription fills

To import fills from a label photo, receipt, pharmacy statement or CSV, get the
file into PHR first (above). Find the patient with `patients.list` using the name
(and date of birth) printed on it; if none or several match, ask the user, and
never create a patient. Read the NDC and call `ndc.lookup`, or `ndc.search` by
drug name, strength, form, route, labeler and package size when it is missing or
unreadable; ask the user to choose among several candidates. Call
`medication_fills.list`, then `medication_fills.upsert` with the NDC (or the
chosen `ndc_candidate`), the fill, price, pharmacy and source, one fill per
receipt or statement line. Never send drug fields: the server resolves them from
the NDC and links or creates the medication for review. Use
`medication_fills.update` with the current version to correct one.

A label, a receipt and an EOB line for the same fill are one fill. Send what each
source shows; the server matches on pharmacy, Rx number and fill date, or
otherwise on fill date, drug, pharmacy and quantity, adds the new source as
evidence, and flags an uncertain match (`review_flags`) for the person rather than
duplicating it. Do not merge fills yourself.

## EOBs

Upload the EOB (above), then `eobs.import` with the `document_id`: PHR reads
Meritain EOB PDFs and Delta Dental claim CSVs itself, idempotently. If it answers
`unrecognised_format`, read the document, call `eobs.list`, then `eobs.upsert`
with the claim, its lines and `source_document_id`; a claim PHR already holds
comes back unchanged. For a pharmacy claim, give each line its `fill` in
`eobs.upsert`, or after `eobs.import` read the lines with `eob_lines.list` and
call `medication_fills.upsert` with each line as `eob_line_id`, so the fill is
linked to the EOB line that adjudicated it. Correct your own claim with
`eobs.update` and its current `version`. `evidence.links` shows the links
between a fill, its EOB line and its documents.

## Imports

Inspect a staged proposal with `imports.get` and compare it with existing
records before proposing anything. Present a summary and wait for the user's
explicit approval before calling `imports.review`.
