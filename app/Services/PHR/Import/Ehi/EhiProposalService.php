<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrDocument;
use App\Models\PhrEhiBlob;
use App\Models\PhrEhiFinding;
use App\Models\PhrEhiOccurrence;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiProposalDecision;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Models\PhrEhiSource;
use App\Models\PhrEhiTableAccount;
use App\Models\PhrImmunization;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyOutcome;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyRefused;
use App\Services\PHR\Import\Ehi\Mapping\EhiCanonicalJson;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingRegistry;
use App\Services\PHR\Import\Ehi\Mapping\EhiProposalDraft;
use App\Services\PHR\Import\Ehi\Mapping\EhiPublishedObjects;
use App\Services\PHR\Import\Ehi\Mapping\EhiTargetDigest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

/**
 * Persisted preview and review of EHI proposals: the single application service
 * behind the CLI (and, from M4, REST/MCP).
 *
 * Preview projects the package if needed, runs every registered mapping, and
 * upserts its drafts on (package, mapping, subject). Each proposal carries a
 * digest of everything a reviewer is shown, including the target record as it
 * was read. A decided proposal is never rewritten; a pending one a mapping no
 * longer produces is superseded.
 *
 * Review consumes that persisted preview. Under the patient artifact guard it
 * re-checks write access, locks the proposal, and refuses (EhiReviewRefused,
 * nothing written) unless the proposal is still pending, the caller's preview
 * digest is current, every source revision still matches, and the target is
 * live and unchanged. Accepting writes the clinical record through the mapping
 * and an evidence link per revision; rejecting or retaining writes no clinical
 * row. Every decision appends to the proposal's history. A storage object a
 * mapping published for a review whose transaction did not commit (a refusal,
 * a later failure, or a retried attempt) is removed once the transaction ends,
 * unless a document or EHI blob row references it.
 */
final class EhiProposalService
{
    /** detail_json.stage on every finding a preview writes. */
    public const string FINDING_STAGE = 'preview';

    /**
     * Clinical tables a proposal may target, with the evidence-link and
     * decision-history column each one uses.
     */
    public const array TARGETS = [
        'phr_immunizations' => ['model' => PhrImmunization::class, 'link' => 'immunization_id', 'decision' => 'linked_immunization_id'],
        'phr_documents' => ['model' => PhrDocument::class, 'link' => 'document_id', 'decision' => 'linked_document_id'],
    ];

    public const array KINDS = [
        PhrEhiProposal::KIND_CREATE,
        PhrEhiProposal::KIND_UPDATE,
        PhrEhiProposal::KIND_CORROBORATE,
        PhrEhiProposal::KIND_POSSIBLE_DUPLICATE,
        PhrEhiProposal::KIND_RETAIN,
    ];

    /** kind => decisions a reviewer may record for it. */
    public const array ALLOWED_DECISIONS = [
        PhrEhiProposal::KIND_CREATE => [PhrEhiProposal::DECISION_ACCEPT, PhrEhiProposal::DECISION_LINK, PhrEhiProposal::DECISION_REJECT, PhrEhiProposal::DECISION_RETAIN],
        PhrEhiProposal::KIND_POSSIBLE_DUPLICATE => [PhrEhiProposal::DECISION_ACCEPT, PhrEhiProposal::DECISION_LINK, PhrEhiProposal::DECISION_REJECT, PhrEhiProposal::DECISION_RETAIN],
        PhrEhiProposal::KIND_UPDATE => [PhrEhiProposal::DECISION_APPROVE_UPDATE, PhrEhiProposal::DECISION_REJECT, PhrEhiProposal::DECISION_RETAIN],
        PhrEhiProposal::KIND_CORROBORATE => [PhrEhiProposal::DECISION_ACCEPT, PhrEhiProposal::DECISION_REJECT, PhrEhiProposal::DECISION_RETAIN],
        PhrEhiProposal::KIND_RETAIN => [PhrEhiProposal::DECISION_RETAIN, PhrEhiProposal::DECISION_REJECT],
    ];

    public const array DECISIONS = [
        PhrEhiProposal::DECISION_ACCEPT,
        PhrEhiProposal::DECISION_APPROVE_UPDATE,
        PhrEhiProposal::DECISION_LINK,
        PhrEhiProposal::DECISION_REJECT,
        PhrEhiProposal::DECISION_RETAIN,
    ];

