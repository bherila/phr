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
(today the six EHI immunization provenance columns on `phr_immunizations`, the review
status and lines digest on `phr_eobs`, the reference-bound inclusiveness and EHI lab
provenance columns on `phr_lab_results`, the EHI encounter context columns on
`phr_office_visits`, and the source-ID bridge columns on `phr_ehi_record_links`). Nullable relationships added later are listed the same way in
`PhrNativeLifecycleCompatibility::TABLE_RELATIONSHIPS` (today a lab result or visit as an evidence
link and decision target). Absent ones are read as null only after the stored content
hash has been verified against what was actually written; any other attribute or
relationship difference fails closed.

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
decisions/links, and document/immunization/lab-result/visit targets, including prior history. The defaults
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
measured. SQL size expressions have driver-specific forms for SQLite, MySQL/MariaDB,
PostgreSQL, and SQL Server. Execution was verified on SQLite and, in a disposable
loopback-only MariaDB 10.11.19 container using PDO MySQL, the native and EHI profiles
below. CI runs the test suite on PostgreSQL 17, which covers a backup and restore round
trip but not these profiles. SQL Server was not exercised. The application snapshot
implementation's supported isolation behavior remains unchanged.

Additional boundary regressions admit a ZIP at its exact compressed-byte limit and
refuse it one byte over, exercise the writer's completed-ZIP gate independently of
expanded bytes, and admit a manifest at 1 MiB minus one byte while refusing 1 MiB.
The manifest boundary is also checked directly through both writer and reader budgets.

The MariaDB checks used synthetic fixtures in a dedicated database and private local
fixture disks; no existing database or production data was used. For 20,000 records, backup and validation completed in 4.2 and 4.0 seconds;
planning completed in 129.2 seconds with 28 MiB peak PHP allocation, and existing-patient
apply in 141.5 seconds with 34 MiB peak PHP allocation. Planning/apply issued
120,012/140,024 queries with at most three/four bindings respectively. A separate
20-record new-patient restore
exercised insertion and parent identity resolution. Clinical preview completed for 700 added
immunization rows in 37.2 seconds with 22 MiB peak PHP allocation. A projection containing
1,106 distinct repeated keys quarantined 2,212 occurrences, retained two duplicate
findings, and used at most two reconciliation bindings. A multibyte SQL probe counted
`é😀` as six bytes. These are local driver compatibility checks, not production
performance measurements.

### Lab-heavy EHI capacity calibration (#330)

The `EHI lifecycle capacity` workflow runs on changes to the resource harness or
EHI mappings and can also be dispatched manually. Heavy calibration belongs in
CI, not a developer's local check loop. Its two independent lanes use PHP 8.5
with a 1 GiB PHP memory ceiling, SQLite and a disposable loopback MariaDB 10.11
service. Fixtures are wholly synthetic, use private temporary artifact disks,
and MariaDB schemas are newly created with a fixed `phr_resource_` prefix; the
harness sets an isolated schema and loopback TCP host instead of selecting an
application schema/host from `.env`. The inherited `DB_SOCKET` caveat below still
applies: clean CI has no socket configured, and these CI measurements contacted
no production data or deployment service. Do not treat the harness as universally
isolated from arbitrary ambient socket configuration until #365 is addressed.

The mixed package includes notes and immunizations plus ten lab components per
order, and optionally prior clinical lab history. Profiles measure 1,000 and
2,000 incoming results at the current 10,000-row/8 MiB limits, 1,000 results with
2,000 prior results, and an **experimental synthetic-only** 5,000-result package
with 2,000 prior results at 50,000 rows/64 MiB. Every profile retains the existing
240-second preview deadline. The experimental admission settings are not new
application defaults or a claim of supported production capacity.

Each lane uploads `ehi-capacity-*` observations containing its database version,
configured limits, the measured admission work rows/bytes before and after,
completion or `resource_limit` outcome, elapsed time, peak PHP allocation, process
RSS, query counts and full proposal counts. A completed
preview must include every supplied lab result and leave prior clinical records
unchanged. A resource refusal must leave the generation incomplete; it is
reported explicitly, never counted as a successful capacity result. The small
1,000-result baseline is required to complete. Files and records from the fixture
itself are not uploaded.

