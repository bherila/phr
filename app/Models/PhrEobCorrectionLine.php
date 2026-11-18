<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhrEobCorrectionLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['patient_id' => 'integer', 'correction_id' => 'integer', 'position' => 'integer', 'payload' => 'array'];
    }
}
