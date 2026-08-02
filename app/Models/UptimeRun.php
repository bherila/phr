<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $job_name
 * @property string $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $duration_ms
 * @property int|null $exit_code
 */
class UptimeRun extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'job_name',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'exit_code',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'exit_code' => 'integer',
        ];
    }
}
