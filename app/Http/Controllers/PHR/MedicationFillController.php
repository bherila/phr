<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PHR\Concerns\HandlesClinicalResourceRequests;
use App\Http\Requests\PHR\ResolveFillDuplicateRequest;
use App\Http\Requests\PHR\ReviewClinicalRecordRequest;
use App\Http\Requests\PHR\StoreMedicationFillRequest;
use App\Http\Resources\PHR\MedicationFillResource;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Fills\MedicationFillDuplicateService;
use App\Services\PHR\Fills\MedicationFillWriter;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrRecordLifecycle;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MedicationFillController extends Controller
{
    /** @use HandlesClinicalResourceRequests<PhrMedicationFill> */
    use HandlesClinicalResourceRequests;

    public function __construct(
        private PhrPatientAccessService $accessService,
        private MedicationFillWriter $writer,
    ) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $filters = $request->validate([
            'medication_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'ndc_status' => ['sometimes', Rule::in(PhrMedicationFill::NDC_STATUSES)],
            'review_status' => ['sometimes', Rule::in(PhrReviewStatus::ALL)],
        ]);

        return $this->listFills($request, $patient, $filters);
    }

    /** The fill history shown on a medication's page. */
    public function forMedication(Request $request, int $patient, int $medication): JsonResponse
    {
        $resolvedPatient = $this->accessService->accessiblePatient($patient, (int) $request->user()?->id);
        PhrMedication::query()->where('patient_id', $resolvedPatient->id)->findOrFail($medication);

        return $this->listFills($request, $patient, ['medication_id' => $medication]);
    }

    public function store(StoreMedicationFillRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $prepared = $this->writer->prepare($resolvedPatient, $request->validated(), null, true);

        $fill = DB::transaction(function () use ($resolvedPatient, $prepared): PhrMedicationFill {
            $fill = PhrMedicationFill::query()->create([
                'patient_id' => $resolvedPatient->id,
                'user_id' => $resolvedPatient->owner_user_id,
                ...$prepared['attributes'],
                'source_kind' => 'manual',
            ]);
            $this->writer->attach($resolvedPatient, $fill, $prepared['medication_id']);

            return $fill;
        });

        return response()->json(['medication_fill' => $this->resourcePayload($this->readClinicalResource($resolvedPatient, (int) $fill->id))], 201);
    }

    public function show(Request $request, int $patient, int $fill): JsonResponse
    {
        return $this->showClinicalResource($request, $patient, $fill);
    }

    public function update(StoreMedicationFillRequest $request, int $patient, int $fill): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $record = PhrMedicationFill::query()->where('patient_id', $resolvedPatient->id)->findOrFail($fill);
        $data = $request->validated();
        $version = $data['version'] ?? null;
        unset($data['version']);
        $this->assertCurrent($record, $version);
        $prepared = $this->writer->prepare($resolvedPatient, $data, $record, true);

        DB::transaction(function () use ($resolvedPatient, $record, $prepared, $version): void {
            $locked = PhrMedicationFill::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrent($locked, $version);
            $before = $locked->medication_id;
            $locked->update($prepared['attributes']);
            if ($prepared['medication_id'] !== null) {
                // An explicit choice goes through the linker too, so the medication this fill
                // leaves is tidied if it was an unreviewed entry only this fill kept alive.
                $this->writer->attach($resolvedPatient, $locked, $prepared['medication_id'], $before);
            } elseif ($prepared['drug_changed']) {
                // An edit never unlinks the medication. The link is revisited only when the
                // NDC changed the drug and the current medication no longer fits it.
                $this->writer->reconcile($resolvedPatient, $locked);
            }
        });

        return response()->json(['medication_fill' => $this->resourcePayload($this->readClinicalResource($resolvedPatient, $fill))]);
    }

    /** A stale version means someone else changed the fill since this form read it. */
    private function assertCurrent(PhrMedicationFill $record, mixed $version): void
    {
        if (is_string($version) && ! hash_equals(app(AgentClinicalRecordVersion::class)->for($record), $version)) {
            throw new ConflictHttpException('This fill changed since it was loaded. Reload it and try again.');
        }
    }

    public function destroy(Request $request, int $patient, int $fill): Response
    {
        $resolvedPatient = $this->accessService->writablePatient($patient, (int) $request->user()?->id);
        $record = PhrMedicationFill::query()->where('patient_id', $resolvedPatient->id)->findOrFail($fill);
        DB::transaction(function () use ($record): void {
            $record->delete();
            // A medication this fill alone kept alive (an unreviewed, auto-created entry) goes with it.
            $this->writer->release($record);
        });

        return response()->noContent();
    }

    public function review(ReviewClinicalRecordRequest $request, int $patient, int $fill): JsonResponse
    {
        return $this->reviewClinicalResource($request, $patient, $fill);
    }

    /**
     * Same fill: merge into the existing fill and retract this one. Different fill: clear the
     * flag. Conditional on the version the reviewer saw (409 otherwise).
     */
    public function resolveDuplicate(ResolveFillDuplicateRequest $request, int $patient, int $fill): JsonResponse
    {
        $resolvedPatient = $this->accessService->writablePatient($patient, (int) $request->user()?->id);
        $candidate = $request->validated('candidate_fill_id');
        $result = app(MedicationFillDuplicateService::class)->resolve(
            $resolvedPatient,
            $fill,
            (string) $request->validated('action'),
            (string) $request->validated('version'),
            $candidate !== null ? (int) $candidate : null,
        );
        $stored = PhrMedicationFill::withTrashed()->with(['medication:id,name', 'evidence'])->whereKey($fill)->firstOrFail();

        return response()->json([
            'outcome' => $result['outcome'],
            'merged_into_fill_id' => $result['merged_into_fill_id'],
            'moved' => $result['moved'],
            'medication_fill' => $this->resourcePayload($stored),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function listFills(Request $request, int $patient, array $filters): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        $query = PhrMedicationFill::query()->with(['medication:id,name', 'evidence'])->where('patient_id', $resolvedPatient->id);
        PhrRecordLifecycle::scopeLive($query);
        if (! $request->boolean('include_rejected')) {
            $query->where('review_status', '!=', PhrReviewStatus::REJECTED);
        }
        foreach (['medication_id', 'ndc_status', 'review_status'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (isset($filters['from'])) {
            $query->whereDate('filled_on', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('filled_on', '<=', $filters['to']);
        }

        return response()->json([
            'medication_fills' => $query->orderByDesc('filled_on')->orderByDesc('id')->get()
                ->map(fn (PhrMedicationFill $fill): array => $this->resourcePayload($fill))
                ->values(),
            'can_manage' => $this->accessService->canWrite($resolvedPatient, $userId),
        ]);
    }

    protected function accessService(): PhrPatientAccessService
    {
        return $this->accessService;
    }

    /**
     * @return class-string<PhrMedicationFill>
     */
    protected function modelClass(): string
    {
        return PhrMedicationFill::class;
    }

    protected function resourceClass(): string
    {
        return MedicationFillResource::class;
    }

    protected function collectionKey(): string
    {
        return 'medication_fills';
    }

    protected function resourceKey(): string
    {
        return 'medication_fill';
    }

    /**
     * @param  Builder<PhrMedicationFill>  $query
     * @return Builder<PhrMedicationFill>
     */
    protected function showQuery(Builder $query): Builder
    {
        return $query->with(['medication:id,name', 'evidence']);
    }
}
