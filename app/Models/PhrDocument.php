<?php

namespace App\Models;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $user_id
 * @property int|null $uploaded_by_user_id
 * @property int|null $genai_job_id
 * @property string|null $title
 * @property string $document_type
 * @property Carbon|null $observed_at
 * @property string|null $original_filename
 * @property string $storage_disk
 * @property string|null $storage_path
 * @property string|null $mime_type
 * @property int $byte_size
 * @property string|null $file_hash
 * @property string|null $extracted_text
 * @property string|null $summary
 * @property string|null $source
 * @property array<int, string>|null $tags
 * @property string|null $import_source
 * @property string|null $external_id
 * @property Carbon|null $imported_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PhrNegativeAssertion> $negativeAssertions
 * @property-read Collection<int, PhrPortalMessage> $portalMessages
 */
class PhrDocument extends Model
{
    use SerializesDatesAsLocal;
    use SoftDeletes;

    public const array DOCUMENT_TYPES = [
        'lab_report',
        'office_visit_note',
        'clinical_questionnaire',
        'patient_symptom_log',
        'discharge_summary',
        'imaging_report',
        'prescription',
        'medical_necessity_letter',
        'prior_authorization',
        'insurance',
        'consent',
        'care_correspondence',
        'other',
    ];

    /**
     * Suggested document tags. Tags remain intentionally extensible so a
     * source-specific label does not require a schema change.
     *
     * @var array<int, string>
     */
    public const array DOCUMENT_TAGS = [
        'medical-necessity',
        'prior-authorization',
        'clinical-questionnaire',
        'patient-symptom-log',
        'care-correspondence',
    ];

    public const array SOURCES = [
        'manual_upload',
        'agent_upload',
        'genai_import',
        'fhir_import',
        'ccda_import',
        'mychart_zip',
    ];

    /**
     * The only disk PHR document bytes are ever written to.
     *
     * Read paths pin the disk to this constant rather than trusting the
     * `storage_disk` column, so a row that somehow acquired a different value
     * cannot redirect a stream at an unrelated filesystem.
     */
    public const string STORAGE_DISK = 'phr_documents';

    protected $fillable = [
        'patient_id',
        'user_id',
        'uploaded_by_user_id',
        'genai_job_id',
        'title',
        'document_type',
        'observed_at',
        'original_filename',
        'storage_disk',
        'storage_path',
        'mime_type',
        'byte_size',
        'file_hash',
        'extracted_text',
        'summary',
        'source',
        'tags',
        'import_source',
        'external_id',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'uploaded_by_user_id' => 'integer',
            'genai_job_id' => 'integer',
            'observed_at' => 'datetime',
            'byte_size' => 'integer',
            'tags' => 'array',
            'imported_at' => 'datetime',
            'deleted_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** @return BelongsTo<GenAiImportJob, $this> */
    public function genAiJob(): BelongsTo
    {
        return $this->belongsTo(GenAiImportJob::class, 'genai_job_id');
    }

    /** @return HasMany<PhrPortalMessage, $this> */
    public function portalMessages(): HasMany
    {
        return $this->hasMany(PhrPortalMessage::class, 'source_document_id');
    }

    /** @return HasMany<PhrNegativeAssertion, $this> */
    public function negativeAssertions(): HasMany
    {
        return $this->hasMany(PhrNegativeAssertion::class, 'source_document_id');
    }

    /** @return HasMany<PhrEob, $this> */
    public function eobs(): HasMany
    {
        return $this->hasMany(PhrEob::class, 'source_document_id');
    }
}
