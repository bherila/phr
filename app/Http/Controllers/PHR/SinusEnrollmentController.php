<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\DeleteSinusEnrollmentBatchRequest;
use App\Http\Requests\PHR\StoreSinusEnrollmentBatchRequest;
use App\Http\Resources\PHR\SinusEnrollmentResource;
use App\Models\PhrRespiratoryEvent;
use App\Models\PhrSinusEnrollment;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Teach-mode training examples — derived embeddings, never audio — synced so a
 * second machine inherits a trained detector.
 *
 * Wire format is base64; storage is raw binary. The embedding bytes are the
 * device's little-endian f32 SQLite BLOB verbatim, so nothing in the round trip
 * reformats a float.
 */
class SinusEnrollmentController extends Controller
{
    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        /** @var Collection<int, PhrSinusEnrollment> $enrollments */
        $enrollments = PhrSinusEnrollment::query()
            ->where('phr_patient_id', $resolvedPatient->id)
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'sinus_enrollments' => SinusEnrollmentResource::collection($enrollments)->resolve(),
            'can_manage' => $this->accessService->canWrite($resolvedPatient, $userId),
        ]);
    }

    /**
     * Idempotent batch ingest keyed on `client_enrollment_uuid`, mirroring the
     * respiratory-event batch contract: per-item verdicts, always 200.
     */
    public function batch(StoreSinusEnrollmentBatchRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);

        /** @var list<array<string, mixed>> $enrollments */
        $enrollments = $request->validated()['enrollments'];

        $results = [];
        $seenUuids = [];

        DB::transaction(function () use ($enrollments, $resolvedPatient, &$results, &$seenUuids): void {
            foreach ($enrollments as $enrollment) {
                $results[] = $this->ingestEnrollment($enrollment, $resolvedPatient->id, $seenUuids);
            }
        });

        return response()->json(['results' => $results]);
    }

    public function deleteBatch(DeleteSinusEnrollmentBatchRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);

        /** @var list<string> $encodedUuids */
        $encodedUuids = array_values(array_unique($request->validated()['uuids']));

        $results = [];
        $deleted = 0;

        foreach ($encodedUuids as $encoded) {
            $raw = $this->decodeUuid($encoded);

            if ($raw === null) {
                $results[] = ['uuid' => $encoded, 'status' => 'not_found'];

                continue;
            }

            $removed = PhrSinusEnrollment::query()
                ->where('phr_patient_id', $resolvedPatient->id)
                ->where('client_enrollment_uuid', $raw)
                ->delete();

            $deleted += $removed;
            $results[] = [
                'uuid' => $encoded,
                'status' => $removed > 0 ? 'deleted' : 'not_found',
            ];
        }

        return response()->json([
            'deleted' => $deleted,
            'results' => $results,
        ]);
    }

    /**
     * @param  array<string, mixed>  $enrollment
     * @param  array<string, true>  $seenUuids
     * @return array{uuid: string|null, status: string, reason?: string}
     */
    private function ingestEnrollment(array $enrollment, int $patientId, array &$seenUuids): array
    {
        $encodedUuid = isset($enrollment['client_enrollment_uuid']) && is_string($enrollment['client_enrollment_uuid'])
            ? $enrollment['client_enrollment_uuid']
            : null;

        $validator = Validator::make($enrollment, $this->enrollmentRules());

        if ($validator->fails()) {
            return [
                'uuid' => $encodedUuid,
                'status' => 'rejected',
                'reason' => (string) $validator->errors()->first(),
            ];
        }

        /** @var array<string, mixed> $data */
        $data = $validator->validated();

        $rawUuid = $this->decodeUuid((string) $data['client_enrollment_uuid']);

        if ($rawUuid === null) {
            return [
                'uuid' => $encodedUuid,
                'status' => 'rejected',
                'reason' => 'client_enrollment_uuid must be base64 of exactly '
                    .PhrSinusEnrollment::UUID_BYTES.' bytes.',
            ];
        }

        $embedding = base64_decode((string) $data['embedding'], true);
        $dim = (int) $data['embedding_dim'];

        if ($embedding === false) {
            return ['uuid' => $encodedUuid, 'status' => 'rejected', 'reason' => 'embedding is not valid base64.'];
        }

        // The byte length must match the declared dimension exactly, and must
        // fit the column: better a per-item rejection than a silent truncation
        // that would corrupt the vector.
        $expectedBytes = $dim * PhrSinusEnrollment::BYTES_PER_DIM;

        if (strlen($embedding) !== $expectedBytes) {
            return [
                'uuid' => $encodedUuid,
                'status' => 'rejected',
                'reason' => 'embedding is '.strlen($embedding).' bytes but embedding_dim implies '.$expectedBytes.'.',
            ];
        }

        if (strlen($embedding) > PhrSinusEnrollment::MAX_EMBEDDING_BYTES) {
            return [
                'uuid' => $encodedUuid,
                'status' => 'rejected',
                'reason' => 'embedding exceeds '.PhrSinusEnrollment::MAX_EMBEDDING_BYTES.' bytes.',
            ];
        }

        if (isset($seenUuids[$encodedUuid])) {
            return ['uuid' => $encodedUuid, 'status' => 'duplicate'];
        }

        $exists = PhrSinusEnrollment::query()
            ->where('phr_patient_id', $patientId)
            ->where('client_enrollment_uuid', $rawUuid)
            ->exists();

        if ($exists) {
            return ['uuid' => $encodedUuid, 'status' => 'duplicate'];
        }

        $seenUuids[(string) $encodedUuid] = true;

        try {
            PhrSinusEnrollment::query()->create([
                'phr_patient_id' => $patientId,
                'client_enrollment_uuid' => $rawUuid,
                'class' => $data['class'],
                'is_negative' => (bool) ($data['is_negative'] ?? false),
                'negative_scoped' => (bool) ($data['negative_scoped'] ?? false),
                'embedding' => $embedding,
                'embedding_dim' => $dim,
                'model_version' => $data['model_version'] ?? null,
                'similarity' => $data['similarity'] ?? null,
                'separation' => $data['separation'] ?? null,
                'peak_dbfs' => $data['peak_dbfs'] ?? null,
                'source_event_uuid' => $data['source_event_uuid'] ?? null,
                'device_id' => $data['device_id'] ?? null,
                'captured_at' => $data['captured_at'],
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['uuid' => $encodedUuid, 'status' => 'duplicate'];
        }

        return ['uuid' => $encodedUuid, 'status' => 'accepted'];
    }

    /**
     * Base64 of exactly 16 raw bytes, or null when malformed.
     */
    private function decodeUuid(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) !== PhrSinusEnrollment::UUID_BYTES) {
            return null;
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentRules(): array
    {
        $maxDim = intdiv(PhrSinusEnrollment::MAX_EMBEDDING_BYTES, PhrSinusEnrollment::BYTES_PER_DIM);

        return [
            'client_enrollment_uuid' => ['required', 'string', 'max:32'],
            'class' => ['required', 'string', 'in:'.implode(',', PhrRespiratoryEvent::EVENT_TYPES)],
            'is_negative' => ['nullable', 'boolean'],
            'negative_scoped' => ['nullable', 'boolean'],
            'embedding' => ['required', 'string'],
            'embedding_dim' => ['required', 'integer', 'between:1,'.$maxDim],
            'model_version' => ['nullable', 'string', 'max:64'],
            'similarity' => ['nullable', 'numeric', 'between:-1,1'],
            'separation' => ['nullable', 'numeric', 'between:-2,2'],
            'peak_dbfs' => ['nullable', 'numeric', 'between:-120,20'],
            'source_event_uuid' => ['nullable', 'string', 'max:64'],
            'device_id' => ['nullable', 'string', 'max:64'],
            'captured_at' => ['required', 'date'],
        ];
    }
}
