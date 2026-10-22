<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrDocument;
use App\Models\PhrEhiBlob;
use App\Models\PhrEhiFinding;
use App\Models\PhrEhiOccurrence;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiProposalDecision;
use App\Models\PhrEhiProposalRevision;
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
use App\Services\PHR\Import\Ehi\Mapping\EhiCandidateSummary;
use App\Services\PHR\Import\Ehi\Mapping\EhiCanonicalJson;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingRegistry;
use App\Services\PHR\Import\Ehi\Mapping\EhiProposalDraft;
use App\Services\PHR\Import\Ehi\Mapping\EhiPublishedObjects;
use App\Services\PHR\Import\Ehi\Mapping\EhiTargetDigest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
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
 * upserts its drafts on (package, mapping, mapping version, subject). Each
 * proposal carries a digest of everything a reviewer is shown, including the
 * target record as it was read. A decided proposal is never rewritten; a pending
 * one a mapping (or its current version) no longer produces is superseded.
 *
 * Each preview run takes the package's next preview generation and stamps every
 * pending proposal it writes or confirms unchanged with it. Drafts are written
 * in chunks as a mapping yields them, so a run that fails part way leaves new
 * and stale pending rows side by side; the generation is recorded as completed
 * only after every mapping, supersession, and finding step succeeded, and only
 * pending proposals of a completed generation are reviewable or listed.
 *
 * Review consumes that persisted preview. Under the patient artifact guard it
 * re-checks write access, locks the proposal, and refuses (EhiReviewRefused,
 * nothing written) unless the proposal is still pending and of the package's
 * completed preview, the caller's preview digest is current, every source
 * revision still matches, and the target is live and unchanged. Accepting
 * writes the clinical record through the mapping and an evidence link per
 * revision; rejecting or retaining writes no clinical row. Every decision appends to the proposal's history. A storage object a
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

    /**
     * Reviewed occurrence dispositions, weakest first. A decision only raises an
     * occurrence along this order; quarantined is outside it and never changes.
     */
    private const array OCCURRENCE_PRECEDENCE = [
        PhrEhiOccurrence::DISPOSITION_PENDING_REVIEW,
        PhrEhiOccurrence::DISPOSITION_RETAINED,
        PhrEhiOccurrence::DISPOSITION_CORROBORATING,
        PhrEhiOccurrence::DISPOSITION_PROJECTED,
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
        $generation = $this->startPreview($package, $actorUserId);

        $budget = new EhiPreviewBudget;
        $budget->assertPatient($patient->id);
        $counts = [];
        $findingCounts = [];
        $findingRows = [];
        foreach ($this->registry->all() as $name => $mapping) {
            $context = new EhiMappingContext($package, $patient, $source, budget: $budget);
            // Drafts are persisted in chunks as the mapping yields them; only the
            // subject keys produced are kept, for duplicate detection. What this
            // preview no longer proposes is whatever it did not stamp.
            /** @var array<string, true> $produced */
            $produced = [];
            $rows = [];
            foreach ($mapping->propose($context) as $draft) {
                if (isset($produced[$draft->subjectKey])) {
                    throw new LogicException("EHI mapping {$name} proposed one subject twice.");
                }
                $produced[$draft->subjectKey] = true;
                $budget->charge(count($produced), 0);
                $budget->checkpoint();
                $rows[$draft->subjectKey] = $this->row($package, $name, $mapping->version(), $draft);
                $counts[$name][$draft->kind] = ($counts[$name][$draft->kind] ?? 0) + 1;
                if (count($rows) >= self::CHUNK || strlen(json_encode($rows, JSON_THROW_ON_ERROR)) >= 1024 * 1024) {
                    $this->persistChunk($package, $actorUserId, $name, $mapping->version(), $generation, $rows);
                    $rows = [];
                }
            }
            $this->persistChunk($package, $actorUserId, $name, $mapping->version(), $generation, $rows);
            $this->supersedeNotProduced($package, $actorUserId, $name, $generation);
            $counts[$name] ??= [];
            ksort($counts[$name], SORT_STRING);

            [$rowsForMapping, $mappingCounts] = $this->findingRows($package, $name, $context->findings(), $context->omittedFindings());
            array_push($findingRows, ...$rowsForMapping);
            foreach ($mappingCounts as $code => $count) {
                $findingCounts[$code] = ($findingCounts[$code] ?? 0) + $count;
            }
        }
        $budget->assertPatient($patient->id);
        $this->supersedeUnregistered($package, $actorUserId, $generation);
        $this->completePreview($package, $actorUserId, $generation, $findingRows);
        $package->refresh();
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
            // Serialized with preview runs by the patient lock: a run that started
            // and has not completed, or a proposal it did not stamp, is not a
            // preview anyone may rely on.
            $package = PhrEhiPackage::query()->whereKey($current->package_id)->where('patient_id', $patient->id)->first();
            if ($package === null || ! $package->completedPreviewIncludes($current)) {
                throw new EhiReviewRefused(EhiReviewRefused::PREVIEW_INCOMPLETE, 'the proposal is not part of a completed preview');
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
        foreach (array_chunk(PhrEhiProposalRevision::idsFor($proposal), self::CHUNK) as $chunk) {
            PhrEhiRecordLink::query()->insertOrIgnore(array_map(static fn (int $revisionId): array => [
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
            ], $chunk));
        }

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
        // A deleted revision takes its proposal row with it (cascade), so a
        // shorter list fails the digest just as changed content does.
        $ids = PhrEhiProposalRevision::idsFor($proposal);
        $digests = [];
        $occurring = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $found = PhrEhiRevision::query()->where('patient_id', $proposal->patient_id)->whereIn('id', $chunk)->pluck('content_digest');
            if ($found->count() !== count($chunk)) {
                throw new EhiReviewRefused(EhiReviewRefused::STALE_SOURCE, 'the source evidence changed since the preview');
            }
            array_push($digests, ...$found->all());
            // Evidence is only what this package still projects: a revision whose
            // occurrences were withdrawn (its table stopped matching the mapping)
            // or quarantined no longer supports the proposal.
            $occurring += PhrEhiOccurrence::query()->where('package_id', $proposal->package_id)
                ->whereIn('revision_id', $chunk)
                ->where('disposition', '!=', PhrEhiOccurrence::DISPOSITION_QUARANTINED)
                ->distinct()->count('revision_id');
        }
        if ($ids === [] || ! hash_equals($proposal->revision_digest, $this->revisionDigest($digests))) {
            throw new EhiReviewRefused(EhiReviewRefused::STALE_SOURCE, 'the source evidence changed since the preview');
        }
        if ($occurring !== count($ids)) {
            throw new EhiReviewRefused(EhiReviewRefused::STALE_SOURCE, 'the source evidence is no longer projected for this package');
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
     *
     * One revision can back several proposals (an encounter row establishing
     * ownership for two notes), so a disposition only ever rises along
     * OCCURRENCE_PRECEDENCE: each occurrence ends at the strongest disposition
     * any decision gave it, whatever order the proposals were reviewed in.
     */
    private function settleOccurrences(PhrEhiProposal $proposal, ?EhiApplyOutcome $outcome, Carbon $now): void
    {
        $disposition = match (true) {
            $outcome === null => PhrEhiOccurrence::DISPOSITION_RETAINED,
            $outcome->linkKind === PhrEhiRecordLink::KIND_PROJECTED => PhrEhiOccurrence::DISPOSITION_PROJECTED,
            default => PhrEhiOccurrence::DISPOSITION_CORROBORATING,
        };
        $weaker = array_slice(self::OCCURRENCE_PRECEDENCE, 0, (int) array_search($disposition, self::OCCURRENCE_PRECEDENCE, true));
        $members = [];
        foreach (array_chunk(PhrEhiProposalRevision::idsFor($proposal), self::CHUNK) as $chunk) {
            $occurrences = PhrEhiOccurrence::query()->where('package_id', $proposal->package_id)
                ->whereIn('revision_id', $chunk)
                ->whereIn('disposition', $weaker);
            array_push($members, ...(clone $occurrences)->distinct()->pluck('member_id')->all());
            $occurrences->update(['disposition' => $disposition, 'updated_at' => $now]);
        }
        $members = array_values(array_unique($members));

        PhrEhiTableAccount::query()->where('package_id', $proposal->package_id)->whereIn('member_id', $members)
            ->orderBy('id')->each(static fn (PhrEhiTableAccount $account) => EhiDispositionCounter::recount($account));
    }

    /**
     * The persisted form of one draft, with its preview digest, and the revisions
     * it relies on in ascending id order (their position order).
     *
     * @return array{attributes: array<string, mixed>, revisions: list<int>}
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

        (new EhiPreviewBudget)->charge(count($draft->revisionIds), strlen(json_encode($draft->proposed, JSON_THROW_ON_ERROR)));
        $revisionIds = array_values(array_unique(array_map('intval', $draft->revisionIds)));
        sort($revisionIds);
        $contentDigests = [];
        foreach (array_chunk($revisionIds, self::CHUNK) as $chunk) {
            $found = PhrEhiRevision::query()
                ->join('phr_ehi_entities', 'phr_ehi_entities.id', '=', 'phr_ehi_revisions.entity_id')
                ->where('phr_ehi_revisions.patient_id', $package->patient_id)
                ->where('phr_ehi_entities.source_id', $package->source_id)
                ->whereIn('phr_ehi_revisions.id', $chunk)
                ->pluck('phr_ehi_revisions.content_digest');
            if ($found->count() !== count($chunk)) {
                throw new LogicException("EHI mapping {$mapping} relied on a revision outside this package's source.");
            }
            array_push($contentDigests, ...$found->all());
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
        $candidateSummary = $draft->candidateSummary;
        if (count($candidates) > EhiCandidateSummary::LIMIT && $candidateSummary === null) {
            $candidateSummary = new EhiCandidateSummary;
            foreach ($candidates as $id) {
                $candidateSummary->add($id);
            }
            $candidates = $candidateSummary->ids;
        }
        $row = [
            'mapping' => $mapping,
            'mapping_version' => $version,
            'subject_key' => $draft->subjectKey,
            'kind' => $draft->kind,
            'revision_digest' => $this->revisionDigest($contentDigests),
            'target_table' => $draft->targetTable,
            'target_id' => $draft->targetId,
            'target_digest' => $targetDigest,
            'proposed_json' => $candidateSummary === null ? $draft->proposed : [...($draft->proposed ?? []), '_candidate_summary' => $candidateSummary->metadata()],
            'before_json' => $draft->before,
            'candidate_ids_json' => $candidates === [] ? null : $candidates,
        ];

        // The revision ids are digested (a reviewer is shown them) but stored as
        // phr_ehi_proposal_revisions rows.
        return [
            'attributes' => [...$row, 'preview_digest' => EhiCanonicalJson::digest([...$row, 'revision_ids' => $revisionIds])],
            'revisions' => $revisionIds,
        ];
    }

    /**
     * Create or refresh one chunk of this mapping version's proposals, stamping
     * each pending one with this preview's generation. A decided one is never
     * rewritten; an unchanged pending one keeps its content and preview digest.
     * A row a later-started preview already stamped belongs to that preview.
     *
     * @param  array<string, array{attributes: array<string, mixed>, revisions: list<int>}>  $rows  subject key => proposal
     */
    private function persistChunk(PhrEhiPackage $package, int $actorUserId, string $mapping, string $version, int $generation, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->writeGuard->run((int) $package->patient_id, function (PhrPatient $patient) use ($package, $actorUserId, $mapping, $version, $generation, $rows): void {
            $this->access->ensureCanWrite($patient, $actorUserId);
            (new EhiPreviewBudget)->assertPatient($patient->id);
            $existing = PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', $mapping)
                ->where('mapping_version', $version)
                ->whereIn('subject_key', array_keys($rows))->lockForUpdate()->get()->keyBy('subject_key');
            // Reserve prospective rows, including dependencies, before a chunk
            // can multiply into many inserts. Changed rows are conservatively
            // reserved alongside their old state; unchanged/decided rows add zero.
            $additionalRows = 0;
            $additionalBytes = 0;
            foreach ($rows as $subject => ['attributes' => $attributes, 'revisions' => $revisionIds]) {
                $prior = $existing->get($subject);
                if ($prior !== null && (! in_array($prior->status, [PhrEhiProposal::STATUS_PENDING, PhrEhiProposal::STATUS_SUPERSEDED], true)
                    || ($prior->preview_generation !== null && $prior->preview_generation > $generation)
                    || ($prior->status === PhrEhiProposal::STATUS_PENDING && $prior->preview_digest === $attributes['preview_digest']))) {
                    continue;
                }
                $additionalRows += 1 + count($revisionIds);
                $additionalBytes += 512 + strlen(json_encode($attributes, JSON_THROW_ON_ERROR)) + 160 * count($revisionIds);
            }
            (new EhiPreviewBudget)->assertPatient($patient->id, $additionalRows, $additionalBytes);
            $unchanged = [];
            foreach ($rows as $subject => ['attributes' => $row, 'revisions' => $revisionIds]) {
                $proposal = $existing->get($subject);
                if ($proposal === null) {
                    $proposal = PhrEhiProposal::query()->create([
                        ...$row,
                        'patient_id' => $patient->id,
                        'package_id' => $package->id,
                        'status' => PhrEhiProposal::STATUS_PENDING,
                        'preview_generation' => $generation,
                    ]);
                    PhrEhiProposalRevision::replaceFor($proposal, $revisionIds);

                    continue;
                }
                $open = in_array($proposal->status, [PhrEhiProposal::STATUS_PENDING, PhrEhiProposal::STATUS_SUPERSEDED], true);
                if (! $open || ($proposal->preview_generation !== null && $proposal->preview_generation > $generation)) {
                    continue;
                }
                if ($proposal->status === PhrEhiProposal::STATUS_PENDING && $proposal->preview_digest === $row['preview_digest']) {
                    $unchanged[] = $proposal->id;

                    continue;
                }
                $proposal->forceFill([...$row, 'status' => PhrEhiProposal::STATUS_PENDING, 'preview_generation' => $generation, 'decided_by_user_id' => null, 'decided_at' => null])->save();
                PhrEhiProposalRevision::replaceFor($proposal, $revisionIds);
            }
            // Confirmed unchanged: only the generation moves, not updated_at, so an
            // unchanged proposal is unchanged in a backup too.
            PhrEhiProposal::query()->whereIn('id', $unchanged)->toBase()->update(['preview_generation' => $generation]);
        });
    }

    /**
     * Supersede this mapping's pending proposals that the preview did not stamp:
     * subjects it no longer produces, and every subject of an earlier mapping
     * version.
     */
    private function supersedeNotProduced(PhrEhiPackage $package, int $actorUserId, string $mapping, int $generation): void
    {
        $stale = PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', $mapping)
            ->where('status', PhrEhiProposal::STATUS_PENDING)
            ->where(fn ($query) => $this->stampedBefore($query, $generation))
            ->select('id');
        $stale->chunkById(self::CHUNK, fn ($rows) => $this->supersede($package, $actorUserId, $rows->modelKeys(), $generation));
    }

    /**
     * Pending proposals of mappings that are no longer registered can never be
     * applied. "Unregistered" is only this run's view: during a rolling deploy a
     * worker without a newly added mapping may run beside one that has it, so a
     * row a later-started preview stamped is that preview's, never superseded.
     */
    private function supersedeUnregistered(PhrEhiPackage $package, int $actorUserId, int $generation): void
    {
        $stale = PhrEhiProposal::query()->where('package_id', $package->id)
            ->where('status', PhrEhiProposal::STATUS_PENDING)
            ->whereNotIn('mapping', array_keys($this->registry->all()))
            ->where(fn ($query) => $this->stampedBefore($query, $generation))
            ->select('id');
        $stale->chunkById(self::CHUNK, fn ($rows) => $this->supersede($package, $actorUserId, $rows->modelKeys(), $generation));
    }

    /**
     * @param  list<int|string>  $ids
     * @param  int  $generation  a row a later-started preview stamped meanwhile is kept
     */
    private function supersede(PhrEhiPackage $package, int $actorUserId, array $ids, int $generation): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->writeGuard->run((int) $package->patient_id, function (PhrPatient $patient) use ($actorUserId, $chunk, $generation): void {
                $this->access->ensureCanWrite($patient, $actorUserId);
                PhrEhiProposal::query()->whereIn('id', $chunk)->where('patient_id', $patient->id)
                    ->where('status', PhrEhiProposal::STATUS_PENDING)
                    ->where(fn ($stamped) => $this->stampedBefore($stamped, $generation))
                    ->update(['status' => PhrEhiProposal::STATUS_SUPERSEDED, 'updated_at' => now()]);
            });
        }
    }

    /**
     * @param  Builder<PhrEhiProposal>  $query
     * @return Builder<PhrEhiProposal>
     */
    private function stampedBefore(Builder $query, int $generation): Builder
    {
        return $query->whereNull('preview_generation')->orWhere('preview_generation', '<', $generation);
    }

    /** Take the package's next preview generation; its pending proposals are unreviewable until it completes. */
    private function startPreview(PhrEhiPackage $package, int $actorUserId): int
    {
        return $this->writeGuard->run((int) $package->patient_id, function (PhrPatient $patient) use ($package, $actorUserId): int {
            $this->access->ensureCanWrite($patient, $actorUserId);
            $started = PhrEhiPackage::query()->whereKey($package->id)->where('patient_id', $patient->id)->lockForUpdate()
                ->firstOrFail()->preview_generation_started;
            $generation = ($started ?? 0) + 1;
            // Preview bookkeeping, not package content: updated_at stays.
            PhrEhiPackage::query()->whereKey($package->id)->toBase()->update(['preview_generation_started' => $generation]);

            return $generation;
        });
    }

    /**
     * Record a fully successful preview as completed, unless a later preview has
     * started since: then that one decides what is current.
     *
     * @param  list<array<string, mixed>>  $findingRows
     */
    private function completePreview(PhrEhiPackage $package, int $actorUserId, int $generation, array $findingRows): void
    {
        $this->writeGuard->run((int) $package->patient_id, function (PhrPatient $patient) use ($package, $actorUserId, $generation, $findingRows): void {
            $this->access->ensureCanWrite($patient, $actorUserId);
            $current = PhrEhiPackage::query()->whereKey($package->id)->where('patient_id', $patient->id)->lockForUpdate()->firstOrFail();
            if ($current->preview_generation_started !== $generation) {
                throw new EhiProjectionRefused(EhiProjectionRefused::PREVIEW_SUPERSEDED, 'a newer preview owns the results');
            }
            PhrEhiFinding::query()->where('package_id', $package->id)->where('detail_json->stage', self::FINDING_STAGE)->delete();
            foreach (array_chunk($findingRows, self::CHUNK) as $chunk) {
                PhrEhiFinding::query()->insert($chunk);
            }
            PhrEhiPackage::query()->whereKey($package->id)->where('patient_id', $patient->id)
                ->where('preview_generation_started', $generation)
                ->toBase()->update(['preview_generation_completed' => $generation]);
        });
    }

    /**
     * Prepare this mapping's bounded findings for atomic completion. Each is located at the first
     * occurrence of its revision in this package; codes must be fixed snake_case.
     *
     * @param  list<array{code: string, revision_id: int|null, detail: array<string, int|string|bool|null>}>  $findings
     * @return array{list<array<string, mixed>>, array<string, int>} rows and retained detail counts
     */
    private function findingRows(PhrEhiPackage $package, string $mapping, array $findings, int $omitted): array
    {
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

        return [$rows, $counts];
    }

    /** @param  list<mixed>  $contentDigests */
    private function revisionDigest(array $contentDigests): string
    {
        $digests = array_map('strval', $contentDigests);
        sort($digests, SORT_STRING);

        return EhiCanonicalJson::digest($digests);
    }
}
