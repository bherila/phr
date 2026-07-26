<?php

namespace App\Services\PHR\Import;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
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

    public function __construct(
        private PhrRecordAttributeMapper $attributeMapper,
        private PhrDocumentImporter $documentImporter,
        private PhrImportModelUpserter $upserter,
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
     * @param  array{import_source?: string, source?: string, external_id?: string|null, genai_job_id?: int|null, source_document_id?: int|null}  $options
     */
    public function importPayload(PhrPatient $patient, int $actorUserId, string $jobType, array $payload, array $options = []): PhrImportResult
    {
        if (! self::isPhrJobType($jobType)) {
            throw new InvalidArgumentException("Unsupported PHR job type: {$jobType}");
        }

        return DB::transaction(function () use ($patient, $actorUserId, $jobType, $payload, $options): PhrImportResult {
            if ($jobType === 'phr_document') {
                $document = $this->documentImporter->createOrUpdateDocument($patient, $actorUserId, $payload, $options);
                $result = new PhrImportResult(documents: 1, created: $document->wasRecentlyCreated ? 1 : 0, updated: $document->wasRecentlyCreated ? 0 : 1);
                $result->merge($this->importDocumentBundle($patient, $actorUserId, $document, $payload, $options));

                return $result;
            }

            return $this->importRecords($patient, $actorUserId, $jobType, $payload, $options);
        });
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array{import_source?: string, source?: string, external_id?: string|null, genai_job_id?: int|null, source_document_id?: int|null}  $options
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
     * @param  array{import_source?: string, source?: string, external_id?: string|null, genai_job_id?: int|null, source_document_id?: int|null}  $options
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

            $model = $this->upserter->upsert($this->attributeMapper->modelClassFor($jobType), $attributes);
            $model->wasRecentlyCreated ? $result->addCreated() : $result->addUpdated();
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