After each completed preview the same isolated fixture accepts one lab proposal
and rejects another through the normal review service, builds a **post-preview**
native archive, removes only that synthetic patient, and restores through the
production restore reader/planner/apply services. A second export must preserve
every portable record's native identity/content hash and every artifact's size/hash.
Clinical lab counts, proposal statuses, decision counts and owner access must also
agree. Owner access is recreated by restore, so its generated timestamp and native
identity are deliberately excluded from the content-hash comparison; its count is
checked separately. Operational preview generations are intentionally reset by
restore, leaving pending proposals in need of a new preview before review.

Per-stage duration/PHP peak allocation, overall RSS, native archive record/byte
counts and round-trip completion are retained alongside preview observations.
The 5,000-result experiment first uses unchanged native defaults and reports any
`record_count_limit`/`record_stream_size_limit` as a refusal. It then measures an
explicit synthetic-only 50,000-record/64 MiB native envelope. A refused preview
skips review/backup/restore and reports `preview_resource_limit`; no refused run
counts as successful lifecycle capacity. This does not raise production limits.
The small 11-result regression checks the same lifecycle locally; realistic
histories run only in CI.

Use these measurements to reproduce the bottleneck and choose the next
calibration experiment. They do not replace measurement on the actual deployment
runtime: its PHP configuration, SQL/network latency and artifact throughput can
differ. The production defaults remain unchanged until that evidence supports a
safe limit or work-partitioning change. Consequently #330 remains open while
that deployment-runtime calibration is outstanding.

The separate `EHI deployment-host calibration` workflow is manual and main-only
for its host lane, pins the reviewed source SHA and required CI gate, and uses the
`prod` environment/production-files concurrency gate. Its PR lane exercises the
same isolation script on a disposable CI runner before any host execution.
It packages only selected application code plus development dependencies (needed
for synthetic factories), never deployed `.env`, storage, application caches or
clinical files. Admission rejects links, duplicate/traversing/absolute paths,
private environment files, archives over 80 MiB compressed/500 MiB expanded and
more than 50,000 members before extraction. Before materialization, source/vendor
symlinks must resolve within the candidate checkout and cannot name environment,
Git metadata, storage or compiled-cache paths.

Host execution uses a fresh private `/tmp/phr-calibration.*` root and the actual
cPanel PHP 8.5 binary. A cleared process environment and entirely synthetic
SQLite/cache/session/queue/storage settings apply **before** framework bootstrap.
PHP has a 1 GiB ceiling, `open_basedir` and temporary-file directories restricted
to the owned root, plus only the verified canonical non-symlink `/dev/null`
character device (Linux major/minor 1:3) used by existing intake hashing; no
`/dev` directory access is allowed. Every phase is limited to 300 seconds and the remote operation
to 1,000 seconds. It measures one 1,000-incoming/2,000-prior-lab profile at unchanged
preview/native defaults. Only bounded aggregate JSON leaves the host; raw fixture
DBs, source files, archives and exception logs are deleted. Cleanup requires a
canonical, current-user-owned mode-0700 root plus its ownership sentinel and never
follows links; transfer/transport failures also have an idempotent cleanup step.

This provides actual-host PHP allocation/time and local-filesystem artifact
throughput evidence, with PHP `ru_maxrss` when `/proc` is outside the isolation
boundary. It does **not** calibrate the production SQL connection or object-store
latency, and must not justify production limit increases by itself. Measuring
MariaDB there needs a separately provisioned disposable schema and credentials;
never use the application's schema or issue application migrations for this task.

Host calibration first runs a bounded read-only prerequisite probe. It returns
only finite command/PHP/device refusal codes, a validated PHP version,
and aggregate counts of canonical current-user-owned mode-0700 legacy roots with
the old fixed sentinel, including how many fall within the failed first attempt's
09:37:55–09:37:57 UTC creation window on 2026-11-05. No root paths or marker contents
are returned, and a possible legacy root is never deleted by this audit. A zero
count proves that no qualifying old-sentinel root remains; a positive count needs
separate identification against that run/time window before any legacy cleanup.

Only a validated preflight allows creation. The runner records a random root name
and unique run nonce before requesting fresh noclobber mode-0700 creation. Runtime
and cleanup require the same nonce in the private marker. If SSH fails immediately
after creation, the runner still has the cleanup identity; a preexisting root or
wrong nonce cannot be overwritten or cleaned. Sanitized stage proofs are retained
on failure while unexpected SSH stdout, banners, stderr and paths remain private.

