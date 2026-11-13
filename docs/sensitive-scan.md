# Schema-dump disclosure checks

`scripts/scan-sensitive.mjs` treats `database/schema/mysql-schema.sql` and
`database/schema/sqlite-schema.sql` as bounded SQL inputs. They are not excluded as
generated files. Tracked mode reads the working file; `--staged` reads the actual
index blob. Missing, nonregular, symlinked, over-2-MiB, NUL-containing or invalid
UTF-8 inputs fail closed. Generic scanner exclusions and inline-ignore comments
cannot skip these two paths. Other files retain the existing scanner behavior.

The recognizer in `scripts/schema-dump-scan.mjs` accepts the current generated
CREATE TABLE/INDEX and MySQL DROP TABLE forms, strict migration-ledger VALUES
inserts, the enumerated mysqldump session settings, and the two exact generated
security-version triggers. It does not execute SQL or implement general SQL
syntax. Unknown syntax, other data writes, malformed statements, arbitrary SET
or trigger bodies, and SQL hidden inside executable MySQL version comments fail
closed. A generator syntax change needs a reviewed allowlist update rather than
an ignore directive. The file, token and statement bounds also limit parsing.

The existing disclosure rules also inspect comments, decoded quoted content, and
labelled string defaults. Only the REFERENCES keyword in a successfully parsed
foreign-key clause gets the contextual false-positive exception; the same text in
ordinary source remains subject to the existing rules. The explicit disclosure
value allowlist still applies to disclosure patterns, but can never permit data
writes or unsupported SQL. Dump diagnostics report only path, line and a finite
rule code, with no matched value or SQL excerpt. At most 100 dump findings are
retained per file.

Run the focused CLI regressions with:

```sh
node --test scripts/scan-sensitive.test.mjs
```

They use temporary Git repositories and runtime-generated synthetic canaries,
cover both tracked and staged modes, and check current generated dumps as well
as rejection and diagnostic-privacy cases. Required Frontend Static Checks run
these regressions before the ordinary repository scan. Full validation runs in CI.
