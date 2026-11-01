# Medication fills: web API contract (phr#265)

This file is the final word on the shapes below; the backend implements them and the UI builds against them. They follow phr's existing clinical-resource conventions: `MedicationResource`, the generic `indexClinicalResource` / `storeClinicalResource` / … controller helpers, and the `useClinicalCrud` hook. Where this file and those conventions differ in something trivial (pagination envelope, date format), the existing convention wins. The backend agent records any such difference in `docs/medication-fills-contract.md` in the branch, and that file becomes the final word.

## Session API (web app), mirroring medications
| Method | Path | Notes |
|---|---|---|
| GET | `/api/phr/patients/{patient}/medication-fills` | Same index envelope and filters as other clinical resources, plus `medication_id`, `from`, `to` (dates), `ndc_status`. Default order `filled_on` desc. |
| POST | `/api/phr/patients/{patient}/medication-fills` | Manual entry (body below). `source_kind` is forced to `manual`. |
| GET | `/api/phr/patients/{patient}/medication-fills/{fill}` | |
| PATCH | `/api/phr/patients/{patient}/medication-fills/{fill}` | Same semantics as the medication update. |
| DELETE | `/api/phr/patients/{patient}/medication-fills/{fill}` | |
| PATCH | `/api/phr/patients/{patient}/medication-fills/{fill}/review` | Same review body as the other clinical resources. |
| GET | `/api/phr/patients/{patient}/medications/{medication}/fills` | Fill history for the medication page, in the same list shape. |
| GET | `/api/ndc/lookup?ndc=` | `NdcLookupResult` |
| GET | `/api/ndc/search?name=&strength=&dosage_form=&route=&labeler=&package=` | `{ "candidates": NdcCandidate[] }` |
| GET / PUT | `/api/user/display-preferences` | `{ "force_lowercase": boolean }`. Default `false`, stored per user. |

Route names follow `patients.medication-fills.*`, `ndc.lookup`, `ndc.search`, `user.display-preferences.*`.

## `MedicationFill` (JSON resource)
```json
{
  "id": 1, "patient_id": 1, "user_id": 1,
  "medication_id": 7, "medication": { "id": 7, "name": "atorvastatin calcium" },
  "import_source": null, "external_id": "…", "source_kind": "label_photo",
  "source_document_id": 12, "source_locator": "page 1",
  "review_status": "pending_review",
  "patient_name_as_printed": "Jane Q. Doe",
  "ndc_as_captured": "00071-0155-23", "ndc_10": "0071-0155-23", "ndc_11": "00071015523",
  "ndc_status": "resolved",
  "drug": {
    "substance_names": ["atorvastatin calcium"], "strengths": ["20 mg/1"],
    "brand_name": "Lipitor", "dosage_form": "tablet, film coated", "route": "oral",
    "labeler_name": "Parke-Davis Div of Pfizer Inc", "package_description": "90 tablet, film coated in 1 bottle (0071-0155-23)",
    "rxcui": "617312"
  },
  "drug_as_printed": "atorvastatin 20mg tab",
  "filled_on": "2026-10-28", "quantity": "90", "quantity_unit": "tablet",
  "days_supply": 90, "rx_number": "1234567", "refills_remaining": 3,
  "directions": "take 1 tablet by mouth daily", "prescriber_name": "Dr. A. Smith",
  "paid_cents": 1000, "retail_cents": 18999,
  "pharmacy_name": "Example Pharmacy", "pharmacy_location": "#1234, Seattle WA",
  "created_at": "…", "updated_at": "…"
}
```
- `drug.*` values are server-resolved and stored with normalized casing. The resource never applies `force_lowercase`; that happens only at render time.
- `quantity` is a decimal string. Money is integer cents.
- `drug` keys may be null when `ndc_status` is `unresolved`.

