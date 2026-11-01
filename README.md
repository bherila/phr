# PHR — Personal Health Record

PHR is a patient-centered health record application for collecting, organizing,
reviewing, and exchanging longitudinal medical information. It combines structured
clinical records with source documents and medical imaging while preserving the
provenance that connects an imported fact to its original evidence.

The application is built with Laravel 13, React 19, TypeScript, and Vite. It can be
used through the browser, a versioned OAuth API, or an MCP client.

## What it does

- Organizes office visits, procedures, medications, conditions, allergies,
  immunizations, labs, vitals, health logs, and insurance EOBs by patient.
- Stores supporting documents and links them to the clinical records derived from
  them.
- Imports C-CDA, FHIR R4 bundles, MyChart archives, EOB data, and reviewed structured
  extraction results.
- Imports and displays DICOM studies, including conventional viewers and a 3D volume
  exploration workflow.
- Searches a selected patient's structured records, notes, document metadata, and
  available extracted text.
- Exports clinical summaries as C-CDA, FHIR, and PDF, and creates a native archive
  that preserves PHR-specific records and original files.
- Accepts respiratory event data from Sinus Sentinel and other authorized clients.
- Supports patient sharing with explicit access levels and patient-scoped
  authorization.

## Architecture

The patient is the central security and data boundary. Clinical records, documents,
imaging, imports, exports, and shares all belong to a patient profile. Requests reach
the same access and domain services whether they originate in the browser or through
an integration.

```mermaid
flowchart LR
    Browser[React PHR] --> Web[Laravel web and session API]
    Client[OAuth client] --> REST[Versioned REST API]
    MCP[MCP client] --> MCPServer[MCP server]
    Device[Paired device] --> DeviceAPI[Device ingest API]

    Web --> Domain[Patient access and domain services]
    REST --> Domain
    MCPServer --> REST
    DeviceAPI --> Domain

    Domain --> DB[(Relational clinical data)]
    Domain --> Queue[Queued imports, exports, and maintenance]
    Domain --> Blobs[Documents, DICOM, and generated artifacts]
```

### Browser application

The React application lives in `resources/js/phr`. Its shell keeps a patient's record
sections in a permanent sidebar, shows each section full width, and opens a selected
record in a detail panel beside the list; every view has its own URL (see
[the interface shell](docs/ui-shell.md)).
Clinical modules share Zod-validated API types, common patient navigation, and a
patient-scoped command search. Blade supplies the authenticated application shell;
React owns the interactive PHR experience.

### Laravel domain

Laravel controllers expose session-authenticated browser endpoints and the external
API. Business rules live in services under `app/Services/PHR`, including patient
access, imports, documents, DICOM processing, health logs, respiratory data, exports,
native backup, restore, and patient deletion. Eloquent models represent clinical
records and their evidence relationships.

`PhrPatientAccessService` is the common authorization boundary. Controllers and
commands resolve a readable, writable, or owned patient before operating on that
patient's data.

### Data and storage

Structured health information is stored relationally so it can be searched, linked,
reviewed, and exported. Larger artifacts are stored through Laravel Flysystem:

- source documents and their extracted text metadata;
- original DICOM instances and derived volume caches;
- generated exports and native backup archives.

Database records retain the storage reference and provenance. Clinical records can
link back to source documents, EOB evidence, and related imaging rather than copying
the underlying artifact.

### Import and review pipeline

Deterministic importers handle C-CDA, FHIR, MyChart, and supported EOB formats.
Document processing can also produce structured proposals from extracted content.
Those proposals are staged for review before acceptance into the clinical model, so
source material and interpreted data remain distinguishable.

When a C-CDA, FHIR or MyChart file is imported from a stored document (an agent's
`imports.create`), every clinical row it writes carries `source_document_id` for that
document, and labs and vitals carry the format as their `source` (`ccda_import`,
`fhir_import`). A file imported without a stored document (the browser's direct
import, the `phr:import:*` commands) writes unlinked rows. A row keeps the document it
was first linked to: a later import, including one that adopts an earlier load's row,
fills a missing link or source but never moves or clears one, and filling one alone is
not a change, so it neither reopens review nor counts as an update.

