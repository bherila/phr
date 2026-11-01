<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrMedicationFill;
use App\Models\PhrOfficeVisit;
use App\Models\PhrProcedure;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Identity\PhrPublicIds;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiExternalId;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiUpdateWindow;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\AgentApi\AgentEvidenceCursor;
use App\Support\AgentApi\OAuthPatientConsents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AgentEvidenceController extends Controller
{
    private const array LINKABLE_TYPES = ['document', 'eob', 'medication-fill', 'office-visit', 'procedure'];

    /**
     * Public ids read in bulk for the page being serialized: [table][record id].
     *
     * @var array<string, array<int, string>>
     */
    private array $publicIdCache = [];

    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrPublicIds $publicIds,
    ) {}

    public function eobs(Request $request, int $patient): JsonResponse
    {
        app(OAuthPatientConsents::class)->assertPatient($request, $patient);
        $finance = $this->financeProjection($request);
        $validated = $request->validate($this->eobListRules($finance));
        $patientId = $this->patientId($request, $patient);
        $query = $this->eobQuery($patientId, $finance);
        AgentApiUpdateWindow::apply($query, $validated, $query->getModel()->qualifyColumn('patient_id'));
        foreach (['import_source', 'source_document_id', 'claim_type'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        $eobDate = $finance ? 'DATE(phr_eobs.processed_date)' : 'DATE(COALESCE(phr_eobs.processed_date, phr_eobs.submission_date, phr_eobs.print_date))';
        if (isset($validated['date_from'])) {
            $query->whereRaw("{$eobDate} >= ?", [Carbon::parse((string) $validated['date_from'])->toDateString()]);
        }
        if (isset($validated['date_to'])) {
            $query->whereRaw("{$eobDate} <= ?", [Carbon::parse((string) $validated['date_to'])->toDateString()]);
        }

        return $this->modelPage(
            $request, $patientId, 'eob', $query, $validated,
            fn (PhrEob $eob): array => $finance ? $this->eobFinancePayload($eob) : $this->eobPayload($eob),
            fn (Collection $eobs) => $this->primeEobPublicIds($patientId, $eobs),
        );
    }

    public function eob(Request $request, int $patient, int $eob): JsonResponse
    {
        app(OAuthPatientConsents::class)->assertPatient($request, $patient);
        $patientId = $this->patientId($request, $patient);
        $finance = $this->financeProjection($request);
        $resolved = $this->eobQuery($patientId, $finance)->findOrFail($eob);

        return response()->json([
            'resource_type' => 'eob',
            'patient_id' => $patientId,
            'data' => $finance ? $this->eobFinancePayload($resolved) : $this->eobPayload($resolved),
        ]);
    }

    public function eobLines(Request $request, int $patient, int $eob): JsonResponse
    {
        $validated = $request->validate($this->pageRules());
        $patientId = $this->patientId($request, $patient);
        PhrEob::query()->where('patient_id', $patientId)->findOrFail($eob);
        $query = PhrEobLine::query()->where('patient_id', $patientId)->where('eob_id', $eob);
        AgentApiUpdateWindow::apply($query, $validated, $query->getModel()->qualifyColumn('patient_id'));

        return $this->modelPage(
            $request, $patientId, 'eob-line', $query, $validated,
            fn (PhrEobLine $line): array => $this->linePayload($line),
        );
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
    private function eobListRules(bool $finance): array
    {
        return $this->pageRules() + [
            'import_source' => $finance ? ['prohibited'] : ['sometimes', 'string', 'max:50'],
            'source_document_id' => $finance ? ['prohibited'] : ['sometimes', 'integer', 'min:1'],
            'claim_type' => $finance ? ['prohibited'] : ['sometimes', 'string', 'max:30'],
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
     * @param  (callable(Collection<int, TModel>): mixed)|null  $prime  reads what the page's serializer needs in bulk
     */
    private function modelPage(Request $request, int $patientId, string $type, Builder $query, array $validated, callable $serializer, ?callable $prime = null): JsonResponse
    {
        $limit = (int) ($validated['limit'] ?? 25);
        $page = $query->orderBy('id')->cursorPaginate(
            $limit,
            ['*'],
            'cursor',
            AgentApiCursor::decode(isset($validated['cursor']) ? (string) $validated['cursor'] : null),
        );
        if ($prime !== null) {
            $prime($page->getCollection());
        }

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

    /**
     * A credential with billing:read but not clinical:read sees EOBs only
     * through claim-level financial metadata, never claim numbers, importer
     * provenance, per-line detail, plan or member details.
     */
    private function financeProjection(Request $request): bool
    {
        return ! (bool) $request->user('api')?->tokenCan(AgentApiScopes::CLINICAL_READ);
    }

    /** @return Builder<PhrEob> */
    private function eobQuery(int $patientId, bool $finance): Builder
    {
        $query = PhrEob::query()->where('patient_id', $patientId);
        if (! $finance) {
            return $query->withCount('lines');
        }
        $lines = static fn (string $aggregate): Builder => PhrEobLine::query()
            ->selectRaw($aggregate)
            ->whereColumn('phr_eob_lines.eob_id', 'phr_eobs.id')
            ->whereColumn('phr_eob_lines.patient_id', 'phr_eobs.patient_id');

        return $query->with('sourceDocument:id,patient_id,file_hash')->select('phr_eobs.*')->addSelect([
            'finance_service_start' => $lines('MIN(phr_eob_lines.service_start)'),
            'finance_service_end' => $lines('MAX(COALESCE(phr_eob_lines.service_end, phr_eob_lines.service_start))'),
        ]);
    }

    /** @param  Collection<int, PhrEob>  $eobs */
    private function primeEobPublicIds(int $patientId, Collection $eobs): void
    {
        $this->publicIdCache[PhrPublicIds::PATIENTS][$patientId] = $this->publicIds->forPatient($patientId);
        $this->publicIdCache[PhrPublicIds::EOBS] = $this->publicIds->forRecords($patientId, PhrPublicIds::EOBS, $eobs->pluck('id'));
        $this->publicIdCache[PhrPublicIds::DOCUMENTS] = $this->publicIds->forRecords($patientId, PhrPublicIds::DOCUMENTS, $eobs->pluck('source_document_id'));
    }

    private function publicId(int $patientId, string $table, ?int $recordId): ?string
    {
        if ($recordId === null) {
            return null;
        }

        if (! array_key_exists($recordId, $this->publicIdCache[$table] ?? [])) {
            $this->publicIdCache[$table][$recordId] = $this->publicIds->forRecords($patientId, $table, [$recordId])[$recordId] ?? null;
        }

        return $this->publicIdCache[$table][$recordId];
    }

    /** @return array<string, mixed> */
    private function eobPayload(PhrEob $eob): array
    {
        $patientId = (int) $eob->patient_id;

        return [
            'id' => $eob->id, 'patient_id' => $eob->patient_id,
            'public_id' => $this->publicId($patientId, PhrPublicIds::EOBS, (int) $eob->id),
            'patient_public_id' => $this->publicId($patientId, PhrPublicIds::PATIENTS, $patientId),
            'source_document_id' => $eob->source_document_id,
            'source_document_public_id' => $this->publicId($patientId, PhrPublicIds::DOCUMENTS, $eob->source_document_id),
            'import_source' => $eob->import_source,
            'external_id' => AgentApiExternalId::withoutDocumentHash($eob->external_id),
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

    /**
     * The finance projection: only what a finance application needs to match
     * a claim to its payments.
     *
     * @return array<string, mixed>
     */
    private function eobFinancePayload(PhrEob $eob): array
    {
        $patientId = (int) $eob->patient_id;
        $document = $eob->sourceDocument;
        if ($document !== null && (int) $document->patient_id !== $patientId) {
            $document = null;
        }

        return [
            'id' => $eob->id, 'public_id' => $this->publicId($patientId, PhrPublicIds::EOBS, (int) $eob->id),
            'patient_public_id' => $this->publicId($patientId, PhrPublicIds::PATIENTS, $patientId),
            'updated_at' => $eob->updated_at?->toIso8601String(),
            'claim_category' => match ($eob->claim_type) {
                'dental', 'vision' => 'dental_vision',
                'pharmacy' => 'pharmacy',
                default => 'medical',
            },
            'service_start' => $this->date($eob->getAttribute('finance_service_start')),
            'service_end' => $this->date($eob->getAttribute('finance_service_end')),
            'processed_date' => $eob->processed_date?->toDateString(),
            'provider_name' => $eob->provider_name,
            'total_charges' => $eob->total_charges, 'total_accepted_fee' => $eob->total_accepted_fee,
            'total_plan_payment' => $eob->total_plan_payment,
            'total_deductible_applied' => $eob->total_deductible_applied,
            'total_copay_applied' => $eob->total_copay_applied,
            'total_patient_responsibility' => $eob->total_patient_responsibility,
            'currency' => null,
            'source_document_public_id' => $document === null ? null : $this->publicId($patientId, PhrPublicIds::DOCUMENTS, (int) $document->id),
            'source_document_sha256' => $document?->file_hash,
        ];
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value)->toDateString() : null;
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
            'medication-fill' => PhrMedicationFill::class, 'office-visit' => PhrOfficeVisit::class, 'procedure' => PhrProcedure::class,
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
            'medication-fill' => $this->fillLinkQueries($sourceId, $patientId),
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
                'medication-fills' => 'medication-fill',
                'conditions' => 'condition', 'allergies' => 'allergy',
                'lab-results' => 'lab-result', 'vitals' => 'vital',
                default => abort(500, 'Clinical search catalog is inconsistent.'),
            };
            // A raw query builder bypasses both the soft-delete scope and the
            // model-level retraction filter, so evidence links would keep
            // reporting IDs for records that no longer stand.
            $queries[$targetType] = DB::table((new $model)->getTable())
                ->where('patient_id', $patientId)
                ->whereNull('deleted_at')
                ->whereNull('retracted_at')
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
                ->whereNull('deleted_at')->whereNull('retracted_at')
                ->whereExists(fn ($pivot) => $pivot->selectRaw('1')->from('phr_office_visit_eobs')
                    ->where('patient_id', $patientId)->where('eob_id', $eobId)
                    ->whereColumn('office_visit_id', 'phr_office_visits.id')),
            'procedure' => DB::table('phr_procedures')->where('patient_id', $patientId)
                ->whereNull('deleted_at')->whereNull('retracted_at')
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

    /**
     * A fill links to the document it was read from; it has no EOB pivot.
     *
     * @return array<string, \Illuminate\Database\Query\Builder>
     */
    private function fillLinkQueries(int $fillId, int $patientId): array
    {
        $documentId = DB::table('phr_medication_fills')->where('patient_id', $patientId)->where('id', $fillId)->value('source_document_id');
        if ($documentId === null) {
            return [];
        }

        return ['document' => DB::table('phr_documents')->where('patient_id', $patientId)
            ->whereNull('deleted_at')->where('id', $documentId)];
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
