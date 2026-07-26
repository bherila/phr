<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PHR\Concerns\HandlesClinicalResourceRequests;
use App\Http\Requests\PHR\StoreLabResultRequest;
use App\Http\Resources\PHR\LabResultResource;
use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class LabResultController extends Controller
{
    /** @use HandlesClinicalResourceRequests<PhrLabResult> */
    use HandlesClinicalResourceRequests;

    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        return $this->indexClinicalResource($request, $patient);
    }

    public function store(StoreLabResultRequest $request, int $patient): JsonResponse
    {
        return $this->storeClinicalResource($request, $patient);
    }

    public function show(Request $request, int $patient, int $labResult): JsonResponse
    {
        return $this->showClinicalResource($request, $patient, $labResult);
    }

    public function showPanel(Request $request, int $patient, int $labResult): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService()->accessiblePatient($patient, $userId);

        $anchor = PhrLabResult::query()
            ->where('patient_id', $resolvedPatient->id)
            ->findOrFail($labResult);

        $panelRows = $this->panelRowsForAnchor($resolvedPatient, $anchor);

        $rows = $panelRows
            ->map(function (PhrLabResult $result) use ($resolvedPatient): array {
                return [
                    'id' => $result->id,
                    'analyte' => $result->analyte,
                    'value' => $result->value,
                    'value_numeric' => $result->value_numeric,
                    'unit' => $result->unit,
                    'range_min' => $result->range_min,
                    'range_max' => $result->range_max,
                    'range_unit' => $result->range_unit,
                    'reference_range_text' => $result->reference_range_text,
                    'abnormal_flag' => $result->abnormal_flag,
                    'result_datetime' => $result->result_datetime?->toDateTimeString(),
                    'collection_datetime' => $result->collection_datetime?->toDateTimeString(),
                    'trend' => $this->trendForResult($resolvedPatient, $result),
                ];
            })
            ->values();

        $orderingProvider = $this->firstNonNull($panelRows, 'ordering_provider');
        $resultingLab = $this->firstNonNull($panelRows, 'resulting_lab');
        $source = $this->firstNonNull($panelRows, 'source');
        $sourceDocumentId = $this->firstNonNull($panelRows, 'source_document_id');
        $panelName = $this->firstNonNull($panelRows, 'test_name');
        $collectionDatetime = $this->firstNonNull($panelRows, 'collection_datetime');

        return response()->json([
            'panel' => [
                'id' => $anchor->id,
                'panel_name' => $panelName,
                'collection_datetime' => $collectionDatetime instanceof Carbon
                    ? $collectionDatetime->toDateTimeString()
                    : $collectionDatetime,
                'ordering_provider' => $orderingProvider,
                'resulting_lab' => $resultingLab,
                'source' => $source,
                'source_document_id' => $sourceDocumentId,
                'source_document_url' => $sourceDocumentId
                    ? url("/api/phr/patients/{$resolvedPatient->id}/documents/{$sourceDocumentId}/file")
                    : null,
                'rows' => $rows,
            ],
        ]);
    }

    /**
     * @param  Collection<int, PhrLabResult>  $rows
     */
    private function firstNonNull(Collection $rows, string $attribute): mixed
    {
        foreach ($rows as $row) {
            $value = $row->{$attribute};
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function update(StoreLabResultRequest $request, int $patient, int $labResult): JsonResponse
    {
        return $this->updateClinicalResource($request, $patient, $labResult);
    }

    public function destroy(Request $request, int $patient, int $labResult): Response
    {
        return $this->destroyClinicalResource($request, $patient, $labResult);
    }

    protected function accessService(): PhrPatientAccessService
    {
        return $this->accessService;
    }

    /**
     * @return class-string<PhrLabResult>
     */
    protected function modelClass(): string
    {
        return PhrLabResult::class;
    }

    protected function resourceClass(): string
    {
        return LabResultResource::class;
    }

    protected function collectionKey(): string
    {
        return 'lab_results';
    }

    protected function resourceKey(): string
    {
        return 'lab_result';
    }

    /**
     * @param  Builder<PhrLabResult>  $query
     * @return Builder<PhrLabResult>
     */
    protected function indexQuery(Builder $query): Builder
    {
        return $query
            ->orderByDesc('result_datetime')
            ->orderByDesc('collection_datetime')
            ->orderByDesc('id');
    }

    /**
     * @return Collection<int, PhrLabResult>
     */
    private function panelRowsForAnchor(PhrPatient $patient, PhrLabResult $anchor): Collection
    {
        if ($anchor->test_name === null) {
            return collect([$anchor]);
        }

        $query = PhrLabResult::query()
            ->where('patient_id', $patient->id)
            ->where('test_name', $anchor->test_name);

        if ($anchor->collection_datetime !== null) {
            $query->where('collection_datetime', $anchor->collection_datetime);
        } elseif ($anchor->result_datetime !== null) {
            $query->where('result_datetime', $anchor->result_datetime);
        } else {
            $query->where('id', $anchor->id);
        }

        return $query
            ->orderBy('analyte')
            ->orderBy('id')
            ->get();
    }

    private function trendForResult(PhrPatient $patient, PhrLabResult $result): ?string
    {
        if ($result->analyte === null || $result->value_numeric === null) {
            return null;
        }

        $query = PhrLabResult::query()
            ->where('patient_id', $patient->id)
            ->where('analyte', $result->analyte)
            ->whereNotNull('value_numeric')
            ->where('id', '!=', $result->id);

        if ($result->unit === null) {
            $query->whereNull('unit');
        } else {
            $query->where('unit', $result->unit);
        }

        $previous = $query
            ->orderByDesc('result_datetime')
            ->orderByDesc('collection_datetime')
            ->orderByDesc('id')
            ->get()
            ->first(fn (PhrLabResult $candidate): bool => $this->isOlderResult($candidate, $result));

        if (! $previous || $previous->value_numeric === null) {
            return null;
        }

        $comparison = $this->compareNumericStrings($result->value_numeric, $previous->value_numeric);

        return match (true) {
            $comparison > 0 => 'up',
            $comparison < 0 => 'down',
            default => 'flat',
        };
    }

    private function compareNumericStrings(string $current, string $prior): int
    {
        if (function_exists('bccomp')) {
            return bccomp($current, $prior, 10);
        }

        $currentFloat = (float) $current;
        $priorFloat = (float) $prior;

        return $currentFloat <=> $priorFloat;
    }

    private function isOlderResult(PhrLabResult $candidate, PhrLabResult $current): bool
    {
        $candidateAt = $candidate->result_datetime ?? $candidate->collection_datetime;
        $currentAt = $current->result_datetime ?? $current->collection_datetime;

        if ($candidateAt instanceof Carbon && $currentAt instanceof Carbon) {
            return $candidateAt->lessThan($currentAt)
                || ($candidateAt->equalTo($currentAt) && $candidate->id < $current->id);
        }

        if ($candidateAt instanceof Carbon) {
            return true;
        }

        if ($currentAt instanceof Carbon) {
            return false;
        }

        return $candidate->id < $current->id;
    }
}
