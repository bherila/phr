<?php

namespace App\Services\PHR\Review;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use App\Models\PassportClient;
use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrDicomStudy;
use App\Models\PhrDocument;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiSource;
use App\Models\PhrHealthLog;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientVital;
use App\Models\PhrProcedure;
use App\Services\PHR\Import\Ehi\EhiImportPresenter;
use App\Services\PHR\Import\PhrImportJobDao;
use App\Services\PHR\Import\PhrImportJobPresenter;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrRecordLifecycle;
use App\Support\PHR\PhrReviewStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What waits for a person's review in one patient's record, read side only.
 *
 * Three existing review flows feed it, and each keeps its own decision path:
 * clinical records in `pending_review` (decided on the browser review route of
 * their section), AI import proposals (PhrImportReviewService, as the Imports
 * page and the agent API's imports.review use) and EHI export proposals
 * (EhiProposalService). Nothing here writes; the Review page sends each
 * decision to the route that already owns it.
 *
 * The caller resolves the patient first. Every query is scoped to it and the
 * number of queries is fixed, not proportional to what is pending.
 */
final readonly class PhrReviewQueueService
{
    /** Clinical sections whose records carry a review status: page id => model. */
    public const array CLINICAL = [
        'labs' => PhrLabResult::class,
        'vitals' => PhrPatientVital::class,
        'office-visits' => PhrOfficeVisit::class,
        'medications' => PhrMedication::class,
        'conditions' => PhrCondition::class,
        'procedures' => PhrProcedure::class,
        'immunizations' => PhrImmunization::class,
        'allergies' => PhrAllergy::class,
    ];

    /** The browser review route's segment, where it differs from the page id. */
    public const array REVIEW_SEGMENTS = ['labs' => 'lab-results'];

    /** EHI mappings and the section their records land in. */
    public const array EHI_SECTIONS = ['immunization' => 'immunizations', 'document' => 'documents'];

    /** Rows listed per section, AI import or EHI export; the rest stay countable. */
    public const int ITEM_LIMIT = 200;

    private const array SOURCE_LABELS = [
        'ccda' => 'C-CDA import',
        'fhir' => 'FHIR import',
        'genai' => 'AI extraction',
    ];

    /** Importers that read a file with PHR's own parsers. */
    private const array PARSED_SOURCES = ['ccda', 'fhir'];

    public function __construct(
        private PhrImportJobDao $jobs,
        private PhrImportJobPresenter $jobPresenter,
        private EhiImportPresenter $ehiPresenter,
        private AgentClinicalRecordVersion $versions,
    ) {}

    /**
     * Pending counts per section and in all, with each section's record count,
     * in three queries.
     *
     * @return array{total: int, sections: array<string, array{pending: int, records: int}>, ai_results: int}
     */
    public function summary(PhrPatient $patient): array
    {
        $sections = $this->sectionCounts([(int) $patient->id])[(int) $patient->id] ?? [];
        $aiResults = $this->pendingAiResultQuery($patient)->count();
        $total = $aiResults;
        foreach ($sections as $counts) {
            $total += $counts['pending'];
        }

        return ['total' => $total, 'sections' => $sections, 'ai_results' => $aiResults];
    }

    /**
     * Per patient and section: records still standing and those waiting for review
     * (clinical records in pending_review, and reviewable EHI proposals for the
     * section their records land in). One union query plus one EHI aggregate,
     * however many patients and sections.
     *
     * @param  list<int>  $patientIds
     * @return array<int, array<string, array{pending: int, records: int}>>
     */
    public function sectionCounts(array $patientIds): array
    {
        if ($patientIds === []) {
            return [];
        }

        $parts = [];
        foreach (self::CLINICAL as $page => $model) {
            $query = $model::query()->whereIn('patient_id', $patientIds)->where('review_status', '!=', PhrReviewStatus::REJECTED);
            PhrRecordLifecycle::scopeLive($query);
            $parts[] = $query->toBase()->selectRaw(
                'patient_id, ? as section, count(*) as records, sum(case when review_status = ? then 1 else 0 end) as pending',
                [$page, PhrReviewStatus::PENDING],
            )->groupBy('patient_id');
        }
        foreach (['health-log' => PhrHealthLog::class, 'documents' => PhrDocument::class, 'imaging' => PhrDicomStudy::class] as $page => $model) {
            $parts[] = $model::query()->whereIn('patient_id', $patientIds)->toBase()
                ->selectRaw('patient_id, ? as section, count(*) as records, 0 as pending', [$page])
                ->groupBy('patient_id');
        }
        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $counts = [];
        foreach ($union->get() as $row) {
            $counts[(int) $row->patient_id][(string) $row->section] = ['pending' => (int) $row->pending, 'records' => (int) $row->records];
        }

        $this->pendingEhiProposals()->whereIn('patient_id', $patientIds)
            ->selectRaw('patient_id, mapping, count(*) as aggregate')
            ->groupBy('patient_id', 'mapping')
            ->toBase()->get()
            ->each(function (object $row) use (&$counts): void {
                $section = self::EHI_SECTIONS[(string) $row->mapping] ?? null;
                if ($section !== null) {
                    $counts[(int) $row->patient_id][$section] ??= ['pending' => 0, 'records' => 0];
                    $counts[(int) $row->patient_id][$section]['pending'] += (int) $row->aggregate;
                }
            });

        return $counts;
    }

    /**
     * Every pending item, grouped by where it came from: records by import source
     * and source document, one group per AI import, one per EHI export.
     *
     * @return list<array<string, mixed>>
     */
    public function groups(PhrPatient $patient): array
    {
        return [...$this->recordGroups($patient), ...$this->aiGroups($patient), ...$this->ehiGroups($patient)];
    }

    /** @return list<array<string, mixed>> */
    private function recordGroups(PhrPatient $patient): array
    {
        $rows = [];
        foreach (self::CLINICAL as $page => $model) {
            $query = $model::query()->where('patient_id', $patient->id)->where('review_status', PhrReviewStatus::PENDING);
            PhrRecordLifecycle::scopeLive($query);
            $records = $query->orderByDesc('updated_at')->orderByDesc('id')->limit(self::ITEM_LIMIT + 1)->get();
            foreach ($records->take(self::ITEM_LIMIT) as $record) {
                $rows[] = [$page, $record];
            }
            if ($records->count() > self::ITEM_LIMIT) {
                $rows[] = [$page, null];
            }
        }

        $documentIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?int => $row[1]?->getAttribute('source_document_id'),
            $rows,
        ))));
        $documents = $documentIds === [] ? collect() : PhrDocument::query()->where('patient_id', $patient->id)
            ->whereIn('id', $documentIds)->pluck('title', 'id');
        $clientIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?string => self::agentClientId($row[1]?->getAttribute('import_source')),
            $rows,
        ))));
        $clients = $clientIds === [] ? collect() : PassportClient::query()->whereIn('id', $clientIds)->pluck('name', 'id');

        $groups = [];
        $truncated = [];
        foreach ($rows as [$page, $record]) {
            if ($record === null) {
                $truncated[] = $page;

                continue;
            }
            $source = $record->getAttribute('import_source');
            $documentId = $record->getAttribute('source_document_id');
            $key = 'records:'.($source ?? '').':'.($documentId ?? '');
            $clientId = self::agentClientId($source);
            $groups[$key] ??= [
                'key' => $key,
                'origin' => 'records',
                'label' => $this->sourceLabel($source),
                'name' => $documentId !== null ? ($documents[$documentId] ?? null) : ($clientId !== null ? ($clients[$clientId] ?? null) : null),
                'parsed_by_phr' => in_array($source, self::PARSED_SOURCES, true),
                'ai_read' => $source === 'genai',
                'document_id' => $documentId !== null && isset($documents[$documentId]) ? (int) $documentId : null,
                'date' => null,
                'items' => [],
            ];
            $updated = $record->getAttribute('updated_at');
            if ($updated instanceof CarbonInterface && ($groups[$key]['date'] === null || $updated->toIso8601String() > $groups[$key]['date'])) {
                $groups[$key]['date'] = $updated->toIso8601String();
            }
            $groups[$key]['items'][] = $this->recordItem($page, $record);
        }

        return array_map(static fn (array $group): array => [
            ...$group,
            'total' => count($group['items']),
            // A section with more pending rows than listed says so on every group,
            // since the unlisted rows may belong to any of them.
            'truncated_sections' => $truncated,
        ], array_values($groups));
    }

    /** @return array<string, mixed> */
    private function recordItem(string $page, Model $record): array
    {
        [$title, $detail] = $this->describe($page, $record);
        $created = $record->getAttribute('created_at');
        $updated = $record->getAttribute('updated_at');

        return [
            'type' => 'record',
            'key' => "record:{$page}:{$record->getKey()}",
            'id' => (int) $record->getKey(),
            'section' => $page,
            'review_segment' => self::REVIEW_SEGMENTS[$page] ?? $page,
            'title' => $title,
            'detail' => $detail,
            // A record changed after it was added was updated by its source (or an
            // agent), which reopened its review. Earlier values are not kept, so
            // there is no field-level difference to show.
            'change' => $created instanceof CarbonInterface && $updated instanceof CarbonInterface && $updated->greaterThan($created) ? 'update' : 'new',
            'version' => $this->versions->for($record),
            'updated_at' => $updated instanceof CarbonInterface ? $updated->toIso8601String() : null,
        ];
    }

    /** @return array{0: string, 1: string|null} */
    private function describe(string $page, Model $record): array
    {
        $a = static fn (string $field): mixed => $record->getAttribute($field);

        return match ($page) {
            'labs' => [
                (string) ($a('analyte') ?: $a('test_name') ?: 'Lab result'),
                $this->join([$this->measure($a('value'), $a('unit')), $a('test_name') !== $a('analyte') ? $a('test_name') : null, $this->date($a('result_datetime') ?? $a('collection_datetime'))]),
            ],
            'vitals' => [(string) ($a('vital_name') ?: 'Vital'), $this->join([$this->measure($a('vital_value'), $a('unit')), $this->date($a('observed_at') ?? $a('vital_date'))])],
            'office-visits' => [
                (string) ($a('visit_type') ?: $a('chief_complaint') ?: 'Visit'),
                $this->join([$this->date($a('visit_started_at') ?? $a('visit_date')), $a('provider_name'), $a('facility_name')]),
            ],
            'medications' => [(string) ($a('name') ?: 'Medication'), $this->join([$this->measure($a('dose'), $a('dose_unit')), $a('frequency'), $a('route')])],
            'conditions' => [
                (string) ($a('name') ?: 'Condition'),
                $this->join([$a('icd10_code') ? 'ICD-10 '.$a('icd10_code') : null, $a('onset_date') ? 'onset '.$this->date($a('onset_date')) : null]),
            ],
            'procedures' => [(string) ($a('name') ?: 'Procedure'), $this->join([$this->date($a('performed_at') ?? $a('performed_on')), $a('performer_name')])],
            'immunizations' => [(string) ($a('vaccine_name') ?: 'Immunization'), $this->join([$this->date($a('administered_on')), $a('dose_number') ? 'dose '.$a('dose_number') : null])],
            'allergies' => [(string) ($a('substance') ?: 'Allergy'), $this->join([$a('reaction'), $a('severity')])],
            default => [Str::headline($page), null],
        };
    }

    /** @return list<array<string, mixed>> */
    private function aiGroups(PhrPatient $patient): array
    {
        $results = $this->pendingAiResultQuery($patient)
            ->orderByDesc('job_id')->orderBy('result_index')->orderBy('id')
            ->get();
        if ($results->isEmpty()) {
            return [];
        }
        $jobs = $this->jobs->forPatient($patient)->whereIn('id', $results->pluck('job_id')->unique()->values())
            ->with('sourceDocument:id,patient_id,genai_job_id,deleted_at,title,original_filename')
            ->get()->keyBy('id');

        $groups = [];
        foreach ($results->groupBy('job_id') as $jobId => $jobResults) {
            $job = $jobs->get($jobId);
            if (! $job instanceof GenAiImportJob) {
                continue;
            }
            $document = $job->sourceDocument;
            $groups[] = [
                'key' => "ai:{$job->id}",
                'origin' => 'ai',
                'label' => 'AI extraction',
                'name' => $document->title ?? $document->original_filename ?? null,
                'parsed_by_phr' => false,
                'ai_read' => true,
                'execution_mode' => $job->execution_mode,
                'document_id' => $document !== null && $document->deleted_at === null ? (int) $document->id : null,
                'date' => ($job->parsed_at ?? $job->created_at)?->toIso8601String(),
                'total' => $jobResults->count(),
                'truncated_sections' => [],
                'items' => $jobResults->take(self::ITEM_LIMIT)->map(fn (GenAiImportResult $result): array => [
                    'type' => 'ai_result',
                    'key' => "ai_result:{$result->id}",
                    'id' => (int) $result->id,
                    'import_id' => (int) $job->id,
                    'job_type' => $job->job_type,
                    'result' => $this->jobPresenter->result($result),
                ])->values()->all(),
            ];
        }

        return $groups;
    }

    /** @return list<array<string, mixed>> */
    private function ehiGroups(PhrPatient $patient): array
    {
        $totals = $this->pendingEhiProposals()->where('patient_id', $patient->id)
            ->selectRaw('package_id, count(*) as aggregate')->groupBy('package_id')
            ->toBase()->pluck('aggregate', 'package_id');
        if ($totals->isEmpty()) {
            return [];
        }
        $packages = PhrEhiPackage::query()->where('patient_id', $patient->id)->whereIn('id', $totals->keys())->orderByDesc('id')->get();
        $sources = PhrEhiSource::query()->whereIn('id', $packages->pluck('source_id')->filter()->unique()->values())->pluck('label', 'id');
        // Ids alone, then the first rows of each package: two queries however
        // many packages there are, without loading every proposal's payload.
        $firstIds = [];
        $this->pendingEhiProposals()->where('patient_id', $patient->id)->whereIn('package_id', $packages->pluck('id'))
            ->orderBy('id')->toBase()->get(['phr_ehi_proposals.id', 'phr_ehi_proposals.package_id'])
            ->each(function (object $row) use (&$firstIds): void {
                if (count($firstIds[(int) $row->package_id] ?? []) < self::ITEM_LIMIT) {
                    $firstIds[(int) $row->package_id][] = (int) $row->id;
                }
            });
        $proposals = PhrEhiProposal::query()->where('patient_id', $patient->id)
            ->whereIn('id', array_merge([], ...array_values($firstIds)))
            ->orderBy('id')->get()->groupBy('package_id');

        return $packages->map(fn (PhrEhiPackage $package): array => [
            'key' => "ehi:{$package->id}",
            'origin' => 'ehi',
            'label' => 'EHI export',
            'name' => $package->source_id !== null ? ($sources[$package->source_id] ?? null) : null,
            'parsed_by_phr' => true,
            'ai_read' => false,
            'package_id' => (int) $package->id,
            'document_id' => null,
            'date' => $package->created_at?->toIso8601String(),
            'total' => (int) $totals[$package->id],
            'truncated_sections' => [],
            'items' => ($proposals->get($package->id) ?? collect())->map(fn (PhrEhiProposal $proposal): array => [
                'type' => 'ehi_proposal',
                'key' => "ehi_proposal:{$proposal->id}",
                'id' => (int) $proposal->id,
                'package_id' => (int) $package->id,
                'section' => self::EHI_SECTIONS[$proposal->mapping] ?? 'documents',
                'proposal' => $this->ehiPresenter->proposal($proposal, true),
            ])->values()->all(),
        ])->values()->all();
    }

    /** @return Builder<GenAiImportResult> */
    private function pendingAiResultQuery(PhrPatient $patient): Builder
    {
        return GenAiImportResult::query()
            ->where('status', 'pending_review')
            ->whereIn('job_id', $this->jobs->forPatient($patient)->select('id'));
    }

    /**
     * Pending proposals of a completed preview: the only ones a reviewer can decide.
     *
     * @return Builder<PhrEhiProposal>
     */
    private function pendingEhiProposals(): Builder
    {
        return PhrEhiProposal::query()
            ->where('status', PhrEhiProposal::STATUS_PENDING)
            ->tap(static fn (Builder $query) => PhrEhiProposal::constrainToCompletedPreview($query->getQuery()));
    }

    private function sourceLabel(?string $source): string
    {
        return match (true) {
            $source === null || $source === '' => 'Added in PHR',
            isset(self::SOURCE_LABELS[$source]) => self::SOURCE_LABELS[$source],
            self::agentClientId($source) !== null => 'From a connected app',
            str_contains($source, 'eob') || str_contains($source, 'meritain') || str_contains($source, 'delta') => 'Insurance claim import',
            default => 'Import',
        };
    }

    private static function agentClientId(mixed $source): ?string
    {
        return is_string($source) && str_starts_with($source, 'agent-client:') ? substr($source, strlen('agent-client:')) : null;
    }

    private function measure(mixed $value, mixed $unit): ?string
    {
        $text = trim(((string) ($value ?? '')).' '.((string) ($unit ?? '')));

        return $text === '' ? null : $text;
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->format('M j, Y') : null;
    }

    /** @param  list<mixed>  $parts */
    private function join(array $parts): ?string
    {
        $text = implode(' · ', array_filter(array_map(static fn (mixed $part): string => is_scalar($part) ? trim((string) $part) : '', $parts), static fn (string $part): bool => $part !== ''));

        return $text === '' ? null : $text;
    }
}
