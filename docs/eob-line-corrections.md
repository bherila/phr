# Correcting EOB lines with medication evidence

Both a versioned `eobs.update` and a same-connection correcting `eobs.upsert`
preserve medication evidence on the service it originally identified. Line
numbers are locations within a claim, and can change when a corrected claim is
reordered. They are not sufficient evidence that two services are the same.

The writer first compares normalized procedure code, code type, revenue code,
description and service dates. Unique services retain their existing line IDs
when moved. Identical full line content can anchor repeated services; an
otherwise unique remaining service can receive amount or note corrections.
Identical repeated lines can remain at their original numbers. The writer does
not guess between ambiguous remaining services.

If an existing medication evidence link has no unique correspondence, including
when its service was removed or its procedure changed, the correction returns
HTTP 409. The claim header, lines and evidence remain unchanged. Review the
linked evidence and explicitly unlink or relink it using the existing patient
record workflow before replacing that service. An unlinked service can still be
edited or removed normally. Both explicit and positional line numbers follow
this policy.

All correspondence checks run under the patient write transaction before the
first change. Number swaps temporarily use unused positive values inside that
transaction, then save the final line models through their normal event path.
A later failure rolls back both the staging and the claim changes. Public
payload limits and medication evidence provenance are unchanged.
