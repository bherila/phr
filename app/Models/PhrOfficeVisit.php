<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * @property Carbon|null $visit_date
 * @property Carbon|null $visit_started_at
 * @property Carbon|null $visit_ended_at
 * @property string|null $visit_type
 * @property string|null $provider_name
 * @property string|null $provider_specialty
 * @property string|null $facility_name
 * @property string|null $chief_complaint
 * @property string|null $assessment
 * @property string|null $plan
 * @property string|null $subjective
 * @property string|null $objective
 * @property array<int, array{code: string, description: string}>|null $icd10_codes
 * @property array<int, array{code: string, description: string}>|null $cpt_codes
 * @property string|null $raw_text
 * @property string|null $encounter_category
 * @property string|null $encounter_status
 * @property list<array<string, string|bool|null>>|null $encounter_diagnoses
 * @property string|null $visit_date_source
 * @property string|null $visit_started_source
 * @property string|null $visit_started_precision
 * @property string|null $visit_started_timezone
 * @property string|null $visit_ended_source
 * @property string|null $visit_ended_precision
 * @property string|null $visit_ended_timezone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrOfficeVisit extends Model
{
    use SerializesDatesAsLocal;
    use SoftDeletes;

    protected $fillable = [
        'patient_id',
        'user_id',
        'import_source',
        'external_id',
        'source_document_id',
        'review_status',
        'visit_date',
        'visit_started_at',
        'visit_ended_at',
        'visit_type',
        'provider_name',
        'provider_specialty',
        'facility_name',
        'chief_complaint',
        'assessment',
        'plan',
        'subjective',
        'objective',
        'icd10_codes',
        'cpt_codes',
        'raw_text',
        'encounter_category',
        'encounter_status',
        'encounter_diagnoses',
        'visit_date_source',
        'visit_started_source',
        'visit_started_precision',
        'visit_started_timezone',
        'visit_ended_source',
        'visit_ended_precision',
        'visit_ended_timezone',
    ];

    /** What a source said about one date: the text it was read from, and its zone. */
    public const array DATE_PROVENANCE = [
        'visit_date' => ['visit_date_source'],
        'visit_started_at' => ['visit_started_source', 'visit_started_precision', 'visit_started_timezone'],
        'visit_ended_at' => ['visit_ended_source', 'visit_ended_precision', 'visit_ended_timezone'],
    ];

    /**
     * An edited date must not keep the provenance of the date it replaced, or an
     * export would treat a person's own time as an unzoned source wall clock.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes): static
    {
        parent::fill($attributes);

        foreach (self::DATE_PROVENANCE as $column => $provenance) {
            if (array_key_exists($column, $attributes) && $this->isDirty($column)) {
                foreach ($provenance as $described) {
                    if (! array_key_exists($described, $attributes)) {
                        $this->setAttribute($described, null);
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Whether a stored start time is a wall clock in a zone nobody recorded. It is
     * shown as written, but an export must not state it as UTC, so it exports the
     * date alone. Null is a record from before zones were recorded.
     */
    public function startZoneIsUnrecorded(): bool
    {
        return in_array($this->visit_started_timezone, ['local', 'unspecified'], true)
            || ($this->visit_started_at === null && $this->visit_date_source !== null);
    }

    protected function casts(): array
    {
        return [
            'retracted_at' => 'datetime',
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
            'visit_date' => 'date',
            'visit_started_at' => 'datetime',
            'visit_ended_at' => 'datetime',
            'icd10_codes' => 'array',
            'cpt_codes' => 'array',
            'encounter_diagnoses' => 'array',
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

    /** @return BelongsToMany<PhrEob, $this> */
    public function eobs(): BelongsToMany
    {
        return $this->belongsToMany(PhrEob::class, 'phr_office_visit_eobs', 'office_visit_id', 'eob_id')
            ->withPivot('patient_id')
            ->withTimestamps();
    }

    /** @return BelongsToMany<PhrDicomStudy, $this> */
    public function imagingStudies(): BelongsToMany
    {
        return $this->belongsToMany(PhrDicomStudy::class, 'phr_office_visit_dicom_studies', 'office_visit_id', 'dicom_study_id')
            ->withPivot('patient_id')
            ->withTimestamps();
    }
}
