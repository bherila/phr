<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property string $record_table
 * @property int $record_id
 * @property string $native_id
 * @property Carbon|null $restored_at
 */
class PhrNativeRecordIdentity extends Model
{
    protected $fillable = [
        'patient_id',
        'record_table',
        'record_id',
        'native_id',
        'restored_at',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'record_id' => 'integer',
            'restored_at' => 'datetime',
        ];
    }
}
