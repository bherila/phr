<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrDocument;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiSource;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyOutcome;
use App\Services\PHR\Import\Ehi\Mapping\EhiDocumentMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiEncounterMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiImmunizationMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiLabResultMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiProposalDraft;
use App\Support\PHR\PhrReviewStatus;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

/**
 * A proposal's target digest must describe the state its proposal was built from. A
 * mapping reads the patient's records once, then yields drafts for a long time, so a
 * person's edit can land in between. Accepting what was built from the older state
 * would overwrite that edit, and the reviewer would never have seen it. Every site
 * that names a target is here: an update and a corroboration in all four mappings, and
 * the two source-ID bridges.
 */
class EhiEditDuringPreviewTest extends TestCase
{
    use EhiIntakeFixtures;

    private const string EDITED = 'Edited by a person during the preview';

    private EhiMappingWithAnEdit $mapping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Storage::fake('phr_exports');
        Storage::fake('phr_dicom');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: class-string<EhiMapping>, 1: array<string, string>, 2: class-string<Model>, 3: string, 4: string, 5: array<string, string>, 6: array<string, string>}>
     *                                                                                                                                                                                       the mapping, its subject key, the record model and the field a person edits, the source table, a row match, a change to that field
     */
    public static function mappings(): array
    {
        return [
            'immunization' => [EhiImmunizationMapping::class, ['IMMUNE_ID' => '601'], PhrImmunization::class, 'lot_number', 'IMMUNE', ['IMMUNE_ID' => '601'], ['LOT' => 'SYN-LOT-CHANGED']],
            'document' => [EhiDocumentMapping::class, ['NOTE_ID' => '88001'], PhrDocument::class, 'title', 'HNO_INFO', ['NOTE_ID' => '88001'], ['NOTE_DESC' => 'Synthetic retitled note']],
            'lab result' => [EhiLabResultMapping::class, ['ORDER_PROC_ID' => '8102', 'ORD_DATE_REAL' => '66001', 'LINE' => '1'], PhrLabResult::class, 'value', 'ORDER_RESULTS', ['ORDER_PROC_ID' => '8102', 'LINE' => '1'], ['ORD_VALUE' => 'Light growth']],
            'encounter' => [EhiEncounterMapping::class, ['PAT_ENC_CSN_ID' => '7101'], PhrOfficeVisit::class, 'provider_name', 'PAT_ENC', ['PAT_ENC_CSN_ID' => '7101'], ['VISIT_PROV_ID_PROV_NAME' => 'SYNTHETIC SECOND PROVIDER']],
        ];
    }

    /**
     * @param  class-string<EhiMapping>  $mapping
     * @param  array<string, string>  $key
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $match
     * @param  array<string, string>  $change
     */
    #[DataProvider('mappings')]
    public function test_an_update_built_before_a_persons_edit_cannot_overwrite_it(string $mapping, array $key, string $model, string $field, string $table, array $match, array $change): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $this->running($mapping);
        $first = $this->previewed($this->package($mapping)->build(), $owner, $patient);
        $this->review($owner, $patient, $this->subject($first, $key), 'accept')->assertOk();
        $sourceId = PhrEhiSource::query()->sole()->id;
        $changed = $this->package($mapping)->changing($table, $match, $change)->build();

        $second = $this->previewedWithAnEdit($changed, $owner, $patient, $sourceId, $key, $model, $field);
        $update = $this->subject($second, $key);
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);

        $this->review($owner, $patient, $update, 'approve_update')->assertConflict()->assertJsonPath('error', 'stale_target');
        self::assertSame(self::EDITED, $model::query()->whereKey($update->target_id)->value($field), 'the edit survives');
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $update->refresh()->status);

        // A later preview shows the reviewer what the record now says, and can then be decided.
        $this->mapping->duringPreview = null;
        app(EhiProposalService::class)->preview($second->refresh(), $patient, (int) $owner->id);
        $again = $this->subject($second, $key);
        self::assertSame(self::EDITED, $again->before_json[$field]);
        $this->review($owner, $patient, $again, 'approve_update')->assertOk();
        self::assertNotSame(self::EDITED, $model::query()->whereKey($again->target_id)->value($field), 'a person approved it, having seen the edit');
    }

    /** @return array<string, array{0: class-string<EhiMapping>, 1: array<string, string>, 2: class-string<Model>, 3: string}> */
    public static function corroborations(): array
    {
        return array_map(static fn (array $case): array => [$case[0], $case[1], $case[2], $case[3]], self::mappings());
    }

    /**
     * @param  class-string<EhiMapping>  $mapping
     * @param  array<string, string>  $key
     * @param  class-string<Model>  $model
     */
    #[DataProvider('corroborations')]
    public function test_a_corroboration_built_before_a_persons_edit_is_refused_as_stale(string $mapping, array $key, string $model, string $field): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $this->running($mapping);
        $first = $this->previewed($this->package($mapping)->build(), $owner, $patient);
        $this->review($owner, $patient, $this->subject($first, $key), 'accept')->assertOk();
        $sourceId = PhrEhiSource::query()->sole()->id;
        $later = $this->package($mapping)->build()->with('README.txt', "Synthetic later readme\n");

        $second = $this->previewedWithAnEdit($later, $owner, $patient, $sourceId, $key, $model, $field);
        $corroborate = $this->subject($second, $key);
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $corroborate->kind);

        $this->review($owner, $patient, $corroborate, 'accept')->assertConflict()->assertJsonPath('error', 'stale_target');
        self::assertSame(self::EDITED, $model::query()->whereKey($corroborate->target_id)->value($field));
    }

    public function test_a_lab_bridge_built_before_a_persons_edit_is_refused_as_stale(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $this->running(EhiLabResultMapping::class);
        PhrLabResult::query()->forceCreate(['patient_id' => $patient->id, 'user_id' => $owner->id, 'review_status' => PhrReviewStatus::CONFIRMED, 'import_source' => 'ccda', 'external_id' => '1.2.3.4|8101', 'analyte' => 'synthetic glucose', 'value' => '95', 'value_numeric' => '95', 'unit' => 'mg/dL', 'result_datetime' => '2024-03-05 10:00:00']);
        $key = ['ORDER_PROC_ID' => '8101', 'ORD_DATE_REAL' => '66000.01', 'LINE' => '2'];

        $package = $this->previewedWithAnEdit(EhiClinicalPackage::withLabs()->build(), $owner, $patient, null, $key, PhrLabResult::class, 'value');
        $bridge = $this->subject($package, $key);
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $bridge->kind);
        self::assertArrayHasKey('bridge', $bridge->proposed_json);

        $this->review($owner, $patient, $bridge, 'accept')->assertConflict()->assertJsonPath('error', 'stale_target');
    }

    public function test_an_encounter_bridge_built_before_a_persons_edit_is_refused_as_stale(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $this->running(EhiEncounterMapping::class);
        PhrOfficeVisit::query()->forceCreate(['patient_id' => $patient->id, 'user_id' => $owner->id, 'review_status' => PhrReviewStatus::CONFIRMED, 'import_source' => 'ccda', 'external_id' => '7101', 'visit_date' => '2024-05-01']);
        $key = ['PAT_ENC_CSN_ID' => '7101'];

        $package = $this->previewedWithAnEdit(EhiClinicalPackage::withEncounters()->build(), $owner, $patient, null, $key, PhrOfficeVisit::class, 'provider_name');
        $bridge = $this->subject($package, $key);
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $bridge->kind);
        self::assertArrayHasKey('bridge', $bridge->proposed_json);

        $this->review($owner, $patient, $bridge, 'accept')->assertConflict()->assertJsonPath('error', 'stale_target');
    }

    public function test_a_draft_naming_a_target_without_the_state_it_was_built_from_is_refused(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $this->running(EhiImmunizationMapping::class);
        $first = $this->previewed(EhiClinicalPackage::standard()->build(), $owner, $patient);
        $key = ['IMMUNE_ID' => '601'];
        $this->review($owner, $patient, $this->subject($first, $key), 'accept')->assertOk();
        $this->mapping->rewrite = static fn (EhiProposalDraft $draft): EhiProposalDraft => new EhiProposalDraft(
            $draft->subjectKey, $draft->kind, $draft->revisionIds, $draft->proposed, $draft->targetTable, $draft->targetId, $draft->before,
        );

        $this->expectException(LogicException::class);
        $this->previewed(EhiClinicalPackage::standard()->build()->with('README.txt', "Synthetic later readme\n"), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id);
    }

    /** @param  class-string<EhiMapping>  $class */
    private function running(string $class): void
    {
        $this->mapping = new EhiMappingWithAnEdit(app($class));
        $this->app->instance(EhiMappingWithAnEdit::class, $this->mapping);
        config(['phr.ehi_mappings' => [EhiMappingWithAnEdit::class]]);
    }

    /**
     * @param  array<string, string>  $key
     * @param  class-string<Model>  $model
     */
    private function previewedWithAnEdit(EhiSyntheticPackage $build, User $owner, PhrPatient $patient, ?int $sourceId, array $key, string $model, string $field): PhrEhiPackage
    {
        $subject = EhiMappingContext::keyDigest($key);
        $edits = 0;
        $this->mapping->duringPreview = static function (EhiProposalDraft $draft) use ($subject, $model, $field, &$edits): void {
            if ($draft->subjectKey === $subject) {
                $record = $model::query()->findOrFail($draft->targetId);
                $record->setAttribute($field, self::EDITED);
                $record->save();
                $edits++;
            }
        };
        $package = $this->previewed($build, $owner, $patient, 'package-2', $sourceId);
        self::assertSame(1, $edits, 'the edit landed between the read and the draft being kept');

        return $package;
    }

    /** @param  class-string<EhiMapping>  $mapping */
    private function package(string $mapping): EhiClinicalPackage
    {
        return match ($mapping) {
            EhiLabResultMapping::class => EhiClinicalPackage::withLabs(),
            EhiEncounterMapping::class => EhiClinicalPackage::withEncounters(),
            default => EhiClinicalPackage::standard(),
        };
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);

        return [$owner, $patient];
    }

    private function previewed(EhiSyntheticPackage $build, User $owner, PhrPatient $patient, string $name = 'package', ?int $sourceId = null): PhrEhiPackage
    {
        $package = $this->ehiIntakeDirectory($build, $patient, (int) $owner->id, $name)->package;
        $package = app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, $sourceId, $sourceId === null ? 'Synthetic Clinic' : null);
        app(EhiProposalService::class)->preview($package, $patient, (int) $owner->id);

        return $package->refresh();
    }

    /** @param  array<string, string>  $key */
    private function subject(PhrEhiPackage $package, array $key): PhrEhiProposal
    {
        return PhrEhiProposal::query()->where('package_id', $package->id)->where('subject_key', EhiMappingContext::keyDigest($key))->firstOrFail();
    }

    private function review(User $owner, PhrPatient $patient, PhrEhiProposal $proposal, string $decision): TestResponse
    {
        return $this->actingAs($owner)->postJson("/api/phr/patients/{$patient->id}/ehi/proposals/{$proposal->id}/review", ['decision' => $decision, 'preview_digest' => $proposal->preview_digest]);
    }
}

/** The real mapping, with a person's edit landing after a draft was built from what it read. */
final class EhiMappingWithAnEdit implements EhiMapping
{
    /** @var (Closure(EhiProposalDraft): void)|null */
    public ?Closure $duringPreview = null;

    /** @var (Closure(EhiProposalDraft): EhiProposalDraft)|null */
    public ?Closure $rewrite = null;

    public function __construct(private readonly EhiMapping $mapping) {}

    public function name(): string
    {
        return $this->mapping->name();
    }

    public function version(): string
    {
        return $this->mapping->version();
    }

    public function expectedKeys(): array
    {
        return $this->mapping->expectedKeys();
    }

    public function propose(EhiMappingContext $context): iterable
    {
        foreach ($this->mapping->propose($context) as $draft) {
            if ($this->duringPreview !== null) {
                ($this->duringPreview)($draft);
            }
            yield $this->rewrite === null ? $draft : ($this->rewrite)($draft);
        }
    }

    public function apply(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome
    {
        return $this->mapping->apply($proposal, $context);
    }
}
