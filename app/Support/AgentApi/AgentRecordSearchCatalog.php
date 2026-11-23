<?php

namespace App\Support\AgentApi;

use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEpisode;
use App\Models\PhrHealthLog;
use App\Models\PhrHealthLogEntry;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrMedicationTransition;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatientVital;
use App\Models\PhrProcedure;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixed search projection for every record type in the unified timeline.
 *
 * Search never accepts a table or column name from a request. Keeping every
 * searchable field here makes the cross-resource query auditable and prevents
 * broad serializers (including raw clinical text) from becoming search output.
 */
final class AgentRecordSearchCatalog
{
    /**
     * @var array<string, array{
     *   model: class-string<Model>, event: non-empty-list<string>, summary: non-empty-list<string>,
     *   q: list<string>, provider: list<string>, facility: list<string>, codes: list<string>, code_arrays: list<string>,
     *   sources: list<string>, review: list<string>, source_document: bool, columns?: non-empty-list<string>, scope?: string
     * }>
     */
    private const array DEFINITIONS = [
        'office-visits' => [
            'model' => PhrOfficeVisit::class,
            'event' => ['visit_started_at', 'visit_date', 'created_at'],
            'summary' => ['chief_complaint', 'visit_type', 'provider_name'],
            'q' => ['chief_complaint', 'visit_type', 'provider_name', 'facility_name', 'location', 'assessment', 'plan', 'admit_source_text', 'discharge_disposition_text'],
            'provider' => ['provider_name'], 'facility' => ['facility_name', 'location'],
            'codes' => [], 'code_arrays' => ['icd10_codes', 'cpt_codes'], 'sources' => ['import_source'], 'review' => ['review_status'],
            'source_document' => true,
        ],
        'procedures' => [
            'model' => PhrProcedure::class,
            'event' => ['performed_at', 'performed_on', 'created_at'],
            'summary' => ['name'], 'q' => ['name', 'reason', 'outcome', 'notes', 'performer_name', 'facility_name'],
            'provider' => ['performer_name'], 'facility' => ['facility_name'],
            'codes' => ['cpt_code', 'snomed_code'], 'code_arrays' => [], 'sources' => ['import_source'], 'review' => ['review_status'],
            'source_document' => true,
        ],
        'immunizations' => [
            'model' => PhrImmunization::class,
            'event' => ['administered_on', 'created_at'],
            'summary' => ['vaccine_name'], 'q' => ['vaccine_name', 'manufacturer', 'administered_by', 'facility_name', 'notes'],
            'provider' => ['administered_by'], 'facility' => ['facility_name'],
            'codes' => ['cvx_code'], 'code_arrays' => [], 'sources' => ['import_source'], 'review' => ['review_status'],
            'source_document' => true,
        ],
        'medications' => [
            'model' => PhrMedication::class,
            'event' => ['started_on', 'created_at'],
            'summary' => ['name'], 'q' => ['name', 'dose', 'frequency', 'prescriber_name', 'reason_for_use'],
            'provider' => ['prescriber_name'], 'facility' => [],
            'codes' => ['rxnorm_code'], 'code_arrays' => [], 'sources' => ['import_source'], 'review' => ['review_status'],
            'source_document' => true,
        ],
        'medication-fills' => [
            'model' => PhrMedicationFill::class,
            'event' => ['filled_on', 'created_at'],
            'summary' => ['brand_name', 'drug_as_printed'],
            'q' => ['brand_name', 'drug_as_printed', 'dosage_form', 'labeler_name', 'pharmacy_name', 'rx_number', 'prescriber_name', 'directions'],
            'provider' => ['prescriber_name'], 'facility' => ['pharmacy_name'],
            'codes' => ['rxcui', 'ndc_10', 'ndc_11'], 'code_arrays' => [], 'sources' => ['import_source'], 'review' => ['review_status'],
            'source_document' => true,
        ],
        'conditions' => [
            'model' => PhrCondition::class,
            'event' => ['onset_date', 'created_at'],
            'summary' => ['name'], 'q' => ['name', 'notes'], 'provider' => [], 'facility' => [],
            'codes' => ['icd10_code', 'snomed_code'], 'code_arrays' => [], 'sources' => ['import_source'],
            'review' => ['review_status'], 'source_document' => true,
        ],
        'allergies' => [
            'model' => PhrAllergy::class,
            'event' => ['created_at'],
            'summary' => ['substance'], 'q' => ['substance', 'reaction', 'notes'], 'provider' => [], 'facility' => [],
            'codes' => ['rxnorm_code', 'snomed_code'], 'code_arrays' => [], 'sources' => ['import_source'],
            'review' => ['review_status'], 'source_document' => true,
        ],
        'lab-results' => [
            'model' => PhrLabResult::class,
            'event' => ['result_datetime', 'collection_datetime', 'created_at'],
            'summary' => ['analyte', 'test_name'], 'q' => ['analyte', 'test_name', 'result_comment', 'message_from_provider'],
            'provider' => ['ordering_provider'], 'facility' => ['resulting_lab'], 'codes' => [], 'code_arrays' => [],
            'sources' => ['source', 'import_source'], 'review' => ['review_status'], 'source_document' => true,
        ],
        'vitals' => [
            'model' => PhrPatientVital::class,
            'event' => ['observed_at', 'vital_date', 'created_at'],
            'summary' => ['vital_name'], 'q' => ['vital_name', 'notes'], 'provider' => [], 'facility' => [], 'codes' => [], 'code_arrays' => [],
            'sources' => ['source', 'import_source'], 'review' => ['review_status'], 'source_document' => true,
        ],
        'episodes' => [
            'model' => PhrEpisode::class,
            'event' => ['started_at', 'created_at'],
            'summary' => ['title'], 'q' => ['title', 'reason'], 'provider' => [], 'facility' => [], 'codes' => [], 'code_arrays' => [],
            'sources' => ['import_source'], 'review' => ['review_status'], 'source_document' => true,
        ],
        // What happened to a home medication during a stay (#414); its summary names the
        // action and phase (project()). Soft-deleted, so changes.list carries its tombstones.
        'medication-transitions' => [
            'model' => PhrMedicationTransition::class,
            'event' => ['created_at'],
            'summary' => ['action'], 'q' => ['note'], 'provider' => [], 'facility' => [], 'codes' => [], 'code_arrays' => [],
            'sources' => ['import_source'], 'review' => ['review_status'], 'source_document' => true,
        ],
        'health-logs' => [
            'model' => PhrHealthLog::class,
            'event' => ['created_at'],
            'summary' => ['name'], 'q' => ['name', 'description', 'kind'], 'provider' => [], 'facility' => [], 'codes' => [], 'code_arrays' => [],
            'sources' => [], 'review' => [], 'source_document' => false,
        ],
        // Each entry of a health log, dated when it happened (#444).
        'health-log-entries' => [
            'model' => PhrHealthLogEntry::class,
            'event' => ['occurred_at', 'created_at'],
            'summary' => ['title'], 'q' => ['title', 'notes'], 'provider' => [], 'facility' => [], 'codes' => [], 'code_arrays' => [],
            'sources' => [], 'review' => [], 'source_document' => false,
        ],
        // Explanation-of-benefit claims (#444), dated by processing. Header columns only:
        // the raw text, parser payload and member identifiers are never read here.
        'eobs' => [
            'model' => PhrEob::class,
            'event' => ['processed_date', 'submission_date', 'print_date', 'created_at'],
            'summary' => ['provider_name', 'claim_number'], 'q' => ['provider_name', 'claim_number', 'carrier', 'plan_name'],
            'provider' => ['provider_name'], 'facility' => [], 'codes' => [], 'code_arrays' => [],
            'sources' => ['import_source'], 'review' => ['review_status'], 'source_document' => true,
            'columns' => [
                'id', 'patient_id', 'source_document_id', 'import_source', 'claim_number', 'claim_type', 'carrier', 'plan_name',
                'provider_name', 'submission_date', 'print_date', 'processed_date', 'review_status', 'created_at', 'updated_at', 'deleted_at',
            ],
        ],
        // Metadata only, behind documents:read. q matches the title and summary:
        // a LIKE over extracted text (up to 1 MiB a row) is a full scan on every
        // supported database, and the text is read with documents.content.get.
        // The selected columns leave the extracted text unread.
        'documents' => [
            'model' => PhrDocument::class,
            'event' => ['observed_at', 'created_at'],
            'summary' => ['title', 'original_filename', 'document_type'], 'q' => ['title', 'summary'], 'provider' => [], 'facility' => [],
            'codes' => [], 'code_arrays' => [], 'sources' => ['source', 'import_source'], 'review' => ['review_status'], 'source_document' => false,
            'columns' => [
                'id', 'patient_id', 'title', 'original_filename', 'document_type', 'observed_at', 'source', 'import_source',
                'review_status', 'created_at', 'updated_at', 'deleted_at', 'retracted_at',
            ],
            'scope' => AgentApiScopes::DOCUMENTS_READ,
        ],
    ];

    /**
     * How many types a request may name: all of them. records.search,
     * timeline.list and changes.list (REST and MCP) declare this bound.
     */
    public const int RESOURCE_TYPE_COUNT = 15;

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /** The scope a type needs beyond the operation's own, or null when it needs none. */
    public static function requiredScope(string $id): ?string
    {
        return self::DEFINITIONS[$id]['scope'] ?? null;
    }

    /** @return array<string, mixed> */
    public static function definition(string $id): array
    {
        return self::DEFINITIONS[$id];
    }
}