Long-running document processing, exports, backup/restore operations, and cleanup use
Laravel queues. Idempotency and source identities allow supported imports and API
writes to be retried safely.

### APIs and integrations

The browser uses patient-scoped JSON endpoints under `/api/phr`. External clients use
the versioned `/api/v1` API with OAuth Authorization Code and PKCE, granular scopes,
and the same patient access rules and JSON resources as the application.

The MCP server is an adapter over that REST surface. Its tools call the versioned API
instead of querying models directly, keeping validation, authorization, rate limits,
and audit behavior in one place. Device pairing and respiratory ingest use a narrower
credential path designed for data-producing devices.

The API contract is generated from the operation registry and served with the
installation's own API and OAuth URLs at `/api/openapi.json`. Its checked copy,
[`public/openapi/phr-agent-v1.json`](public/openapi/phr-agent-v1.json), is pinned to the
generated document by a test (`UPDATE_OPERATION_SNAPSHOTS=1` rewrites it); schemas live in
its components, and everything else comes from `app/Support/AgentApi/AgentRestDocumentation.php`. REST connectors
authenticate with OAuth or with a personal API token; both are created by the signed-in
person under **Config → API Access**, limited to the permissions they choose.

Every agent operation is declared once in a capability registry. One evaluation per request decides which MCP tools a connection sees,
and `/api/v1/me` reports the same result: the operations the credential can use now, and
each withheld one with its reason (a missing scope, or depending on a withheld operation). See
[`docs/agent-api-security.md`](docs/agent-api-security.md) for the integration threat
model and security boundaries.

REST routes use `Route::operation()` to read their paths and requirements from the
registry. Missing scopes return the withheld operation, reason, and detail alongside
an `insufficient_scope` bearer challenge. The public capabilities inventory is also
derived from the registry. Reconciliation preview is a prerequisite for MCP tool
discovery; REST apply still accepts a preview digest obtained with another credential.

## Connect an MCP client

PHR's remote MCP endpoint is `https://phr.bherila.net/api/v1/mcp`. It uses OAuth
with Bherila.net for sign-in; do not create or paste a personal API token. The
browser opened by the login command shows the requested PHR permissions before
continuing to the client.

Install it for your user account, then complete the browser login:

```bash
# Codex CLI
codex mcp add phr --url https://phr.bherila.net/api/v1/mcp \
  --oauth-resource https://phr.bherila.net/api/v1/mcp
codex mcp login phr

# Claude Code CLI
claude mcp add --transport http --scope user phr https://phr.bherila.net/api/v1/mcp
claude mcp login phr
```

Restart the client if it was already running. The MCP endpoint is its own OAuth
protected resource, `https://phr.bherila.net/api/v1/mcp` (metadata at
`/.well-known/oauth-protected-resource/api/v1/mcp`), so its credentials work only there
and REST credentials are refused by it. The MCP initialization response teaches
compatible harnesses to call `identity.get`, then use `patients.list` and
`patients.get` to select and confirm a patient without inferring an ID. Clients that
implement MCP prompts can also expose the guided `safely-update-clinical-record` and
`review-import-proposal` workflows when their required tools are authorized. Tool and
prompt discovery is filtered to the connection's granted OAuth scopes, and every call
still enforces patient access through the underlying REST route. Because PHR content is
health information, connect only a client and account you trust.

### ChatGPT

ChatGPT connects through a plugin package rather than a CLI command. Build it with
`pnpm chatgpt-plugin`, which writes `build/phr-chatgpt-plugin.zip`, and upload that
under Plugins in ChatGPT (developer mode, or a workspace admin on team plans). The
package in [`integrations/chatgpt-plugin`](integrations/chatgpt-plugin) holds no server
code: `mcp.json` points ChatGPT at the endpoint above with OAuth dynamic client
registration and a narrower default scope set, and a skill teaches the safe
read-before-write workflow. `tests/Feature/ChatGptPluginTest.php` fails if the skill
names a tool or resource type the default scopes do not expose.