## `NdcCandidate` / `NdcLookupResult`
```json
{ "ndc_10": "0071-0155-23", "ndc_11": "00071015523", "product_ndc": "0071-0155",
  "drug": { "…": "same shape as MedicationFill.drug" },
  "match_reasons": ["package NDC exact"], "fetched_at": "…" }
```
```json
{ "status": "resolved|ambiguous|unresolved", "input": "00071015523",
  "selected": "NdcCandidate|null", "candidates": ["NdcCandidate"] }
```
`resolved` means one candidate, which is `selected`. `ambiguous` means more than one, with `selected` null. `unresolved` means none.

## Manual create / update body
```json
{ "ndc": "00071-0155-23", "ndc_candidate": "0071-0155-23 (optional: the chosen ndc_10 when ambiguous)",
  "filled_on": "2026-10-28", "quantity": "90", "quantity_unit": "tablet",
  "paid_cents": 1000, "retail_cents": 18999, "pharmacy_name": "…",
  "days_supply": null, "rx_number": null, "refills_remaining": null,
  "directions": null, "prescriber_name": null, "pharmacy_location": null,
  "medication_id": null, "drug_as_printed": null }
```
- The server resolves `drug` from `ndc` or `ndc_candidate`. Clients never send `drug`.
- When `medication_id` is null, the server links an existing medication or creates one, as the issue describes.

## Validation errors
Standard Laravel 422 with `errors`. NDC-specific messages:
- `ndc`: "No FDA listing matches this NDC" (unresolved, when the client did not choose to save it unresolved; send `allow_unresolved: true` to save anyway)
- `ndc_candidate`: "Choose one of the matching packages"

## Display rules (UI)
- Fills table columns, left to right, one field per column:
  1. date (YY.MM.DD)
  2. name (NDC `substance_names`; falls back to the linked medication, then `drug_as_printed`)
  3. strength (NDC `strengths`)
  4. dosage form (NDC `dosage_form`)
  5. quantity
  6. paid ($X.XX)
  7. retail price ($X.XX)
  8. prescriber
  9. pharmacy
  10. route (NDC `route`)
  11. manufacturer (NDC `labeler_name`)
  12. package description (NDC `package_description`)
- Several ingredients (and their strengths, in the same order) are semicolon-delimited, as in the FDA NDC directory's SUBSTANCENAME: `amoxicillin; clavulanate potassium`, `600 mg/5 mL; 42.9 mg/5 mL`.
- Brand name and directions are not table columns; they show in the entry form.
- Missing values render as "—".
- With `force_lowercase`, render the text columns (name, strength, dosage form, quantity unit, prescriber, pharmacy, route, manufacturer, package description) in lowercase (CSS `text-transform: lowercase` is fine). Units keep their standard case (`mL` stays `mL`, `IU` stays `IU`); the owner asked for this on 2026-11-01. Apply it on the fills table, the medication fill history and the entry form preview.

## Final shapes and deviations (backend, authoritative over the sections above)

Where the backend follows phr's existing convention instead of the proposal above:

