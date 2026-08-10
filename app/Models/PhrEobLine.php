<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $eob_id
 * @property int $patient_id
 * @property int $line_number
 * @property string $procedure_code
 * @property string $code_type
 * @property Carbon|null $service_start
 * @property Carbon|null $service_end
 */
class PhrEobLine extends Model
{
    use SerializesDatesAsLocal;

    protected $fillable = [
        'eob_id',
        'patient_id',
        'line_number',
        'procedure_code',
        'revenue_code',
        'code_type',
        'description',
        'service_start',
        'service_end',
        'total_charges',
        'provider_discount',
        'ineligible_amount',
        'notes_applied',
        'deductible_applied',
        'copay_applied',
        'benefit_percent',
        'carrier_payment',
        'plan_payment',
        'patient_responsibility',
        'parsed_data',
        'raw_text',
    ];

    protected function casts(): array
    {
        return [
            'eob_id' => 'integer',
            'patient_id' => 'integer',
            'line_number' => 'integer',
            'service_start' => 'date',
            'service_end' => 'date',
            'total_charges' => 'decimal:2',
            'provider_discount' => 'decimal:2',
            'ineligible_amount' => 'decimal:2',
            'notes_applied' => 'array',
            'deductible_applied' => 'decimal:2',
            'copay_applied' => 'decimal:2',
            'benefit_percent' => 'decimal:2',
            'carrier_payment' => 'decimal:2',
            'plan_payment' => 'decimal:2',
            'patient_responsibility' => 'decimal:2',
            'parsed_data' => 'array',
        ];
    }

    /** @return BelongsTo<PhrEob, $this> */
    public function eob(): BelongsTo
    {
        return $this->belongsTo(PhrEob::class, 'eob_id');
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }
}
