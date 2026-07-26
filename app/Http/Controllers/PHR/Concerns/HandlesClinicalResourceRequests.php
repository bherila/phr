<?php

namespace App\Http\Controllers\PHR\Concerns;

use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

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

    protected function indexClinicalResource(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService()->accessiblePatient($patient, $userId);
        $modelClass = $this->modelClass();

        $records = $this->indexQuery(
            $modelClass::query()->where('patient_id', $resolvedPatient->id)
        )
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

        return response()->json([$this->resourceKey() => $this->resourcePayload($record)], 201);
    }

    protected function showClinicalResource(Request $request, int $patient, int $record): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService()->accessiblePatient($patient, $userId);

        return response()->json([
            $this->resourceKey() => $this->resourcePayload($this->resolveClinicalResource($resolvedPatient, $record)),
        ]);
    }

    protected function updateClinicalResource(FormRequest $request, int $patient, int $record): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService()->writablePatient($patient, $userId);
        $resolved = $this->resolveClinicalResource($resolvedPatient, $record);
        $resolved->update($request->validated());

        return response()->json([$this->resourceKey() => $this->resourcePayload($resolved->refresh())]);
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

    /**
     * @return array<string, mixed>
     */
    private function resourcePayload(Model $record): array
    {
        $resourceClass = $this->resourceClass();

        return (new $resourceClass($record))->resolve();
    }
}
