# Patient authorization capability matrix and call-site inventory

Preparatory reference for a staged adoption of a shared privacy/authorization package.
It describes the authorization boundary as the application implements it **today**, for
the first intended pilot scope: `PhrPatient` and `PhrAllergy`. Nothing here proposes a
change; every statement was resolved from the current implementation and the existing
suite rather than from a role name, and the characterization tests named at the end
hold the statements in place.

Scope note: this document covers the patient + allergy pilot. Other clinical resources
share the same traits and services, so most rows generalize, but they were not
separately verified and are not claimed here.

## The three capabilities

`PhrPatientAccessService` (`app/Services/PHR/Access/PhrPatientAccessService.php`) is the
single boundary through which a client-supplied patient id becomes a resolved patient.
It distinguishes exactly three capabilities, and they are not a ladder of one rule.

| Capability | Resolved by | Rule as implemented |
|---|---|---|
| **readable** | `accessiblePatient`, `accessiblePatientWithCurrentGrant`, `accessiblePatientsQuery`, `PhrPatient::scopeAccessibleBy` | `owner_user_id = actor` **or** any `phr_patient_user_access` row for the actor, at any level |
| **writable** | `writablePatient`, `canWrite`, `ensureCanWrite`, `writablePatientsQuery` | `owner_user_id = actor` **or** a grant row at level `owner` or `manager` |
| **actual-owner-only** | `ownedPatient`, `ensureOwner`, `ownedPatientsQuery` | `owner_user_id = actor`, and nothing else |

Three consequences follow, and each is load-bearing:

- **Read sharing is not write permission.** A `viewer` grant reads and never writes.
- **A grant labelled `owner` is not ownership.** It makes the holder *writable*; it does
  not satisfy `ensureOwner`. Sharing, export, native backup and patient deletion compare
  the actor against `phr_patients.owner_user_id` directly, so the grant label cannot
  stand in for it. No supported writer creates such a row today — the sharing request
  accepts only `manager`/`viewer`, and the native-restore planner blocks an owner-level
  share — but the service's distinction is real and is pinned by test.
- **Patient ownership is the resource boundary, not the actor.** The actor is frequently
  a different authorized person; the patient is what scopes the data.

Not-found versus forbidden is itself part of the contract. An actor with **no** grant is
answered `404` — the patient's existence is not disclosed. An actor with a grant that is
merely insufficient is answered `403`. A record id that does not belong to the resolved
patient is `404`, on reads and on every write verb.

There is **no admin, service or CLI bypass**. No role check appears anywhere in the PHR
controllers, the agent controllers or the access service; console commands take an
explicit `--actor` and go through the same three methods.

## Capability matrix — patient and allergy operations

`R` = readable, `W` = writable, `O` = actual-owner-only.

