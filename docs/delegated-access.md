# Delegated access operations

The identity provider can manage PHR accounts from its own user-management screen through
`POST /application-access` (delegated access contract version 2, served by
`bherila/auth-laravel`). PHR answers it with `App\Services\Accounts\PhrApplicationAccessAdapter`.

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
locked rows of an update. Each change is written to the authentication audit log
(`auth_audit_log`, events `delegated_access_*`) with the request's `jti` in `metadata`, for
correlation with the provider's records. Like any role change, it also invalidates the
person's existing agent API credentials.

## Enabling it

The endpoint answers 404 until it is enabled, and refuses every `update` (403) until writes
are enabled as a separate step. Start read-only, check the provider's screen, then enable
writes.

| Variable | Meaning |
|---|---|
| `PHR_DELEGATED_ACCESS_ENABLED` | `true` to answer; default `false` |
| `PHR_DELEGATED_ACCESS_WRITES_ENABLED` | `true` to accept provisioning and role changes; default `false` |
| `PHR_DELEGATED_ACCESS_ISSUER` | the provider's exact HTTPS issuer; must equal `OAUTH_PROVIDER_URL` |
| `PHR_DELEGATED_ACCESS_ENDPOINT` | this endpoint's exact HTTPS URL as the provider calls it, e.g. `https://phr.example.test/application-access` |
| `PHR_DELEGATED_ACCESS_APPLICATION` | PHR's key in the provider's application registry |
| `PHR_DELEGATED_ACCESS_PUBLIC_KEYS` | `key-id\|/path/to/public.pem`, comma-separated |
| `PHR_DELEGATED_ACCESS_NONCE_CONNECTION` | optional; the nonce table's connection, the default one otherwise |
| `OAUTH_PROVIDER` | must be set explicitly; accounts are bound under this name |

**One key per application.** The provider signs PHR's requests with an integration key pair
issued for PHR alone, under PHR's own registry key. List only that public key; never reuse
one issued for another application. To rotate, list both keys, switch the provider to the
new key id, then remove the old one.

If a key file is unreadable, `OAUTH_PROVIDER` is unset, or the issuer is not the sign-in
provider, every request is refused (`invalid_verifier_configuration`) rather than answered.

Replay protection uses the `bherila_auth_delegated_nonces` table, created by the ordinary
migrations; `bherila-auth:prune-delegated-nonces` runs daily and removes only expired entries.
Bound the route's request body to 64 KiB at the web server where possible, and do not let
anything in front rewrite the body or strip `Authorization`: the signed assertion covers the
exact bytes.

To stop accepting changes, set `PHR_DELEGATED_ACCESS_WRITES_ENABLED=false`; to turn the
endpoint off entirely, set `PHR_DELEGATED_ACCESS_ENABLED=false`. Neither needs a change at the
provider. Clear the configuration cache after changing either.
