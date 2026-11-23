<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document's relation to the document it appends to (an addendum) or replaces
 * (an amendment), FHIR DocumentReference.relatesTo (phr#409).
 *
 * `related_document_id` is null while the source's reference names no document
 * PHR holds: the reference is kept in `related_reference`, never guessed, and a
 * later import that brings the target resolves it.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $document_id
 * @property int|null $related_document_id
 * @property string|null $related_reference
 * @property string $relation
 */
class PhrDocumentRelation extends Model
{
    protected $table = 'phr_document_relations';

    protected $fillable = ['patient_id', 'document_id', 'related_document_id', 'related_reference', 'relation'];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'document_id' => 'integer',
            'related_document_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PhrDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PhrDocument::class, 'document_id');
    }

    /** @return BelongsTo<PhrDocument, $this> */
    public function relatedDocument(): BelongsTo
    {
        return $this->belongsTo(PhrDocument::class, 'related_document_id');
    }
}