### Process imports with your own subscription

In **AI Provider Settings**, choose **My subscription client** to keep document
extractions in your private PHR queue instead of calling a server-side model API.
An OAuth-connected Codex, Claude Code, or REST-capable client can drain that queue
ad hoc or on a schedule with the dedicated `genai:read` and `genai:work` scopes.
Files are streamed from short-lived URLs that still require the OAuth bearer header;
they are never embedded in MCP messages. See
[`docs/external-genai-processing.md`](docs/external-genai-processing.md) for the MCP
tools, REST workflow, lease behavior, and hosted-connector limitation.

### Data portability

Interoperability exports and native backups serve different purposes:

- C-CDA, FHIR, and PDF provide portable clinical summaries.
- The versioned native archive preserves application-specific relationships and
  original artifacts for lossless backup and restore.

The Data Hub inventories a patient's records and artifacts, creates exports and native
backups, validates native restores before applying them, and coordinates patient
deletion with durable artifact cleanup. The native archive format is documented in
[`docs/phr-native-v1.md`](docs/phr-native-v1.md).

Epic EHI Tables exports use `phr:ehi:intake` to preserve and account for their
source evidence. The MyChart ZIP importer directs EHI-shaped packages there unless
it finds actual FHIR Bundle or C-CDA ClinicalDocument content; a JSON/XML manifest
alone is insufficient. TSV headers and records are limited to 8,192 fields before
cell arrays can grow beyond that bound. Over-limit records are accounted as
malformed while their original bytes remain preserved. Dictionary table names must
match the same ASCII identifier rules as table member names.

## Repository map

PHR pages include the authorized patient list in the existing `application/json`
initial data. A shared React store supplies the navbar, patient pages and import
selector, using the same response shape as `/api/phr/patients`. Create, edit and
delete update that store; sharing changes and completed restores refresh it.
The server uses one list service for HTML and the API. The HTML seed is limited
to 100 profiles and 128 KiB; larger lists use one shared API fallback. Empty lists
are valid initial data. Both HTML and the list API use private, no-store responses.

| Path | Responsibility |
| --- | --- |
| `app/Models/Phr*.php` | Patient, clinical, evidence, imaging, and portability models |
| `app/Services/PHR` | Patient access and domain workflows |
| `app/Http/Controllers/PHR` | Browser-facing PHR endpoints |
| `app/Http/Controllers/Api/V1` | OAuth REST API and MCP entry point |
| `app/GenAiProcessor` | Minimal queued document-extraction pipeline |
| `app/Console/Commands/Phr` | Import, export, reconciliation, and maintenance commands |
| `resources/js/phr` | React PHR modules and the sidebar application shell |
| `resources/views/phr` | Blade mounts and server-rendered PDF views |
| `routes/api.php` | Session, device, OAuth API, and MCP routes |
| `docs` | Format, conformance, and security design notes |
| `integrations/chatgpt-plugin` | ChatGPT plugin package (manifest, MCP server reference, skill) |
| `tests`, `tests-ts`, `tests/e2e` | Backend, frontend, and browser coverage |

## Running locally

Requirements are PHP 8.4–8.5, Composer, Node.js, and pnpm 11.

```bash
composer install
pnpm install
cp .env.example .env
php artisan key:generate
php scripts/configure-agent-mutation-digest-key.php
touch database/database.sqlite
php artisan migrate

composer run dev
```

The default local database is SQLite. Create a local user, grant it the `user` role,
and set its password with:

```bash
php artisan user:set-password you@example.com
```

## Self-hosting

PHR is self-contained apart from sign-in: a fork deploys with its own identity
provider instance and needs no code changes. Every agent-API URL (OAuth issuer,
protected resources `APP_URL/api/v1` and `APP_URL/api/v1/mcp`, authorization, token and
registration endpoints, discovery documents) derives from `APP_URL`.

