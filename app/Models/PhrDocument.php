<?php

namespace App\Models;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Support\PHR\PhrClinicalNote;
use App\Support\PHR\PhrDocumentSource;
use App\Support\PHR\PhrDocumentType;
use App\Support\PHR\PhrReviewStatus;
use App\Traits\HasPublicId;
use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property string $review_status
 * @property string|null $loinc_code
 * @property string|null $source_type_label
 * @property string|null $author_name
 * @property string|null $author_role
 * @property string|null $cosigner_name
 * @property Carbon|null $authored_at
 * @property Carbon|null $signed_at
 * @property string|null $doc_status
 * @property string|null $patient_signature_status
 * @property Carbon|null $patient_signed_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $retracted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PhrNegativeAssertion> $negativeAssertions
 * @property-read Collection<int, PhrPortalMessage> $portalMessages
 * @property-read PhrDocumentRelation|null $relatesTo
 */
class PhrDocument extends Model
{
    use HasPublicId;
    use SerializesDatesAsLocal;
    use SoftDeletes;

    /**
     * Every allowed value of `document_type`, enforced on save; see PhrDocumentType.
     *
     * @var list<string>
     */
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
        // Clinical notes and stay documents (phr#409).
        'progress_note',
        'consult_note',
        'history_and_physical',
        'procedure_note',
        'nursing_note',
        'advance_directive',
        'ems_report',
        'clinical_image',
        'patient_education',
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

    /**
     * Every allowed value of `source`, enforced on save; see PhrDocumentSource.
     *
     * @var list<string>
     */
    public const array SOURCES = [
        'manual_upload',
        'agent_upload',
        'genai_import',
        'fhir_import',
        'ccda_import',
        'mychart_zip',
        // Accepted EHI Tables proposals (issue #213); the file is a verified copy
        // of preserved EHI evidence, or derived text tagged ehi-derived-text.
        'ehi_tables',
    ];

    /**
     * The only disk PHR document bytes are ever written to.
     *
     * Read paths pin the disk to this constant rather than trusting the
     * `storage_disk` column, so a row that somehow acquired a different value
     * cannot redirect a stream at an unrelated filesystem.
     */
    public const string STORAGE_DISK = 'phr_documents';

    /**
     * A writer that names no type stores `other`, the same as the column default.
     *
     * A writer that names no review status stores `confirmed`, the column default:
     * a person's own upload and what the console commands store. Every machine
     * writer (an agent, an AI read, a standards import) names `pending_review`, so
     * nothing unreviewed reaches an export (PhrReviewStatus).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'document_type' => PhrDocumentType::FALLBACK,
        'review_status' => PhrReviewStatus::CONFIRMED,
    ];

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
        'review_status',
        'loinc_code',
        'source_type_label',
        'author_name',
        'author_role',
        'cosigner_name',
        'authored_at',
        'signed_at',
        'doc_status',
        'patient_signature_status',
        'patient_signed_at',
    ];

    protected static function booted(): void
    {
        // The browser parses `source` against SOURCES, so one stray value blanks
        // the document viewer. A row is checked when it is created or its source
        // changes; an untouched legacy value never turns a metadata edit into an
        // error (the data migration repairs those rows).
        //
        // `document_type` follows the same rule against DOCUMENT_TYPES.
        static::saving(static function (self $document): void {
            if (! $document->exists || $document->isDirty('source')) {
                PhrDocumentSource::assertValid($document->source);
            }
            if (! $document->exists || $document->isDirty('document_type')) {
                PhrDocumentType::assertValid($document->document_type);
            }
            // Note metadata (phr#409) is null or one of its vocabulary's values.
            foreach (['doc_status' => PhrClinicalNote::DOC_STATUSES, 'patient_signature_status' => PhrClinicalNote::PATIENT_SIGNATURE_STATUSES] as $column => $allowed) {
                $value = $document->getAttribute($column);
                if ($document->isDirty($column) && $value !== null && ! in_array($value, $allowed, true)) {
                    throw new \InvalidArgumentException("Invalid document {$column}.");
                }
            }
        });
        static::saved(static function (self $document): void {
            if ($document->wasChanged(['file_hash', 'patient_id', 'deleted_at'])) {
                $document->eobs()->touch();
            }
        });
        static::deleting(static function (self $document): void {
            // Before a hard delete clears the source foreign key, advance the
            // surviving EOB so incremental consumers see its missing source.
            $document->eobs()->touch();
        });
    }

    protected function casts(): array
    {
        return [
            'retracted_at' => 'datetime',
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'uploaded_by_user_id' => 'integer',
            'genai_job_id' => 'integer',
            'observed_at' => 'datetime',
            'byte_size' => 'integer',
            'tags' => 'array',
            'imported_at' => 'datetime',
            'deleted_at' => 'datetime',
            'authored_at' => 'datetime',
            'signed_at' => 'datetime',
            'patient_signed_at' => 'datetime',
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

    /**
     * The document this one appends to or replaces (phr#409).
     *
     * @return HasOne<PhrDocumentRelation, $this>
     */
    public function relatesTo(): HasOne
    {
        return $this->hasOne(PhrDocumentRelation::class, 'document_id');
    }

    /** @return HasMany<PhrEob, $this> */
    public function eobs(): HasMany
    {
        return $this->hasMany(PhrEob::class, 'source_document_id');
    }
}
