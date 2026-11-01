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
        ];
    }

    /**
     * An end date that has arrived means the medication has ended, whoever wrote the record and
     * whatever status they sent: a form, an import or an agent. It is applied on read so that a
     * date still ahead, such as the last day of a course, takes effect by itself. The stored
     * value is untouched, so taking the date away restores it. "completed" is also the FHIR code.
     *
     * @return Attribute<string|null, never>
     */
    protected function status(): Attribute
    {
        return Attribute::get(function (?string $value): ?string {
            $endedOn = $this->ended_on;
            $stillRunning = $value === null || in_array($value, ['active', 'on_hold'], true);

            return $stillRunning && $endedOn !== null && ! $endedOn->isFuture() ? 'completed' : $value;
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