| Setting | Purpose |
|---|---|
| `APP_URL` | The public origin. Everything the OAuth server and API advertise derives from it. |
| `OAUTH_PROVIDER`, `OAUTH_PROVIDER_URL`, `OAUTH_CLIENT_ID`, `OAUTH_CLIENT_SECRET`, `OAUTH_REDIRECT_URI` | Sign-in through your identity provider instance. It only signs people in; PHR runs its own authorization server for agents. |
| `OAUTH_SERVER_ENABLED` | Kill switch for the agent authorization server (default `true`): discovery, registration, authorization and token issuance. |
| `PASSPORT_PRIVATE_KEY`, `PASSPORT_PUBLIC_KEY` | Token signing keys. When unset they are read from `storage/app/private/oauth`; never commit them. |
| `BHERILA_AUTH_TRUSTED_PROXIES`, `TRUSTED_PROXIES` | Whose `X-Forwarded-For`/`-Proto` to believe (default: on, `cloudflare`). Use a comma-separated list for another proxy, `*` only when a firewall admits nothing but the proxy, or empty with no proxy in front. Per-client rate limits key on the resulting address. |
| `AGENT_API_MCP_ALLOWED_ORIGINS`, `AGENT_API_MCP_ALLOWED_HOSTS` | Browser origins and `Host` values the MCP endpoint accepts. |
| `BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED`, `BHERILA_AUTH_PROVIDER_IDENTITY_CACHE_STORE` | Provider identity enforcement, off by default: when on, a browser session or OAuth credential whose identity was disabled, deleted, reset or ungranted at the identity provider is refused. Sign-in records each session's baseline even while it is off, so enable it only after people have signed in again on such a release; credentials issued before that re-authorize once. Needs the provider's identity-status endpoint and a lock-capable cache store shared by every web worker (default: `CACHE_STORE`). |
| `OPENFDA_API_KEY` | Optional openFDA key for NDC lookups of prescription fills (without one, openFDA allows 1,000 requests a day). Package answers are cached; only drug descriptors are sent, never patient data. `OPENFDA_TIMEOUT_SECONDS` (default 5) bounds each call. |
| `OAUTH_SERVER_CORS_ALLOWED_ORIGINS` | Browser origins (comma-separated, or `*`) given CORS on OAuth discovery, registration and the token endpoint, for browser-based agents. Empty by default: no CORS headers. Independent of the MCP endpoint's own origin check above. |

The agent authorization server follows the auth package's agent preset: S256 PKCE for
every client, public-only self-registration, and credentials bound to one of two
protected resources, each with its own RFC 9728 document at its path-inserted URL:
REST at `APP_URL/api/v1` (every module scope; an omitted `resource` is taken as this
one) and MCP at `APP_URL/api/v1/mcp` (`mcp:use` plus the module scopes its tools
require). A credential issued for one is refused at the other, and every 401 names the
metadata of the route's own resource. Edge configuration
(proxy and CDN rules) is deployment-specific and not part of this repository.

### Connect a finance app

