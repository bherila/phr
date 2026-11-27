<?php

namespace App\Services\PHR\Review;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEpisode;
use App\Models\PhrPatient;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Browser review decisions, conditional on the record the person saw. Batch reads
 * lock records by kind and id; model saves retain the single-record lifecycle hooks.
 *
 * @phpstan-type ReviewItem array{segment: string, id: int, version: string}
 * @phpstan-type ReviewOutcome array{done: list<array{segment: string, id: int}>, failed: list<array{segment: string, id: int, error: string}>}
 */
final readonly class ClinicalReviewDecisions
{
    public const int BATCH_LIMIT = 100;

    /** @return array<string, class-string<Model>> */
    public static function models(): array
    {
        $models = ['eobs' => PhrEob::class, 'episodes' => PhrEpisode::class];
        foreach (PhrReviewQueueService::CLINICAL as $section => $model) {
            $models[PhrReviewQueueService::REVIEW_SEGMENTS[$section] ?? $section] = $model;
        }

        return $models;
    }

    public function __construct(private AgentClinicalRecordVersion $versions) {}

    /** The caller holds the record lock in a transaction, on both review paths. */
    public function reviewLocked(Model $record, string $status, ?string $expected): void
    {
        if ($expected !== null && ! hash_equals($this->versions->for($record), $expected)) {
            $kind = match (true) {
                $record instanceof PhrDocument => 'document',
                $record instanceof PhrEob => 'EOB',
                default => 'record',
            };
            throw new ConflictHttpException("This {$kind} changed since it was loaded. Reload and review it again.");
        }
        $record->update(['review_status' => $status]);
    }

    /**
     * A stale or missing record fails independently. Unexpected errors roll back
     * this whole bounded request, so its response never claims rolled-back saves.
     *
     * @param  list<ReviewItem>  $items
     * @return ReviewOutcome
     */
    public function batch(PhrPatient $patient, array $items, string $status): array
    {
        return DB::transaction(function () use ($patient, $items, $status): array {
            /** @var array<string, list<ReviewItem>> $groups */
            $groups = [];
            foreach ($items as $item) {
                $groups[$item['segment']][] = $item;
            }
            ksort($groups);
            $outcome = ['done' => [], 'failed' => []];
            foreach ($groups as $segment => $group) {
                $model = self::models()[$segment];
                $records = $model::query()->where('patient_id', $patient->id)
                    ->whereIn('id', array_column($group, 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($group as $item) {
                    $identity = ['segment' => $segment, 'id' => $item['id']];
                    $record = $records->get($item['id']);
                    if ($record === null) {
                        $outcome['failed'][] = [...$identity, 'error' => 'This record is no longer available. Reload and review the list again.'];

                        continue;
                    }
                    try {
                        $this->reviewLocked($record, $status, $item['version']);
                        $outcome['done'][] = $identity;
                    } catch (ConflictHttpException $conflict) {
                        $outcome['failed'][] = [...$identity, 'error' => $conflict->getMessage()];
                    }
                }
            }

            return $outcome;
        });
    }
}