| Operation | Route / entry point | Needs | Token scope | Status |
|---|---|---|---|---|
| List allergies | `GET api/phr/patients/{patient}/allergies` | R | — (session) | existing-protected |
| Show allergy | `GET .../allergies/{allergy}` | R | — | existing-protected |
| Create allergy | `POST .../allergies` | W | — | existing-protected |
| Update allergy | `PATCH .../allergies/{allergy}` | W | — | existing-protected |
| Delete allergy (soft) | `DELETE .../allergies/{allergy}` | W | — | existing-protected |
| Review allergy | `PATCH .../allergies/{allergy}/review` | W | — | existing-protected |
| List patients | `GET api/phr/patients` | R (query) | — | existing-protected |
| Create patient | `POST api/phr/patients` | — (creates own) | — | existing-protected |
| Show patient | `GET api/phr/patients/{patient}` | R | — | existing-protected |
| Update patient | `PATCH api/phr/patients/{patient}` | W | — | existing-protected |
| Delete patient | `DELETE api/phr/patients/{patient}` | **O** | — | existing-protected |
| Deletion preview | `GET api/phr/data-hub/patients/{patient}/deletion-preview` | **O** | — | existing-protected |
| Grant access | `POST api/phr/patients/{patient}/access` | **O** | — | existing-protected, throttled 10/min |
| Revoke access | `DELETE api/phr/patients/{patient}/access/{access}` | **O** | — | existing-protected |
| Patient search | `GET api/phr/patients/{patient}/search` | R | — | existing-protected |
| Export request | `POST api/phr/patients/{patient}/exports` | **O** | — | existing-protected |
| Native backup | `POST api/phr/patients/{patient}/native-backups` | **O** | — | existing-protected |
| Patient page shell | `GET /phr/patient/{patient}` | R | — | existing-protected |
| Agent: list patients | `GET api/v1/patients` | R (query) | `patients:read` | existing-protected |
| Agent: show patient | `GET api/v1/patients/{patient}` | R | `patients:read` | existing-protected |
| Agent: create patient | `POST api/v1/patients` | — (creates own) | `patients:write` | existing-protected |
| Agent: list allergies | `GET api/v1/patients/{patient}/allergies` | R | `clinical:read` | existing-protected |
| Agent: show allergy | `GET api/v1/patients/{patient}/allergies/{record}` | R | `clinical:read` | existing-protected |
| Agent: resolve external ids | `POST api/v1/patients/{patient}/allergies/resolve` | R | `clinical:read` | existing-protected |
| Agent: upsert allergy | `PUT api/v1/patients/{patient}/allergies` | W | `clinical:write` | existing-protected |
| Agent: patch allergy | `PATCH api/v1/patients/{patient}/allergies/{record}` | W | `clinical:read` **and** `clinical:write` | existing-protected |
| Agent: retract allergy | `POST api/v1/patients/{patient}/allergies/{record}/retract` | W | `clinical:write` | existing-protected |
| Agent: record search / timeline / changes | `GET api/v1/patients/{patient}/{records,timeline,changes}` | R | `clinical:read` | existing-protected |
| MCP allergy tools | `POST api/v1/mcp` | R / W per tool | `mcp:use` + the tool's own scope | existing-protected (re-enters the routes above) |
| Import C-CDA / FHIR / MyChart / EOB / PDF | `php artisan phr:import-*` | W | — | existing-protected, explicit `--actor` |
| Reconcile EOB allergy procedures | `php artisan phr:reconcile-*` | W | — | existing-protected, explicit `--actor` |
| Export / backup commands | `php artisan phr:export` | **O** | — | existing-protected, explicit `--actor` |
| Accept a GenAI extraction | `POST` GenAI import accept | W | — | existing-protected (patient id read from the job's own context, not the client) |
| Export data assembly | `PhrExportDataService` | caller-resolved | — | trusted infrastructure |
| Structured import | `PhrStructuredDataImporter::importPayload` | caller-resolved (`PhrPatient` object) | — | trusted infrastructure |
| Native backup / restore catalog | `PhrNativeBackupCatalog`, `PhrNativeRestorePlanner` | **O** at the controller | — | existing-protected + trusted infrastructure |
| Deletion cleanup job | `CleanupDeletedPhrPatientArtifactsJob` | post-authorization cleanup | — | trusted infrastructure |
| Export / backup generation jobs | `GeneratePhrExportJob`, `GeneratePhrNativeBackupJob` | post-authorization | — | trusted infrastructure |
| Restore preview / apply jobs | `PreviewPhrNativeRestoreJob`, `ApplyPhrNativeRestoreJob` | post-authorization | — | trusted infrastructure |
| Data Hub inventory | `PhrDataInventoryService` | O + R (two queries) | — | existing-protected |
| Serialized allergy payload | `AllergyResource` | — | — | trusted infrastructure (no authorization of its own; callers resolve first) |
| File storage and delivery | signed URLs, disks | separate boundary | `documents:*` / `exports:*` | **not yet verified** — out of pilot scope |

**Token abilities restrict; they never expand.** A `clinical:write` ability on a token
held by a `viewer` still cannot write: the scope check and the grant check are
independent, and both must pass. Equally, the read and write scopes are separate, and a
missing scope is a default deny.

### What a resolved patient does *not* authorize

`resolveClinicalResource` in `HandlesClinicalResourceRequests`, and every agent read and
write path, scope the record query by `patient_id` of the **resolved** patient before
`findOrFail`. The child id is therefore never a client assertion: an allergy requested
under patient A must belong to A, even when the actor holds grants on both A and B.

## Writers of patient grants, ownership, and actor/token validity

Every writer that can change who may reach a pilot patient.

### Grant rows (`phr_patient_user_access`)

| Writer | File | Authorization | Notes |
|---|---|---|---|
| Owner-grant on patient creation | `PatientController::store` | actor becomes owner | one `owner` row, in the same transaction as the patient |
| Owner-grant on agent patient creation | `AgentPatientController::store` | `patients:write`, actor becomes owner | mirrors the browser path |
| Share a patient | `PatientAccessController::store` | **actual-owner-only** | `updateOrCreate` on (patient, user); level restricted to `manager`/`viewer`; self-grant and unknown address are uniform no-ops |
| Revoke a share | `PatientAccessController::destroy` | **actual-owner-only** | refuses to delete a row at level `owner` |
| Native restore | `PhrNativeRestorePlanner` / `PhrNativeRestoreService` | actual-owner-only at the controller | blocks a restored share whose level is `owner`; shares are omitted unless explicitly requested |
| Patient deletion cascade | `PhrPatientDeletionService` + FK `cascadeOnDelete` | **actual-owner-only**, re-checked under a row lock | counts active shares and requires acknowledgement |
| Schema backfill | `2026_05_17_042849_normalize_phr_patient_schema` | migration | historical, one-time |

A grant row `$touches` its patient, so any grant change bumps `phr_patients.updated_at`
and therefore advances the agent API's patient update window.

### Ownership (`phr_patients.owner_user_id`)

Written in exactly two places — `PatientController::store` and
`AgentPatientController::store` — always to the creating actor. There is **no transfer
path**. `owner_user_id` is `$fillable` on the model, so the guard is the validated-field
allow-list: neither `StorePatientRequest` nor `UpdatePatientRequest` declares it, so
`update($request->validated())` can never carry it. The same shape protects the allergy
scope columns: `patient_id`, `user_id`, `review_status`, `import_source`, `external_id`
and `source_document_id` are all `$fillable` on `PhrAllergy`, and all absent from
`AllergyDataRules`, so `storeClinicalResource`'s `[...bound scope, ...$request->validated()]`
spread cannot be overridden by the client. **This makes the rules class, not `$fillable`,
the security boundary for mass assignment.** A future move of these fields into a rules
class would silently open reparenting.

### Actor and token validity

| Writer | File | Effect |
|---|---|---|
| Role change | `User` saved-model hook | a role that can no longer sign in revokes that account's OAuth tokens |
| Login gate on every agent request | `EnsureOAuthUserCanLogin` | re-checks `canLogin()` per request and revokes on failure |
| Self-disconnect | `AgentTokenController::destroy` | revokes one rotation family, outside the ordinary throttle bucket |
| Family revocation | `OAuthCredentialRevoker` | revokes a family or an account's credentials under a lock ordered family-root first |
| Refresh rotation | `AccountAware*Repository`, `OAuthExchangeAccountGuard` | binds credentials to the account that authorized them |
| Scope grant | authorization flow + `AgentApiScopes` | reserved scope names are not registered until the operation ships, so an old refresh token cannot gain future capabilities |
| MCP key lifecycle | `User::mcpTokenIsActive` | a token with no recorded expiry is inactive, failing closed |

Authorization is resolved **per request** from the grant rows; nothing caches a positive
permission, and no durable allow decision is serialized into a queued job. Revocation is
therefore effective on the next request. A revoke racing an in-flight write is **not**
covered by any current locking protocol between the grant writers and the allergy write
path, and no such guarantee is claimed here.

## Review lifecycle (a separate axis from access)

Write permission does not include the power to accept clinical content.

- `pending_review` is server-assigned. Every agent create writes it, and every effective
  agent edit reopens it.
- `review_status` is `prohibited` on both agent write requests — refused, never silently
  dropped, so a caller can never believe it confirmed a record.
- The browser review endpoint accepts only `confirmed` and `rejected`; a reviewer cannot
  put a record back into the queue by hand.
- The review endpoint requires **writable**, not merely readable, and resolves the
  record through the patient pair like every other write.
- Rejected records leave the working list but stay reachable with `include_rejected`.
- Exports emit only `confirmed`, non-retracted records.

## Disclosure rules that constrain any future refactor

- A write-only token receives a **receipt** — `id`, `patient_id`, `review_status`, plus
  the new opaque version — and never record contents, on create, idempotent replay or
  retraction.
- A failed precondition (`409`) discloses neither the record nor its current version.
- A denied lookup returns nothing about what it refused.
- Agent API audit rows are metadata only.
- The sharing endpoint answers identically whether or not the address belongs to an
  account, so it cannot be used to enumerate accounts.
- Retracted and soft-deleted records leave every read surface.

## Paths deliberately labelled *not yet verified*

- **File storage and delivery** — document and export blob paths, signed URLs and disk
  layout. A separate boundary, explicitly out of the pilot; its coverage remains open.
- **DICOM** — imaging routes resolve through the same service, but the viewer, volume
  cache and signed instance URLs were not examined here.
- **Cross-connection revoke/write races** — no coordinated locking protocol exists
  between grant writers and clinical writers; the SQLite suite cannot demonstrate one
  either way.
- **GenAI extraction accept** — the job is looked up before the patient is resolved. The
  patient id used for the check comes from the job's own stored context and the
  `writablePatient` call is the real gate, so no content is reachable; the lookup order
  itself was not examined further here.
- **Per-query cost** — no benchmark yet exists for authorization queries per returned
  allergy; the list path resolves parent access once and filters in SQL, but this was not
  measured.

## Characterization coverage

- `tests/Feature/PHR/PhrPatientAllergyAuthorizationCharacterizationTest.php` — session
  surface: owner, reader, writable manager, owner-labelled grant versus actual ownership,
  revoked and absent grants, wrong patient/child pair with two accessible patients,
  absent context, review restrictions, and mass assignment of scope fields.
- `tests/Feature/PHR/PhrAllergyAgentAuthorizationCharacterizationTest.php` — token
  surface: scopes restrict rather than expand, no grant, revocation, write-only receipts,
  the review lifecycle an agent may not assert, per-patient external-id namespacing, and
  absent or unusable bearers.

Existing suites that already cover neighbouring behaviour and should be run alongside:
`PhrPatientAccessTest`, `PhrPatientCrudTest`, `PhrClinicalDataTest`,
`ClinicalReviewControllerTest`, `AgentApiClinicalReadTest`, `AgentApiClinicalWriteTest`,
`AgentApiRecordLifecycleTest`.
