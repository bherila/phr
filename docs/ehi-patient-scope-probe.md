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

## Policy remains evidence-driven

This tooling does not relax intake. A real export's structure-only report is still
needed to settle issue #331. If affected public tables appear, investigate whether
a documented join can prove ownership before proposing a narrowly scoped rule.
Do not preserve an unowned row under a patient merely to let the rest import.
