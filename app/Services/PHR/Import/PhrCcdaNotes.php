<?php

namespace App\Services\PHR\Import;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Services\PHR\Documents\PhrDocumentRelations;
use App\Support\PHR\PhrReviewStatus;

/**
 * Writes the notes PhrCcdaNoteActivities reads (phr#409): each is a document,
 * pending review, whose file is its text stored through Storage as a .txt object.
 * Addenda are linked once every note of the document is read, since one may come
 * before the note it appends to.
 */
final class PhrCcdaNotes
{
    private const string IMPORT_SOURCE = 'ccda';

    /** @var list<array{document: string, relation: string, reference: string, target: string}> */
    private array $pendingRelations = [];

    public function __construct(
        private PhrStructuredDataImporter $structuredImporter,
        private PhrDocumentRelations $relations,
    ) {}

    /**
     * @param  list<array{payload: array<string, mixed>, text: string, relation: array{relation: string, reference: string, target: string}|null}>  $notes
     */
    public function import(PhrPatient $patient, int $actorUserId, array $notes): PhrImportResult
    {
        $result = new PhrImportResult;
        foreach ($notes as $note) {
            $externalId = (string) $note['payload']['external_id'];
            $result->merge($this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_document', $note['payload'], [
                'import_source' => self::IMPORT_SOURCE,
                'content' => [
                    'bytes' => $note['text'],
                    'filename' => str_replace(':', '-', $externalId).'.txt',
                    'mime_type' => 'text/plain',
                    'text' => $note['text'],
                ],
            ]));
            if ($note['relation'] !== null) {
                $this->pendingRelations[] = ['document' => $externalId, ...$note['relation']];
            }
        }

        return $result;
    }

    public function linkRelations(PhrPatient $patient): void
    {
        $pending = $this->pendingRelations;
        $this->pendingRelations = [];
        foreach ($pending as $relation) {
            $document = $this->document($patient, $relation['document']);
            if (! $document instanceof PhrDocument) {
                continue;
            }
            $target = $this->document($patient, $relation['target']);
            $changed = $this->relations->setFromSource($document, $relation['relation'], $relation['reference'], $target?->id);
            if ($changed) {
                // What the source says the document amends is part of it: a change is
                // reviewed, and moves the document for a client syncing on updated_at.
                if ($document->review_status === PhrReviewStatus::PENDING) {
                    $document->touch();
                } else {
                    $document->update(['review_status' => PhrReviewStatus::PENDING]);
                }
            }
        }
        $this->relations->resolvePending((int) $patient->id, self::IMPORT_SOURCE, fn (string $reference): ?string => self::externalIdFor($reference));
    }

    /** The import identity a kept `root^extension` (or bare root) reference names. */
    public static function externalIdFor(string $reference): ?string
    {
        [$root, $extension] = array_pad(explode('^', $reference, 2), 2, '');

        return $root === '' ? null : 'ccda-note-'.sha1(implode('|', ['id', $root, $extension]));
    }

    private function document(PhrPatient $patient, string $externalId): ?PhrDocument
    {
        return PhrDocument::query()->where('patient_id', $patient->id)->where('import_source', self::IMPORT_SOURCE)->where('external_id', $externalId)->first();
    }
}
