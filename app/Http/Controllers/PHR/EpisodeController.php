<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PHR\Concerns\HandlesClinicalResourceRequests;
use App\Http\Requests\PHR\ReviewClinicalRecordRequest;
use App\Http\Requests\PHR\StoreEpisodeRequest;
use App\Http\Resources\PHR\EpisodeResource;
use App\Models\PhrEpisode;
use App\Models\PhrOfficeVisit;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Encounters\PhrEpisodeLinks;
use App\Support\PHR\PhrRecordLifecycle;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Episodes of care in the browser (#415): each read carries the visits grouped under
 * the episode beside it, as `encounters`, so the record itself stays what the agent
 * API and a write response return.
 */
class EpisodeController extends Controller
{
    /** @use HandlesClinicalResourceRequests<PhrEpisode> */
    use HandlesClinicalResourceRequests;

    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrEpisodeLinks $links,
    ) {}

    /** The patient's episodes, or with office_visit_id only those that visit is grouped under. */
    public function index(Request $request, int $patient): JsonResponse
    {
        $validated = $request->validate([
            'office_visit_id' => ['sometimes', 'integer', 'min:1'],
            'include_rejected' => ['sometimes', 'boolean'],
        ]);
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        $query = PhrEpisode::query()->where('patient_id', $resolvedPatient->id);
        PhrRecordLifecycle::scopeLive($query);
        if (! $request->boolean('include_rejected')) {
            $query->where('review_status', '!=', PhrReviewStatus::REJECTED);
        }
        if (isset($validated['office_visit_id'])) {
            $query->whereIn('id', fn ($links) => $links->select('episode_id')->from('phr_episode_encounters')
                ->where('patient_id', $resolvedPatient->id)->where('office_visit_id', (int) $validated['office_visit_id']));
        }
        $episodes = $this->indexQuery($query)->get();
        $encounters = $this->encounters((int) $resolvedPatient->id, $episodes->modelKeys());

        return response()->json([
            'episodes' => $episodes->map(fn (PhrEpisode $episode): array => [
                ...(new EpisodeResource($episode))->resolve(),
                'encounters' => $encounters[(int) $episode->id] ?? [],
            ])->values(),
            'can_manage' => $this->accessService->canWrite($resolvedPatient, $userId),
        ]);
    }

    public function store(StoreEpisodeRequest $request, int $patient): JsonResponse
    {
        return $this->storeClinicalResource($request, $patient);
    }

    public function show(Request $request, int $patient, int $episode): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);
        $record = PhrEpisode::query()->where('patient_id', $resolvedPatient->id)->findOrFail($episode);

        return response()->json([
            'episode' => (new EpisodeResource($record))->resolve(),
            'encounters' => $this->encounters((int) $resolvedPatient->id, [(int) $record->id])[(int) $record->id] ?? [],
            'can_manage' => $this->accessService->canWrite($resolvedPatient, $userId),
        ]);
    }

    public function update(StoreEpisodeRequest $request, int $patient, int $episode): JsonResponse
    {
        return $this->updateClinicalResource($request, $patient, $episode);
    }

    public function destroy(Request $request, int $patient, int $episode): Response
    {
        return $this->destroyClinicalResource($request, $patient, $episode);
    }

    public function review(ReviewClinicalRecordRequest $request, int $patient, int $episode): JsonResponse
    {
        return $this->reviewClinicalResource($request, $patient, $episode);
    }

    protected function accessService(): PhrPatientAccessService
    {
        return $this->accessService;
    }

    /** @return class-string<PhrEpisode> */
    protected function modelClass(): string
    {
        return PhrEpisode::class;
    }

    protected function resourceClass(): string
    {
        return EpisodeResource::class;
    }

    protected function collectionKey(): string
    {
        return 'episodes';
    }

    protected function resourceKey(): string
    {
        return 'episode';
    }

    /**
     * @param  Builder<PhrEpisode>  $query
     * @return Builder<PhrEpisode>
     */
    protected function indexQuery(Builder $query): Builder
    {
        return $query->orderByDesc('started_at')->orderByDesc('id');
    }

    /**
     * A short summary of each standing visit grouped under each episode, earliest first.
     *
     * @param  list<int>  $episodeIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function encounters(int $patientId, array $episodeIds): array
    {
        $ids = $this->links->encounterIds($patientId, $episodeIds);
        $visitIds = array_values(array_unique(array_merge([], ...array_values($ids))));
        $visits = $visitIds === [] ? collect() : PhrOfficeVisit::query()->where('patient_id', $patientId)->whereIn('id', $visitIds)->get()->keyBy('id');

        $summaries = [];
        foreach ($ids as $episodeId => $visitList) {
            foreach ($visitList as $visitId) {
                $visit = $visits->get($visitId);
                if (! $visit instanceof PhrOfficeVisit) {
                    continue;
                }
                $summaries[$episodeId][] = [
                    'id' => (int) $visit->id,
                    'visit_date' => $visit->visit_date?->toDateString(),
                    'visit_started_at' => $visit->visit_started_at?->toDateTimeString(),
                    'visit_ended_at' => $visit->visit_ended_at?->toDateTimeString(),
                    'visit_type' => $visit->visit_type,
                    'encounter_class' => $visit->encounter_class,
                    'facility_name' => $visit->facility_name,
                    'review_status' => $visit->review_status,
                ];
            }
        }

        return $summaries;
    }
}
