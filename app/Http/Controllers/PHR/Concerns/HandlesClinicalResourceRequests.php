<?php

namespace App\Http\Controllers\PHR\Concerns;

use App\Http\Requests\PHR\ReviewClinicalRecordRequest;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrRecordLifecycle;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @template TClinicalModel of Model
 */
trait HandlesClinicalResourceRequests
{
    abstract protected function accessService(): PhrPatientAccessService;

    /**
     * @return class-string<TClinicalModel>
     */
    abstract protected function modelClass(): string;

    /**
     * @return class-string<JsonResource>
     */
    abstract protected function resourceClass(): string;

    abstract protected function collectionKey(): string;

    abstract protected function resourceKey(): string;

    /**
     * @param  Builder<TClinicalModel>  $query
     * @return Builder<TClinicalModel>
     */
    protected function indexQuery(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }

    /**
     * What a read of one record loads with it. Every write response is built
     * from this same read, so a create, update or review returns the record
     * exactly as a later GET of it would.
     *
     * @param  Builder<TClinicalModel>  $query
     * @return Builder<TClinicalModel>
     */
    protected function showQuery(Builder $query): Builder
    {
        return $query;
    }

    protected function indexClinicalResource(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService()->accessiblePatient($patient, $userId);
        $modelClass = $this->modelClass();

        $query = $this->indexQuery(
            $modelClass::query()->where('patient_id', $resolvedPatient->id)
        );

        // A record its source withdrew is not current clinical data, so it leaves
        // the working list the way a deleted one does. Deletion is handled by the
        // soft-delete scope.
        PhrRecordLifecycle::scopeLive($query);

        // Rejected records stay out of the working list. They remain reachable
        // so a mistaken rejection can be undone, but only on request.
        if (! $request->boolean('include_rejected')) {
            $query->where('review_status', '!=', PhrReviewStatus::REJECTED);
        }

        $records = $query
            ->get()
            ->map(fn (Model $record): array => $this->resourcePayload($record))
            ->values();

        return response()->json([
            $this->collectionKey() => $records,
            'can_manage' => $this->accessService()->canWrite($resolvedPatient, $userId),
        ]);
    }

    protected function storeClinicalResource(FormRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService()->writablePatient($patient, $userId);
        $modelClass = $this->modelClass();

        $record = $modelClass::query()->create([
            'patient_id' => $resolvedPatient->id,
            'user_id' => $resolvedPatient->owner_user_id,
            ...$request->validated(),
        ]);

        // create() never reads the row back, so a column the request omitted has
        // no value in memory even where the database supplied a default
        // (review_status, and the status columns). Respond with a real read.
        return response()->json([
            $this->resourceKey() => $this->resourcePayload($this->readClinicalResource($resolvedPatient, (int) $record->getKey())),
        ], 201);
    }

    protected function showClinicalResource(Request $request, int $patient, int $record): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService()->accessiblePatient($patient, $userId);

        return response()->json([
            $this->resourceKey() => $this->resourcePayload($this->readClinicalResource($resolvedPatient, $record)),
            'can_manage' => $this->accessService()->canWrite($resolvedPatient, $userId),
        ]);
    }

    protected function updateClinicalResource(FormRequest $request, int $patient, int $record): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService()->writablePatient($patient, $userId);
        $this->resolveClinicalResource($resolvedPatient, $record)->update($request->validated());

        return response()->json([$this->resourceKey() => $this->resourcePayload($this->readClinicalResource($resolvedPatient, $record))]);
    }

    /**
     * Records a reviewer's accept/reject decision. This is the only path that may
     * move a record out of `pending_review`; the agent API can never assert it.
     *
     * With a version, the decision is conditional on the record being unchanged
     * since the reviewer read it. A mismatch is a 409 that discloses neither the
     * record nor its current version, the same as the agent write paths.
     */
    protected function reviewClinicalResource(ReviewClinicalRecordRequest $request, int $patient, int $record): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService()->writablePatient($patient, $userId);
        $expected = $request->expectedVersion();
        $modelClass = $this->modelClass();
        DB::transaction(function () use ($modelClass, $resolvedPatient, $record, $expected, $request): void {
            $locked = $modelClass::query()
                ->where('patient_id', $resolvedPatient->id)
                ->lockForUpdate()
                ->findOrFail($record);
            if ($expected !== null && ! hash_equals(app(AgentClinicalRecordVersion::class)->for($locked), $expected)) {
                throw new ConflictHttpException('This record changed since it was loaded. Reload and review it again.');
            }
            $locked->update(['review_status' => $request->reviewStatus()]);
        });

        return response()->json([$this->resourceKey() => $this->resourcePayload($this->readClinicalResource($resolvedPatient, $record))]);
    }

    protected function destroyClinicalResource(Request $request, int $patient, int $record): Response
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService()->writablePatient($patient, $userId);
        $this->resolveClinicalResource($resolvedPatient, $record)->delete();

        return response()->noContent();
    }

    private function resolveClinicalResource(PhrPatient $patient, int $record): Model
    {
        $modelClass = $this->modelClass();

        return $modelClass::query()
            ->where('patient_id', $patient->id)
            ->findOrFail($record);
    }

    private function readClinicalResource(PhrPatient $patient, int $record): Model
    {
        $modelClass = $this->modelClass();

        return $this->showQuery($modelClass::query()->where('patient_id', $patient->id))
            ->findOrFail($record);
    }

    /**
     * @return array<string, mixed>
     */
    private function resourcePayload(Model $record): array
    {
        $resourceClass = $this->resourceClass();

        return (new $resourceClass($record))->resolve();
    }
}
