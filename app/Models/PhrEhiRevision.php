<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable content snapshot of one source entity. values_json maps column => raw string, or null for an explicit null marker.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $entity_id
 * @property int|null $first_package_id
 * @property string $content_digest
 * @property array<string, string|null> $values_json
 * @property string|null $dictionary_fingerprint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PhrEhiEntity $entity
 */
class PhrEhiRevision extends Model
{
    use SerializesDatesAsLocal;

    protected $table = 'phr_ehi_revisions';

    protected $fillable = ['patient_id', 'entity_id', 'first_package_id', 'content_digest', 'values_json', 'dictionary_fingerprint'];

    protected function casts(): array
    {
        return ['values_json' => 'array'];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<PhrEhiEntity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(PhrEhiEntity::class, 'entity_id');
    }
}