For patient-scoped delegation, use [patient selection and delegated billing consent](docs/agent-api-security.md#patient-selection-and-delegated-billing-consent).

On a self-hosted instance, the operator registers a finance consumer from the PHR
installation directory. Replace `<App name>` with the name people should see on
consent, and `<callback>` with the exact callback URL supplied by the consumer:

```bash
php artisan phr:agent-api:register-first-party-client "<App name>" "--redirect-uri=<callback>" --scope=billing:read --scope=identity:read
```

Use an HTTPS callback (HTTP is accepted only on loopback). Paste the labelled
client ID and client secret into the consumer's connection setup, along with this
instance's public URL. **Store the secret now:** it is shown only once and PHR
keeps only its hash. Treat the terminal output as a credential; do not paste it
into logs, issues or source control. If it is lost, register a replacement client
with a new name. Scripted setups can add `--json`; the JSON contains `client_id`,
`client_secret`, `scopes`, a `secret_notice`, `repeat_consent_skip_required` and
`warnings`. Protect that output just as you would the text output.

A finance consumer should request only `billing:read` and `identity:read`.
Registration warns if you combine `billing:read` with broader scopes; it does not
silently remove them. Public dynamic registration cannot obtain `billing:read`,
so use this operator command for a finance connection.

Optionally add the client ID to the comma-separated
`AGENT_API_FIRST_PARTY_OAUTH_CLIENTS` setting, preserving any existing entries,
and refresh your deployment's cached configuration. This only skips repeat
consent screens after the person has approved the requested scopes once. Leaving
it unset is supported: the client still works, and consent screens remain. Requests
with `patient_select=1` always show the patient picker, including allow-listed clients.

Open **PHR Config → Connected apps** to review the apps you have authorized,
including their permissions, protected resources and grant dates. **Revoke access**
disconnects that app for your account across resources. Reconnecting requires fresh
consent.

You can also use the consumer's Disconnect action if it calls
`DELETE /api/v1/oauth/consent`, or send that request to this PHR instance with that
connection's bearer token. Both paths withdraw consent and revoke that client's
authorization codes, access tokens and refresh tokens for that person across
resources. Remove the ID
from the optional allow-list if you no longer want repeat-consent skipping.
These steps withdraw access for that person; they do not permanently disable the
client registration for everyone.

To permanently disable the registration for everyone, run the operator command
with the exact client ID you saved at registration:

```bash
php artisan phr:agent-api:revoke-first-party-client "<client-id>"
```

This disables only an operator-registered, ownerless confidential authorization-code
client. It revokes that client's grants for every person, retaining the disabled
registration as a record. Other clients and their grants are unaffected. Repeating
the command is safe; if cleanup is interrupted, the registration stays disabled
and rerunning finishes the grant cleanup. Remove its ID from the optional
`AGENT_API_FIRST_PARTY_OAUTH_CLIENTS` setting and refresh cached configuration.
A disabled registration cannot reconnect; register a new client instead.

## Validation

```bash
pnpm run type-check
pnpm run lint
pnpm run test
pnpm run build
pnpm run test:e2e

./vendor/bin/pint --test
composer audit --locked --no-interaction
php -d memory_limit=1G vendor/bin/phpunit
```

CI runs PHPUnit in three separate runner jobs to isolate their in-memory databases,
fake disks and cached Laravel configuration. PHPUnit's `--list-tests-xml` inventory
is partitioned by class using `.github/phpunit-timings.json`; new classes are
included automatically. These relative weights were measured in [GitHub CI](https://github.com/bherila/phr/actions/runs/39836186052).
They guide balancing, not test selection or runtime guarantees.
The required **Run Tests** check verifies the JUnit reports against the inventory
so every discovered test, including data-provider cases, must appear exactly once.
It continues to require every frontend, backend and integration check.

Each `phpunit-shard-*` artifact retains its inventory, selection and JUnit timings
for 14 days. To rebalance after a successful CI run, download its artifacts and
regenerate the weights (review and commit the resulting JSON):

```bash
gh run download RUN_ID --pattern 'phpunit-shard-*' --dir build/phpunit-results
php scripts/phpunit-shards.php verify build/phpunit-results 3
php scripts/phpunit-shards.php weights .github/phpunit-timings.json build/phpunit-results/phpunit-shard-*/junit.xml
```

For a local shard, generate the inventory, plan a shard numbered 1–3, then run it:

```bash
mkdir -p build/phpunit
php vendor/bin/phpunit --list-tests-xml build/phpunit/inventory.xml
php scripts/phpunit-shards.php plan build/phpunit/inventory.xml .github/phpunit-timings.json 3 1 build/phpunit
php vendor/bin/phpunit --test-id-filter-file build/phpunit/test-ids.txt --fail-on-empty-test-suite
```

Run local shards sequentially unless each process has its own correctly configured
worktree; sharing a checkout also shares fake disks and cached configuration.
The ordinary full-suite command above remains available.

Pint retains a content cache at `.cache/pint.cache`, including when using `--test`.
CI restores that cache and uses two formatter processes for cold or changed files.
The cache checks file contents and the formatter/PHP/rules signature; the full
source set remains checked. For the same local parallel check, run
`./vendor/bin/pint --test --parallel --max-processes=2`.

Production deployments opt into the shared action's canonical runtime audit after
final-path cache refresh and again after finalization. Missing or escaped cached
runtime paths fail without provisioning directories. The post-finalizer PHR hook
checks exact serving identity, lock absence, an empty transaction inventory, the
persistent OAuth key pair, and the two canonical scheduler/worker entries with 1G
limits. The shared audit additionally checks persistent database configuration,
pending migrations and aggregate queue counts. Diagnostics consume no queued work
and emit no patient data or key contents. A newer deployment generation supersedes
post-unlock diagnostics rather than falsely failing an earlier healthy release.

Configured hook paths, managed cron and audit memory settings are validated before
remote deployment state changes.

Atomic deployments verify the exact serving release and PHR's HTTP, assets, OAuth,
MCP, OHIF, queue, key and cron contracts before probing web PHP. An inconclusive
PHP probe can leave that verified release serving and uncommitted while the run
fails. HTTP 200 alone cannot grant this exception; failed application checks and
definitive PHP or memory mismatches restore the retained prior application release
and its scheduler entries through `failure-policy: rollback`. Shared data and applied
migrations are retained, so migrations must remain compatible with that prior code.
The shared health and PHP probes run on the host against its own web server,
while PHR's application verifier checks the public HTTPS endpoints, including
their OAuth, MCP and OHIF access boundaries. HTTP failures report the check name, status,
MIME type, curl exit code and allowlisted Cloudflare indicators. Bodies, cookies,
redirect URLs and raw curl errors remain private and are removed after verification.

The Playwright suite uses isolated local storage and a synthetic OAuth provider. Test
fixtures must remain synthetic.

`Tests\Support\PhrSyntheticChartFixture::seed($ownerUserId)` creates a patient whose chart
looks like real data, for layout checks and screenshots: a 20-result lab panel mixing text
values (Not Detected, Negative, Nonreactive) with flagged numeric values, a second panel with
long analyte names, conditions with long names and ICD-10 codes, and a few records pending
review. The Playwright seeder adds it for the record owner as "Synthetic Chart Patient"; PHPUnit
tests can call it directly. Every value in it is invented.

## Diagnose a deployment left in maintenance

The manual **PHR Maintenance Diagnostic** workflow accepts the exact selected
release and source commit reported by the failed deployment. Run it from `main`
after its changes and pinned shared helpers have been reviewed and merged. It uses
the existing `prod` SSH secrets and queues in the same production concurrency group
as application and OHIF writes. A present deployment lock, interrupted transaction,
unexpected live identity or noncanonical control directory prevents probing.

The diagnostic reports only validated aggregate evidence: canonical maintenance-file presence,
saved application cron presence, selected runtime paths and database persistence,
pending migration and queue counts, `/up` and `/login` HTTP statuses, and web PHP
requirements. `/up` can return 200 while `/login` returns 503 in maintenance; both are
checked. Maintenance-file presence does not infer the state of an alternative cache
maintenance driver. The web PHP helper creates a random temporary file in `public/` and attempts
cleanup on every exit. No service state, cron, migrations, keys or patient data are
changed. A failed web proof reports cleanup as unconfirmed and requires operator
inspection before recovery.

This workflow provides evidence for a deliberate recovery decision. The shared
action's `recovery-release-id` finalizes interrupted transactions; it does not resume
an already finalized release intentionally left in maintenance. The diagnostic
does not resume that release or dispatch a new deployment.

### Production credentials and historical workflow reruns

Production writers and diagnostics use only `PHR_PRODUCTION_SSH_KEY`, backed by a
dedicated restricted ed25519 key. The reviewed credential cutover preserved foreign
server keys and verified a fresh connection using the new key alone. The legacy
`SSH_PRIVATE_KEY` secret was removed from all repository-visible scopes after prior
production-capable runs finished. A rerun of the original pre-policy deployment
then failed at SSH configuration before publication or deployment. The completed
temporary key-bootstrap workflow has been retired.

See the [credential proof](https://github.com/bherila/phr/actions/runs/37247309536)
and [historical rerun denial](https://github.com/bherila/phr/actions/runs/37239370303/attempts/2).
Do not restore the legacy secret name: old workflow definitions would regain their
production credential. Its server authorization remains intact because other
applications may share that key.

## Resume a finalized release left in maintenance

The separate manual **PHR Guarded Maintenance Resume** workflow runs only from
`main`, using the verified `PHR_PRODUCTION_SSH_KEY` credential and the production
writer queue. Review and merge it before dispatch; verify the new credential first.
Supply the exact selected release (`<commit-prefix>-<owning-CI-run>-<attempt>`) and
full source commit. The owning production CI attempt and deployment job must have
completed; cancelled or active attempts are refused.
The owning deployment job must have failed; a successful deployment cannot prove
that it owns a later maintenance marker.
The existing maintenance marker must date to that original deployment job (with
at most one minute of clock skew); its captured timestamp and content hash must
remain unchanged until `up`. A later operator-created marker is refused.

Before any service change, the workflow requires all read-only diagnostic proofs,
a maintenance file, a saved recovery cron file, no active host lock and no pending
transaction. It then acquires the host lock with a unique ownership nonce and repeats
identity, canonical path, persistent database, zero pending migration and web PHP
proofs under that lock. Service state changes use Laravel's existing maintenance
commands, with PHP 8.5 and a 1G limit. Bootstrap caches and facade generation use private temporary paths;
the cached application encryption key is validated and the existing read-only OAuth
key command verifies the canonical persistent signing pair before `up` and before
unlocking. Key file hashes must remain unchanged throughout the operation.
No migrations, application cache refreshes, source, environment, key or patient data
changes are performed. Only file-backed maintenance is supported.

The exact release must serve `/up` with 200 and `/login` with 200 or 302 before the
reviewed shared cron helpers restore the two canonical PHR entries. Other applications'
entries and the saved recovery file are preserved. The recovery file is never installed
as a whole account crontab. Failure after attempting `up` tries `down` and an application-only
cron pause while exact identity, safe config and lock ownership are still provable.
Uncertain ownership or rollback retains the lock and reports unconfirmed recovery;
there is no automatic takeover or claim of successful cleanup. Logs contain fixed
aggregate labels only. A retained lock needs reviewed operator inspection before
another writer or recovery attempt.
Both the host session and SSH transport have total deadlines. An SSH timeout or
disconnect alone does not prove the host has stopped or rollback completed; obtain
fresh read-only evidence and inspect any retained ownership before retrying. Never
blindly rerun or remove a retained lock.

## Privacy

This repository is public. Do not commit real patient names, dates of birth, record
numbers, provider details, addresses, source documents, DICOM data, or other protected
health information. Code, tests, fixtures, commit messages, issues, and CI output must
use synthetic data.

## Additional documentation

- [C-CDA conformance notes](docs/ccda-conformance.md)
- [C-CDA import: structured entries, fallback, re-import](docs/ccda-import.md)
- [Agent API security model](docs/agent-api-security.md)
- [Delegated access operations](docs/delegated-access.md)
- [Patient authorization capability matrix and call-site inventory](docs/patient-authorization-inventory.md)
- [Native backup format](docs/phr-native-v1.md)
- [Interface shell: layout, sidebar, URLs](docs/ui-shell.md)
- [Keyboard navigation](docs/keyboard-navigation.md)
- [Medication fills: web API contract](docs/medication-fills-contract.md)

Production ordering, supersession records, OHIF publication proof, and intentional
rollback procedures are described in [.github/deployment-policy.md](.github/deployment-policy.md).
