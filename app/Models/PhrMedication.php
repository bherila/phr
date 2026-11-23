<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Database\Factories\PhrMedicationFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $user_id
 * @property string|null $import_source
 * @property string|null $external_id
 * @property int|null $source_document_id
 * @property string $review_status
 * @property string $name
 * @property string|null $rxnorm_code
 * @property string|null $dose
 * @property string|null $dose_unit
 * @property string|null $route
 * @property string|null $frequency
 * @property Carbon|null $started_on
 * @property Carbon|null $ended_on
 * @property string $status
 * @property string|null $prescriber_name
 * @property string|null $reason_for_use
 * @property string|null $raw_text
 * @property string|null $category
 * @property bool|null $as_needed
 * @property string|null $as_needed_reason
 * @property string|null $brand_name
 * @property string|null $rate
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property list<array{code: string, message: string, external_id?: string}>|null $review_flags
 * @property Carbon|null $retracted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrMedication extends Model
{
    /** @use HasFactory<PhrMedicationFactory> */
    use HasFactory;

    use SerializesDatesAsLocal;
    use SoftDeletes;

    protected $fillable = [
        'patient_id',
        'user_id',
        'import_source',
        'external_id',
        'source_document_id',
        'review_status',
        'name',
        'rxnorm_code',
        'dose',
        'dose_unit',
        'route',
        'frequency',
        'started_on',
        'ended_on',
        'status',
        'prescriber_name',
        'reason_for_use',
        'raw_text',
        'category',
        'as_needed',
        'as_needed_reason',
        'brand_name',
        'rate',
        'started_at',
        'ended_at',
        'review_flags',
    ];

    /**
     * The setting and order columns added for hospital medication lists (#413). See
     * PhrMedicationCategory.
     */
    public const array SETTING = [
        'category',
        'as_needed',
        'as_needed_reason',
        'brand_name',
        'rate',
        'started_at',
        'ended_at',
        'review_flags',
    ];

    protected function casts(): array
    {
        return [
            'retracted_at' => 'datetime',
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
            'started_on' => 'date',
            'ended_on' => 'date',
            'as_needed' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'review_flags' => 'array',
        ];
    }

    /**
     * An end date that has arrived means the medication has ended, whoever wrote the record and
     * whatever status they sent: a form, an import or an agent. It is applied on read so that a
     * date still ahead, such as the last day of a course, takes effect by itself. The stored
     * value is untouched, so taking the date away restores it. "completed" is also the FHIR code.
     *
     * The end's time, when the source gave one, is when it ended: an order ending at six this
     * evening is still running at noon, though its date has arrived (#432). A date alone has
     * arrived from its first moment.
     *
     * @return Attribute<string|null, never>
     */
    protected function status(): Attribute
    {
        return Attribute::get(function (?string $value): ?string {
            $ended = $this->ended_at ?? $this->ended_on;
            $stillRunning = $value === null || in_array($value, ['active', 'on_hold'], true);

            return $stillRunning && $ended !== null && ! $ended->isFuture() ? 'completed' : $value;
        });
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
}
