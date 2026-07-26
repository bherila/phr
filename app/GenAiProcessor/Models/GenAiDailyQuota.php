<?php

namespace App\GenAiProcessor\Models;

use Illuminate\Database\Eloquent\Model;

class GenAiDailyQuota extends Model
{
    protected $table = 'genai_daily_quota';

    protected $primaryKey = 'usage_date';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'usage_date',
        'request_count',
        'updated_at',
    ];

    protected $casts = [
        'request_count' => 'integer',
    ];
}