    private const array SEVERITIES = [PhrEhiFinding::SEVERITY_INFO, PhrEhiFinding::SEVERITY_WARNING, PhrEhiFinding::SEVERITY_ERROR];

    private const int CHUNK = 200;

    public function __construct(
        private readonly PhrPatientArtifactWriteGuard $writeGuard,
        private readonly PhrPatientAccessService $access,
        private readonly EhiMappingRegistry $registry,
        private readonly EhiProjectionService $projection,
    ) {}

    /**
     * @throws EhiProjectionRefused the package cannot be projected
     * @throws AuthorizationException the actor cannot (or can no longer) write this patient
     * @throws ModelNotFoundException the package does not belong to the patient
     */
    public function preview(PhrEhiPackage $package, PhrPatient $patient, int $actorUserId): EhiPreview
    {
        $this->access->ensureCanWrite($patient, $actorUserId);
        if ((int) $package->patient_id !== (int) $patient->id) {
            throw (new ModelNotFoundException)->setModel(PhrEhiPackage::class, [$package->id]);
        }
        $projection = $this->projection->project($package, $actorUserId);
        $package = $projection->package;
        $source = PhrEhiSource::query()->whereKey($package->source_id)->where('patient_id', $patient->id)->firstOrFail();

        $counts = [];
        $findingCounts = [];
        foreach ($this->registry->all() as $name => $mapping) {
            $context = new EhiMappingContext($package, $patient, $source);
            $rows = [];
            foreach ($mapping->propose($context) as $draft) {
                if (isset($rows[$draft->subjectKey])) {
                    throw new LogicException("EHI mapping {$name} proposed one subject twice.");
                }
                $rows[$draft->subjectKey] = $this->row($package, $name, $mapping->version(), $draft);
                $counts[$name][$draft->kind] = ($counts[$name][$draft->kind] ?? 0) + 1;
            }
            $counts[$name] ??= [];
            ksort($counts[$name], SORT_STRING);

            $this->persist($package, $actorUserId, $name, $rows);
            foreach ($this->persistFindings($package, $actorUserId, $name, $context->findings()) as $code => $count) {
                $findingCounts[$code] = ($findingCounts[$code] ?? 0) + $count;
            }
        }
        $this->supersedeUnregistered($package, $actorUserId);
        ksort($findingCounts, SORT_STRING);

        $statuses = PhrEhiProposal::query()->where('package_id', $package->id)
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)->sortKeys()->all();
        $digests = PhrEhiProposal::query()->where('package_id', $package->id)
            ->where('status', '!=', PhrEhiProposal::STATUS_SUPERSEDED)->pluck('preview_digest')
            ->map(static fn (mixed $digest): string => (string) $digest)->sort(SORT_STRING)->values()->all();

