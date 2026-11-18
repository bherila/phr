<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable patient-owned correction metadata; line snapshots live in separate rows.
 *
 * @property array<string, mixed> $before_data
 * @property array<string, mixed> $after_data
 */
class PhrEobCorrection extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer', 'eob_id' => 'integer', 'ordinal' => 'integer',
            'before_document_id' => 'integer', 'after_document_id' => 'integer',
            'actor_provenance' => 'array', 'client_provenance' => 'array',
            'legacy_provenance' => 'array',
            'before_data' => 'array', 'after_data' => 'array',
            'before_document_provenance' => 'array', 'after_document_provenance' => 'array',
        ];
    }
}
