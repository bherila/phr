---
name: phr-health-records
description: Use when the user asks about their personal health records in PHR - patients, visits, medications, conditions, allergies, labs, vitals, immunizations, procedures, documents, imaging, health logs, or imports - or wants to add or correct a record.
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
  (`office-visits`, `procedures`, `immunizations`, `medications`,
  `conditions`, `allergies`, `lab-results`, `vitals`, `health-logs`).
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

## Imports

Inspect a staged proposal with `imports.get` and compare it with existing
records before proposing anything. Present a summary and wait for the user's
explicit approval before calling `imports.review`.
