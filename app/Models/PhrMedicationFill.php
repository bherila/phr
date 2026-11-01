<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Database\Factories\PhrMedicationFillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One prescription dispensing (FHIR MedicationDispense), with the drug resolved
 * from its NDC by the server.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $user_id
 * @property int|null $medication_id
 * @property string|null $import_source
 * @property string|null $external_id
 * @property int|null $source_document_id
 * @property string $review_status
 * @property string|null $patient_name_as_printed
 * @property string|null $ndc_as_captured
 * @property string|null $ndc_10
 * @property string|null $ndc_11
 * @property string $ndc_status
 * @property list<string>|null $substance_names
 * @property list<string>|null $strengths
 * @property string|null $brand_name
 * @property string|null $dosage_form
 * @property string|null $route
 * @property string|null $labeler_name
 * @property string|null $package_description
 * @property string|null $rxcui
 * @property string|null $drug_as_printed
 * @property array<string, mixed>|null $fda_snapshot
 * @property Carbon|null $fda_fetched_at
 * @property Carbon $filled_on
 * @property string $quantity
 * @property string|null $quantity_unit
 * @property int|null $days_supply
 * @property string|null $rx_number
 * @property int|null $refills_remaining
 * @property string|null $directions
 * @property string|null $prescriber_name
 * @property int|null $paid_cents
 * @property int|null $retail_cents
 * @property string|null $pharmacy_name
 * @property string|null $pharmacy_location
 * @property string $source_kind
 * @property string|null $source_locator
 * @property list<array{code: string, message: string, fill_id?: int}>|null $review_flags
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrMedicationFill extends Model
{
    /** @use HasFactory<PhrMedicationFillFactory> */
    use HasFactory;

    use SerializesDatesAsLocal;
    use SoftDeletes;

    public const string NDC_RESOLVED = 'resolved';

    public const string NDC_AMBIGUOUS = 'ambiguous';

    public const string NDC_UNRESOLVED = 'unresolved';

    public const string NDC_MANUAL = 'manual';

    /** @var list<string> */
    public const array NDC_STATUSES = [self::NDC_RESOLVED, self::NDC_AMBIGUOUS, self::NDC_UNRESOLVED, self::NDC_MANUAL];

    /** @var list<string> */
    public const array SOURCE_KINDS = ['label_photo', 'receipt_pdf', 'csv', 'statement', 'eob', 'manual'];

    protected $table = 'phr_medication_fills';

    protected $fillable = [
        'patient_id',
        'user_id',
        'medication_id',
        'import_source',
        'external_id',
        'source_document_id',
        'review_status',
        'patient_name_as_printed',
        'ndc_as_captured',
        'ndc_10',
        'ndc_11',
        'ndc_status',
        'substance_names',
        'strengths',
        'brand_name',
        'dosage_form',
        'route',
        'labeler_name',
        'package_description',
        'rxcui',
        'drug_as_printed',
        'fda_snapshot',
        'fda_fetched_at',
        'filled_on',
        'quantity',
        'quantity_unit',
        'days_supply',
        'rx_number',
        'refills_remaining',
        'directions',
        'prescriber_name',
        'paid_cents',
        'retail_cents',
        'pharmacy_name',
        'pharmacy_location',
        'source_kind',
        'source_locator',
        'review_flags',
    ];

    protected function casts(): array
    {
        return [
            'retracted_at' => 'datetime',
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'medication_id' => 'integer',
            'source_document_id' => 'integer',
            'substance_names' => 'array',
            'strengths' => 'array',
            'fda_snapshot' => 'array',
            'fda_fetched_at' => 'datetime',
            'filled_on' => 'date',
            'quantity' => 'decimal:3',
            'days_supply' => 'integer',
            'refills_remaining' => 'integer',
            'paid_cents' => 'integer',
            'retail_cents' => 'integer',
            'review_flags' => 'array',
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

    /** @return BelongsTo<PhrMedication, $this> */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(PhrMedication::class, 'medication_id');
    }

    /** @return BelongsTo<PhrDocument, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(PhrDocument::class, 'source_document_id');
    }

    /** @return HasMany<PhrMedicationFillEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(PhrMedicationFillEvidence::class, 'fill_id');
    }
}
