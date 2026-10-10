# Delegated access operations

The identity provider can manage PHR accounts from its own user-management screen through
`POST /application-access` (delegated access contract version 3, served by
`bherila/auth-laravel` 0.21 or later, which answers version 3 only). PHR answers it with `App\Services\Accounts\PhrApplicationAccessAdapter`.

## What it manages

PHR is an account-only application: it advertises no workspace roles, and every access value
has `workspaces: []`. Patient sharing stays inside PHR. Two things can be managed:

- **Provisioning.** Creating an account for a provider subject that has none yet. The account is
  bound to the sign-in provider's name (`OAUTH_PROVIDER`) and the exact subject, with
  placeholder name and `.invalid` address until the person's first sign-in replaces them. An
  existing row is never adopted, by address or otherwise; a subject that is already bound is a
  conflict (409).
- **The administrator role** (`application_admin`). Only `admin` in `users.user_role` changes;
  every other role is kept as it is. Demoting an account whose only sign-in role was `admin`
  adds `user`, so it stays an ordinary account rather than being disabled.

Only an active PHR administrator bound to the sign-in provider may use any operation, the
read-only ones included. The administrator role cannot be changed through delegation for:

- account #1, which is always an administrator;
- the acting administrator's own account;
- an account that cannot sign in (promoting it would re-enable it);
- the last administrator who can still sign in.

These are reported as `allowed_edits.application_admin: false` and refused again on the
locked rows of an update.

## Removal

`remove` takes away everything delegation manages, which in PHR is the administrator role and
nothing else. It is a demotion under exactly the rules above:

- `admin` is removed from `users.user_role`; `user` is added if nothing else would let the
  account sign in. Every other role is kept.
- The account, its provider binding, its ability to sign in as an ordinary user, and every
  patient and health record it owns or shares stay. Suspending sign-in or deleting data are not
  part of `remove`.
- Account #1, the acting administrator's own account and the last administrator who can sign in
  are refused (`not_authorized`, 403) and reported as `allowed_edits.remove: false`.
- Removing an account that is not an administrator, including one that cannot sign in, changes
  nothing and answers the same revision; it never re-enables a disabled account.
- An unknown or unbound subject is `not_provisioned` (404); a stale revision is a conflict (409). Each change, a removal included, is written to the authentication audit log
(`auth_audit_log`, events `delegated_access_*`) with the request's `jti` and the write's
`operation_id` in `metadata`, for correlation with the provider's records. A no-op or a refusal
writes nothing. Like any role change, it also invalidates the
person's existing agent API credentials.

## Search and metadata

`subjects` takes an optional `query` (2 to 100 characters): a case-insensitive substring of the
label (the name, or the subject for an account without one) or the address. It searches the same
accounts the listing shows, those bound to the sign-in provider, so an unbound row is never found
by its address. `workspaces` is always an empty page.

Each state and `subjects` entry carries read-only observations, never used for authorization and
outside the revision:

- `provisioned_at`: when the account row was created;
- `last_seen_at`: its latest successful sign-in recorded in `auth_audit_log` (password, code,
  provider or passkey), or `null` when there is none (never signed in, or older than the audit
  log's retention).

`first_sign_in_at` is not reported, since the audit log may be pruned and does not reach back to
older accounts.

## Enabling it

The endpoint answers 404 until it is enabled, and refuses every `update` and `remove` (403)
until writes are enabled as a separate step. Start read-only, check the provider's screen, then enable
writes.

| Variable | Meaning |
|---|---|
| `PHR_DELEGATED_ACCESS_ENABLED` | `true` to answer; default `false` |
| `PHR_DELEGATED_ACCESS_WRITES_ENABLED` | `true` to accept provisioning, role changes and removal; default `false` |
| `PHR_DELEGATED_ACCESS_ISSUER` | the provider's exact HTTPS issuer; must equal `OAUTH_PROVIDER_URL` |
| `PHR_DELEGATED_ACCESS_ENDPOINT` | this endpoint's exact HTTPS URL as the provider calls it, e.g. `https://phr.example.test/application-access` |
| `PHR_DELEGATED_ACCESS_APPLICATION` | PHR's key in the provider's application registry |
| `PHR_DELEGATED_ACCESS_PUBLIC_KEYS` | `key-id\|/path/to/public.pem`, comma-separated |
| `PHR_DELEGATED_ACCESS_NONCE_CONNECTION` | optional; the nonce table's connection, the default one otherwise |
| `PHR_DELEGATED_ACCESS_RECEIPT_CONNECTION` | optional; the operation receipts table's connection, the nonce connection otherwise |
| `OAUTH_PROVIDER` | must be set explicitly; accounts are bound under this name |

**One key per application.** The provider signs PHR's requests with an integration key pair
issued for PHR alone, under PHR's own registry key. List only that public key; never reuse
one issued for another application. To rotate, list both keys, switch the provider to the
new key id, then remove the old one.

If a key file is unreadable, `OAUTH_PROVIDER` is unset, or the issuer is not the sign-in
provider, every request is refused (`invalid_verifier_configuration`) rather than answered.

Replay protection uses the `bherila_auth_delegated_nonces` table, and write receipts the
`bherila_auth_delegated_receipts` table, both created by the ordinary migrations. Every `update`
and `remove` carries an `operation_id`; the package stores its answer, so a retry of the same
operation is answered from the receipt without running again, and the provider can ask for the
outcome of an uncertain write. Writes are refused (`receipt_storage_unavailable`, 503) until the
receipts table exists, so migrate before switching the provider to version 3.
`bherila-auth:prune-delegated-nonces` runs daily and removes expired nonces and receipts older
than 30 days.
Bound the route's request body to 64 KiB at the web server where possible, and do not let
anything in front rewrite the body or strip `Authorization`: the signed assertion covers the
exact bytes.

To stop accepting changes, set `PHR_DELEGATED_ACCESS_WRITES_ENABLED=false`; to turn the
endpoint off entirely, set `PHR_DELEGATED_ACCESS_ENABLED=false`. Neither needs a change at the
provider. Clear the configuration cache after changing either.
