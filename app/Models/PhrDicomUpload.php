<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $uploaded_by_user_id
 * @property string $status
 * @property string|null $original_root_name
 * @property int $total_files
 * @property int $stored_files
 * @property int $skipped_files
 * @property int $total_bytes
 * @property int $stored_bytes
 * @property string $r2_prefix
 * @property array<string, mixed>|null $manifest_json
 * @property array<int, array<string, mixed>>|null $skipped_files_json
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PhrPatient $patient
 * @property-read User $uploader
 * @property-read Collection<int, PhrDicomFile> $files
 * @property-read Collection<int, PhrDicomStudy> $studies
 */
class PhrDicomUpload extends Model
{
    use SerializesDatesAsLocal;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'patient_id',
        'uploaded_by_user_id',
        'status',
        'original_root_name',
        'total_files',
        'stored_files',
        'skipped_files',
        'total_bytes',
        'stored_bytes',
        'r2_prefix',
        'manifest_json',
        'skipped_files_json',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'uploaded_by_user_id' => 'integer',
            'total_files' => 'integer',
            'stored_files' => 'integer',
            'skipped_files' => 'integer',
            'total_bytes' => 'integer',
            'stored_bytes' => 'integer',
            'manifest_json' => 'array',
            'skipped_files_json' => 'array',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** @return HasMany<PhrDicomFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(PhrDicomFile::class, 'upload_id');
    }

    /** @return HasMany<PhrDicomStudy, $this> */
    public function studies(): HasMany
    {
        return $this->hasMany(PhrDicomStudy::class, 'upload_id');
    }
}
