<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PHR\Concerns\HandlesClinicalResourceRequests;
use App\Http\Requests\PHR\StoreVitalRequest;
use App\Http\Resources\PHR\VitalResource;
use App\Models\PhrPatientVital;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\PHR\VitalMetricResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VitalController extends Controller
{
    /** @use HandlesClinicalResourceRequests<PhrPatientVital> */
    use HandlesClinicalResourceRequests;

    public function __construct(
        private PhrPatientAccessService $accessService,
        private VitalMetricResolver $metricResolver,
    ) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        return $this->indexClinicalResource($request, $patient);
    }

    public function store(StoreVitalRequest $request, int $patient): JsonResponse
    {
        return $this->storeClinicalResource($request, $patient);
    }

    public function show(Request $request, int $patient, int $vital): JsonResponse
    {
        return $this->showClinicalResource($request, $patient, $vital);
    }

    public function trend(Request $request, int $patient, string $metricKey): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService()->accessiblePatient($patient, $userId);
        /** @var Collection<int, PhrPatientVital> $vitals */
        $vitals = PhrPatientVital::query()
            ->where('patient_id', $resolvedPatient->id)
            ->orderBy('observed_at')
            ->orderBy('vital_date')
            ->orderBy('id')
            ->get();

        $points = [];
        $label = null;
        $unit = null;

        foreach ($vitals as $vital) {
            foreach ($this->metricResolver->metricCandidates($vital) as $candidate) {
                if ($candidate['key'] !== $metricKey) {
                    continue;
                }

                $label ??= $candidate['label'];
                $unit ??= $candidate['unit'];

                $points[] = [
                    'reading_id' => $vital->id,
                    'recorded_at' => $vital->observed_at?->toDateTimeString() ?? $vital->vital_date?->startOfDay()->toDateTimeString(),
                    'value' => $candidate['value'],
                ];
            }
        }

        if ($points === []) {
            abort(404);
        }

        return response()->json([
            'metric_key' => $metricKey,
            'metric_label' => $label ?? 'Vital',
            'unit' => $unit,
            'points' => $points,
        ]);
    }

    public function update(StoreVitalRequest $request, int $patient, int $vital): JsonResponse
    {
        return $this->updateClinicalResource($request, $patient, $vital);
    }

    public function destroy(Request $request, int $patient, int $vital): Response
    {
        return $this->destroyClinicalResource($request, $patient, $vital);
    }

    protected function accessService(): PhrPatientAccessService
    {
        return $this->accessService;
    }

    /**
     * @return class-string<PhrPatientVital>
     */
    protected function modelClass(): string
    {
        return PhrPatientVital::class;
    }

    protected function resourceClass(): string
    {
        return VitalResource::class;
    }

    protected function collectionKey(): string
    {
        return 'vitals';
    }

    protected function resourceKey(): string
    {
        return 'vital';
    }

    /**
     * @param  Builder<PhrPatientVital>  $query
     * @return Builder<PhrPatientVital>
     */
    protected function indexQuery(Builder $query): Builder
    {
        return $query
            ->orderByDesc('observed_at')
            ->orderByDesc('vital_date')
            ->orderByDesc('id');
    }
}
