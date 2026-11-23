<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An episode of care (#415): encounters that belong to one story, grouped through
 * phr_episode_encounters (PhrEpisodeLinks).
 *
 * @property int $id
 * @property int $patient_id
 * @property int $user_id
 * @property string|null $import_source
 * @property string|null $external_id
 * @property int|null $source_document_id
 * @property string $review_status
 * @property string|null $kind
 * @property string|null $status
 * @property string $title
 * @property string|null $reason
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $retracted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrEpisode extends Model
{
    use SerializesDatesAsLocal;
    use SoftDeletes;

    protected $table = 'phr_episodes';

    protected $fillable = [
        'patient_id',
        'user_id',
        'import_source',
        'external_id',
        'source_document_id',
        'review_status',
        'kind',
        'status',
        'title',
        'reason',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'retracted_at' => 'datetime',
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<PhrDocument, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(PhrDocument::class, 'source_document_id');
    }

    /** @return BelongsToMany<PhrOfficeVisit, $this> */
    public function encounters(): BelongsToMany
    {
        return $this->belongsToMany(PhrOfficeVisit::class, 'phr_episode_encounters', 'episode_id', 'office_visit_id')
            ->withTimestamps();
    }
}
