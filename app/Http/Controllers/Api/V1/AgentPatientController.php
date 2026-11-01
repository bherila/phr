<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\StorePatientRequest;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\Access\AgentPatientPresenter;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Fills\PatientNameMatcher;
use App\Services\PHR\Identity\PhrPublicIds;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiUpdateWindow;
use App\Support\AgentApi\OAuthPatientConsents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AgentPatientController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private AgentPatientPresenter $presenter,
        private PhrPublicIds $publicIds,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $billingOnly = $this->billingOnly($request);
        $validated = $request->validate($this->listRules($billingOnly));
        $userId = (int) $request->user('api')?->id;
        $query = $this->accessService->accessiblePatientsQuery($userId)
            ->with(['accessGrants' => function (Relation $relation) use ($userId): void {
                $relation->getQuery()->where('user_id', $userId);
            }]);

        app(OAuthPatientConsents::class)->restrict($request, $query);
        AgentApiUpdateWindow::apply($query, $validated, 'phr_patients.id');
        $archived = (string) ($validated['archived'] ?? 'include');
        if ($archived === 'exclude') {
            $query->whereNull('archived_at');
        } elseif ($archived === 'only') {
            $query->whereNotNull('archived_at');
        }

        if (isset($validated['name'])) {
            return $this->byPrintedName($request, $query, $userId, $billingOnly, (string) $validated['name'], isset($validated['birth_date']) ? (string) $validated['birth_date'] : null);
        }

        $limit = (int) ($validated['limit'] ?? 25);
        $page = $query
            ->orderBy('id')
            ->cursorPaginate($limit, ['*'], 'cursor', AgentApiCursor::decode($this->encodedCursor($validated)));

        $publicIds = $this->publicIds->forPatients($page->getCollection()->pluck('id'));

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (PhrPatient $patient): array => $this->presenter->payload($patient, $userId, publicId: $publicIds[(int) $patient->id], billingOnly: $billingOnly))
                ->values(),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }

    /**
     * The one patient a name printed on a source document refers to. Never
     * guesses and never creates: with no match, or several, it refuses and tells
     * the caller to ask the person.
     *
     * @param  Builder<PhrPatient>  $query
     */
    private function byPrintedName(Request $request, Builder $query, int $userId, bool $billingOnly, string $name, ?string $birthDate): JsonResponse
    {
        $result = (new PatientNameMatcher)->match($query->orderBy('id')->get(), $name, $birthDate);
        if ($result['status'] === PatientNameMatcher::MATCHED) {
            $patient = $result['patients'][0];
            $publicIds = $this->publicIds->forPatients(collect([(int) $patient->id]));

            return response()->json([
                'data' => [$this->presenter->payload($patient, $userId, publicId: $publicIds[(int) $patient->id], billingOnly: $billingOnly)],
                'pagination' => ['limit' => 1, 'has_more' => false, 'next_cursor' => null],
            ]);
        }

        $none = $result['status'] === PatientNameMatcher::NONE;
        $message = $none
            ? "No patient you can access matches the name \"{$name}\"".($birthDate !== null ? " with date of birth {$birthDate}" : '').'. Ask the user which patient this belongs to. Do not guess, and do not create a patient.'
            : 'Several patients match the name "'.$name.'": '.collect($result['patients'])->map(fn (PhrPatient $patient): string => "{$patient->display_name} (id {$patient->id}".($patient->birth_date === null ? '' : ", born {$patient->birth_date->toDateString()}").')')->implode('; ').'. Ask the user which one, or for the date of birth printed on the source. Do not guess.';

        return response()->json([
            'message' => $message,
            'code' => $none ? 'patient_match_none' : 'patient_match_ambiguous',
            'errors' => ['name' => [$message]],
            'candidates' => collect($result['patients'])->map(fn (PhrPatient $patient): array => ['id' => $patient->id, 'display_name' => $patient->display_name])->values(),
        ], 422);
    }

    public function show(Request $request, int $patient): JsonResponse
    {
        app(OAuthPatientConsents::class)->assertPatient($request, $patient);
        $userId = (int) $request->user('api')?->id;
        $resolved = $this->accessService->accessiblePatientWithCurrentGrant($patient, $userId);

        $billingOnly = $this->billingOnly($request);

        return response()->json([
            'data' => $this->presenter->payload($resolved, $userId, includeNotes: ! $billingOnly, billingOnly: $billingOnly),
        ]);
    }

    /**
     * billing:read without patients:read sees only routing and public ids.
     */
    private function billingOnly(Request $request): bool
    {
        return ! (bool) $request->user('api')?->tokenCan(AgentApiScopes::PATIENTS_READ);
    }

    /**
     * Mirrors PhrPatientController::store's persistence: the creator becomes
     * both owner_user_id and the sole owner-level access grant. Reuses
     * StorePatientRequest's validation rules directly, rather than
     * duplicating them, so the two entry points can never drift apart.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate((new StorePatientRequest)->rules());
        $userId = (int) $request->user('api')?->id;

        $patient = DB::transaction(function () use ($userId, $validated): PhrPatient {
            $patient = PhrPatient::create([
                'owner_user_id' => $userId,
                ...$validated,
            ]);

            PhrPatientUserAccess::create([
                'patient_id' => $patient->id,
                'user_id' => $userId,
                'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
                'granted_by_user_id' => $userId,
                'granted_at' => now(),
            ]);

            return $patient;
        });

        // No grant load: the creator is always this patient's owner_user_id, so
        // AgentPatientPresenter takes its owner branch and never reads
        // accessGrants, and PhrPatientAccessService::canWrite() short-circuits
        // on ownership before touching the relation. show() loads the grant
        // because its caller may hold only a shared-access row.
        return response()->json([
            'data' => $this->presenter->payload($patient, $userId, includeNotes: true),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function listRules(bool $billingOnly): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'updated_after' => $billingOnly ? ['prohibited'] : ['sometimes', 'date'],
            'updated_before' => $billingOnly ? ['prohibited'] : ['sometimes', 'date', 'after_or_equal:updated_after'],
            'archived' => $billingOnly ? ['prohibited'] : ['sometimes', Rule::in(['include', 'exclude', 'only'])],
            'name' => $billingOnly ? ['prohibited'] : ['required_with:birth_date', 'string', 'min:1', 'max:255'],
            'birth_date' => $billingOnly ? ['prohibited'] : ['sometimes', 'date'],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function encodedCursor(array $validated): ?string
    {
        return isset($validated['cursor']) ? (string) $validated['cursor'] : null;
    }
}
