<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $user_id
 * @property int|null $source_document_id
 * @property string $import_source
 * @property string $external_id
 * @property string|null $claim_fingerprint
 * @property string|null $claim_number
 * @property string $claim_type
 * @property Carbon|null $submission_date
 * @property Carbon|null $print_date
 * @property Carbon|null $processed_date
 * @property-read Collection<int, PhrEobLine> $lines
 */
class PhrEob extends Model
{
    use SerializesDatesAsLocal;

    protected $fillable = [
        'patient_id',
        'user_id',
        'source_document_id',
        'import_source',
        'external_id',
        'claim_fingerprint',
        'claim_number',
        'claim_type',
        'administrator',
        'carrier',
        'plan_name',
        'group_number',
        'member_id',
        'participant_name',
        'patient_name',
        'provider_name',
        'provider_phone',
        'payment_to',
        'provider_tin',
        'check_number',
        'check_amount',
        'submission_date',
        'print_date',
        'processed_date',
        'total_accepted_fee',
        'total_charges',
        'total_provider_discount',
        'total_ineligible_amount',
        'total_deductible_applied',
        'total_copay_applied',
        'total_benefit_percent',
        'total_carrier_payment',
        'total_plan_payment',
        'total_patient_responsibility',
        'parsed_data',
        'raw_text',
        'parser_version',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
            'check_amount' => 'decimal:2',
            'submission_date' => 'date',
            'print_date' => 'date',
            'processed_date' => 'date',
            'total_accepted_fee' => 'decimal:2',
            'total_charges' => 'decimal:2',
            'total_provider_discount' => 'decimal:2',
            'total_ineligible_amount' => 'decimal:2',
            'total_deductible_applied' => 'decimal:2',
            'total_copay_applied' => 'decimal:2',
            'total_benefit_percent' => 'decimal:2',
            'total_carrier_payment' => 'decimal:2',
            'total_plan_payment' => 'decimal:2',
            'total_patient_responsibility' => 'decimal:2',
            'parsed_data' => 'array',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<PhrDocument, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(PhrDocument::class, 'source_document_id');
    }

    /** @return HasMany<PhrEobLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PhrEobLine::class, 'eob_id');
    }

    /** @return BelongsToMany<PhrOfficeVisit, $this> */
    public function officeVisits(): BelongsToMany
    {
        return $this->belongsToMany(PhrOfficeVisit::class, 'phr_office_visit_eobs', 'eob_id', 'office_visit_id')
            ->withPivot('patient_id')
            ->withTimestamps();
    }

    /** @return BelongsToMany<PhrProcedure, $this> */
    public function procedures(): BelongsToMany
    {
        return $this->belongsToMany(PhrProcedure::class, 'phr_procedure_eobs', 'eob_id', 'procedure_id')
            ->withPivot('patient_id')
            ->withTimestamps();
    }
}
