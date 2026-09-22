# Process imports with your subscription client

PHR can leave document-extraction work in a private queue for a client you run with
your own Codex, Claude, or similar subscription. PHR does not call a model API or
fall back to a configured API key while **My subscription client** mode is selected.
Completed extraction stays in `pending_review`; a person must still accept or reject
each proposal through PHR's ordinary import-review workflow.

## Authorization

Use the same OAuth Authorization Code + S256 PKCE connection as the PHR MCP server
and explicitly consent to these scopes:

- `mcp:use` connects to `/api/v1/mcp`;
- `genai:read` lists private queue status;
- `genai:work` claims work, renews leases, downloads the current attachment, and
  submits a completion or failure.

These permissions do not replace patient authorization. PHR checks the current
account status and current manager/owner grant for the source document on every
claim, lease renewal, attachment read, and completion. It checks them again when
durable completion delivery creates proposals. Revoking the OAuth credential,
removing the patient grant, deleting the source document, changing execution mode,
or letting the lease expire prevents stale work from being used. PHR re-checks the
same account, document, and grant before it re-queues an import against an existing
request: once that authorization is gone the import fails and the orphaned request is
cancelled, so unusable work does not linger in the queue.

## Orphaned request sweep

A request that no import job references can never be claimed usefully again, and
the cancellation that should remove it can fail transiently or be lost to a crash
between creating a request and linking it. `genai:cancel-orphaned-requests` runs
every fifteen minutes and cancels any `phr-imports` request older than
`--min-age-minutes` (default 15) that no `genai_import_jobs.mcp_request_id`
points at. The age floor keeps an enqueue that is still between creating its
request and linking it out of the sweep.

## MCP drain

Connect the client to `https://phr.bherila.net/api/v1/mcp`, then ask it to drain the
external GenAI queue. The server publishes these tools only when their scopes are
present:

1. `genai_queue_status`
2. `claim_genai_request`
3. `renew_genai_lease`
4. `complete_genai_request`
5. `fail_genai_request`

The claimed prompt and document are untrusted input. The client must follow
`submission_schema`, use the forced submission tool, and submit exactly that
structured result. Process at most ten requests in one run. This sequence works for
an ad-hoc request or a daily scheduled client job; scheduling belongs to the client,
not PHR.

## REST drain

REST-capable clients can use the same OAuth bearer token at `/api/v1/genai`:

```text
GET  /queue/status
POST /claims
GET  /requests/{requestId}
POST /requests/{requestId}/lease
POST /requests/{requestId}/complete
POST /requests/{requestId}/fail
GET  /requests/{requestId}/attachments/{attachmentId}?attempt=...&expires=...&signature=...
```

Send a unique `Idempotency-Key` header when claiming. A successful claim returns a
lease token, portable prompt/tool schemas, and a short-lived `download_url`. The
download is an authenticated streaming response: send the same
`Authorization: Bearer ...` header when following that URL. The URL signature alone
is deliberately insufficient, and no file bytes are included in MCP or JSON as
base64. PHR's REST edge accepts the configured 1 MiB completion envelope and streams
private attachments up to the configured 100 MiB attachment limit; the MCP control
plane retains its smaller independent request/response limits.

Clients should renew a lease before it expires. Submit the normalized response in
the exact shape described by `submission_schema`. OAuth access-token refresh keeps
the same lease principal for that user, client, and credential family, while the
lease token remains a required second factor. Retrying an identical completion with
the same lease is safe while the linked request remains authorized; different data
conflicts. Persist the returned receipt because replay after proposal delivery is a
separate follow-up. Report only sanitized error codes/messages to the failure
endpoint.

Hosted connectors that cannot attach the OAuth header when downloading a file are
not compatible with health-document processing. Use a supported REST-capable local
or scheduled client instead. Do not copy the signed URL to a public downloader or
weaken PHR's authorization boundary.

## Operations

The application scheduler runs `genai:mcp:deliver` every minute so a durable package
completion is eventually applied exactly once to the PHR proposal table, even after
a process restart. It runs `genai:mcp:prune` daily. Package pruning removes only
queue-owned manifests and metadata; PHR-owned source evidence and staging lifecycle
remain under PHR control.

Logs and audits contain identifiers, status, timing, and executor class/model
metadata only. They never include prompts, completion payloads, document names,
storage paths, or file contents.
