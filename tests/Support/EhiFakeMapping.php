<?php

namespace Tests\Support;

use App\Models\PhrEhiProposal;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Models\PhrImmunization;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyOutcome;
use App\Services\PHR\Import\Ehi\Mapping\EhiMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiProposalDraft;
use App\Support\PHR\PhrReviewStatus;
use Closure;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use RuntimeException;

/**
 * A test-only mapping over the synthetic SYNTH_ITEM table (key ITEM_ID, LINE),
 * proposing immunizations. Static knobs let tests change its output between
 * previews; reset() restores the defaults.
 *
 * - STATUS "D" yields a retain proposal.
 * - A revision already linked to a record corroborates it; another revision of
 *   a linked entity proposes an update of that record.
 * - Otherwise an unlinked record with the same vaccine name makes it a possible
 *   duplicate, else a create.
 */
final class EhiFakeMapping implements EhiMapping
{
    public const string TABLE = 'SYNTH_ITEM';

    public static string $version = 'ehi-syn-1';

    /** Appended to every proposed vaccine name. */
    public static string $suffix = '';

    /** @var list<string> entity key digests to leave out of the next preview */
    public static array $skip = [];

    /** Runs inside apply() after any write, e.g. to throw a refusal. */
    public static ?Closure $afterApply = null;

    public static function reset(): void
    {
        self::$version = 'ehi-syn-1';
        self::$suffix = '';
        self::$skip = [];
        self::$afterApply = null;
    }

    public function name(): string
    {
        return 'synthetic';
    }

    public function version(): string
    {
        return self::$version;
    }

    public function expectedKeys(): array
    {
        return [self::TABLE => ['ITEM_ID', 'LINE']];
    }

    public function propose(EhiMappingContext $context): iterable
    {
        foreach ($context->revisions(self::TABLE) as $revision) {
            $entity = $revision->entity;
            if (in_array($entity->key_digest, self::$skip, true)) {
                continue;
            }
            $values = $revision->values_json;
            if (($values['STATUS'] ?? null) === 'D') {
                $context->finding('synthetic_deleted_row', $revision, ['severity' => 'info']);
                yield new EhiProposalDraft($entity->key_digest, PhrEhiProposal::KIND_RETAIN, [$revision->id]);

                continue;
            }
            $proposed = $this->proposed($values);

            $link = $context->linksForEntity($entity)->whereNotNull('immunization_id')->last();
            if ($link instanceof PhrEhiRecordLink) {
                $target = PhrImmunization::query()->withoutGlobalScope(SoftDeletingScope::class)->find($link->immunization_id);
                $kind = $link->revision_id === $revision->id ? PhrEhiProposal::KIND_CORROBORATE : PhrEhiProposal::KIND_UPDATE;
                yield new EhiProposalDraft(
                    $entity->key_digest,
                    $kind,
                    [$revision->id],
                    $proposed,
                    'phr_immunizations',
                    (int) $link->immunization_id,
                    $target === null ? null : ['vaccine_name' => $target->vaccine_name, 'administered_on' => $target->administered_on?->toDateString()],
                );

                continue;
            }

            $candidates = PhrImmunization::query()->where('patient_id', $context->patient->id)
                ->where('vaccine_name', $proposed['vaccine_name'])->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            yield new EhiProposalDraft(
                $entity->key_digest,
                $candidates === [] ? PhrEhiProposal::KIND_CREATE : PhrEhiProposal::KIND_POSSIBLE_DUPLICATE,
                [$revision->id],
                $proposed,
                candidateIds: $candidates,
            );
        }
    }

    public function apply(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome
    {
        if ($context->decision === PhrEhiProposal::DECISION_LINK) {
            $outcome = new EhiApplyOutcome('phr_immunizations', (int) $context->linkTargetId, PhrEhiRecordLink::KIND_BRIDGED);
        } elseif ($proposal->kind === PhrEhiProposal::KIND_CORROBORATE) {
            $outcome = new EhiApplyOutcome('phr_immunizations', (int) $proposal->target_id, PhrEhiRecordLink::KIND_CORROBORATING);
        } elseif ($proposal->kind === PhrEhiProposal::KIND_UPDATE) {
            $record = PhrImmunization::query()->findOrFail($proposal->target_id);
            $record->fill($proposal->proposed_json ?? [])->save();
            $outcome = new EhiApplyOutcome('phr_immunizations', (int) $record->id, PhrEhiRecordLink::KIND_PROJECTED);
        } else {
            $record = PhrImmunization::query()->create([
                ...($proposal->proposed_json ?? []),
                'patient_id' => $context->patient->id,
                'user_id' => $context->patient->owner_user_id,
                'review_status' => PhrReviewStatus::CONFIRMED,
            ]);
            $outcome = new EhiApplyOutcome('phr_immunizations', (int) $record->id, PhrEhiRecordLink::KIND_PROJECTED);
        }

        if (self::$afterApply !== null) {
            (self::$afterApply)($outcome);
        }

        return $outcome;
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string|null>
     */
    private function proposed(array $values): array
    {
        $name = $values['NAME'] ?? null;
        if ($name === null) {
            throw new RuntimeException('Synthetic row without a name.');
        }

        return [
            'vaccine_name' => $name.self::$suffix,
            'administered_on' => ($values['GIVEN_ON'] ?? '') === '' ? null : $values['GIVEN_ON'],
        ];
    }

    /** The revision of one key supplied by a package, for assertions. */
    public static function revisionFor(int $packageId, string $itemId, string $line): ?PhrEhiRevision
    {
        return PhrEhiRevision::query()
            ->whereHas('entity', fn ($query) => $query->where('key_digest', EhiMappingContext::keyDigest(['ITEM_ID' => $itemId, 'LINE' => $line])))
            ->whereIn('id', fn ($query) => $query->select('revision_id')->from('phr_ehi_occurrences')->where('package_id', $packageId))
            ->first();
    }
}
