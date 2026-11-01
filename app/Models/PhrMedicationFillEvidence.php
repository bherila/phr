<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of evidence for a fill besides the document it was first read from:
 * another document (the receipt of a fill first read from a label) or the EOB
 * line that adjudicated it.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $fill_id
 * @property int|null $document_id
 * @property int|null $eob_line_id
 * @property string $source_kind
 * @property string|null $source_locator
 * @property string $evidence_key
 */
class PhrMedicationFillEvidence extends Model
{
    protected $table = 'phr_medication_fill_evidence';

    protected $fillable = ['patient_id', 'fill_id', 'document_id', 'eob_line_id', 'source_kind', 'source_locator', 'evidence_key', 'import_source', 'external_id'];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'fill_id' => 'integer',
            'document_id' => 'integer',
            'eob_line_id' => 'integer',
        ];
    }

    /** The same evidence, however many times a source is read again. */
    public static function keyFor(?int $documentId, ?int $eobLineId, string $sourceKind, ?string $locator): string
    {
        return substr(hash('sha256', implode('|', [$documentId ?? '', $eobLineId ?? '', $sourceKind, $locator ?? ''])), 0, 40);
    }

    /** @return BelongsTo<PhrMedicationFill, $this> */
    public function medicationFill(): BelongsTo
    {
        return $this->belongsTo(PhrMedicationFill::class, 'fill_id');
    }
}
