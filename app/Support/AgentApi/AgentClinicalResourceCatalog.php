<?php

namespace App\Support\AgentApi;

use App\Contracts\PHR\ClinicalDataRules;
use App\Http\Resources\PHR\AllergyResource;
use App\Http\Resources\PHR\ConditionResource;
use App\Http\Resources\PHR\HealthLogResource;
use App\Http\Resources\PHR\ImmunizationResource;
use App\Http\Resources\PHR\LabResultResource;
use App\Http\Resources\PHR\MedicationResource;
use App\Http\Resources\PHR\OfficeVisitResource;
use App\Http\Resources\PHR\ProcedureResource;
use App\Http\Resources\PHR\VitalResource;
use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrHealthLog;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatientVital;
use App\Models\PhrProcedure;
use App\Support\PHR\Validation\AllergyDataRules;
use App\Support\PHR\Validation\ConditionDataRules;
use App\Support\PHR\Validation\ImmunizationDataRules;
use App\Support\PHR\Validation\LabResultDataRules;
use App\Support\PHR\Validation\MedicationDataRules;
use App\Support\PHR\Validation\OfficeVisitDataRules;
use App\Support\PHR\Validation\ProcedureDataRules;
use App\Support\PHR\Validation\VitalDataRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fixed declaration of the clinical types exposed by the agent API.
 *
 * Keeping model and serializer selection in an allow-list makes a route value
 * incapable of selecting an arbitrary class or table. The JsonResource classes
 * are the same ones used by the browser API, so adding the agent surface does not
 * create a second clinical serialization contract.
 */
final class AgentClinicalResourceCatalog
{
    /**
     * @var array<string, array{
     *     model: class-string<Model>,
     *     resource: class-string<JsonResource>,
     *     provenance: bool,
     *     write_rules?: class-string<ClinicalDataRules>,
     *     health_log_aggregates?: bool
     * }>
     */
    private const array DEFINITIONS = [
        'office-visits' => [
            'model' => PhrOfficeVisit::class,
            'resource' => OfficeVisitResource::class,
            'provenance' => true,
            'write_rules' => OfficeVisitDataRules::class,
        ],
        'procedures' => [
            'model' => PhrProcedure::class,
            'resource' => ProcedureResource::class,
            'provenance' => true,
            'write_rules' => ProcedureDataRules::class,
        ],
        'immunizations' => [
            'model' => PhrImmunization::class,
            'resource' => ImmunizationResource::class,
            'provenance' => true,
            'write_rules' => ImmunizationDataRules::class,
        ],
        'medications' => [
            'model' => PhrMedication::class,
            'resource' => MedicationResource::class,
            'provenance' => true,
            'write_rules' => MedicationDataRules::class,
        ],
        'conditions' => [
            'model' => PhrCondition::class,
            'resource' => ConditionResource::class,
            'provenance' => true,
            'write_rules' => ConditionDataRules::class,
        ],
        'allergies' => [
            'model' => PhrAllergy::class,
            'resource' => AllergyResource::class,
            'provenance' => true,
            'write_rules' => AllergyDataRules::class,
        ],
        'lab-results' => [
            'model' => PhrLabResult::class,
            'resource' => LabResultResource::class,
            'provenance' => true,
            'write_rules' => LabResultDataRules::class,
        ],
        'vitals' => [
            'model' => PhrPatientVital::class,
            'resource' => VitalResource::class,
            'provenance' => true,
            'write_rules' => VitalDataRules::class,
        ],
        'health-logs' => [
            'model' => PhrHealthLog::class,
            'resource' => HealthLogResource::class,
            'provenance' => false,
            'health_log_aggregates' => true,
        ],
    ];

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /** @return list<string> */
    public static function writableIds(): array
    {
        return array_keys(array_filter(
            self::DEFINITIONS,
            static fn (array $definition): bool => isset($definition['write_rules']),
        ));
    }

    public static function upsertOperationId(string $resource): string
    {
        return str_replace('-', '_', $resource).'.upsert';
    }

    /** @return list<string> */
    public static function writableOperationIds(): array
    {
        return array_map(self::upsertOperationId(...), self::writableIds());
    }

    /**
     * @return array{
     *     model: class-string<Model>,
     *     resource: class-string<JsonResource>,
     *     provenance: bool,
     *     write_rules?: class-string<ClinicalDataRules>,
     *     health_log_aggregates?: bool
     * }|null
     */
    public static function definition(string $resource): ?array
    {
        return self::DEFINITIONS[$resource] ?? null;
    }
}
