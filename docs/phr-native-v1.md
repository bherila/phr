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
restore service recreates the mapping beside a restored row whose database ID changed.

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
  email addresses, and source user IDs are not present, and restore presents grants for review rather than applying them automatically.
- Ready archives expire after seven days and are removed by the scheduled native-backup
  pruner.

The executable source of truth for included tables, relationships, row policies, and
exclusion reasons is `PhrNativeBackupCatalog`. Its coverage tests walk migration foreign
keys and link artifact-bearing tables back to `PhrStorageMap` so schema drift fails CI.
The owner-authorized restore workflow validates an uploaded archive, persists a preview,
and re-plans under locks before applying it in one graph transaction. Artifact failures
before commit compensate only objects written by that attempt. Finalization publishes
restore watermarks after the graph commits; a finalization retry does not replay writes.


## Bounded M2 resource contract

Native writer and reader share the following admission policy. Limits are independent:
a small ZIP can still contain too many records, and a small number of records can still
carry too much structured text. These are resource refusals, not declarations that an
archive is corrupt. Existing archives inside these limits keep the same v1 encoding,
canonical hashes, table generations, and plan digest.

| Configuration | Default | Enforcement |
| --- | --- | --- |
| `PHR_NATIVE_MAX_RECORDS` | 20,000 | Total catalog records; reader checks declared and actual counts |
| `PHR_NATIVE_MAX_RECORD_STREAM_BYTES` | 32 MiB | Total actual uncompressed NDJSON bytes, including blank lines/newlines |
| `PHR_NATIVE_RESTORE_MAX_RECORD_BYTES` | 2 MiB | Actual canonical line including newline, checked by writer and reader |
| `PHR_NATIVE_MAX_ARTIFACTS` | 2,000 | Artifact metadata; additional aggregate metadata bound of 1 MiB |
| Manifest bytes | strictly below 1 MiB | Actual encoded manifest; unchanged reader boundary, now also writer-enforced |
| `PHR_NATIVE_PAGE_BYTES` | 4 MiB | SQL raw-byte size probes before payload hydration; one legal row may exceed a smaller configured page target |
| `PHR_NATIVE_BACKUP_CHUNK_SIZE` | 500 | Maximum rows per database page, capped at 500; byte bound may shorten it |
| `PHR_NATIVE_BACKUP_MAX_UNCOMPRESSED_BYTES` | 20 GiB | Expanded archive and completed ZIP, including manifest and artifacts |

The writer charges records before writing their canonical lines and artifact candidates
before retaining their full internal metadata. Reader ZIP entry sizes are preflighted,
then actual record counters are charged before decoding and adding identity metadata.
A single raw SQL row above the configured record-byte limit is conservatively refused
before hydration, including its operational columns. Storage-to-local ZIP copies are
bounded. Artifact verification retains only the small
metadata subset it needs, never document text. Temporary record files are closed and
partial output is removed on refusal.

Restore uses indexed, read-only identity lookups for only archived records and their
relationships. It does not preload the existing patient's identity graph or retain all
current hashes. Its action map, validation ID sets, and actor map remain proportional to
the admitted archive, bounded by the count/byte limits. Increasing unrelated current
patient identities does not enlarge those maps. No database scratch tables, PDO SQLite
dependency for production restore, new schema generation, or per-page commits are added.
Snapshot DICOM locking and artifact I/O can still depend on the patient's files and
storage throughput; job timeouts remain in force. Raising a limit requires repeating the
resource measurements on the intended runtime/database, not just increasing PHP memory.

### EHI preview admission

Clinical preview starts a generation, then checks a conservative patient-wide work set:
EHI sources/packages/members, entities/revisions/occurrences, proposals/dependencies,
decisions/links, and document/immunization targets, including prior history. The defaults
are `PHR_EHI_PREVIEW_MAX_ROWS=10000`, `PHR_EHI_PREVIEW_MAX_BYTES=8388608` (8 MiB of raw
SQL column bytes), and `PHR_EHI_PREVIEW_MAX_SECONDS=240`. These are separate from the
larger source-preservation byte ceilings. SQL aggregates and revision-page size probes
run before large model/JSON materializations. Work is rechecked at materialization and
proposal-persistence boundaries. A chunk reserves its new/changed proposals and
dependency rows before writing; changed rows conservatively count beside their old state. A preview that grows past the budget remains incomplete.

A refusal preserves source bytes, durable evidence, accounting, and previous decisions.
It does not truncate revision dependencies or make partially generated proposals
reviewable. A small new package can be refused because of its reachable patient history.
Some large preserved packages therefore need a later scale expansion before clinical
preview, and some patients cannot yet produce a native archive under these limits.

