<?php

namespace App\Services\PHR\Documents;

use App\Models\PhrDocument;
use App\Models\PhrDocumentRelation;
use App\Support\PHR\PhrClinicalNote;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A document's addendum or amendment link (phr#409), FHIR relatesTo: one per
 * document, to the document it appends to or replaces.
 *
 * A link names a document of the same patient, never the document itself, and
 * never closes a loop. A source reference no document answers to is kept with
 * no target (`related_reference`) rather than guessed, and resolved once the
 * document it names arrives.
 */
final class PhrDocumentRelations
{
    /**
     * Link `$document` to `$relatedDocumentId` (a person's or an agent's choice),
     * or remove its link with null. Returns whether anything changed.
     *
     * @param  array{document_id: int, relation: string}|null  $relatesTo
     */
    public function set(PhrDocument $document, ?array $relatesTo): bool
    {
        $current = PhrDocumentRelation::query()->where('document_id', $document->id)->first();
        if ($relatesTo === null) {
            if ($current === null) {
                return false;
            }
            $current->delete();

            return true;
        }

        $targetId = $relatesTo['document_id'];
        $relation = PhrClinicalNote::relation($relatesTo['relation']) ?? throw new UnprocessableEntityHttpException('relates_to.relation must be appends or replaces.');
        $this->assertLinkable($document, $targetId);
        if ($current !== null && $current->related_document_id === $targetId && $current->relation === $relation && $current->related_reference === null) {
            return false;
        }

        PhrDocumentRelation::query()->updateOrCreate(
            ['document_id' => $document->id],
            ['patient_id' => $document->patient_id, 'related_document_id' => $targetId, 'related_reference' => null, 'relation' => $relation],
        );

        return true;
    }

    /**
     * Record what an import read: a relation to the document a source reference
     * names, resolved through `$targetId` when PHR holds it. Returns whether
     * anything changed. An unusable relation code leaves the document unlinked.
     */
    public function setFromSource(PhrDocument $document, string $relation, string $reference, ?int $targetId): bool
    {
        $relation = PhrClinicalNote::relation($relation);
        if ($relation === null) {
            return false;
        }
        if ($targetId !== null && ! $this->linkable($document, $targetId)) {
            $targetId = null;
        }
        $reference = mb_substr($reference, 0, 255);
        $current = PhrDocumentRelation::query()->where('document_id', $document->id)->first();
        if ($current !== null && $current->relation === $relation && $current->related_reference === $reference
            && ($current->related_document_id === $targetId || ($targetId === null && $current->related_document_id !== null))) {
            // Unchanged, or resolved earlier and not contradicted now.
            return false;
        }

        PhrDocumentRelation::query()->updateOrCreate(
            ['document_id' => $document->id],
            ['patient_id' => $document->patient_id, 'related_document_id' => $targetId, 'related_reference' => $reference, 'relation' => $relation],
        );

        return true;
    }

    /**
     * Resolve the patient's kept source references that name a document by this
     * import identity. Returns how many were resolved.
     *
     * @param  callable(string): ?string  $externalIdFor  the external_id a source reference names, or null
     */
    public function resolvePending(int $patientId, string $importSource, callable $externalIdFor): int
    {
        $resolved = 0;
        $pending = PhrDocumentRelation::query()->where('patient_id', $patientId)->whereNull('related_document_id')->whereNotNull('related_reference')->get();
        foreach ($pending as $relation) {
            $externalId = $externalIdFor((string) $relation->related_reference);
            if ($externalId === null) {
                continue;
            }
            $target = PhrDocument::query()->where('patient_id', $patientId)->where('import_source', $importSource)->where('external_id', $externalId)->value('id');
            $document = PhrDocument::query()->find($relation->document_id);
            if ($target === null || ! $document instanceof PhrDocument || ! $this->linkable($document, (int) $target)) {
                continue;
            }
            $relation->update(['related_document_id' => (int) $target]);
            // The link is the document's: a client syncing on updated_at sees it resolve.
            $document->touch();
            $resolved++;
        }

        return $resolved;
    }

    /**
     * The link as the API shows it.
     *
     * @return array{document_id: int|null, relation: string, reference: string|null}|null
     */
    public static function payload(PhrDocument $document): ?array
    {
        $relation = $document->relationLoaded('relatesTo') ? $document->relatesTo : $document->relatesTo()->first();
        if (! $relation instanceof PhrDocumentRelation) {
            return null;
        }

        return [
            'document_id' => $relation->related_document_id,
            'relation' => $relation->relation,
            'reference' => $relation->related_reference,
        ];
    }

    private function assertLinkable(PhrDocument $document, int $targetId): void
    {
        if (! $this->linkable($document, $targetId)) {
            throw new UnprocessableEntityHttpException('relates_to.document_id must name another document of this patient that does not already relate to this one.');
        }
    }

    private function linkable(PhrDocument $document, int $targetId): bool
    {
        if ($targetId === (int) $document->id) {
            return false;
        }
        if (! PhrDocument::query()->where('patient_id', $document->patient_id)->whereKey($targetId)->exists()) {
            return false;
        }

        // Walk the target's chain: a link back to this document would close a loop.
        $seen = [(int) $document->id => true];
        $next = $targetId;
        for ($step = 0; $next !== null && $step < 100; $step++) {
            if (isset($seen[$next])) {
                return false;
            }
            $seen[$next] = true;
            $related = PhrDocumentRelation::query()->where('document_id', $next)->value('related_document_id');
            $next = $related === null ? null : (int) $related;
        }

        return true;
    }
}
