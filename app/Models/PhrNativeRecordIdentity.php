<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhrNativeRecordIdentity extends Model
{
    protected $fillable = [
        'patient_id',
        'record_table',
        'record_id',
        'native_id',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'record_id' => 'integer',
        ];
    }
}