Findings retain only the first configured number per mapping and a scalar omitted count.
Publication replaces all preview findings and completes the current generation in one
patient-guarded transaction. Failed/superseded runs publish neither. Status labels prior
completed findings as historical while the new preview is incomplete or after restore.
Duplicate candidate display retains the first 100 IDs, exact total, truncation flag, and
a versioned hash of all observed matching IDs in `proposed_json._candidate_summary`.
The full summary participates in the preview digest. Omitted live candidates remain
explicitly linkable under existing target/access/lifecycle checks; accepting a separate
same-day dose remains possible. Decided proposals are never rewritten to shrink history.

### Reproducing the resource measurements

Use synthetic fixtures in a fresh private directory. Preparation is a separate PHP
process so its allocations are excluded from each operation's PHP peak. No command uses
the application's configured database or storage: the harness forces an isolated
file-backed SQLite database and local fixture disks before connecting. The fixture
directory is never overwritten; remove it explicitly after use.

```bash
php -d memory_limit=1G scripts/measure-phr-resources.php --dir=/tmp/phr-resource-example --prepare --shape=small --records=20000
python3 scripts/observe-phr-resources.py --dir=/tmp/phr-resource-example --operation=backup
python3 scripts/observe-phr-resources.py --dir=/tmp/phr-resource-example --operation=validate
python3 scripts/observe-phr-resources.py --dir=/tmp/phr-resource-example --operation=plan
python3 scripts/observe-phr-resources.py --dir=/tmp/phr-resource-example --operation=apply
# Run last: deletes only this fixture's patient before restoring it as a new patient.
python3 scripts/observe-phr-resources.py --dir=/tmp/phr-resource-example --operation=apply --new-patient
```

Other shapes are `wide` (per-record text `--bytes=2000000`), `artifacts`, `ehi`
(immunization count), `fanout` (one note's child-row count and text repetition `--bytes`),
and `dense` (8,000 small unknown cells per source row). For EHI shapes measure `preview`.
`--extra-identities=N` adds unrelated durable identities after creating the archive.
The observer samples Linux `/proc` RSS and native temporary file sizes every 5 ms;
those disk samples are observed high-water marks, not a replacement for enforced quotas.
The harness retains only query counters and maximum bindings, never a full query log.

Measurements on PHP 8.5.6 with file-backed SQLite, 1 GiB PHP limit, October 20, 2026:

| Fixture / operation | Peak PHP allocation | Elapsed time |
| --- | --- | --- |
| 5,000 records: backup / validate / plan / existing apply | 32 / 20 / 18 / 20 MiB | 0.9 / 0.6 / 5.4 / 6.9 s |
| 10,000 records: backup / validate / plan / existing apply | 32 / 22 / 22 / 24 MiB | 1.9 / 1.6 / 11.6 / 14.8 s |
| 20,000 records: backup / validate / plan / existing apply | 47.4 / 30 / 28 / 32 MiB | 3.6 / 2.0 / 22.4 / 29.5 s |
| 20,000 records: new-patient apply | 34 MiB | 37.9 s |
| 16 document texts of 2,000,000 bytes: backup / validate / plan / apply | 46.3 / 26 / 24 / 26 MiB | 1.1 / 0.7 / 0.6 / 1.9 s |
| 300 / 700 / 1,080 immunizations: clinical preview | 18 / 20 / 24 MiB | 2.8 / 8.5 / 21–38 s |
| One note with 1,200 children / 1,000 wider children: clinical preview | 16 / 41.5 MiB | 1.3 / 2.5 s |
| Dense JSON (8,000 unknown cells per row): preview | 20 MiB | 2.8 s |
| 2,000 artifacts: backup / validation | 36 / 18 MiB | 0.7 / 0.3 s |
| 20 archived records against 100,000 unrelated current identities: planning | 16 MiB | 0.06 s |

Successful maximum native runs used under 100 MiB observed process RSS; the wider note
used about 104 MiB. The 32 MB wide-record backup had an observed temporary-file
high-water mark of 32,059,222 bytes. The small archive against 100,000 unrelated
identities needed only 140 planning queries and retained the same 16 MiB PHP peak as
a small target. Count 20,001 was refused as `record_count_limit`; a clinical preview
that grew beyond the 10,000-row work set was refused as `resource_limit` without
publishing its partial generation. Boundary regressions additionally cover exact and
one-over byte/count/line limits, a forged low manifest count, and publication rollback.
These local timings establish headroom for this synthetic profile, not a production
throughput guarantee. Production MySQL network latency and artifact throughput were not
measured. The tested SQL size expressions have driver-specific forms for SQLite,
MySQL/MariaDB, PostgreSQL, and SQL Server; the application snapshot implementation's
supported isolation behavior remains unchanged.