        return new EhiPreview($package, $projection, $counts, $statuses, $findingCounts, EhiCanonicalJson::digest($digests));
    }

    /**
     * @param  string  $decision  one of self::DECISIONS
     * @param  string  $previewDigest  the proposal preview digest the reviewer saw
     * @param  int|null  $linkTargetId  the existing record for DECISION_LINK
     *
     * @throws EhiReviewRefused nothing was written
     * @throws AuthorizationException the actor cannot (or can no longer) write this patient
     */
    public function review(PhrEhiProposal $proposal, string $decision, int $actorUserId, string $previewDigest, ?int $linkTargetId = null): PhrEhiProposal
    {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new EhiReviewRefused(EhiReviewRefused::DECISION_NOT_ALLOWED, 'the decision is not recognized');
        }
        if (($decision === PhrEhiProposal::DECISION_LINK) !== ($linkTargetId !== null)) {
            throw new EhiReviewRefused(EhiReviewRefused::LINK_TARGET_REQUIRED, 'a record to link is required for, and only for, link_existing');
        }

        $published = new EhiPublishedObjects;
        try {
            return $this->decide($proposal, $decision, $actorUserId, $previewDigest, $linkTargetId, $published);
        } finally {
            $this->removeUnreferenced($published);
        }
    }

    private function decide(PhrEhiProposal $proposal, string $decision, int $actorUserId, string $previewDigest, ?int $linkTargetId, EhiPublishedObjects $published): PhrEhiProposal
    {
        return $this->writeGuard->run((int) $proposal->patient_id, function (PhrPatient $patient) use ($proposal, $decision, $actorUserId, $previewDigest, $linkTargetId, $published): PhrEhiProposal {
            $this->access->ensureCanWrite($patient, $actorUserId);
            $current = PhrEhiProposal::query()->whereKey($proposal->id)->where('patient_id', $patient->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== PhrEhiProposal::STATUS_PENDING) {
                if ($this->alreadyDecided($current, $decision, $linkTargetId)) {
                    return $current;
                }

                throw new EhiReviewRefused(EhiReviewRefused::NOT_PENDING, 'the proposal is not pending');
            }
            if (! hash_equals($current->preview_digest, $previewDigest)) {
                throw new EhiReviewRefused(EhiReviewRefused::STALE_PREVIEW, 'the proposal changed since it was previewed');
            }
            if (! in_array($decision, self::ALLOWED_DECISIONS[$current->kind] ?? [], true)) {
                throw new EhiReviewRefused(EhiReviewRefused::DECISION_NOT_ALLOWED, 'the decision is not allowed for this proposal kind');
            }
            $this->verifyRevisions($current);

            $outcome = null;
            if (in_array($decision, [PhrEhiProposal::DECISION_ACCEPT, PhrEhiProposal::DECISION_APPROVE_UPDATE, PhrEhiProposal::DECISION_LINK], true)) {
                $outcome = $this->apply($current, $patient, $decision, $actorUserId, $linkTargetId, $published);
            }

            $now = now();
            PhrEhiProposalDecision::query()->create([
                'patient_id' => $patient->id,
                'proposal_id' => $current->id,
                'actor_user_id' => $actorUserId,
                'decision' => $decision,
                'previous_status' => $current->status,
                'mapping_version' => $current->mapping_version,
                'revision_digest' => $current->revision_digest,
                'linked_immunization_id' => $outcome?->recordTable === 'phr_immunizations' ? $outcome->recordId : null,
                'linked_document_id' => $outcome?->recordTable === 'phr_documents' ? $outcome->recordId : null,
            ]);
            $current->forceFill([
                'status' => match ($decision) {
                    PhrEhiProposal::DECISION_ACCEPT, PhrEhiProposal::DECISION_APPROVE_UPDATE => PhrEhiProposal::STATUS_ACCEPTED,
                    PhrEhiProposal::DECISION_LINK => PhrEhiProposal::STATUS_LINKED,
                    PhrEhiProposal::DECISION_REJECT => PhrEhiProposal::STATUS_REJECTED,
                    default => PhrEhiProposal::STATUS_RETAINED,
                },
                'decided_by_user_id' => $actorUserId,
                'decided_at' => $now,
            ])->save();
            $this->settleOccurrences($current, $outcome, $now);

            return $current;
        });
    }

    /**
     * Run the mapping's apply() against a locked, live, unchanged target and link
     * every revision to the record it produced or chose.
     */
    private function apply(PhrEhiProposal $proposal, PhrPatient $patient, string $decision, int $actorUserId, ?int $linkTargetId, EhiPublishedObjects $published): EhiApplyOutcome
    {
        $mapping = $this->registry->get($proposal->mapping);
        if ($mapping === null || $mapping->version() !== $proposal->mapping_version) {
            throw new EhiReviewRefused(EhiReviewRefused::STALE_MAPPING, 'the mapping that produced the proposal is unavailable or changed');
        }

        if ($proposal->target_table !== null || $proposal->target_id !== null) {
            $target = $this->lockLive($patient, (string) $proposal->target_table, (int) $proposal->target_id);
            if ($proposal->target_digest === null || ! hash_equals($proposal->target_digest, EhiTargetDigest::of($target))) {
                throw new EhiReviewRefused(EhiReviewRefused::STALE_TARGET, 'the target record changed since the preview');
            }
        }

        $now = now();
        try {
            $outcome = $mapping->apply($proposal, new EhiApplyContext($patient, $actorUserId, $decision, $linkTargetId, $now, $published));
        } catch (Throwable $refused) {
            // Thrown inside the review transaction, so the mapping's writes roll back.
            $reason = self::applyRefusalReason($refused);
            if ($reason === null) {
                throw $refused;
            }

            throw new EhiReviewRefused($reason, 'the mapping refused to apply the proposal', $refused);
        }

        $expected = match (true) {
            $decision === PhrEhiProposal::DECISION_LINK => PhrEhiRecordLink::KIND_BRIDGED,
            $proposal->kind === PhrEhiProposal::KIND_CORROBORATE => PhrEhiRecordLink::KIND_CORROBORATING,
            default => PhrEhiRecordLink::KIND_PROJECTED,
        };
        if ($outcome->linkKind !== $expected || ! isset(self::TARGETS[$outcome->recordTable])) {
            throw new LogicException('EHI mapping returned an outcome its decision does not allow.');
        }
        if ($decision === PhrEhiProposal::DECISION_LINK && $outcome->recordId !== $linkTargetId) {
            throw new LogicException('EHI mapping linked a record other than the one chosen.');
        }
        if ($proposal->target_id !== null && $decision !== PhrEhiProposal::DECISION_LINK
            && ($outcome->recordTable !== $proposal->target_table || $outcome->recordId !== $proposal->target_id)) {
            throw new LogicException('EHI mapping applied to a record other than the previewed target.');
        }
        // Whatever the mapping wrote or chose must be a live record of this patient.
        $this->lockLive($patient, $outcome->recordTable, $outcome->recordId);

        $column = self::TARGETS[$outcome->recordTable]['link'];
        $links = array_map(static fn (int $revisionId): array => [
            'patient_id' => $patient->id,
            'revision_id' => $revisionId,
            'proposal_id' => $proposal->id,
            'immunization_id' => $column === 'immunization_id' ? $outcome->recordId : null,
            'document_id' => $column === 'document_id' ? $outcome->recordId : null,
            'link_kind' => $outcome->linkKind,
            'mapping_version' => $proposal->mapping_version,
            'created_by_user_id' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->revisionIds($proposal));
        PhrEhiRecordLink::query()->insertOrIgnore($links);

        return $outcome;
    }

    /**
     * Once the review transaction has ended, delete each object a mapping
     * published for it that no committed row references: the copy of a
     * rolled-back (or retried) attempt would otherwise be orphaned. Only objects
     * this review wrote, on the document disk, are considered; a key any document
     * (deleted or not) or EHI blob row names is never touched. Best effort: the
     * review's own result or failure is what the caller sees.
     */
    private function removeUnreferenced(EhiPublishedObjects $published): void
    {
        foreach ($published->all() as $object) {
            try {
                if ($object['disk'] !== PhrDocument::STORAGE_DISK
                    || PhrDocument::withTrashed()->where('storage_disk', $object['disk'])->where('storage_path', $object['path'])->exists()
                    || PhrEhiBlob::query()->where('storage_disk', $object['disk'])->where('storage_path', $object['path'])->exists()) {
                    continue;
                }
                Storage::disk($object['disk'])->delete($object['path']);
            } catch (Throwable) {
                // An object that cannot be removed now is left for storage reconciliation.
            }
        }
    }

    /**
     * The fixed reason of a mapping's apply refusal, or null for any other failure.
     * EhiApplyRefused is the contract; a mapping-namespace exception carrying a
     * string `reason` is accepted too. A reason that is not a fixed snake_case
     * code is reported as apply_refused rather than echoed.
     */
    private static function applyRefusalReason(Throwable $failure): ?string
    {
        $reason = match (true) {
            $failure instanceof EhiApplyRefused => $failure->reason,
            str_starts_with($failure::class, 'App\\Services\\PHR\\Import\\Ehi\\Mapping\\')
                && property_exists($failure, 'reason') && is_string($failure->reason) => $failure->reason,
            default => null,
        };
        if ($reason === null) {
            return null;
        }

        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reason) === 1 ? $reason : EhiReviewRefused::APPLY_REFUSED;
    }

    /**
     * Lock a clinical record including soft-deleted rows, so a withdrawn record
     * is refused rather than invisible (and never resurrected).
     */
    private function lockLive(PhrPatient $patient, string $table, int $id): Model
    {
        $class = self::TARGETS[$table]['model'] ?? null;
        if ($class === null) {
            throw new EhiReviewRefused(EhiReviewRefused::TARGET_WITHDRAWN, 'the target is not a supported record');
        }
        $record = $class::query()->withoutGlobalScope(SoftDeletingScope::class)->whereKey($id)->lockForUpdate()->first();
        if (! $record instanceof Model
            || (int) $record->getAttribute('patient_id') !== (int) $patient->id
            || $record->getAttribute('deleted_at') !== null
            || $record->getAttribute('retracted_at') !== null) {
            throw new EhiReviewRefused(EhiReviewRefused::TARGET_WITHDRAWN, 'the target record is gone, deleted, or retracted');
        }

        return $record;
    }

    /** Every relied-on revision still exists for this patient and hashes as previewed. */
    private function verifyRevisions(PhrEhiProposal $proposal): void
    {
        $ids = $this->revisionIds($proposal);
        $revisions = PhrEhiRevision::query()->where('patient_id', $proposal->patient_id)->whereIn('id', $ids)->get(['id', 'content_digest']);
        if ($ids === [] || $revisions->count() !== count($ids)
            || ! hash_equals($proposal->revision_digest, $this->revisionDigest($revisions->pluck('content_digest')->all()))) {
            throw new EhiReviewRefused(EhiReviewRefused::STALE_SOURCE, 'the source evidence changed since the preview');
        }
    }

    private function alreadyDecided(PhrEhiProposal $proposal, string $decision, ?int $linkTargetId): bool
    {
        $last = PhrEhiProposalDecision::query()->where('proposal_id', $proposal->id)->orderByDesc('id')->first();
        if ($last === null || $last->decision !== $decision) {
            return false;
        }

        return $decision !== PhrEhiProposal::DECISION_LINK
            || in_array($linkTargetId, [$last->linked_immunization_id, $last->linked_document_id], true);
    }

    /**
     * Give this package's occurrences of the decided revisions their reviewed
     * disposition (never lifting a quarantine) and reconcile their accounts.
     */
    private function settleOccurrences(PhrEhiProposal $proposal, ?EhiApplyOutcome $outcome, Carbon $now): void
    {
        $disposition = match (true) {
            $outcome === null => PhrEhiOccurrence::DISPOSITION_RETAINED,
            $outcome->linkKind === PhrEhiRecordLink::KIND_PROJECTED => PhrEhiOccurrence::DISPOSITION_PROJECTED,
            default => PhrEhiOccurrence::DISPOSITION_CORROBORATING,
        };
        $occurrences = PhrEhiOccurrence::query()->where('package_id', $proposal->package_id)
            ->whereIn('revision_id', $this->revisionIds($proposal))
            ->where('disposition', '!=', PhrEhiOccurrence::DISPOSITION_QUARANTINED);
        $members = (clone $occurrences)->distinct()->pluck('member_id')->all();
        $occurrences->update(['disposition' => $disposition, 'updated_at' => $now]);

        PhrEhiTableAccount::query()->where('package_id', $proposal->package_id)->whereIn('member_id', $members)
            ->orderBy('id')->each(static fn (PhrEhiTableAccount $account) => EhiDispositionCounter::recount($account));
    }

    /**
     * The persisted form of one draft, with its preview digest.
     *
     * @return array<string, mixed>
     */
    private function row(PhrEhiPackage $package, string $mapping, string $version, EhiProposalDraft $draft): array
    {
        if ($draft->subjectKey === '' || strlen($draft->subjectKey) > 64
            || ! in_array($draft->kind, self::KINDS, true)
            || $draft->revisionIds === []
            || ($draft->targetTable === null) !== ($draft->targetId === null)
            || ($draft->targetTable !== null && ! isset(self::TARGETS[$draft->targetTable]))) {
            throw new LogicException("EHI mapping {$mapping} produced an invalid draft.");
        }

        $revisionIds = array_values(array_unique(array_map('intval', $draft->revisionIds)));
        sort($revisionIds);
        $revisions = PhrEhiRevision::query()
            ->join('phr_ehi_entities', 'phr_ehi_entities.id', '=', 'phr_ehi_revisions.entity_id')
            ->where('phr_ehi_revisions.patient_id', $package->patient_id)
            ->where('phr_ehi_entities.source_id', $package->source_id)
            ->whereIn('phr_ehi_revisions.id', $revisionIds)
            ->pluck('phr_ehi_revisions.content_digest');
        if ($revisions->count() !== count($revisionIds)) {
            throw new LogicException("EHI mapping {$mapping} relied on a revision outside this package's source.");
        }

        $targetDigest = null;
        if ($draft->targetTable !== null) {
            $class = self::TARGETS[$draft->targetTable]['model'];
            $target = $class::query()->withoutGlobalScope(SoftDeletingScope::class)
                ->where('patient_id', $package->patient_id)->whereKey($draft->targetId)->first();
            $targetDigest = $target instanceof Model ? EhiTargetDigest::of($target) : null;
        }

        $candidates = array_values(array_unique(array_map('intval', $draft->candidateIds)));
        sort($candidates);
        $row = [
            'mapping' => $mapping,
            'mapping_version' => $version,
            'subject_key' => $draft->subjectKey,
            'kind' => $draft->kind,
            'revision_ids_json' => $revisionIds,
            'revision_digest' => $this->revisionDigest($revisions->all()),
            'target_table' => $draft->targetTable,
            'target_id' => $draft->targetId,
            'target_digest' => $targetDigest,
            'proposed_json' => $draft->proposed,
            'before_json' => $draft->before,
            'candidate_ids_json' => $candidates === [] ? null : $candidates,
        ];

        return [...$row, 'preview_digest' => EhiCanonicalJson::digest($row)];
    }

    /**
     * Upsert one mapping's drafts in bounded transactions, then supersede its
     * pending proposals it no longer produces. Decided rows are never touched.
     *
     * @param  array<string, array<string, mixed>>  $rows  subject key => row
     */
    private function persist(PhrEhiPackage $package, int $actorUserId, string $mapping, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK, true) as $chunk) {
            $this->writeGuard->run((int) $package->patient_id, function (PhrPatient $patient) use ($package, $actorUserId, $mapping, $chunk): void {
                $this->access->ensureCanWrite($patient, $actorUserId);
                $existing = PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', $mapping)
                    ->whereIn('subject_key', array_keys($chunk))->lockForUpdate()->get()->keyBy('subject_key');
                foreach ($chunk as $subject => $row) {
                    $proposal = $existing->get($subject);
                    if ($proposal === null) {
                        PhrEhiProposal::query()->create([...$row, 'patient_id' => $patient->id, 'package_id' => $package->id, 'status' => PhrEhiProposal::STATUS_PENDING]);

                        continue;
                    }
                    $open = in_array($proposal->status, [PhrEhiProposal::STATUS_PENDING, PhrEhiProposal::STATUS_SUPERSEDED], true);
                    if (! $open || ($proposal->status === PhrEhiProposal::STATUS_PENDING && $proposal->preview_digest === $row['preview_digest'])) {
                        continue;
                    }
                    $proposal->forceFill([...$row, 'status' => PhrEhiProposal::STATUS_PENDING, 'decided_by_user_id' => null, 'decided_at' => null])->save();
                }
            });
        }

        $produced = array_flip(array_map('strval', array_keys($rows)));
        $stale = PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', $mapping)
            ->where('status', PhrEhiProposal::STATUS_PENDING)->pluck('subject_key', 'id')
            ->filter(static fn (mixed $subject): bool => ! isset($produced[(string) $subject]))->keys()->all();
        $this->supersede($package, $actorUserId, $stale);
    }

    /** Pending proposals of mappings that are no longer registered can never be applied. */
    private function supersedeUnregistered(PhrEhiPackage $package, int $actorUserId): void
    {
        $stale = PhrEhiProposal::query()->where('package_id', $package->id)
            ->where('status', PhrEhiProposal::STATUS_PENDING)
            ->whereNotIn('mapping', array_keys($this->registry->all()))->pluck('id')->all();
        $this->supersede($package, $actorUserId, $stale);
    }

    /** @param  list<int|string>  $ids */
    private function supersede(PhrEhiPackage $package, int $actorUserId, array $ids): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->writeGuard->run((int) $package->patient_id, function (PhrPatient $patient) use ($actorUserId, $chunk): void {
                $this->access->ensureCanWrite($patient, $actorUserId);
                PhrEhiProposal::query()->whereIn('id', $chunk)->where('patient_id', $patient->id)
                    ->where('status', PhrEhiProposal::STATUS_PENDING)
                    ->update(['status' => PhrEhiProposal::STATUS_SUPERSEDED, 'updated_at' => now()]);
            });
        }
    }

    /**
     * Replace this mapping's preview findings. Each is located at the first
     * occurrence of its revision in this package; codes must be fixed snake_case.
     *
     * @param  list<array{code: string, revision_id: int|null, detail: array<string, int|string|bool|null>}>  $findings
     * @return array<string, int> code => count written
     */
    private function persistFindings(PhrEhiPackage $package, int $actorUserId, string $mapping, array $findings): array
    {
        $limit = EhiLimits::fromConfig()->maxFindingsPerMember;
        $omitted = max(0, count($findings) - $limit);
        $findings = array_slice($findings, 0, $limit);
        $revisionIds = array_values(array_unique(array_filter(array_column($findings, 'revision_id'))));
        $locators = [];
        PhrEhiOccurrence::query()->where('package_id', $package->id)->whereIn('revision_id', $revisionIds)
            ->orderBy('member_id')->orderBy('line_number')
            ->each(function (PhrEhiOccurrence $occurrence) use (&$locators): void {
                $locators[$occurrence->revision_id] ??= $occurrence;
            });

        $now = now();
        $counts = [];
        $rows = [];
        foreach ($findings as $finding) {
            $code = $finding['code'];
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1) {
                throw new LogicException("EHI mapping {$mapping} raised a finding without a fixed code.");
            }
            $locator = $finding['revision_id'] === null ? null : ($locators[$finding['revision_id']] ?? null);
            // A mapping may grade its own codes with detail.severity.
            $detail = $finding['detail'];
            $severity = is_string($detail['severity'] ?? null) && in_array($detail['severity'], self::SEVERITIES, true)
                ? $detail['severity']
                : (EhiFindingCode::SEVERITY[$code] ?? PhrEhiFinding::SEVERITY_WARNING);
            unset($detail['severity']);
            $rows[] = [
                'patient_id' => $package->patient_id,
                'package_id' => $package->id,
                'member_id' => $locator?->member_id,
                'code' => $code,
                'severity' => $severity,
                'line_number' => $locator?->line_number,
                'byte_offset' => $locator?->byte_offset,
                'column_name' => null,
                'detail_json' => json_encode([...$detail, 'stage' => self::FINDING_STAGE, 'mapping' => $mapping], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }
        if ($omitted > 0) {
            $rows[] = [
                'patient_id' => $package->patient_id,
                'package_id' => $package->id,
                'member_id' => null,
                'code' => EhiFindingCode::FINDINGS_TRUNCATED,
                'severity' => EhiFindingCode::SEVERITY[EhiFindingCode::FINDINGS_TRUNCATED],
                'line_number' => null,
                'byte_offset' => null,
                'column_name' => null,
                'detail_json' => json_encode(['omitted' => $omitted, 'stage' => self::FINDING_STAGE, 'mapping' => $mapping], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->writeGuard->run((int) $package->patient_id, function (PhrPatient $patient) use ($package, $actorUserId, $mapping, $rows): void {
            $this->access->ensureCanWrite($patient, $actorUserId);
            PhrEhiFinding::query()->where('package_id', $package->id)
                ->where('detail_json->stage', self::FINDING_STAGE)->where('detail_json->mapping', $mapping)->delete();
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                PhrEhiFinding::query()->insert($chunk);
            }
        });

        return $counts;
    }

    /** @return list<int> */
    private function revisionIds(PhrEhiProposal $proposal): array
    {
        return array_map('intval', $proposal->revision_ids_json);
    }

    /** @param  list<mixed>  $contentDigests */
    private function revisionDigest(array $contentDigests): string
    {
        $digests = array_map('strval', $contentDigests);
        sort($digests, SORT_STRING);

        return EhiCanonicalJson::digest($digests);
    }
}
