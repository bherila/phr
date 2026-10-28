<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One queued preview of an EHI package, requested from the browser.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $package_id
 * @property int|null $requested_by_user_id
 * @property string $status
 * @property string|null $failure_reason
 * @property array{counts?: array<string, array<string, int>>, statuses?: array<string, int>, findings?: array<string, int>, preview_digest?: string, replayed?: bool}|null $result_json
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrEhiPreviewRun extends Model
{
    use SerializesDatesAsLocal;

    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    /** Refused or stopped; failure_reason says why, and the preview can be requested again. */
    public const string STATUS_FAILED = 'failed';

    public const array ACTIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING];

    protected $table = 'phr_ehi_preview_runs';

    protected $fillable = [
        'patient_id',
        'package_id',
        'requested_by_user_id',
        'status',
        'failure_reason',
        'result_json',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'package_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'result_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
