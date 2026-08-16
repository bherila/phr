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

The agent audit table intentionally excludes request URLs, route parameters, query
strings, request and response bodies, filenames, error messages, IP addresses, and
user agents. It records only an opaque request UUID, actor/client/token references,
the fixed route name, method, status, duration, and timestamp. Application and OAuth
errors must remain generic; operational logs must never include bearer credentials or
clinical payloads.

## Token lifecycle

Interactive clients use Authorization Code with S256 PKCE. Access tokens expire after
15 minutes. Refresh tokens expire after 30 days and are revoked when exchanged, so a
successful refresh rotates the credential. Password and implicit grants are disabled.

The client can immediately disconnect itself with `DELETE /api/v1/oauth/token`; any
authenticated token may revoke itself even without an identity scope. This revokes
both the current access token and its associated refresh token. Disabling or deleting
an account revokes all of its token families, and the bearer/refresh paths also reject
an account that no longer has login permission. An account
operator can also revoke a client token from the application database using Passport's
token models. If a signing key may have escaped, revoke all active OAuth tokens before
replacing the key pair. Key replacement invalidates every outstanding access token and
must be treated as an incident response action, not a routine deploy step.

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
