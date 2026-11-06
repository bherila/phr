# PHR HTTP contracts — C1/C2

Run `pnpm run test:http-contracts` from a clean candidate checkout with matching
Composer/Node dependencies. The command creates a new ignored generation under
`build/http-contracts`, runs the dedicated Laravel producer, and passes its exact
HTTP bytes to production Zod schemas. No fixture refresh or committed generated
responses are needed. The normal frontend Jest command remains PHP-independent.

`catalog.json` is the reviewed scenario/test inventory, independent of the emitted
manifest. C1 contains eight authenticated HTTP cases: no-patient, empty/populated
owned inventory, metadata-only viewer/manager inventory, an uploading restore,
and empty/uploading restore lists. `sources.json` names the producer, consumer,
catalog and contract sources whose hashes bind every generation to its checkout.
The writer publishes a manifest only after all scenarios have succeeded. It writes
`getContent()` bytes directly; decoding a body for assertions never changes those
bytes. Shared-response privacy is checked before and after schema parsing because
ordinary Zod objects may strip unknown fields.

The dedicated Jest configuration selects only `.contract.ts` suites outside normal
frontend discovery. The runner compares actual Jest assertion names/statuses with
the catalog: missing, duplicate, extra, skipped or zero tests fail. Harness cases
also reject incomplete, mixed, stale, altered or unsafe fixture generations.

The required `HTTP Response Contracts` CI job runs the same command with
`--prove-historical-mutations`. This CI-only option independently removes the real
frontend inventory enum member and the real restore-presenter object cast. Each
mutant regenerates actual HTTP fixtures and starts a fresh production-schema
consumer. Only the expected case/path/kind/code failures count. The script restores
source bytes in `finally`, checks source hashes, and requires pristine success
before and after restoration. Its sanitized `proof.json` is the retained evidence;
raw fixtures and private producer/Jest logs are never uploaded or printed.

C2 adds native backup pending/processing/ready/failed list and singular envelopes,
null/populated metadata, expired download eligibility, and all nine restore states.
The nine `restore-poll-*` cases are explicitly persisted fixture-state polls through
the actual HTTP presenter, not worker-transition or concurrency proofs. Separate
real synthetic archive journeys exercise actual chunk/preview/apply/poll routes
with queued work and synchronous completion, new/existing patient targets,
create/skip counts, artifact counts, and grant restoration disabled/enabled. The
prepared-state polls cover nonzero create/skip/block counts and blockers too.

`http_assertions` is a separate independent catalog of permission/error scenarios.
The producer checks actual HTTP status and, where applicable, a closed error code;
only successful assertion metadata and candidate/generation identity enter the
manifest. These are **HTTP producer assertions, not Zod error-schema coverage**:
the frontend has no consumed error schema here, so none is invented for testing.
Missing/extra/duplicate/zero assertions, wrong status/error and mixed provenance
fail closed. Backups are actual-owner-only for list/create/download. Restore
operations are actor-scoped: viewer/manager/outsider actors can create their own
uploads, but cannot poll/chunk/preview/apply another actor's attempt or apply an
archive targeting an existing patient they do not own. An owner has a successful
download; expired/unsigned downloads and stale digest/changed grant opt-in fail.
No error response body or download URL is included in retained proof output.

When adding C3 scenarios, update the independently reviewed catalog, actual HTTP
setup, and relevant production-schema registry together. EHI legacy compatibility remains
a separate follow-up PR under #359. Do not broaden native parsing to coerce arrays
into maps or replace strict inventory enums to make contract tests pass.
