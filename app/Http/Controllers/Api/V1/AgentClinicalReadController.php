<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PHR\ClinicalDataRules;
use App\Http\Controllers\Controller;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiUpdateWindow;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;

final class AgentClinicalReadController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private AgentClinicalRecordVersion $versions,
    ) {}

    public function index(Request $request, int $patient, string $resource): JsonResponse
    {
        $definition = $this->definition($resource);
        $validated = $request->validate($this->listRules($definition['provenance'], $resource));
        $userId = (int) $request->user('api')?->id;
        $resolvedPatient = $this->accessService->accessiblePatientWithCurrentGrant($patient, $userId);
        $modelClass = $definition['model'];

        $query = $modelClass::query()->where('patient_id', $resolvedPatient->id);
        AgentApiUpdateWindow::apply($query, $validated, $query->getModel()->qualifyColumn('patient_id'));
        $this->applyFilters($query, $validated, $definition['provenance'], $resource);
        if ($definition['health_log_aggregates'] ?? false) {
            $query
                ->withCount('entries')
                ->withMax('entries as latest_entry_at', 'occurred_at');
        }

        $limit = (int) ($validated['limit'] ?? 25);
        $page = $query
            ->orderBy('id')
            ->cursorPaginate($limit, ['*'], 'cursor', AgentApiCursor::decode($this->encodedCursor($validated)));
        $resourceClass = $definition['resource'];

        return response()->json([
            'resource_type' => $resource,
            'patient_id' => $resolvedPatient->id,
            'data' => $page->getCollection()
                ->map(fn (Model $record): array => $this->resourcePayload(
                    $resourceClass,
                    $record,
                    $request,
                    isset($definition['write_rules']),
                ))
                ->values(),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }

    public function show(Request $request, int $patient, string $resource, int $record): JsonResponse
    {
        $definition = $this->definition($resource);
        $userId = (int) $request->user('api')?->id;
        $resolvedPatient = $this->accessService->accessiblePatientWithCurrentGrant($patient, $userId);
        $modelClass = $definition['model'];
        $query = $modelClass::query()->where('patient_id', $resolvedPatient->id);
        if ($definition['health_log_aggregates'] ?? false) {
            $query
                ->withCount('entries')
                ->withMax('entries as latest_entry_at', 'occurred_at');
        }

        $resolved = $query->findOrFail($record);

        return response()->json([
            'resource_type' => $resource,
            'patient_id' => $resolvedPatient->id,
            'data' => $this->resourcePayload(
                $definition['resource'],
                $resolved,
                $request,
                isset($definition['write_rules']),
            ),
        ]);
    }

    /**
     * @return array{
     *     model: class-string<Model>,
     *     resource: class-string<JsonResource>,
     *     provenance: bool,
     *     write_rules?: class-string<ClinicalDataRules>,
     *     health_log_aggregates?: bool
     * }
     */
    private function definition(string $resource): array
    {
        return AgentClinicalResourceCatalog::definition($resource) ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function listRules(bool $supportsProvenance, string $resource): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'updated_after' => ['sometimes', 'date'],
            'updated_before' => ['sometimes', 'date', 'after_or_equal:updated_after'],
            'import_source' => $supportsProvenance
                ? ['sometimes', 'string', 'max:100']
                : ['prohibited'],
            'source_document_id' => $supportsProvenance
                ? ['sometimes', 'integer', 'min:1']
                : ['prohibited'],
            'archived' => $resource === 'health-logs'
                ? ['sometimes', Rule::in(['include', 'exclude', 'only'])]
                : ['prohibited'],
        ];
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $validated
     */
    private function applyFilters(Builder $query, array $validated, bool $supportsProvenance, string $resource): void
    {
        if ($supportsProvenance && isset($validated['import_source'])) {
            $query->where('import_source', $validated['import_source']);
        }
        if ($supportsProvenance && isset($validated['source_document_id'])) {
            $query->where('source_document_id', $validated['source_document_id']);
        }
        if ($resource === 'health-logs') {
            $archived = (string) ($validated['archived'] ?? 'include');
            if ($archived === 'exclude') {
                $query->whereNull('archived_at');
            } elseif ($archived === 'only') {
                $query->whereNotNull('archived_at');
            }
        }
    }

    /** @param array<string, mixed> $validated */
    private function encodedCursor(array $validated): ?string
    {
        return isset($validated['cursor']) ? (string) $validated['cursor'] : null;
    }

    /**
     * @param  class-string<JsonResource>  $resourceClass
     * @return array<string, mixed>
     */
    private function resourcePayload(
        string $resourceClass,
        Model $record,
        Request $request,
        bool $includeVersion,
    ): array {
        $payload = (new $resourceClass($record))->resolve($request);
        if ($includeVersion) {
            $payload['version'] = $this->versions->for($record);
        }

        return $payload;
    }
}
