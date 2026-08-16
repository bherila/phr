# Agent API security and operations

The versioned agent API is an additional bearer-token boundary around existing PHR
authorization services. It does not grant database, filesystem, shell, or blanket
account access. Every clinical operation must independently require an OAuth scope
and resolve the requested patient through `PhrPatientAccessService`.

## Threat model

The primary threats are a stolen access or refresh token, a malicious OAuth client,
cross-patient identifier substitution, excessive enumeration, replayed writes, and
clinical data escaping through logs or diagnostics. The controls are short-lived
access tokens, one-use rotating refresh tokens, explicit user consent, default-deny
scopes, patient grant checks, bounded pagination, per-user throttling, idempotency and
concurrency preconditions on writes, and metadata-only audit records.
The public authorization and token endpoints have dedicated pre-authentication IP
buckets, separate from each other and from protected-resource buckets, so invalid
requests cannot create unbounded session, parsing, or transaction work.
Protected-resource pre-authentication buckets normalize numeric patient and record
path segments, preventing identifier changes from creating fresh parsing budgets.

Patient discovery deliberately has its own `patients:read` scope. Its response omits
the owner's user id and every grant except the caller's fixed access metadata. The
separate `clinical:read` scope permits list/get access to the fixed core-resource
allow-list only after the patient id is resolved through `PhrPatientAccessService`.
Clinical list responses are cursor-bounded to 100 rows, and source/update filters are
validated before query construction. Update windows treat a native restore's ingestion
time as an update without rewriting the archived record's full-fidelity timestamps;
the watermark lives only in the archive-excluded native identity ledger. Restore
publishing uses a short two-transaction handoff: pending identities are always treated
as new, then their ingestion timestamp and terminal restore status become visible
atomically after the patient graph commits. The fixed
catalog maps route slugs to the same
model and JSON Resource classes consumed by the browser API; route input never selects
an arbitrary class or table.

Clinical mutations require the separate `clinical:write` scope plus owner/manager
access. The first write surface is a fixed office-visit/procedure allow-list. Its
Laravel validation rules are shared with browser CRUD, while a typed command and write
DAO keep REST and MCP payload handling out of the persistence service. Stable external
IDs are stored under `agent-client:<OAuth client UUID>` so another client cannot claim
the same identity. Source-document IDs are resolved inside the target patient boundary.
Every mutable record exposes an opaque HMAC version derived from all stored attributes;
changed writes require an exact version, while an exact replay returns `unchanged`
before the conflict check. The HMAC prevents low-entropy clinical values from being
tested offline and catches same-second changes that timestamp-only concurrency misses.

Unified search uses a second fixed catalog of searchable columns and emits only a
concise record envelope; it never serializes raw clinical text. EOB responses likewise
omit raw parser payloads, member and tax identifiers, check numbers, and actor ids.
Evidence links are resolved from direct patient-scoped columns and fixed pivot tables,
not from a client-selected table or join.

Document metadata has an independent `documents:read` scope and omits storage paths,
storage disks, content hashes, extracted text, and actor/job identifiers. Downloading
bytes requires both a current bearer token with that scope and a one-minute signed URL
created for the same patient-scoped document. The file route pins storage to the
declared private document disk, rejects missing bytes, forces attachment disposition,
and applies no-store, sandbox, and content-sniffing protections. The signature is not
a bearer credential, and the bearer token alone cannot call the unsigned file route.

Document creation separately requires `documents:write` plus owner/manager access. The
browser and agent controllers share one upload service, validation request, canonical
storage-key builder, and patient artifact write lock. Agent uploads require a stable
external ID namespaced to the OAuth client. Inside the patient lock, an exact identity
and hash is an unchanged retry, while a changed hash for the identity or a second
identity with the same hash in that client namespace conflicts without writing another
row or blob. A composite patient/source/hash index supports that bounded
deduplication lookup. MCP materializes its size-limited base64 input only for the
duration of the internal multipart REST subrequest and removes the temporary file in a
finally block; larger uploads stay on the ordinary multipart endpoint.
Upload responses also preserve scope separation: write-only tokens receive an opaque
document ID and processing state, while title, summary, dates, tags, filenames, and
other document metadata remain behind `documents:read`.

