<?php

namespace App\Services\PHR\Import;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PhrStructuredDataImporter
{
    public const array JOB_TYPES = PhrRecordAttributeMapper::JOB_TYPES;

    private const array DOCUMENT_BUNDLE_RECORD_TYPES = [
        'lab_results' => 'phr_lab_result',
        'vitals' => 'phr_vital',
        'encounters' => 'phr_office_visit',
        'office_visits' => 'phr_office_visit',
        'medications' => 'phr_medication',
        'immunizations' => 'phr_immunization',
        'conditions' => 'phr_problem_list',
        'procedures' => 'phr_procedure',
        'allergies' => 'phr_allergy',
        'portal_messages' => 'phr_portal_message',
        'negative_assertions' => 'phr_negative_assertion',
    ];

    /**
     * Provenance a row keeps once it has it: the document it was first read
     * from, and how. A later import fills it where a row has none (one an earlier
     * load made, adopted on re-import, say) but never moves or clears it.
     */
    private const array KEPT_PROVENANCE = ['source_document_id', 'source'];

    /** @var array{source_document_id: int, source: string}|null */
    private ?array $sourceDocument = null;

    public function __construct(
        private PhrRecordAttributeMapper $attributeMapper,
        private PhrDocumentImporter $documentImporter,
        private PhrImportModelUpserter $upserter,
        private PhrDocumentFillImporter $fillImporter,
    ) {}

    public static function isPhrJobType(string $jobType): bool
    {
        return PhrRecordAttributeMapper::isPhrJobType($jobType);
    }

