# Checking patient ownership before EHI intake

`phr:ehi:probe` reads package structure without importing records or changing the
patient database. Run it privately against a representative export before deciding
whether the `unscoped_patient_row` refusal needs a table-specific ownership rule:

```sh
php artisan phr:ehi:probe --zip=/path/to/export.zip --json
# Or inspect an extracted package:
php artisan phr:ehi:probe --dir=/path/to/extracted-package --json
```

Do not attach an export, original filenames, row values, or intake exception text
to an issue. The probe's report is designed to omit those values; use that report
for a structural discussion instead. The same report is available without `--json`.

## Patient-scope diagnostics

Probe version `ehi-probe-3` reports aggregate counts in `tables.pat_id` and the same
counts in `tables.pat_id.by_table`. Named buckets are limited to the public table
names PHR already recognizes, including `PAT_ENC` and `ORDER_PROC`. Every other
table is combined in `(other)`: the probe does not print installation-specific
names, member paths, column names, patient identifiers, or source row numbers.
Multiple members with the same public table name contribute to one bucket.

Each bucket reports table-member count, headers declaring `PAT_ID`, headers whose
patient scope cannot be read, blank patient cells, malformed rows whose patient
cannot be established, and unreadable members. A malformed header still declaring
`PAT_ID` has patient-scoped rows, just as intake treats it. An undecodable header
may declare `PAT_ID`, so malformed rows beneath it are counted conservatively too.
A header that can prove `PAT_ID` is absent does not turn malformed rows into this
particular refusal; those rows remain part of the ordinary malformed-row counts.

`unscoped_patient_row_check` has three fixed outcomes:

- `refusal_observed`: a blank patient cell or a malformed row with declared or
  unknown patient scope was observed. Current intake refuses that package.
- `incomplete`: no such row was observed, but a header's patient scope or a table
  read could not be completed. Resolve the structural problem before concluding
  that this check passed.
- `not_observed`: this probe observed no rows triggering this particular refusal.
  This is not permission to import and does not establish that all other intake,
  source-patient binding, dictionary, or multi-patient checks will pass.

Counts describe the provisional TSV dialect used by current intake. The report's
separate RFC 4180 comparison helps investigate whether quoting changes the shape;
it does not select a different dialect or approve ownership automatically.

## Decision: intake keeps refusing unscoped patient rows

Issue #331 is closed as working as intended. Intake refuses a whole package when a
table with a `PAT_ID` column has a blank or unreadable patient value
(`unscoped_patient_row`, `EhiIntakeService::observePatientKey()`), and nothing is
stored for a refused package. Such a row could belong to another patient, so it is
refused rather than preserved or quarantined under this one. Tables without a
`PAT_ID` column are not refused; their malformed rows are counted and reported as
findings. `EhiIntakeServiceTest` pins this behaviour.

The refusal is a deliberate fail-closed privacy rule, and its cost is visible and
reversible: if real exports do contain such rows, imports fail loudly and the rule
can be revisited with evidence.

**Reopen the question when** a real export is refused with `unscoped_patient_row`,
or a representative export becomes available. Then run the structure-only probe
privately and, if affected public tables appear, investigate whether a documented
join or another column can prove ownership before proposing a narrowly scoped rule
for those named tables. Do not preserve an unowned row under a patient merely to
let the rest import, and do not relax intake because the probe reports
`not_observed`.
