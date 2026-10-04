# Production request ordering

The application and OHIF jobs retain up to 100 pending production requests with
`queue: max`. Both use `phr-production-files`, and both mutations hold the same
remote `~/.deployments/phr-laravel/deploy.lock`. Queue order is not source order.
No timeout automatically takes another writer's lock; interruption requires
proving the writer stopped before using the documented recovery procedure.

Before production writes, the application gate checks its exact checkout against
its frontend artifact source, current main ancestry, the live release metadata,
and up to 100 CI runs. A validated descendant supersedes an older request; an
unvalidated descendant does not. An older source cannot replace newer live code,
even if manually rerun. Each skip records the replacing commit and run or live
release. Requests outside a full bounded inventory fail closed. To intentionally
roll back application behavior, merge a reviewed forward revert on main and let
its normal validation/deployment run.

Both writers inventory at most 500 OHIF artifacts across five bounded pages,
failing closed on incomplete, oversized or changing inventories. They resolve
the highest main OHIF source run with a retained build
artifact, then its highest artifact ID. Artifact upload time does not turn a
rerun of an old source run into a newer request. A canceled publication still has
an eligible build. The artifact's workflow, source, ID and archive SHA-256 must
match; extraction rejects escapes and links. To intentionally select another
OHIF version, dispatch a new build from main with the desired release tag.

Publication records source commit, source run, artifact ID, archive digest,
full-tree digest and writer run/attempt in the private shared `ohif-publication`
record. The publisher proves actual full-tree bytes, even when a digest marker
claims they match, and repairs corruption from the exact build. It refuses stale
requests and immutable-artifact relabeling. Application publication borrows the
shared action's lock inside live verification, before checking public assets and
committing healthy. First-install staging acquires its own lock.

`Deployment Request Audit` observes terminal main CI/OHIF runs independently,
including requests canceled while pending at either concurrency level. It saves
request identity/conclusion and observed desired main/OHIF sources in a 90-day
artifact and job summary. GitHub does not report cancellation cause: this record
provides replacement context without claiming which arrival caused it. Application
supersession and OHIF publication summaries provide the explicit decision record.
The observer reads deployment metadata only and does not deploy or read patient
records. Expired/deleted OHIF artifacts cannot be reconstructed; dispatch a fresh
build if no retained desired artifact remains.
