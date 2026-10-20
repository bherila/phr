# `phr-native-v1` archive contract

`phr-native-v1` is the application-native, lossless backup format for one patient. It is
separate from the existing FHIR, C-CDA, PDF, and ZIP exports, whose interoperability
contracts are unchanged. Version 1 is a Zip64 archive containing:

- `manifest.json`, with the schema version, table counts and hashes, artifact metadata,
  and documented exclusions;
- one canonical NDJSON file under `records/` for every included catalog table; and
- opaque artifact entries under `artifacts/` for source documents, authoritative
  DICOM/DICOMDIR files, and preserved EHI package members.

The manifest and record files may be deflated. Artifact entries are stored without
compression because DICOM and many uploaded formats are already compressed. Archive
creation streams artifact bytes from their configured Laravel disks into a private local
temporary file; it does not materialize those bytes in PHP memory. Zip64 removes the
classic ZIP 4 GiB and entry-count limits. A configurable 20 GiB default ceiling applies
to uncompressed record and artifact bytes and fails closed before output is retained.

## Stable identities

Every exported row and referenced actor has a durable, opaque UUID assigned in
`phr_native_record_identities`. Most editable clinical rows do not have an immutable
natural key. Deriving identity from mutable content would make an edit look like a new
record, while deriving it from content shared by duplicate observations could collapse
two real rows. Database IDs are not portable and must not be exposed. Persisting an
opaque UUID avoids all three failures: it survives edits and re-backups, and the
test-only reader recreates the mapping beside a restored row whose database ID changed.

For patients, documents and EOBs the identity is also the record's agent-API
`public_id`, so it is created when the record is written (or, for a row written
without model events, the first time the API returns it) rather than at the first
backup, and it is unique per record type across the installation. A restore preview
compares only the rows the archive contains: a record written after the archive keeps
its own identity and stays outside the restore's conflict set.

## Artifacts

Each manifest artifact names its `kind`, the `recordNativeId` of the row that owns it,
its `size`, and its `sha256`. The entry path is fixed by kind and owner, and the reader
checks size and digest against both the streamed bytes and the owning row:

| Kind | Owning table | Entry path | Checked against |
|---|---|---|---|
| `document` | `phr_documents` | `artifacts/documents/{nativeId}.blob` | `byte_size`, `file_hash` when set |
| `dicom`, `dicomdir` | `phr_dicom_files` | `artifacts/dicom/{nativeId}.blob` | `file_size_bytes`, `sha256` |
| `ehi` | `phr_ehi_blobs` | `artifacts/ehi/{nativeId}.blob` | `byte_size`, `sha256` |

Every file-backed document, original DICOM file, and EHI blob row must have its
artifact. An EHI blob is content-addressed within its patient: one artifact serves every
package member with those bytes, and restore writes it to the configured EHI disk at
`patients/{id}/ehi/{sha256}`. A new blob whose digest the target patient already holds
under another identity is a `record_conflict`.

## EHI Tables packages

`phr_ehi_sources`, `phr_ehi_packages`, `phr_ehi_blobs`, `phr_ehi_package_members`,
`phr_ehi_table_accounts`, and `phr_ehi_findings` are archived in that order, so every
parent restores before its children. They hold preservation and accounting state only;
no clinical rows are derived from them. Blob storage location and the package intake
lease (`lease_token`, `lease_expires_at`), `checkpoint_json`, and the proposal preview
generations (`preview_generation_started`, `preview_generation_completed`, added with
milestone 2) are operational and excluded; a restored package is never mid-intake or
mid-preview.

The evidence and review tables (#213 milestone 2) follow, after the clinical tables they
link to: `phr_ehi_entities`, `phr_ehi_revisions`, `phr_ehi_occurrences`,
`phr_ehi_proposals`, `phr_ehi_proposal_revisions`, `phr_ehi_proposal_decisions`, and
`phr_ehi_record_links`. Every foreign key is a declared relationship, so restored record
links, the revisions each proposal relies on, and decision history point at the restored
rows. A proposal's `target_id` and `candidate_ids_json` are preview-time database ids
kept verbatim, and its `preview_generation` is excluded, so a restored pending proposal
belongs to no completed preview: review refuses it (`preview_incomplete`) until a new
preview re-stamps or rewrites it, re-verifying its target. Projection resume state lives
in the excluded `checkpoint_json`, so the first preview after a restore re-projects,
converging on the restored rows.

## Archives older than a table

Schema version 1 stays 1 when a table is added. The reader requires the manifest's
table set to equal the catalog, except that a table generation added after v1 shipped
(`PhrNativeTableCompatibility::LATER_GENERATIONS`: today `ehi_tables`, the six EHI
preservation tables, then `ehi_evidence`, the seven evidence and review tables) may be
wholly absent, in which case it reads as empty. Generations are ordered: an archive may
lack a generation only if it also lacks every later one, so pre-EHI and M1-era archives
restore. Part of a generation, a later generation without an earlier one, a missing
original v1 table, or an unknown table still fails closed as `invalid_archive`.

Columns follow the same idea. A record may lack `deleted_at`/`retracted_at`, and the
nullable columns listed per table in `PhrNativeLifecycleCompatibility::TABLE_COLUMNS`
(today the six EHI immunization provenance columns on `phr_immunizations`). Absent ones
are read as null only after the stored content hash has been verified against the
attributes actually written; any other attribute difference fails closed.

## Deliberate boundaries

- Soft-deleted documents are included with `deleted_at` and any source bytes preserved.
- Generated interoperability exports and native backup rows are excluded as reproducible
  operational output.
- Derived DICOM volume caches are excluded because they can be rebuilt from original
  DICOM data.
- Storage keys and operational import/failure details are excluded and regenerated by a
  reader.
- Access-grant level and timestamps are retained with opaque actor references. Names,
  email addresses, and source user IDs are not present, and a future production restore
  must present grants for review rather than applying them automatically.
- Ready archives expire after seven days and are removed by the scheduled native-backup
  pruner.

The executable source of truth for included tables, relationships, row policies, and
exclusion reasons is `PhrNativeBackupCatalog`. Its coverage tests walk migration foreign
keys and link artifact-bearing tables back to `PhrStorageMap` so schema drift fails CI.
Production restore is intentionally not part of version 1 backup generation; the
test-only reader proves that the archive is sufficient to reconstruct the graph.