Structured imports retain the same scope split. `imports:write` permits queueing,
bounded retries, and terminal proposal decisions but does not reveal extracted data;
`imports:read` is required to list jobs or inspect proposals. A failed job is represented
by a stable failure code rather than its stored provider error or raw response. Retry
clears stale, unreviewed output before redispatch and refuses exhausted or already
reviewed jobs. Import creation reuses the browser staging service, pins document reads
to the owned document disk, cleans unpublished staging bytes on failure, and relies on
the existing pending-job recovery command if queue dispatch is temporarily unavailable.

The agent audit table intentionally excludes request URLs, route parameters, query
strings, request and response bodies, filenames, error messages, IP addresses, and
user agents. It records only an opaque request UUID, actor/client/token references,
the fixed route name, method, status, duration, and timestamp. Application and OAuth
errors must remain generic; operational logs must never include bearer credentials or
clinical payloads. Repeated 429 responses are atomically sampled to one audit per
actor, route, and UTC minute, and metadata audits are pruned after 365 days by default.

## Token lifecycle

Interactive clients use Authorization Code with S256 PKCE. Access tokens expire after
15 minutes. Refresh tokens expire after 30 days and are revoked when exchanged, so a
successful refresh rotates the credential. Password and implicit grants are disabled.
Each rotation family has a dedicated database row used as its stable lock. Family-aware
pruning retains that lock plus every parent and consumed refresh row while any successor
can remain active, so long-lived clients keep the advertised refresh behavior and replay
can revoke the entire family, including any successor issued from a stolen token.
Deployment verifies that the persisted signing pair is matching RSA key material
with a modulus of at least 2048 bits before the application is activated.

The client can immediately disconnect itself with `DELETE /api/v1/oauth/token`; any
authenticated token may revoke itself even without an identity scope. This serializes
against refresh rotation and revokes the presented token's entire rotation family.
Disabling or deleting
an account revokes all of its token families, and the bearer/refresh paths also reject
an account that no longer has login permission. An account
operator can also revoke a client token from the application database using Passport's
token models. If a signing key may have escaped, revoke all active OAuth tokens before
replacing the key pair. Key replacement invalidates every outstanding access token and
must be treated as an incident response action, not a routine deploy step.

Dynamic registration is limited to public clients: the endpoint never issues a client
secret and accepts only authorization-code plus refresh grants. Redirects must be HTTPS
or HTTP loopback URLs and remain exact-match inputs to Passport. Registration is
IP-throttled, bounded to small JSON requests, and unused registrations are removed after
24 hours. MCP authorization requests require the canonical `/api/v1` resource indicator;
the same audience is persisted on the authorization code and access-token row, checked
again at code exchange and refresh, and never derived from a bearer token supplied by a
different resource server.

The MCP Streamable HTTP endpoint adds `mcp:use` as an independent connection scope;
every tool call still passes through the underlying REST route's narrower data scope.
Its fixed tool catalog delegates to a typed DAO and cookie-free internal REST transport,
so it reuses route validation, patient authorization, serializers, throttles, and audit
middleware instead of becoming a second clinical implementation. The scoped request
context is restored after each subrequest. Browser session cookies are never copied.

The transport keeps the SDK's CORS, DNS-rebinding, and protocol-version protections,
uses a 256 KiB request ceiling, and accepts cross-origin browser requests only from an
explicit configuration allow-list. MCP sessions expire after 30 minutes and use an
irreversible token-derived cache namespace, preventing a session UUID from crossing token
boundaries. Session state contains protocol negotiation only. The SDK receives a null
logger because its debug logger includes tool arguments and results; application logs and
default traces therefore cannot acquire clinical payloads through the MCP layer.

Production signing keys live only in `storage/app/private/oauth`, outside the deployed
repository contents. Deployment creates them once when both are absent, preserves them
on later rsyncs, rejects a partial key pair, and never prints key material.

## Release invariants

- Add each route to the versioned OpenAPI document and capabilities response.
- Require the narrowest applicable scope on the route.
- Resolve every patient identifier through the shared patient-access service.
- Keep list responses cursor-paginated with a maximum page size of 100.
- Test unauthorized patients, missing scopes, expired/revoked tokens, rate limits,
  metadata-only audit fields, and PHI-safe failures for each representative flow.
- Never add clinical values, identifiers, filenames, object keys, or token material to
  logs, telemetry, audit rows, tests, or public review artifacts.