    /**
     * @return array<int, string>
     */
    public static function writableJobTypes(): array
    {
        return PhrRecordAttributeMapper::writableJobTypes();
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array{import_source?: string, source?: string, external_id?: string|null, genai_job_id?: int|null, source_document_id?: int|null, review_status?: string}  $options
     */
    public function importPayload(PhrPatient $patient, int $actorUserId, string $jobType, array $payload, array $options = []): PhrImportResult
    {
        if (! self::isPhrJobType($jobType)) {
            throw new InvalidArgumentException("Unsupported PHR job type: {$jobType}");
        }

        return DB::transaction(function () use ($patient, $actorUserId, $jobType, $payload, $options): PhrImportResult {
            if ($jobType === 'phr_document') {
                $document = $this->documentImporter->createOrUpdateDocument($patient, $actorUserId, $payload, $options);
                $result = new PhrImportResult(
                    documents: 1,
                    created: $document->wasRecentlyCreated ? 1 : 0,
                    updated: ! $document->wasRecentlyCreated && $document->wasChanged() ? 1 : 0,
                    unchanged: ! $document->wasRecentlyCreated && ! $document->wasChanged() ? 1 : 0,
                );
                $result->merge($this->importDocumentBundle($patient, $actorUserId, $document, $payload, $options));

                return $result;
            }

            return $this->importRecords($patient, $actorUserId, $jobType, $payload, $options);
        });
    }

    /**
     * Runs `$import` with every record it writes through importUnreviewed()
     * linked to `$document`, the stored document the file was read from, and
     * `$source` (a PhrDocument::SOURCES value naming how it was read) as the
     * record's source where its model has one. With no document, records are
     * written as before, unlinked. The link is the server's own: a document id a
     * parsed record names is never trusted.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $import
     * @return TResult
     */
    public function fromDocument(?PhrDocument $document, string $source, callable $import): mixed
    {
        $previous = $this->sourceDocument;
        $this->sourceDocument = $document === null ? null : ['source_document_id' => (int) $document->id, 'source' => $source];
        try {
            return $import();
        } finally {
            $this->sourceDocument = $previous;
        }
    }

    /**
     * Records read straight from a file nobody has reviewed (C-CDA, FHIR, MyChart):
     * new rows, and existing rows the file changes, wait for a person's review.
     * A row the file leaves as it is keeps the review it has. Inside
     * fromDocument(), each is linked to that document.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  array{import_source?: string, source?: string, external_id?: string|null, genai_job_id?: int|null, source_document_id?: int|null}  $options
     */
    public function importUnreviewed(PhrPatient $patient, int $actorUserId, string $jobType, array $payload, array $options = []): PhrImportResult
    {
        return $this->importPayload($patient, $actorUserId, $jobType, $payload, [...$options, ...($this->sourceDocument ?? []), 'review_status' => PhrReviewStatus::PENDING]);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array{import_source?: string, source?: string, external_id?: string|null, genai_job_id?: int|null, source_document_id?: int|null, review_status?: string}  $options
     */
    public function importDocumentBundle(PhrPatient $patient, int $actorUserId, PhrDocument $document, array $payload, array $options = []): PhrImportResult
    {
        return DB::transaction(function () use ($patient, $actorUserId, $document, $payload, $options): PhrImportResult {
            $result = new PhrImportResult;
            $records = $this->documentBundleRecords($payload);
            $recordOptions = [
                ...$options,
                'source_document_id' => $document->id,
            ];
            // The document's own external_id must not be reused for its child
            // records, or every bundled row of a model would resolve to the same
            // external_id and the upserter would collapse them into one row
            // instead of using each record's own stable record_key.
            unset($recordOptions['external_id']);

            // Fills are the source of truth for the prescriptions a document lists: they
            // link or create their medication, so a medication extracted from the same
            // line is dropped rather than written twice.
            $fills = $records['medication_fills'] ?? null;
            if (is_array($fills) && $fills !== []) {
                $fillResult = $this->fillImporter->import($patient, $document, array_values($fills));
                $result->merge($fillResult->result);
                if (isset($records['medications']) && is_array($records['medications'])) {
                    $records['medications'] = $this->fillImporter->withoutFillLines($records['medications'], $fillResult->lines);
                }
            }

            foreach (self::DOCUMENT_BUNDLE_RECORD_TYPES as $payloadKey => $jobType) {
                $recordsForType = $records[$payloadKey] ?? null;
                if (! is_array($recordsForType)) {
                    continue;
                }

                $result->merge($this->importRecords($patient, $actorUserId, $jobType, $recordsForType, $recordOptions));
            }

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function storeLocalDocument(PhrPatient $patient, int $actorUserId, string $path, array $attributes = []): PhrDocument
    {
        return $this->documentImporter->storeLocalDocument($patient, $actorUserId, $path, $attributes);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function storeGenAiDocument(PhrPatient $patient, int $actorUserId, GenAiImportJob $job, array $payload): PhrDocument
    {
        return $this->documentImporter->storeGenAiDocument($patient, $actorUserId, $job, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDocumentFromGenAiResult(PhrDocument $document, GenAiImportJob $job, array $payload): PhrDocument
    {
        return $this->documentImporter->updateDocumentFromGenAiResult($document, $job, $payload);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array{import_source?: string, source?: string, external_id?: string|null, genai_job_id?: int|null, source_document_id?: int|null, review_status?: string}  $options
     */
    private function importRecords(PhrPatient $patient, int $actorUserId, string $jobType, array $payload, array $options): PhrImportResult
    {
        $result = new PhrImportResult;
        foreach ($this->attributeMapper->recordsFromPayload($jobType, $payload) as $record) {
            if (! is_array($record)) {
                $result->addSkipped();

                continue;
            }

            $attributes = $this->attributeMapper->attributesFor($patient, $actorUserId, $jobType, $record, $options);
            if ($attributes === [] || $this->attributeMapper->missingRequiredField($jobType, $attributes)) {
                $result->addSkipped();

                continue;
            }

            $review = $options['review_status'] ?? null;
            if ($review !== null) {
                $attributes['review_status'] = $review;
            }
            $model = $this->upserter->upsert($this->attributeMapper->modelClassFor($jobType), $attributes, $review, self::KEPT_PROVENANCE);
            match (true) {
                $model->wasRecentlyCreated => $result->addCreated(),
                $model->wasChanged() => $result->addUpdated(),
                default => $result->addUnchanged(),
            };
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function documentBundleRecords(array $payload): array
    {
        if (isset($payload['records']) && is_array($payload['records']) && ! array_is_list($payload['records'])) {
            return $payload['records'];
        }

        return $payload;
    }
}
