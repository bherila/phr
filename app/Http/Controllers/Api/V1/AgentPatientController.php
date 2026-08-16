<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PhrPatient;
use App\Services\PHR\Access\AgentPatientPresenter;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiUpdateWindow;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AgentPatientController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private AgentPatientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate($this->listRules());
        $userId = (int) $request->user('api')?->id;
        $query = $this->accessService->accessiblePatientsQuery($userId)
            ->with(['accessGrants' => function (Relation $relation) use ($userId): void {
                $relation->getQuery()->where('user_id', $userId);
            }]);

        AgentApiUpdateWindow::apply($query, $validated, 'phr_patients.id');
        $archived = (string) ($validated['archived'] ?? 'include');
        if ($archived === 'exclude') {
            $query->whereNull('archived_at');
        } elseif ($archived === 'only') {
            $query->whereNotNull('archived_at');
        }

        $limit = (int) ($validated['limit'] ?? 25);
        $page = $query
            ->orderBy('id')
            ->cursorPaginate($limit, ['*'], 'cursor', AgentApiCursor::decode($this->encodedCursor($validated)));

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (PhrPatient $patient): array => $this->presenter->payload($patient, $userId))
                ->values(),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }

    public function show(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user('api')?->id;
        $resolved = $this->accessService->accessiblePatientWithCurrentGrant($patient, $userId);

        return response()->json([
            'data' => $this->presenter->payload($resolved, $userId, includeNotes: true),
        ]);
    }

    /** @return array<string, mixed> */
    private function listRules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'updated_after' => ['sometimes', 'date'],
            'updated_before' => ['sometimes', 'date', 'after_or_equal:updated_after'],
            'archived' => ['sometimes', Rule::in(['include', 'exclude', 'only'])],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function encodedCursor(array $validated): ?string
    {
        return isset($validated['cursor']) ? (string) $validated['cursor'] : null;
    }
}
