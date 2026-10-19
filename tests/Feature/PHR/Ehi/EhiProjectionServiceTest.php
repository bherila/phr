<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiBlob;
use App\Models\PhrEhiEntity;
use App\Models\PhrEhiFinding;
use App\Models\PhrEhiOccurrence;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Models\PhrEhiSource;
use App\Models\PhrEhiTableAccount;
use App\Services\PHR\Import\Ehi\EhiFindingCode;
use App\Services\PHR\Import\Ehi\EhiPackageSummary;
use App\Services\PHR\Import\Ehi\EhiProjectionRefused;
use App\Services\PHR\Import\Ehi\EhiProjectionService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyOutcome;
use App\Services\PHR\Import\Ehi\Mapping\EhiMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingRegistry;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Support\EhiFakeMapping;
use Tests\Support\EhiProjectionFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

class EhiProjectionServiceTest extends TestCase
{
    use EhiProjectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhiProjection();
    }

    protected function tearDown(): void
    {
        $this->tearDownEhiProjection();
        parent::tearDown();
    }

    public function test_projects_mapped_rows_with_full_raw_keys_and_reconciled_dispositions(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $rows = [
            ...$this->standardItems(),
            ['', '1', 'Synthetic keyless', '2020-04-01', 'A'],
            ['200', '1', 'Synthetic repeated one', '2020-05-01', 'A'],
            ['200', '1', 'Synthetic repeated two', '2020-05-02', 'A'],
        ];
        $package = $this->boundPackage($this->itemPackage($rows), $patient, $owner);

        $result = app(EhiProjectionService::class)->project($package, $owner->id);

        self::assertFalse($result->replayed);
        self::assertSame(1, $result->tablesProjected);
        self::assertSame(0, $result->tablesMismatched);
        self::assertSame(PhrEhiPackage::CLINICAL_PROJECTED, $result->package->clinical_status);
        self::assertNull($result->package->lease_token);

        // "100"/"0100" stay distinct; the repeated key is one entity, two revisions.
        self::assertSame(5, PhrEhiEntity::query()->count());
        self::assertSame(6, PhrEhiRevision::query()->count());
        self::assertSame(6, PhrEhiOccurrence::query()->count());
        $leading = PhrEhiEntity::query()->where('key_digest', EhiMappingContext::keyDigest(['ITEM_ID' => '0100', 'LINE' => '1']))->sole();
        self::assertSame(['ITEM_ID' => '0100', 'LINE' => '1'], $leading->key_json);
        self::assertNotSame($leading->id, PhrEhiEntity::query()->where('key_digest', EhiMappingContext::keyDigest(['ITEM_ID' => '100', 'LINE' => '1']))->value('id'));
        $revision = EhiFakeMapping::revisionFor($package->id, '100', '1');
        self::assertNotNull($revision);
        self::assertSame(['ITEM_ID', 'LINE', 'NAME', 'GIVEN_ON', 'STATUS'], array_keys($revision->values_json));
        self::assertSame($package->id, $revision->first_package_id);

        self::assertSame(4, PhrEhiOccurrence::query()->where('disposition', PhrEhiOccurrence::DISPOSITION_PENDING_REVIEW)->count());
        self::assertSame(2, PhrEhiOccurrence::query()->where('disposition', PhrEhiOccurrence::DISPOSITION_QUARANTINED)->count());
        self::assertSame(1, PhrEhiFinding::query()->where('code', EhiFindingCode::MISSING_KEY)->whereNotNull('line_number')->count());
        self::assertSame([7, 8], PhrEhiFinding::query()->where('code', EhiFindingCode::DUPLICATE_KEY)->orderBy('line_number')->pluck('line_number')->all());

        $account = PhrEhiTableAccount::query()->where('package_id', $package->id)->where('table_name', EhiFakeMapping::TABLE)->sole();
        self::assertSame(7, $account->source_row_count);
        self::assertSame(['projected' => 0, 'corroborating' => 0, 'pending_review' => 4, 'retained' => 0, 'quarantined' => 3], $account->disposition_counts_json);
        self::assertSame(['projected' => 0, 'corroborating' => 0, 'pending_review' => 4, 'retained' => 0, 'quarantined' => 3], $result->dispositions);
        self::assertSame(0, EhiPackageSummary::forPackage($package)->unreconciledTables);

        $findings = PhrEhiFinding::query()->get()->toJson();
        self::assertStringNotContainsString(EhiSyntheticPackage::MARKER, $findings);
        self::assertStringNotContainsString('Synthetic repeated', $findings);
    }

    public function test_a_row_too_large_for_a_backup_record_is_quarantined_not_projected(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        // A quarter of this limit (1024 bytes) is smaller than the long row.
        config(['phr.native_restore_max_record_bytes' => 4096]);
        $rows = [
            ['100', '1', 'Synthetic vaccine A', '2020-01-01', 'A'],
            ['100', '2', str_repeat('Synthetic long text ', 80), '2020-02-01', 'A'],
        ];
        $package = $this->boundPackage($this->itemPackage($rows), $patient, $owner);

        $result = app(EhiProjectionService::class)->project($package, $owner->id);

        self::assertSame(1, PhrEhiRevision::query()->count());
        self::assertNull(EhiFakeMapping::revisionFor($package->id, '100', '2'));
        self::assertSame([3], PhrEhiFinding::query()->where('code', EhiFindingCode::REVISION_TOO_LARGE)->pluck('line_number')->all());
        self::assertSame(['projected' => 0, 'corroborating' => 0, 'pending_review' => 1, 'retained' => 0, 'quarantined' => 1], $result->dispositions);
        self::assertSame(0, EhiPackageSummary::forPackage($package)->unreconciledTables);
    }

    public function test_a_key_the_dictionary_documents_differently_is_a_finding_never_a_guess(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $differs = $this->boundPackage($this->itemPackage($this->standardItems(), ['ITEM_ID']), $patient, $owner, 'differs');

        $result = app(EhiProjectionService::class)->project($differs, $owner->id);

        self::assertSame(0, $result->tablesProjected);
        self::assertSame(1, $result->tablesMismatched);
        self::assertSame(0, PhrEhiEntity::query()->count());
        $finding = PhrEhiFinding::query()->where('code', EhiFindingCode::MAPPING_KEY_MISMATCH)->sole();
        self::assertSame(['stage' => 'projection', 'mapping' => 'synthetic', 'problem' => 'key_differs'], $finding->detail_json);
        $account = PhrEhiTableAccount::query()->where('package_id', $differs->id)->where('table_name', EhiFakeMapping::TABLE)->sole();
        self::assertSame([PhrEhiTableAccount::DISPOSITION_RETAINED_UNMAPPED => 4], $account->disposition_counts_json);

        $undocumented = $this->boundPackage($this->itemPackage($this->standardItems(), null), $patient, $owner, 'undocumented');
        app(EhiProjectionService::class)->project($undocumented, $owner->id);
        self::assertSame('table_undocumented', PhrEhiFinding::query()->where('package_id', $undocumented->id)
            ->where('code', EhiFindingCode::MAPPING_KEY_MISMATCH)->sole()->detail_json['problem'] ?? null);
        self::assertSame(0, PhrEhiEntity::query()->count());
    }

    public function test_a_table_that_stops_matching_its_mapping_key_withdraws_its_earlier_evidence(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $package = $this->boundPackage($this->itemPackage($this->standardItems()), $patient, $owner);
        $proposals = app(EhiProposalService::class);
        $proposals->preview($package, $patient, $owner->id);
        $accepted = PhrEhiProposal::query()->where('subject_key', EhiMappingContext::keyDigest(['ITEM_ID' => '100', 'LINE' => '1']))->sole();
        $proposals->review($accepted, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $accepted->preview_digest);
        $source = PhrEhiSource::query()->findOrFail($package->source_id);
        $entities = PhrEhiEntity::query()->orderBy('id')->pluck('id')->all();
        $revisions = PhrEhiRevision::query()->orderBy('id')->pluck('id')->all();
        $first = PhrEhiOccurrence::query()->where('package_id', $package->id)->orderBy('line_number')->firstOrFail();

        // A new mapping version expects a key this package's dictionary does not document.
        config(['phr.ehi_mappings' => [EhiRekeyedMapping::class]]);
        $preview = app(EhiProposalService::class)->preview($package->refresh(), $patient, $owner->id);

        self::assertSame(0, $preview->projection->tablesProjected);
        self::assertSame(1, $preview->projection->tablesMismatched);
        self::assertSame(['synthetic' => []], $preview->counts, 'rows keyed under the obsolete contract propose nothing');
        self::assertSame(['accepted' => 1, 'superseded' => 3], $preview->statuses);
        self::assertSame(0, PhrEhiOccurrence::query()->where('package_id', $package->id)->count());
        // Per-source evidence other rows rely on stays: the record link, its revision, every entity.
        self::assertSame($entities, PhrEhiEntity::query()->orderBy('id')->pluck('id')->all());
        self::assertSame($revisions, PhrEhiRevision::query()->orderBy('id')->pluck('id')->all());
        self::assertSame(1, PhrEhiRecordLink::query()->where('proposal_id', $accepted->id)->count());
        self::assertSame(PhrEhiProposal::STATUS_ACCEPTED, $accepted->refresh()->status);
        $account = PhrEhiTableAccount::query()->where('package_id', $package->id)->where('table_name', EhiFakeMapping::TABLE)->sole();
        self::assertSame([PhrEhiTableAccount::DISPOSITION_RETAINED_UNMAPPED => 4], $account->disposition_counts_json);
        self::assertSame(0, EhiPackageSummary::forPackage($package)->unreconciledTables);
        self::assertSame(1, PhrEhiFinding::query()->where('package_id', $package->id)->where('code', EhiFindingCode::MAPPING_KEY_MISMATCH)->count());

        // Even an occurrence that lingered would supply nothing for a withheld table.
        $lingering = PhrEhiOccurrence::query()->create([...$first->only(['patient_id', 'package_id', 'member_id', 'revision_id', 'line_number', 'byte_offset']), 'disposition' => PhrEhiOccurrence::DISPOSITION_PENDING_REVIEW]); // sensitive-scan-ignore: package member FK, not an insurance member id
        $context = new EhiMappingContext($package->refresh(), $patient, $source);
        self::assertCount(0, $context->revisions(EhiFakeMapping::TABLE));
        self::assertNull($context->revisionByKey(EhiFakeMapping::TABLE, ['ITEM_ID' => '100', 'LINE' => '1']));
        self::assertSame([], [...(new EhiRekeyedMapping)->propose($context)]);
        $lingering->delete();

        // Matching again, the table reuses the same entities and revisions.
        config(['phr.ehi_mappings' => [EhiFakeMapping::class]]);
        $result = app(EhiProjectionService::class)->project($package, $owner->id);
        self::assertSame(1, $result->tablesProjected);
        self::assertSame($revisions, PhrEhiRevision::query()->orderBy('id')->pluck('id')->all());
        self::assertSame(['projected' => 0, 'corroborating' => 1, 'pending_review' => 3, 'retained' => 0, 'quarantined' => 0], $result->dispositions);
        self::assertSame(0, EhiPackageSummary::forPackage($package)->unreconciledTables);
    }

    public function test_reprojection_and_an_interrupted_projection_converge(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $rows = [...$this->standardItems(), ['', '2', 'Synthetic keyless', '', 'A'], ['200', '1', 'One', '', 'A'], ['200', '1', 'Two', '', 'A']];
        $package = $this->boundPackage($this->itemPackage($rows), $patient, $owner);
        $service = app(EhiProjectionService::class);
        $service->project($package, $owner->id);
        $snapshot = $this->snapshot();

        self::assertTrue($service->project($package, $owner->id)->replayed);
        self::assertSame($snapshot, $this->snapshot());

        // A crash mid-projection: a dead lease, half-written checkpoint, and rows.
        $package->refresh()->forceFill([
            'clinical_status' => PhrEhiPackage::CLINICAL_PROJECTING,
            'lease_token' => 'dead-worker',
            'lease_expires_at' => now()->subMinute(),
            'checkpoint_json' => [...($package->checkpoint_json ?? []), 'projection' => ['registry' => 'old', 'tables' => []]],
        ])->save();
        $resumed = $service->project($package, $owner->id);
        self::assertFalse($resumed->replayed);
        self::assertSame($snapshot, $this->snapshot());

        // Mappings changing re-projects too, still onto the same rows.
        EhiFakeMapping::$version = 'ehi-syn-2';
        self::assertTrue($service->needsProjection($package->refresh()));
        $service->project($package, $owner->id);
        self::assertSame($snapshot, $this->snapshot());
    }

    public function test_changed_content_in_a_later_package_is_a_new_revision_of_the_same_entity(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $first = $this->boundPackage($this->itemPackage($this->standardItems()), $patient, $owner, 'first');
        $changed = $this->standardItems();
        $changed[0][2] = 'Synthetic vaccine A corrected';
        $second = $this->boundPackage($this->itemPackage($changed), $patient, $owner, 'second');
        self::assertSame($first->source_id, $second->source_id);

        app(EhiProjectionService::class)->project($first, $owner->id);
        app(EhiProjectionService::class)->project($second, $owner->id);

        self::assertSame(4, PhrEhiEntity::query()->count());
        self::assertSame(5, PhrEhiRevision::query()->count());
        $old = EhiFakeMapping::revisionFor($first->id, '100', '1');
        $new = EhiFakeMapping::revisionFor($second->id, '100', '1');
        self::assertNotNull($old);
        self::assertNotNull($new);
        self::assertSame($old->entity_id, $new->entity_id);
        self::assertNotSame($old->id, $new->id);
        self::assertSame($second->id, $new->first_package_id);
        // Unchanged rows are the same revision, seen again, still first seen in the first package.
        $unchanged = EhiFakeMapping::revisionFor($second->id, '100', '2');
        self::assertSame(EhiFakeMapping::revisionFor($first->id, '100', '2')?->id, $unchanged?->id);
        self::assertSame($first->id, $unchanged?->first_package_id);
        self::assertSame(8, PhrEhiOccurrence::query()->count());
    }

    public function test_refuses_unbound_packages_and_tampered_bytes(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $unbound = $this->ehiIntakeDirectory($this->itemPackage($this->standardItems()), $patient, $owner->id)->package;
        $this->assertRefused(EhiProjectionRefused::NOT_BOUND, fn () => app(EhiProjectionService::class)->project($unbound, $owner->id));

        $package = $this->boundPackage($this->itemPackage($this->standardItems()), $patient, $owner, 'bound');
        $tsv = (string) $package->members()->where('table_name', EhiFakeMapping::TABLE)->sole()->sha256;
        $blob = PhrEhiBlob::query()->where('sha256', $tsv)->sole();
        Storage::disk($blob->storage_disk)->put($blob->storage_path, 'tampered');
        $this->assertRefused(EhiProjectionRefused::BLOB_UNVERIFIED, fn () => app(EhiProjectionService::class)->project($package, $owner->id));
        $package->refresh();
        self::assertSame(PhrEhiPackage::CLINICAL_FAILED, $package->clinical_status);
        self::assertSame(EhiProjectionRefused::BLOB_UNVERIFIED, $package->failure_category);
        self::assertNull($package->lease_token);
        self::assertSame(0, PhrEhiEntity::query()->count());
    }

    public function test_registry_refuses_duplicate_names_and_conflicting_keys(): void
    {
        config(['phr.ehi_mappings' => [EhiFakeMapping::class]]);
        self::assertSame(['synthetic'], array_keys(app(EhiMappingRegistry::class)->all()));
        self::assertSame([EhiFakeMapping::TABLE => ['ITEM_ID', 'LINE']], app(EhiMappingRegistry::class)->tables());

        foreach ([[EhiFakeMapping::class, EhiFakeMapping::class], [EhiFakeMapping::class, EhiConflictingKeyMapping::class], [\stdClass::class]] as $configured) {
            config(['phr.ehi_mappings' => $configured]);
            try {
                app(EhiMappingRegistry::class)->all();
                self::fail('The registry must refuse this configuration.');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'entities' => PhrEhiEntity::query()->orderBy('id')->get(['id', 'key_digest'])->toArray(),
            'revisions' => PhrEhiRevision::query()->orderBy('id')->get(['id', 'entity_id', 'content_digest'])->toArray(),
            'occurrences' => PhrEhiOccurrence::query()->orderBy('id')->get(['id', 'revision_id', 'line_number', 'disposition'])->toArray(),
            'findings' => PhrEhiFinding::query()->where('detail_json->stage', 'projection')->orderBy('line_number')->get(['code', 'line_number'])->toArray(),
            'accounts' => PhrEhiTableAccount::query()->orderBy('id')->pluck('disposition_counts_json')->all(),
        ];
    }

    private function assertRefused(string $reason, \Closure $call): void
    {
        try {
            $call();
            self::fail("Expected EhiProjectionRefused ({$reason}).");
        } catch (EhiProjectionRefused $refused) {
            self::assertSame($reason, $refused->reason);
            self::assertStringNotContainsString(EhiSyntheticPackage::MARKER, $refused->getMessage());
        }
    }
}

/** EhiFakeMapping re-versioned under a key the synthetic dictionary does not document. */
final class EhiRekeyedMapping implements EhiMapping
{
    public function name(): string
    {
        return 'synthetic';
    }

    public function version(): string
    {
        return 'rekeyed-1';
    }

    public function expectedKeys(): array
    {
        return [EhiFakeMapping::TABLE => ['ITEM_ID']];
    }

    public function propose(EhiMappingContext $context): iterable
    {
        return (new EhiFakeMapping)->propose($context);
    }

    public function apply(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome
    {
        return (new EhiFakeMapping)->apply($proposal, $context);
    }
}

/** Declares a different key for the table EhiFakeMapping projects. */
final class EhiConflictingKeyMapping implements EhiMapping
{
    public function name(): string
    {
        return 'synthetic_conflict';
    }

    public function version(): string
    {
        return 'conflict-1';
    }

    public function expectedKeys(): array
    {
        return [EhiFakeMapping::TABLE => ['ITEM_ID']];
    }

    public function propose(EhiMappingContext $context): iterable
    {
        return [];
    }

    public function apply(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome
    {
        throw new LogicException('Never applied.');
    }
}
