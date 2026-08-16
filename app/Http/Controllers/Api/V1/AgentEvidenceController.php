<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrOfficeVisit;
use App\Models\PhrProcedure;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiUpdateWindow;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\AgentApi\AgentEvidenceCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AgentEvidenceController extends Controller
{
    private const array LINKABLE_TYPES = ['document', 'eob', 'office-visit', 'procedure'];

    public function __construct(private PhrPatientAccessService $accessService) {}

    public function eobs(Request $request, int $patient): JsonResponse
    {
        $validated = $request->validate($this->eobListRules());
        $patientId = $this->patientId($request, $patient);
        $query = PhrEob::query()->where('patient_id', $patientId)->withCount('lines');
        AgentApiUpdateWindow::apply($query, $validated, $query->getModel()->qualifyColumn('patient_id'));
        foreach (['import_source', 'source_document_id', 'claim_type'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        if (isset($validated['date_from'])) {
            $query->whereDate('processed_date', '>=', $validated['date_from']);
        }
        if (isset($validated['date_to'])) {
            $query->whereDate('processed_date', '<=', $validated['date_to']);
        }

        return $this->modelPage($request, $patientId, 'eob', $query, $validated, fn (PhrEob $eob): array => $this->eobPayload($eob));
    }

    public function eob(Request $request, int $patient, int $eob): JsonResponse
    {
        $patientId = $this->patientId($request, $patient);
        $resolved = PhrEob::query()->where('patient_id', $patientId)->withCount('lines')->findOrFail($eob);

        return response()->json(['resource_type' => 'eob', 'patient_id' => $patientId, 'data' => $this->eobPayload($resolved)]);
    }

    public function eobLines(Request $request, int $patient, int $eob): JsonResponse
    {
        $validated = $request->validate($this->pageRules());
        $patientId = $this->patientId($request, $patient);
        PhrEob::query()->where('patient_id', $patientId)->findOrFail($eob);
        $query = PhrEobLine::query()->where('patient_id', $patientId)->where('eob_id', $eob);
        AgentApiUpdateWindow::apply($query, $validated, $query->getModel()->qualifyColumn('patient_id'));

        return $this->modelPage($request, $patientId, 'eob-line', $query, $validated, fn (PhrEobLine $line): array => $this->linePayload($line));
    }

    public function eobLine(Request $request, int $patient, int $eob, int $line): JsonResponse
    {
        $patientId = $this->patientId($request, $patient);
        PhrEob::query()->where('patient_id', $patientId)->findOrFail($eob);
        $resolved = PhrEobLine::query()
            ->where('patient_id', $patientId)
            ->where('eob_id', $eob)
            ->findOrFail($line);

        return response()->json(['resource_type' => 'eob-line', 'patient_id' => $patientId, 'data' => $this->linePayload($resolved)]);
    }

    public function links(Request $request, int $patient): JsonResponse
    {
        $validated = $request->validate([
            'resource_type' => ['required', Rule::in(self::LINKABLE_TYPES)],
            'resource_id' => ['required', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
        ]);
        $patientId = $this->patientId($request, $patient);
        $sourceType = (string) $validated['resource_type'];
        $sourceId = (int) $validated['resource_id'];
        if ($sourceType === 'document') {
            abort_unless($request->user('api')?->tokenCan(AgentApiScopes::DOCUMENTS_READ), 403);
        }
        $this->assertSourceExists($sourceType, $sourceId, $patientId);
        $cursor = AgentEvidenceCursor::decode(isset($validated['cursor']) ? (string) $validated['cursor'] : null);
        $limit = (int) ($validated['limit'] ?? 25);
        $links = collect($this->linkTargets($sourceType, $sourceId, $patientId, $cursor, $limit + 1))
            ->sortBy([['target_type', 'asc'], ['target_id', 'asc']])
            ->values();
        $hasMore = $links->count() > $limit;
        $page = $links->take($limit)->values();
        $last = $page->last();

        return response()->json([
            'patient_id' => $patientId,
            'source' => ['resource_type' => $sourceType, 'id' => $sourceId],
            'data' => $page,
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore && is_array($last)
                    ? AgentEvidenceCursor::encode(['target_type' => $last['target_type'], 'target_id' => $last['target_id']])
                    : null,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function eobListRules(): array
    {
        return $this->pageRules() + [
            'import_source' => ['sometimes', 'string', 'max:50'],
            'source_document_id' => ['sometimes', 'integer', 'min:1'],
            'claim_type' => ['sometimes', 'string', 'max:30'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
        ];
    }

    /** @return array<string, mixed> */
    private function pageRules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'updated_after' => ['sometimes', 'date'],
            'updated_before' => ['sometimes', 'date', 'after_or_equal:updated_after'],
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $validated
     * @param  callable(TModel): array<string, mixed>  $serializer
     */
    private function modelPage(Request $request, int $patientId, string $type, Builder $query, array $validated, callable $serializer): JsonResponse
    {
        $limit = (int) ($validated['limit'] ?? 25);
        $page = $query->orderBy('id')->cursorPaginate(
            $limit,
            ['*'],
            'cursor',
            AgentApiCursor::decode(isset($validated['cursor']) ? (string) $validated['cursor'] : null),
        );

        return response()->json([
            'resource_type' => $type,
            'patient_id' => $patientId,
            'data' => $page->getCollection()->map($serializer)->values(),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function eobPayload(PhrEob $eob): array
    {
        return [
            'id' => $eob->id, 'patient_id' => $eob->patient_id,
            'source_document_id' => $eob->source_document_id,
            'import_source' => $eob->import_source, 'external_id' => $eob->external_id,
            'claim_number' => $eob->claim_number, 'claim_type' => $eob->claim_type,
            'administrator' => $eob->administrator, 'carrier' => $eob->carrier, 'plan_name' => $eob->plan_name,
            'provider_name' => $eob->provider_name,
            'submission_date' => $eob->submission_date?->toDateString(),
            'print_date' => $eob->print_date?->toDateString(), 'processed_date' => $eob->processed_date?->toDateString(),
            'total_accepted_fee' => $eob->total_accepted_fee, 'total_charges' => $eob->total_charges,
            'total_provider_discount' => $eob->total_provider_discount,
            'total_ineligible_amount' => $eob->total_ineligible_amount,
            'total_deductible_applied' => $eob->total_deductible_applied,
            'total_copay_applied' => $eob->total_copay_applied,
            'total_benefit_percent' => $eob->total_benefit_percent,
            'total_carrier_payment' => $eob->total_carrier_payment,
            'total_plan_payment' => $eob->total_plan_payment,
            'total_patient_responsibility' => $eob->total_patient_responsibility,
            'lines_count' => (int) ($eob->lines_count ?? 0),
            'created_at' => $eob->created_at?->toIso8601String(), 'updated_at' => $eob->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function linePayload(PhrEobLine $line): array
    {
        return [
            'id' => $line->id, 'eob_id' => $line->eob_id, 'patient_id' => $line->patient_id,
            'line_number' => $line->line_number, 'procedure_code' => $line->procedure_code,
            'revenue_code' => $line->revenue_code, 'code_type' => $line->code_type,
            'description' => $line->description,
            'service_start' => $line->service_start?->toDateString(), 'service_end' => $line->service_end?->toDateString(),
            'accepted_fee' => $line->accepted_fee, 'total_charges' => $line->total_charges,
            'provider_discount' => $line->provider_discount, 'ineligible_amount' => $line->ineligible_amount,
            'deductible_applied' => $line->deductible_applied, 'copay_applied' => $line->copay_applied,
            'benefit_percent' => $line->benefit_percent, 'carrier_payment' => $line->carrier_payment,
            'plan_payment' => $line->plan_payment, 'patient_responsibility' => $line->patient_responsibility,
            'created_at' => $line->created_at?->toIso8601String(), 'updated_at' => $line->updated_at?->toIso8601String(),
        ];
    }

    private function patientId(Request $request, int $patient): int
    {
        return (int) $this->accessService->accessiblePatientWithCurrentGrant(
            $patient,
            (int) $request->user('api')?->id,
        )->id;
    }

    private function assertSourceExists(string $type, int $id, int $patientId): void
    {
        $model = match ($type) {
            'document' => PhrDocument::class, 'eob' => PhrEob::class,
            'office-visit' => PhrOfficeVisit::class, 'procedure' => PhrProcedure::class,
            default => abort(404),
        };
        $model::query()->where('patient_id', $patientId)->findOrFail($id);
    }

    /**
     * @param  array{target_type: string, target_id: int}|null  $cursor
     * @return list<array{target_type: string, target_id: int}>
     */
    private function linkTargets(string $sourceType, int $sourceId, int $patientId, ?array $cursor, int $limit): array
    {
        $queries = match ($sourceType) {
            'document' => $this->documentLinkQueries($sourceId, $patientId),
            'eob' => $this->eobLinkQueries($sourceId, $patientId),
            'office-visit' => $this->clinicalLinkQueries('phr_office_visits', 'phr_office_visit_eobs', 'office_visit_id', $sourceId, $patientId),
            'procedure' => $this->clinicalLinkQueries('phr_procedures', 'phr_procedure_eobs', 'procedure_id', $sourceId, $patientId),
            default => abort(404),
        };
        $links = [];
        foreach ($queries as $type => $query) {
            $comparison = $cursor === null ? 1 : strcmp($type, $cursor['target_type']);
            if ($cursor !== null && $comparison < 0) {
                continue;
            }
            if ($cursor !== null && $comparison === 0) {
                $query->where('id', '>', $cursor['target_id']);
            }
            foreach ($query->orderBy('id')->limit($limit)->pluck('id') as $id) {
                $links[] = ['target_type' => $type, 'target_id' => (int) $id];
            }
        }

        return $links;
    }

    /** @return array<string, \Illuminate\Database\Query\Builder> */
    private function documentLinkQueries(int $documentId, int $patientId): array
    {
        $queries = ['eob' => DB::table('phr_eobs')->where('patient_id', $patientId)->where('source_document_id', $documentId)];
        foreach (AgentClinicalResourceCatalog::ids() as $resource) {
            $definition = AgentClinicalResourceCatalog::definition($resource);
            if (! ($definition['provenance'] ?? false)) {
                continue;
            }
            /** @var class-string<Model> $model */
            $model = $definition['model'];
            $targetType = match ($resource) {
                'office-visits' => 'office-visit', 'procedures' => 'procedure',
                'immunizations' => 'immunization', 'medications' => 'medication',
                'conditions' => 'condition', 'allergies' => 'allergy',
                'lab-results' => 'lab-result', 'vitals' => 'vital',
                default => abort(500, 'Clinical search catalog is inconsistent.'),
            };
            $queries[$targetType] = DB::table((new $model)->getTable())
                ->where('patient_id', $patientId)
                ->where('source_document_id', $documentId);
        }

        return $queries;
    }

    /** @return array<string, \Illuminate\Database\Query\Builder> */
    private function eobLinkQueries(int $eobId, int $patientId): array
    {
        $queries = [
            'eob-line' => DB::table('phr_eob_lines')->where('patient_id', $patientId)->where('eob_id', $eobId),
            'office-visit' => DB::table('phr_office_visits')->where('patient_id', $patientId)
                ->whereExists(fn ($pivot) => $pivot->selectRaw('1')->from('phr_office_visit_eobs')
                    ->where('patient_id', $patientId)->where('eob_id', $eobId)
                    ->whereColumn('office_visit_id', 'phr_office_visits.id')),
            'procedure' => DB::table('phr_procedures')->where('patient_id', $patientId)
                ->whereExists(fn ($pivot) => $pivot->selectRaw('1')->from('phr_procedure_eobs')
                    ->where('patient_id', $patientId)->where('eob_id', $eobId)
                    ->whereColumn('procedure_id', 'phr_procedures.id')),
        ];
        $documentId = PhrEob::query()->where('patient_id', $patientId)->whereKey($eobId)->value('source_document_id');
        if ($documentId !== null) {
            $queries['document'] = DB::table('phr_documents')->where('patient_id', $patientId)
                ->whereNull('deleted_at')->where('id', $documentId);
        }

        return $queries;
    }

    /** @return array<string, \Illuminate\Database\Query\Builder> */
    private function clinicalLinkQueries(string $table, string $pivot, string $foreignKey, int $sourceId, int $patientId): array
    {
        $queries = [
            'eob' => DB::table('phr_eobs')->where('patient_id', $patientId)
                ->whereExists(fn ($links) => $links->selectRaw('1')->from($pivot)
                    ->where('patient_id', $patientId)->where($foreignKey, $sourceId)
                    ->whereColumn('eob_id', 'phr_eobs.id')),
        ];
        $documentId = DB::table($table)->where('patient_id', $patientId)->where('id', $sourceId)->value('source_document_id');
        if ($documentId !== null) {
            $queries['document'] = DB::table('phr_documents')->where('patient_id', $patientId)
                ->whereNull('deleted_at')->where('id', $documentId);
        }

        return $queries;
    }
}
