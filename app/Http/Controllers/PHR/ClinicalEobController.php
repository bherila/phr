<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\ReviewClinicalRecordRequest;
use App\Http\Resources\PHR\EobSummaryResource;
use App\Models\PhrEob;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrProcedure;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ClinicalEobController extends Controller
{
    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);
        $validated = $request->validate([
            'service_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $serviceDate = $validated['service_date'];

        $eobs = PhrEob::query()
            ->where('patient_id', $resolvedPatient->id)
            ->whereHas('lines', function ($query) use ($serviceDate): void {
                $query->where(function ($dates) use ($serviceDate): void {
                    $dates->whereDate('service_start', $serviceDate)
                        ->orWhere(function ($range) use ($serviceDate): void {
                            $range->whereDate('service_start', '<=', $serviceDate)
                                ->whereDate('service_end', '>=', $serviceDate);
                        });
                });
            })
            ->with('lines')
            ->orderByDesc('processed_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'eobs' => EobSummaryResource::collection($eobs)->resolve(),
            'can_manage' => $this->accessService->canWrite($resolvedPatient, $userId),
        ]);
    }

    /**
     * A person's decision on an EOB an agent wrote. The same rules as every clinical
     * record's review: confirmed or rejected only, and, with a version, only if the
     * EOB is still exactly what the reviewer saw.
     */
    public function review(ReviewClinicalRecordRequest $request, int $patient, int $eob): JsonResponse
    {
        $resolved = $this->writablePatient($request, $patient);
        $expected = $request->expectedVersion();
        DB::transaction(function () use ($resolved, $eob, $expected, $request): void {
            $locked = PhrEob::query()->where('patient_id', $resolved->id)->lockForUpdate()->findOrFail($eob);
            if ($expected !== null && ! hash_equals(app(AgentClinicalRecordVersion::class)->for($locked), $expected)) {
                throw new ConflictHttpException('This EOB changed since it was loaded. Reload and review it again.');
            }
            $locked->update(['review_status' => $request->reviewStatus()]);
        });
        $reviewed = $this->eob($resolved, $eob);

        return response()->json([
            'eob' => [
                ...(new EobSummaryResource($reviewed->load('lines')))->resolve(),
                'version' => app(AgentClinicalRecordVersion::class)->for($reviewed->withoutRelations()),
            ],
        ]);
    }

    public function linkOfficeVisit(Request $request, int $patient, int $visit, int $eob): JsonResponse
    {
        $resolvedPatient = $this->writablePatient($request, $patient);
        $resolvedVisit = $this->officeVisit($resolvedPatient, $visit);

        return $this->link($resolvedVisit->eobs(), $resolvedPatient, $eob);
    }

    public function unlinkOfficeVisit(Request $request, int $patient, int $visit, int $eob): Response
    {
        $resolvedPatient = $this->writablePatient($request, $patient);
        $resolvedVisit = $this->officeVisit($resolvedPatient, $visit);

        return $this->unlink($resolvedVisit->eobs(), $resolvedPatient, $eob);
    }

    public function linkProcedure(Request $request, int $patient, int $procedure, int $eob): JsonResponse
    {
        $resolvedPatient = $this->writablePatient($request, $patient);
        $resolvedProcedure = $this->procedure($resolvedPatient, $procedure);

        return $this->link($resolvedProcedure->eobs(), $resolvedPatient, $eob);
    }

    public function unlinkProcedure(Request $request, int $patient, int $procedure, int $eob): Response
    {
        $resolvedPatient = $this->writablePatient($request, $patient);
        $resolvedProcedure = $this->procedure($resolvedPatient, $procedure);

        return $this->unlink($resolvedProcedure->eobs(), $resolvedPatient, $eob);
    }

    private function writablePatient(Request $request, int $patient): PhrPatient
    {
        return $this->accessService->writablePatient($patient, (int) $request->user()?->id);
    }

    private function officeVisit(PhrPatient $patient, int $visit): PhrOfficeVisit
    {
        return PhrOfficeVisit::query()
            ->where('patient_id', $patient->id)
            ->findOrFail($visit);
    }

    private function procedure(PhrPatient $patient, int $procedure): PhrProcedure
    {
        return PhrProcedure::query()
            ->where('patient_id', $patient->id)
            ->findOrFail($procedure);
    }

    /**
     * @template TDeclaringModel of PhrOfficeVisit|PhrProcedure
     *
     * @param  BelongsToMany<PhrEob, TDeclaringModel>  $relation
     */
    private function link(BelongsToMany $relation, PhrPatient $patient, int $eob): JsonResponse
    {
        $resolvedEob = $this->eob($patient, $eob);
        $relation->syncWithoutDetaching([
            $resolvedEob->id => ['patient_id' => $patient->id],
        ]);

        return response()->json([
            'eob' => (new EobSummaryResource($resolvedEob->load('lines')))->resolve(),
        ], 201);
    }

    /**
     * @template TDeclaringModel of PhrOfficeVisit|PhrProcedure
     *
     * @param  BelongsToMany<PhrEob, TDeclaringModel>  $relation
     */
    private function unlink(BelongsToMany $relation, PhrPatient $patient, int $eob): Response
    {
        $resolvedEob = $this->eob($patient, $eob);
        $relation->detach($resolvedEob->id);

        return response()->noContent();
    }

    private function eob(PhrPatient $patient, int $eob): PhrEob
    {
        return PhrEob::query()
            ->where('patient_id', $patient->id)
            ->findOrFail($eob);
    }
}
