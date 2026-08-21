<?php

namespace App\Support\AgentApi;

use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrHealthLog;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
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
     *   sources: list<string>, review: list<string>, source_document: bool
     * }>
     */
    private const array DEFINITIONS = [
        'office-visits' => [
            'model' => PhrOfficeVisit::class,
            'event' => ['visit_started_at', 'visit_date', 'created_at'],
            'summary' => ['chief_complaint', 'visit_type', 'provider_name'],
            'q' => ['chief_complaint', 'visit_type', 'provider_name', 'facility_name', 'assessment', 'plan'],
            'provider' => ['provider_name'], 'facility' => ['facility_name'],
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
        'conditions' => [
            'model' => PhrCondition::class,
            'event' => ['onset_date', 'created_at'],
            'summary' => ['name'], 'q' => ['name', 'notes'], 'provider' => [], 'facility' => [],
            'codes' => ['icd10_code', 'snomed_code'], 'code_arrays' => [], 'sources' => ['import_source'],
            'review' => ['verification_status'], 'source_document' => true,
        ],
        'allergies' => [
            'model' => PhrAllergy::class,
            'event' => ['created_at'],
            'summary' => ['substance'], 'q' => ['substance', 'reaction', 'notes'], 'provider' => [], 'facility' => [],
            'codes' => ['rxnorm_code', 'snomed_code'], 'code_arrays' => [], 'sources' => ['import_source'],
            'review' => ['verification_status'], 'source_document' => true,
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
        'health-logs' => [
            'model' => PhrHealthLog::class,
            'event' => ['created_at'],
            'summary' => ['name'], 'q' => ['name', 'description', 'kind'], 'provider' => [], 'facility' => [], 'codes' => [], 'code_arrays' => [],
            'sources' => [], 'review' => [], 'source_document' => false,
        ],
    ];

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /** @return array<string, mixed> */
    public static function definition(string $id): array
    {
        return self::DEFINITIONS[$id];
    }
}