1. **Envelopes** follow the generic clinical controller helpers. Index: `{ "medication_fills": MedicationFill[], "can_manage": boolean }`. Show, create (201), update and review: `{ "medication_fill": MedicationFill }` (show also carries `can_manage`). The medication history route `GET /api/patients/{patient}/medications/{medication}/fills` returns the index shape.
2. **Index filters** are `medication_id`, `from`, `to` (dates, inclusive, on `filled_on`), `ndc_status`, `review_status`, `include_rejected` (as for other resources). Order is `filled_on` desc, then `id` desc. Retracted and deleted fills are not listed.
3. **Dates**: `filled_on` is `YYYY-MM-DD`; `created_at` / `updated_at` are `YYYY-MM-DD HH:MM:SS`, as in `MedicationResource`.
4. **`quantity`** is a decimal string with trailing zeros trimmed (`"90"`, `"0.5"`).
5. **`ndc_11`** is eleven digits without hyphens (`"00071015523"`); `ndc_10` is hyphenated as openFDA lists it (`"0071-0155-23"`). **`ndc_10` is the NDC clients show**; `ndc_as_captured` is kept as evidence and shown only when `ndc_10` is null. An 11-digit input is converted to 10 digits by a fixed rule before the openFDA lookup: split 5-4-2, then apply the first match of (a) first segment starts with 0, drop it (`00299-5918-25` -> `0299-5918-25`); (b) middle segment starts with 0, drop it (`54868-0925-02` -> `54868-925-02`; `60505-0134-00` -> `60505-134-00`); (c) last segment starts with 0, drop it (5-4-1). So an 11-digit NDC is never `ambiguous`; only 10 digits without hyphens (no known layout: 4-4-2, 5-3-2, 5-4-1) can be. Unresolved and ambiguous fills still keep `ndc_10`/`ndc_11` whenever the input fixes a 10-digit form.
6. **`NdcCandidate`** is exactly as proposed (`fetched_at`, `match_reasons`); `ndc_search` candidates add a numeric `score`. Lookup candidates carry `match_reasons: ["package NDC exact"]`.
7. **`NdcLookupResult`** is as proposed plus two optional keys: `reason` (`invalid_ndc` | `not_found` | `lookup_unavailable` | null) and `next_step` (guidance text, null when resolved).
8. **Manual create/update body**: `ndc`, `ndc_candidate`, `allow_unresolved`, `medication_id`, `drug_as_printed` and the fill fields as proposed. `source_kind` is forced to `manual` for session writes. Drug fields (`brand_name`, `substance_names`, ...) are never accepted from the web client.
   - `ndc` omitted: the fill is saved with `ndc_status: manual` and no drug fields (the person types the drug in `drug_as_printed`).
   - `ndc` resolved: `ndc_status: resolved`, drug fields from openFDA.
   - `ndc` ambiguous (10 digits without hyphens only): 422 on `ndc_candidate` ("Choose one of the matching packages") until `ndc_candidate` names one of the candidates' `ndc_10`.
   - `ndc` unresolved: 422 on `ndc` ("No FDA listing matches this NDC") unless `allow_unresolved: true`, which saves `ndc_status: unresolved` with `drug_as_printed` as the only drug text.
9. **Display preferences**: `GET/PUT /api/user/display-preferences`, body `{ "force_lowercase": boolean }`. The value is also in `app-initial-data` as `currentUser.force_lowercase_display`.
10. **`MedicationFill.medication`** is `{ "id", "name" }` or null.
11. **Paths and route names follow phr's `/api/phr` prefix** (the table above wrote `/api/...`). The real routes are:
    - `GET|POST /api/phr/patients/{patient}/medication-fills`, `GET|PATCH|DELETE /api/phr/patients/{patient}/medication-fills/{fill}`, `PATCH /api/phr/patients/{patient}/medication-fills/{fill}/review`
    - `GET /api/phr/patients/{patient}/medications/{medication}/fills`
    - `GET /api/ndc/lookup?ndc=`, `GET /api/ndc/search?name=&strength=&dosage_form=&route=&labeler=&package=` (outside `/phr`, as written in the table)
    - `GET|PUT /api/user/display-preferences`

    Route names are `phr.patients.medication-fills.*`, `phr.patients.medications.fills`, `ndc.lookup`, `ndc.search`, and `user.display-preferences.show|update`.
