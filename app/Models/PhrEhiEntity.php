<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A source entity identified by its full dictionary primary key within one declared source.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $source_id
 * @property string $table_name
 * @property string $key_digest
 * @property array<string, string|null> $key_json
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrEhiEntity extends Model
{
    use SerializesDatesAsLocal;

    protected $table = 'phr_ehi_entities';

    protected $fillable = ['patient_id', 'source_id', 'table_name', 'key_digest', 'key_json'];

    protected function casts(): array
    {
        return ['key_json' => 'array'];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return HasMany<PhrEhiRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(PhrEhiRevision::class, 'entity_id');
    }
}
