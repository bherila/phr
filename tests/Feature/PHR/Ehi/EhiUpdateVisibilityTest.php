<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiEncounterMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiImmunizationMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiLabResultMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

/**
 * An update whose only change is a source-detail field still reaches the review route
 * with both sides of it, for each mapping that has such a field: this is what the review
 * screen has to show before "Approve update" can mean anything. See ehiProposalView.
 */
class EhiUpdateVisibilityTest extends TestCase
{
    use EhiIntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Storage::fake('phr_exports');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    public function test_a_change_only_in_an_immunizations_source_details_reaches_review_with_both_sides(): void
    {
        [$owner, $patient, $package] = $this->accepted(EhiClinicalPackage::standard(), EhiImmunizationMapping::NAME, ['IMMUNE_ID' => '601']);
        $second = $this->previewed(EhiClinicalPackage::standard()->changing('IMMUNE', ['IMMUNE_ID' => '601'], ['DOSE' => '1.0 mL'])->build(), $owner, $patient, 'package-2', (int) $package->source_id);

        $listed = $this->listed($owner, $patient, $second, EhiImmunizationMapping::NAME, ['IMMUNE_ID' => '601']);
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $listed['kind']);
        self::assertSame(['raw_text'], array_keys($listed['proposed']));
        self::assertStringContainsString('DOSE: 1.0 mL', $listed['proposed']['raw_text']);
        self::assertStringContainsString('DOSE: 0.5 mL', $listed['before']['raw_text']);
    }

    public function test_a_change_only_in_a_labs_raw_value_reaches_review_with_both_sides(): void
    {
        $key = ['ORDER_PROC_ID' => '8101', 'ORD_DATE_REAL' => '66000.01', 'LINE' => '2'];
        [$owner, $patient, $package] = $this->accepted(EhiClinicalPackage::withLabs(), EhiLabResultMapping::NAME, $key);
        $second = $this->previewed(EhiClinicalPackage::withLabs()->changing('ORDER_RESULTS', $key, ['ORD_RAW_VALUE' => '95,00'])->build(), $owner, $patient, 'package-2', (int) $package->source_id);

        $listed = $this->listed($owner, $patient, $second, EhiLabResultMapping::NAME, $key);
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $listed['kind']);
        self::assertSame(['value_raw'], $listed['proposed']['changed']);
        self::assertSame('95,0', $listed['before']['value_raw']);
        self::assertSame('95,00', $listed['proposed']['attributes']['value_raw']);
    }

    public function test_a_change_only_in_an_encounters_source_details_reaches_review_with_both_sides(): void
    {
        $key = ['PAT_ENC_CSN_ID' => '7101'];
        [$owner, $patient, $package] = $this->accepted(EhiClinicalPackage::withEncounters(), EhiEncounterMapping::NAME, $key);
        $second = $this->previewed(EhiClinicalPackage::withEncounters()->changing('PAT_ENC', $key, ['CONTACT_COMMENT' => 'Synthetic corrected comment'])->build(), $owner, $patient, 'package-2', (int) $package->source_id);

        $listed = $this->listed($owner, $patient, $second, EhiEncounterMapping::NAME, $key);
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $listed['kind']);
        self::assertSame(['raw_text'], $listed['proposed']['changed']);
        self::assertStringContainsString('Synthetic contact comment', $listed['before']['raw_text']);
        self::assertStringContainsString('Synthetic corrected comment', $listed['proposed']['attributes']['raw_text']);
    }

    /**
     * @param  array<string, string>  $key
     * @return array{0: User, 1: PhrPatient, 2: PhrEhiPackage}
     */
    private function accepted(EhiClinicalPackage $build, string $mapping, array $key): array
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $package = $this->previewed($build->build(), $owner, $patient);
        $proposal = PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', $mapping)->where('subject_key', EhiMappingContext::keyDigest($key))->firstOrFail();
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patient->id}/ehi/proposals/{$proposal->id}/review", ['decision' => 'accept', 'preview_digest' => $proposal->preview_digest])->assertOk();

        return [$owner, $patient, $package];
    }

    private function previewed(EhiSyntheticPackage $build, User $owner, PhrPatient $patient, string $name = 'package', ?int $sourceId = null): PhrEhiPackage
    {
        $package = $this->ehiIntakeDirectory($build, $patient, (int) $owner->id, $name)->package;
        $package = app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, $sourceId, $sourceId === null ? 'Synthetic Clinic' : null);
        app(EhiProposalService::class)->preview($package, $patient, (int) $owner->id);

        return $package->refresh();
    }

    /**
     * @param  array<string, string>  $key
     * @return array<string, mixed> the proposal as the review screen receives it
     */
    private function listed(User $owner, PhrPatient $patient, PhrEhiPackage $package, string $mapping, array $key): array
    {
        $id = PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', $mapping)->where('subject_key', EhiMappingContext::keyDigest($key))->value('id');
        $listed = collect($this->actingAs($owner)->getJson("/api/phr/patients/{$patient->id}/ehi/packages/{$package->id}/proposals?status=pending&limit=500")->assertOk()->json('proposals'))->firstWhere('id', $id);
        self::assertIsArray($listed);

        return $listed;
    }
}
