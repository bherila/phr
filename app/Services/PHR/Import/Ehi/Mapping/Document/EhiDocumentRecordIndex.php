<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Document;

use App\Models\PhrDocument;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Support\PHR\PhrRecordLifecycle;

/**
 * The patient's documents and their evidence links, read once, so reconciling a note
 * costs no query of its own. Each document's text is read only to hash it and is not
 * kept: the comparison and the duplicate check need nothing but the digest.
 */
final class EhiDocumentRecordIndex
{
    /** @var array<int, array{id: int, active: bool, comparable: array{title: string|null, document_type: string|null, observed_at: string|null, extracted_text_sha256: string|null}}> */
    private array $documents = [];

    /** @var array<string, array<string, int>> import source => external id => document id */
    private array $identities = [];

    /** @var array<string, list<int>> type, day, and text digest => live documents, by ascending id */
    private array $sameText = [];

    /** @var array<int, list<int>> entity id => linked documents */
    private array $byEntity = [];

    /** @var array<int, array<int, true>> document id => revisions linked to it */
    private array $linkedRevisions = [];

    public function __construct(EhiMappingContext $context)
    {
        foreach ($context->linksByEntity('document_id') as $entityId => $links) {
            foreach ($links as $link) {
                $documentId = (int) $link->document_id;
                $this->byEntity[$entityId][] = $documentId;
                $this->linkedRevisions[$documentId][(int) $link->revision_id] = true;
            }
        }

        // Before any text is read: the patient-wide check bounds what this can hold.
        $context->budget->assertPatient($context->patient->id);
        $scanned = 0;
        $documents = PhrDocument::withTrashed()->where('patient_id', $context->patient->id)
            ->select(['id', 'title', 'document_type', 'observed_at', 'extracted_text', 'import_source', 'external_id', 'deleted_at', 'retracted_at']);
        foreach ($documents->lazyById(100) as $document) {
            $context->budget->charge(++$scanned, 0);
            $this->add($document);
        }
    }

    /**
     * Documents this entity is linked to, and the one holding this import identity.
     *
     * @return list<array{id: int, active: bool, comparable: array{title: string|null, document_type: string|null, observed_at: string|null, extracted_text_sha256: string|null}}> by ascending id
     */
    public function targets(int $entityId, ?string $importSource, string $externalId): array
    {
        $ids = $this->byEntity[$entityId] ?? [];
        $identity = $this->identities[(string) $importSource][$externalId] ?? null;
        if ($identity !== null) {
            $ids[] = $identity;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        $targets = [];
        foreach ($ids as $id) {
            if (isset($this->documents[$id])) {
                $targets[] = $this->documents[$id];
            }
        }

        return $targets;
    }

    /**
     * How many of these revisions are already linked to the document.
     *
     * @param  list<int>  $revisionIds
     */
    public function linkedCount(int $documentId, array $revisionIds): int
    {
        return count(array_intersect_key(array_flip($revisionIds), $this->linkedRevisions[$documentId] ?? []));
    }

    /** @return list<int> live documents of this type and day with this exact text */
    public function sameText(string $documentType, string $day, string $textSha256): array
    {
        return $this->sameText[$documentType."\0".$day."\0".$textSha256] ?? [];
    }

    private function add(PhrDocument $document): void
    {
        $sha256 = $document->extracted_text === null ? null : hash('sha256', $document->extracted_text);
        $this->documents[$document->id] = [
            'id' => $document->id,
            'active' => PhrRecordLifecycle::of($document) === PhrRecordLifecycle::ACTIVE,
            'comparable' => [
                'title' => $document->title,
                'document_type' => $document->document_type,
                'observed_at' => $document->observed_at?->format('Y-m-d H:i:s'),
                'extracted_text_sha256' => $sha256,
            ],
        ];
        if ($document->import_source !== null && $document->external_id !== null) {
            $this->identities[$document->import_source][$document->external_id] = $document->id;
        }
        $day = $document->observed_at?->format('Y-m-d');
        if ($sha256 !== null && $day !== null && $document->deleted_at === null && $document->retracted_at === null) {
            $this->sameText[$document->document_type."\0".$day."\0".$sha256][] = $document->id;
        }
    }
}
