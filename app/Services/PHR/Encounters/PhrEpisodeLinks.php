<?php

namespace App\Services\PHR\Encounters;

use App\Models\PhrEpisode;
use App\Models\PhrOfficeVisit;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The encounters grouped under an episode of care (#415), through phr_episode_encounters.
 *
 * A link is evidence, like a record's link to its encounter (PhrEncounterLinks): writing
 * or removing one changes neither the episode's nor the visit's columns, so neither
 * version nor review status moves. An encounter may belong to several episodes. A link
 * counts only while both sides stand (not deleted, not withdrawn by their source).
 */
final class PhrEpisodeLinks
{
    public const string LINKED = 'linked';

    public const string UNLINKED = 'unlinked';

    public const string UNCHANGED = 'unchanged';

    public function link(int $patientId, int $episodeId, int $visitId): string
    {
        return DB::transaction(function () use ($patientId, $episodeId, $visitId): string {
            $episode = PhrEpisode::query()->where('patient_id', $patientId)->whereNull('retracted_at')->lockForUpdate()->find($episodeId)
                ?? throw new NotFoundHttpException('Episode not found.');
            if ($episode->review_status === PhrReviewStatus::REJECTED) {
                throw new ConflictHttpException('A rejected episode cannot take new encounters.');
            }
            $visit = PhrOfficeVisit::query()->where('patient_id', $patientId)->whereNull('retracted_at')->find($visitId)
                ?? throw new NotFoundHttpException('Office visit not found.');
            if ($visit->review_status === PhrReviewStatus::REJECTED) {
                throw new ConflictHttpException('A rejected visit cannot be grouped into an episode.');
            }

            $inserted = DB::table('phr_episode_encounters')->insertOrIgnore([
                'patient_id' => $patientId,
                'episode_id' => $episodeId,
                'office_visit_id' => $visitId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $inserted > 0 ? self::LINKED : self::UNCHANGED;
        });
    }

    public function unlink(int $patientId, int $episodeId, int $visitId): string
    {
        PhrEpisode::query()->where('patient_id', $patientId)->findOrFail($episodeId);

        $deleted = DB::table('phr_episode_encounters')
            ->where('patient_id', $patientId)
            ->where('episode_id', $episodeId)
            ->where('office_visit_id', $visitId)
            ->delete();

        return $deleted > 0 ? self::UNLINKED : self::UNCHANGED;
    }

    /**
     * The standing visits grouped under each episode, earliest first.
     *
     * @param  iterable<int|string>  $episodeIds
     * @return array<int, list<int>> episode id => office visit ids
     */
    public function encounterIds(int $patientId, iterable $episodeIds): array
    {
        $ids = self::ints($episodeIds);
        $map = array_fill_keys($ids, []);
        if ($ids === []) {
            return $map;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('phr_episode_encounters as links')
                ->join('phr_office_visits as visits', 'visits.id', '=', 'links.office_visit_id')
                ->where('links.patient_id', $patientId)
                ->whereIn('links.episode_id', $chunk)
                ->whereNull('visits.deleted_at')
                ->whereNull('visits.retracted_at')
                ->orderByRaw('COALESCE(visits.visit_started_at, visits.visit_date) IS NULL')
                ->orderByRaw('COALESCE(visits.visit_started_at, visits.visit_date)')
                ->orderBy('visits.id')
                ->get(['links.episode_id', 'links.office_visit_id']);
            foreach ($rows as $row) {
                $map[(int) $row->episode_id][] = (int) $row->office_visit_id;
            }
        }

        return $map;
    }

    /**
     * The standing episodes each visit is grouped under.
     *
     * @param  iterable<int|string>  $visitIds
     * @return array<int, list<int>> office visit id => episode ids
     */
    public function episodeIds(int $patientId, iterable $visitIds): array
    {
        $ids = self::ints($visitIds);
        $map = array_fill_keys($ids, []);
        if ($ids === []) {
            return $map;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('phr_episode_encounters as links')
                ->join('phr_episodes as episodes', 'episodes.id', '=', 'links.episode_id')
                ->where('links.patient_id', $patientId)
                ->whereIn('links.office_visit_id', $chunk)
                ->whereNull('episodes.deleted_at')
                ->whereNull('episodes.retracted_at')
                ->orderBy('links.episode_id')
                ->get(['links.office_visit_id', 'links.episode_id']);
            foreach ($rows as $row) {
                $map[(int) $row->office_visit_id][] = (int) $row->episode_id;
            }
        }

        return $map;
    }

    /**
     * What an export may reference: for each exported visit, the exported episodes it is
     * grouped under. A link to either side the export leaves out would dangle, so it is
     * dropped.
     *
     * @param  iterable<int|string>  $episodeIds
     * @param  iterable<int|string>  $visitIds
     * @return array<int, list<int>> office visit id => episode ids
     */
    public function linksBetween(int $patientId, iterable $episodeIds, iterable $visitIds): array
    {
        $episodes = self::ints($episodeIds);
        $visits = array_flip(self::ints($visitIds));
        $map = [];
        if ($episodes === [] || $visits === []) {
            return $map;
        }
        foreach (array_chunk($episodes, 500) as $chunk) {
            $rows = DB::table('phr_episode_encounters')
                ->where('patient_id', $patientId)
                ->whereIn('episode_id', $chunk)
                ->orderBy('episode_id')
                ->get(['episode_id', 'office_visit_id']);
            foreach ($rows as $row) {
                if (isset($visits[(int) $row->office_visit_id])) {
                    $map[(int) $row->office_visit_id][] = (int) $row->episode_id;
                }
            }
        }

        return $map;
    }

    /**
     * @param  iterable<int|string>  $values
     * @return list<int>
     */
    private static function ints(iterable $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }
}
