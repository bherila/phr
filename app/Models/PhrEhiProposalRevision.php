<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One revision a proposal relies on, at its position in the mapping's order.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $proposal_id
 * @property int $revision_id
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrEhiProposalRevision extends Model
{
    use SerializesDatesAsLocal;

    private const int CHUNK = 200;

    protected $table = 'phr_ehi_proposal_revisions';

    protected $fillable = ['patient_id', 'proposal_id', 'revision_id', 'position'];

    protected function casts(): array
    {
        return ['proposal_id' => 'integer', 'revision_id' => 'integer', 'position' => 'integer'];
    }

    /**
     * Replace the revisions of a proposal, in chunks. Call inside the caller's
     * patient write transaction.
     *
     * @param  list<int>  $revisionIds  in the order they are relied on
     */
    public static function replaceFor(PhrEhiProposal $proposal, array $revisionIds): void
    {
        self::query()->where('proposal_id', $proposal->id)->delete();
        $now = now();
        foreach (array_chunk($revisionIds, self::CHUNK, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $position => $revisionId) {
                $rows[] = [
                    'patient_id' => $proposal->patient_id,
                    'proposal_id' => $proposal->id,
                    'revision_id' => $revisionId,
                    'position' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            self::query()->insert($rows);
        }
    }

    /**
     * The revision ids a proposal relies on, in position order.
     *
     * @return list<int>
     */
    public static function idsFor(PhrEhiProposal $proposal): array
    {
        return self::query()->where('proposal_id', $proposal->id)->orderBy('position')->pluck('revision_id')
            ->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }

    /** @return BelongsTo<PhrEhiProposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(PhrEhiProposal::class, 'proposal_id');
    }

    /** @return BelongsTo<PhrEhiRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(PhrEhiRevision::class, 'revision_id');
    }
}
