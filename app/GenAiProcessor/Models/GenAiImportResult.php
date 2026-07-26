<?php

namespace App\GenAiProcessor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenAiImportResult extends Model
{
    protected $table = 'genai_import_results';

    protected $fillable = [
        'job_id',
        'result_index',
        'result_json',
        'status',
        'produced_by',
        'imported_at',
    ];

    protected $casts = [
        'result_index' => 'integer',
        'imported_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<GenAiImportJob, self>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(GenAiImportJob::class, 'job_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function getResultArray(): array
    {
        if (empty($this->result_json)) {
            return [];
        }

        return json_decode($this->result_json, true) ?? [];
    }

    public function markImported(): void
    {
        $this->update([
            'status' => 'imported',
            'imported_at' => now(),
        ]);
    }

    public function markSkipped(): void
    {
        $this->update(['status' => 'skipped']);
    }
}