The deployment host does not need Python. CI admits the compressed archive under
its existing path/type/80 MiB compressed/500 MiB expanded/50,000-member/128 MiB-file
limits, then normalizes only regular files and admitted directories into a fresh
transfer tree. It preserves empty directories and emits a deterministic bounded
SHA256/size inventory pinned to the source candidate and Composer lock digest.
A 120-second rsync transfers only that tree into the nonce-owned private root,
never deployment or public directories. Transfer duration is retained explicitly.

Before creating `.env`, cache, storage or fixtures, standalone PHP core (`-n`)
checks the out-of-band manifest/verifier digests, every path/type/size/hash, exact
candidate/lock identity and the complete tree, rejecting missing/extra files,
unknown empty directories, special files and file/directory links without following
them. The unchanged 60-second admission, 1 GiB memory and existing byte/member/file
caps apply. There is no remote archive extraction. Bash/PHP run the isolated
lifecycle and emit bounded aggregate JSON; Python validation/decoration remains on
the CI runner only. No global host installation or production SQL access occurs.

Archive member count and normalized tree entry count are reported separately:
normalization may add implicit parent directories. Both counts remain bounded by
50,000; exact host verification matches the normalized tree inventory.

EHI preview failures retain the existing `resource_limit` reason for compatibility.
New failed preview runs may include `failure_details: {resource_limit: "time"}` or
`"capacity"`; these are closed public codes, never exception text or source values.
Older runs without details and unknown stored details return null. Row/byte caps
are capacity refusals: repeating the same request cannot overcome those limits.
A time-budget stop keeps durable evidence and leaves its generation incomplete.
A retry preserves completed projection-table checkpoints but starts a new proposal
generation and recomputes mappings from the beginning; it may reach the same time
limit again. The API does not promise cursor-based continuation. Successful runs
clear the failure detail. Limits and deadlines are unchanged.

### E0 complete-lifecycle qualification

The retained capacity controls still declare **two lab decisions** (one accept,
one reject). Their operation/job time guards and production settings are unchanged;
full-review support must not be inferred from those controls. The control lifecycle
now uses managed backup generation, an independently streamed/verified download,
`PhrPatientDeletionService` plus storage cleanup, and proves all deletion-plan
objects (including the managed backup and source objects) disappear while that
independent copy survives. Restore/re-export compares exact native identities and
content hashes for non-access portable records and original artifact bytes.
Owner/access rows use explicit actor/role policy normalization, not only row counts.

`scripts/ehi-qualification-profiles.json` separately declares E0 targets. The
small full-review/replay/share control and exact native boundary must complete;
one-over boundaries must refuse at their declared backup stage. The 2,000+2,000
full-review/change target, 200+20,000 history, three changed cycles, 5,000+2,000
stretch, wide rows, evidence fanout and many-artifact experiments are explicitly
experimental. Full review accepts unambiguous creates/corroborations and approved
updates, rejects the first lab deliberately, and retains ambiguous/unmapped
proposals; other mappings are explicitly reviewed. Changed cycles preserve that
rejected lab's content, add the declared new results and change the declared other
values. Re-preview and byte-identical/equivalently repacked ZIP replay must not add
clinical rows or decisions; a changed nonclinical manifest must not duplicate
clinical rows. Repacked containers have different archive bytes but the same
semantic package identity. No export is split or edited outside synthetic fixtures.

The existing 50,000-row/64 MiB preview/native experiment remains a **control**, not
a selected production envelope. Full review of the 5,000-result case was forecast
near 63,695 native records before richer mappings/history, above 50,000; the
November 6 full-review runs observed 63,732 catalog records before backup refused
at the 50,000-record experiment limit. This is a measured refusal, not a successful
native round trip. Each qualification has the
unchanged 300-second **overall process ceiling**, including seed/intake/project,
and retains the separate 240-second proposal budget. A timed-out or resource-refused
experiment is reported as an **unmet qualification**, never capacity success.
Unknown/invariant failures fail CI even for an experimental profile. The existing
30-minute job ceiling remains; batches are bounded and independently inspectable.

Stage telemetry reports time, query count and hashed shapes (never SQL/bindings),
largest parameter batch, transaction count/lifetime, locking-statement time, PHP
peak and portable durable row/byte counts. Transaction lifetime is a conservative
lock-holding window, not a production server lock-wait measurement. The external
sampler records per-stage RSS/native temporary-file high-water and separate
private-fixture disk high-water (inputs/DB/managed outputs included). Total profile
wall cost and incomplete timeout telemetry remain visible. Large histories and all
realistic benchmarks run in CI; local tests use tiny synthetic controls only.