12. **Page and section ids.** The patient page is `/phr/patient/{id}/medication-fills`, page id `medication-fills`, listed in `PHR_PAGE_IDS` immediately after `medications` (the PHP `PageController::PATIENT_PAGES` already has it in that position, and a navigation test requires the two lists to match). The review summary (`GET /api/phr/patients/{id}/review/summary`) reports fills under section id `medication-fills`, and a pending fill appears in the Review page with `section: "medication-fills"` and `review_segment: "medication-fills"`. There is no fill detail route; a fill opens as a row, and the medication detail page lists its fills.
13. **`ndc_search` over the web** returns `{ "candidates": NdcCandidate[] }` ranked best first, each with `match_reasons` and `score`. The web `ndc` query for lookup accepts what a person types (hyphens, spaces, an `NDC` prefix).
14. **Drug text when the NDC does not resolve.** `drug_as_printed` is required whenever `ndc_status` is not `resolved` (including `manual`, where no NDC was given). It is the only drug text a client supplies.
15. **Medication link.** An explicit `medication_id` must belong to the patient. Otherwise the server links by RxNorm code, then product (NDC labeler + product), then substances + strength + dosage form, and creates a `pending_review` medication when nothing matches. `GET .../medications/{id}/fills` is how the medication page lists the result.
16. **Optimistic concurrency.** `MedicationFill` carries `version`, the same opaque 64-hex value phr uses for every clinical record (`AgentClinicalRecordVersion`, the one the MCP `*_update` tools take as `expected_version`). `PATCH .../medication-fills/{fill}` accepts an optional `version`; when it is not the record's current version the response is `409 {"message": "..."}` and nothing is changed. The review route keeps its existing `version` behavior.
17. **`medication_id` on PATCH.** Absent or `null` keeps the current link: an edit never unlinks. If the edit changes the NDC and the current medication no longer fits the new drug (RxNorm code, name and dose, or the other fills linked to it), the server links or creates one. An explicit id relinks to that medication after checking it belongs to the patient (422 on `medication_id` otherwise). On create, `null` means link-or-create.
18. **Initial data.** `app-initial-data.currentUser.force_lowercase_display` is a boolean, emitted from the same user record that `GET /api/user/display-preferences` reads; the PUT response is `{ "force_lowercase": boolean }`.
19. **Clearing the NDC.** `PATCH` with an explicit `"ndc": null` removes the NDC: `ndc_status` becomes `manual`, the FDA fields and snapshot are cleared, and `drug_as_printed` is required (422 on `ndc` otherwise). Omitting `ndc` keeps the stored drug.
20. **`drug_as_printed` on every write.** While `ndc_status` is not `resolved`, an edit that does not touch the NDC still cannot blank `drug_as_printed` (422).
21. **Rate limits.** `/api/ndc/lookup` and `/api/ndc/search` are limited per user (`OPENFDA_WEB_REQUESTS_PER_MINUTE`, default 30; 429 beyond it). Search answers are cached for a short time.

## Cross-source dedupe, EOB links and review flags (phr#268)

22. **One fill, however many sources.** The fills write path (`MedicationFillUpsertService`, shared by `medication_fills.upsert`, the pharmacy lines of `eobs.upsert` and any later extraction) decides under a patient lock whether the fill is new. Order: this connection's own `external_id` (an ordinary idempotent retry or update); then `MedicationFillMatcher` against the patient's live, not-rejected fills of the same day:
    - same Rx number (and the pharmacy and drug do not contradict it): the same fill, `dedupe.matched_by: "rx_number"`;
    - no Rx number on one side, and same drug (NDC, else drug words and strength), pharmacy (word subset: "CVS" is "CVS Pharmacy #12") and quantity: the same fill, `matched_by: "attributes"`;
    - the same drug with a contradiction or gap the data cannot settle (pharmacy or quantity unknown, a second Rx number with everything else equal, two candidates): uncertain.
    A match returns the existing fill (`dedupe.status: "matched"`), fills only its blanks (a stored value is never overwritten by a second source), records the new document as evidence and reopens review only if a field was filled. An uncertain match creates the fill, `pending_review`, flagged by an evidence row (`review_flags: [{code: "possible_duplicate", message, fill_id}]` in reads, remapped by a native restore) and `dedupe.status: "flagged"`: a person keeps one and rejects the other. Everything else is a new fill (`dedupe.status: "none"`).
23. **Evidence, not a column.** A fill's first document stays `source_document_id`. Every other source is a `phr_medication_fill_evidence` row (a document, an EOB line, or both), idempotent per source. Reads add `eob_line_ids` and `evidence_document_ids` to the fill; `eob_line_id` is accepted on `medication_fills.upsert` (create only) to link a fill to the EOB line that adjudicated it; EOB lines add `medication_fill_ids`; `evidence.links` links fill, EOB line, EOB and document in both directions.
24. **`source_kind`** also accepts `statement` and `eob`.
