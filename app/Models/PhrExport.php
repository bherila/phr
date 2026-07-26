<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $user_id
 * @property int $requested_by_user_id
 * @property string $format
 * @property array<int, string>|null $formats_json
 * @property string $status
 * @property string $storage_disk
 * @property string|null $storage_path
 * @property string|null $filename
 * @property int|null $file_size_bytes
 * @property string|null $error_message
 * @property Carbon|null $generated_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrExport extends Model
{
    use SerializesDatesAsLocal;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_READY = 'ready';

    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'patient_id',
        'user_id',
        'requested_by_user_id',
        'format',
        'formats_json',
        'status',
        'storage_disk',
        'storage_path',
        'filename',
        'file_size_bytes',
        'error_message',
        'generated_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'formats_json' => 'array',
            'file_size_bytes' => 'integer',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
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
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