Sharing qualification preserves existing-patient opt-in grants and checks real
viewer/manager/owner authorization. After real deletion, default new-patient
restore omits nonowner grants and denies both former nonowners. Explicit opt-in
new-patient planning must retain `actor_mapping_missing`; the harness does not
invent user mappings or delete identities to force success. These are distinct
policy outcomes, not a claim that unknown shared actors can be resurrected.
Production SQL/object-store calibration and R1 remain pending; no schema,
credentials, production data or capacity-default changes are part of E0.

Independent pre-backup oracles derive every expected synthetic lab identity/value
from the declared result/history/change counts. Clinical content and prior decision
and decided-proposal hashes must remain stable across re-preview and exact/repacked
replay. Manifest-only review may append decisions but cannot overwrite prior rows
or change clinical contents. Changed cycles may update only declared lab values,
retain prior nonvalue fields/identities/decisions, and create only declared new labs.
Only verification flags/hashes/counts leave the fixture, never source values.

Artifact count boundaries explicitly use a lowered synthetic count cap of 64:
source blobs plus the two declared published document artifacts must attain exactly
64 (must complete) or 65 (must refuse at backup). The separate 2,000-count profile is
experimental because the independent 1 MiB artifact-metadata ceiling may bind first;
it is not labeled successful exact-boundary proof if refused. Production artifact
settings remain unchanged.

### Bounded processing and authorization changes (November 2026)

The initial implementation keeps the generation/review architecture, archive
format, production admission limits and deadlines unchanged:

- [#367](https://github.com/bherila/phr/pull/367) adds a 4 MiB encoded-payload
  flush target alongside projection's existing 500-row/finding cap. Accounting
  includes buffered revision/finding metadata, and flush happens before the next
  entry crosses the target. An individually legal entry larger than the target
  is written alone immediately. This bounds batching, not total PHP memory:
  parser allocations, the next entry/JSON encoding, PHP/SQL allocations,
  dictionaries, candidate/evidence indexes and durable aggregate growth remain
  separate resource dimensions. Per-component allocation
  attribution for parser, dictionary and buffer memory is not measured; retained
  stage PHP/RSS peaks combine them. Source bytes, offsets, oversized-revision
  findings, accounting and table checkpoints are preserved.
- [#361](https://github.com/bherila/phr/pull/361) serializes grant creation,
  downgrade and revocation through the existing patient write guard and checks
  ownership afresh under that lock. Real two-connection PostgreSQL regressions
  distinguish the legacy parent-touch interleaving from the fixed atomic grant
  mutation. An acknowledged revocation cannot be followed by a newly committed
  guarded chunk authorized through that revoked grant. This does not establish
  a new archive/worker coordination protocol; the archive-during-generation
  characterization remains a [P2 follow-up](https://github.com/bherila/phr/issues/362).
- [#368](https://github.com/bherila/phr/pull/368) adds a plan-local 512-entry LRU
  for inverse native-identity lookups used to project current target rows.
  Null misses are cached too; the key includes patient, table and record ID.
  Current target rows, actor mappings, forward identity lookups and restore
  writes retain their existing paths. Cache state expires when each plan returns
  or throws, so a subsequent plan sees target and identity edits. No archive
  format, transaction boundary or restore visibility rule changes.

The measured N1 comparison uses the E0 candidate `e39578f` and optimized candidate
`7c0fd1e`; it is distinct from final integrated qualification. In disposable
MariaDB, the 200-incoming/20,000-prior full lifecycle fell from 464,903 to 357,269
queries (23.15%) and from 295.766 to 242.880 seconds of process wall time (17.88%).
The existing-target preview and apply stages each avoid 53,817 repeated identity
queries. New-patient restore still issues 108,510 queries, so its timing variation
is not evidence that its apply path was optimized. PHP peak remained 143,388,672
bytes on MariaDB; SQLite's peak rose 2 MiB. This is a query/runtime improvement,
not a demonstrated peak-memory reduction. All qualification outcomes and
recovery checks remained unchanged. Retained evidence:
[E0 run](https://github.com/bherila/phr/actions/runs/42191613859),
[N1 run](https://github.com/bherila/phr/actions/runs/42195227289).

Interpret retained telemetry with the known
[P2 harness limitations](https://github.com/bherila/phr/issues/365): cleanup checks
currently start from the production deletion plan's object inventory rather than
an independent pre-delete inventory; the disposable SQL connection inherits a
possible `DB_SOCKET`; and post-restore `rebackup`/`fingerprint_after` durable
snapshots still use the deleted patient ID. Do not use those false-zero snapshots
as capacity evidence. The explicit restored-patient `restore_apply` snapshot and
archive/source/access/hash checks are separate and usable. Clean CI uses no
socket; production SQL calibration remains unmeasured.

R1 remains pending. The 50,000-row/64 MiB preview plus 50,000-record/64 MiB native
pair is an experiment, not a selected production envelope: the declared
2,000-result changed-import and three-cycle profiles refuse at the first changed
preview, and full review of the 5,000-result profile exceeds native admission.
Do not infer successful changed-cycle capacity from completed initial review or
green expected-refusal jobs. No durable cursors or partial mapping review were
introduced. A future admission change needs a jointly qualified review/replay
policy and sufficient SQL/storage evidence. Rollback must restrict new admission
while preserving a recovery-capable native envelope for graphs already admitted;
lowering restore limits beneath existing archives can strand recoverable data.

### V1 integrated evidence and capacity decision — 2026-11-06

The integrated application candidate is
`e528f24d417c818bca0c236556af8895141c4bee`, including S1/E0/E1/N1 and #359 C1–C3.
[Final qualification run 42196863191](https://github.com/bherila/phr/actions/runs/42196863191)
passed all ten jobs. All 26 qualification JSON files and 18 historical control
JSON files were inspected. Results agree across SQLite and disposable MariaDB:

| Declared profile | Observed outcome | Capacity interpretation |
| --- | --- | --- |
| Small full-review/replay/share; 200 incoming/20,000 prior; wide rows; fanout; mixed mappings; 2,000 artifacts | Full recovery completes | `qualification_met=true` for these synthetic profiles only |
| Exact native-record and 64-artifact boundaries | Full recovery completes at the declared cap | Synthetic exact-boundary proof |
| 2,000 incoming/2,000 prior plus changed import; three changed cycles | `resource_limit` at `cycle_1_preview`; zero changed cycles complete | Both targets remain unmet |
| 5,000 incoming/2,000 prior, full review | `record_count_limit` at backup; 63,732 catalog records observed | Expanded native envelope is insufficient |
| Native-record and artifact one-over controls | `record_count_limit` / `artifact_metadata_limit` at backup | Expected safe refusal; `qualification_met=false` |

The completed profiles verify independent download, deletion-plan cleanup,
portable/source hashes and access policy. The sharing profiles preserve existing
opt-in grants, block unmapped actors during new-patient opt-in planning, and omit
nonowner grants during default new-patient recovery. The #365 limitations above
still apply; completion is not an independent proof that the deletion plan covers
every object family.

Final MariaDB large-history cost is 243.042 seconds of process wall time,
357,268 queries and 143,388,672 bytes PHP peak, within the unchanged 300-second
qualification ceiling. SQLite is 99.258 seconds / 358,221 queries. These are
single CI observations, not production latency guarantees or new capacity limits.

The integrated wide-row projection demonstrates E1's batching tradeoff against
N1's candidate without E1. MariaDB projection PHP peak falls from 118,054,912 to
81,821,696 bytes, largest binding batch from 432 to 135, and queries rise from
287 to 315; stage time changes from 0.718 to 0.856 seconds. Whole-profile PHP peak
falls from 118,054,912 to 99,434,496 bytes. SQLite projection PHP peak falls from
92,807,168 to 73,932,800 bytes, but its whole-profile peak stays 101,531,648 bytes.
This supports the encoded-buffer change without establishing constant memory or
separate parser/dictionary/index allocation bounds.

The integrated [HTTP contract proof](https://github.com/bherila/phr/actions/runs/42196976354)
(artifact `13492900420`) pins the same candidate: 66 current HTTP responses,
two derived compatibility cases, 34 HTTP assertions, 51 pristine harness guards,
five independent generations, both historical mutation failures at the intended
paths, and restored source hashes. Contract tests complement the required
browser recovery journey; its 30-second deadline remains unchanged.

**Decision:** ship the bounded processing, lock alignment, query improvement and
qualification infrastructure under unchanged production defaults. Do not select
the experimental pair as a supported production envelope. #330 stays open for
the unmet replay/full-review targets and sufficient production SQL/storage
evidence; R1 remains pending. The unavailable representative export (#331) and
disposable deployment SQL schema remain deferred. No production application
schema, credentials, data or privileged host calibration was used for V1.
