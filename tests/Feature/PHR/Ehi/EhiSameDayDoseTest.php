<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrImmunization;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiImmunizationMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\TestCase;

/** Two doses of one vaccine on one day are two doses: same-day repeats stay possible. */
class EhiSameDayDoseTest extends TestCase
{
    use EhiIntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Storage::fake('phr_exports');
        Queue::fake();
        config(['phr.ehi_mappings' => [EhiImmunizationMapping::class]]);
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    public function test_a_second_dose_of_one_vaccine_that_day_is_not_a_duplicate_of_the_first_once_it_is_accepted(): void
    {
        [$owner, $patient, $package] = $this->withTwoSameDayDoses();
        $first = $this->dose($package, '601');
        $second = $this->dose($package, '701');
        self::assertSame(PhrEhiProposal::KIND_CREATE, $first->kind);
        self::assertSame(PhrEhiProposal::KIND_CREATE, $second->kind);

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patient->id}/ehi/proposals/{$first->id}/review", ['decision' => 'accept', 'preview_digest' => $first->preview_digest])->assertOk();
        app(EhiProposalService::class)->preview($package, $patient, (int) $owner->id);

        $second->refresh();
        self::assertSame(PhrEhiProposal::KIND_CREATE, $second->kind, 'a different IMMUNE_ID from one source is a different dose');
        self::assertNull($second->candidate_ids_json);
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patient->id}/ehi/proposals/{$second->id}/review", ['decision' => 'accept', 'preview_digest' => $second->preview_digest])->assertOk();
        self::assertSame(2, PhrImmunization::query()->where('vaccine_name', 'SYNTHETIC VACCINE A')->where('patient_id', $patient->id)->count());
    }

    public function test_the_same_vaccine_that_day_from_elsewhere_is_still_a_question_for_a_person(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $fhir = PhrImmunization::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'import_source' => 'fhir', 'external_id' => 'Immunization/opaque-1', 'review_status' => PhrReviewStatus::CONFIRMED, 'vaccine_name' => 'Synthetic Vaccine A', 'administered_on' => '2024-03-05']);
        $manual = PhrImmunization::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'review_status' => PhrReviewStatus::CONFIRMED, 'vaccine_name' => 'Synthetic Vaccine A', 'administered_on' => '2024-03-05']);
        $package = $this->ehiIntakeDirectory(EhiClinicalPackage::standard()->build(), $patient, (int) $owner->id)->package;
        $package = app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, null, 'Synthetic Clinic');
        app(EhiProposalService::class)->preview($package, $patient, (int) $owner->id);

        $proposal = $this->dose($package->refresh(), '601');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $proposal->kind);
        self::assertSame([$fhir->id, $manual->id], $proposal->candidate_ids_json);
    }

    /** @return array{0: User, 1: PhrPatient, 2: PhrEhiPackage} */
    private function withTwoSameDayDoses(): array
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $build = EhiClinicalPackage::standard()
            ->adding('IMMUNE', ['IMMUNE_ID' => '701', 'IMMUNZATN_ID' => '9701', 'IMMUNZATN_ID_NAME' => 'SYNTHETIC VACCINE A', 'IMMUNE_DATE' => '2024-03-05', 'IMMNZTN_STATUS_C_NAME' => 'Given', 'IMM_HISTORIC_ADM_YN' => 'N'])
            ->adding('PAT_IMMUNIZATIONS', ['PAT_ID' => EhiClinicalPackage::PATIENT_KEY, 'LINE' => '71', 'IMMUNE_ID' => '701'])
            ->build();
        $package = $this->ehiIntakeDirectory($build, $patient, (int) $owner->id)->package;
        $package = app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, null, 'Synthetic Clinic');
        app(EhiProposalService::class)->preview($package, $patient, (int) $owner->id);

        return [$owner, $patient, $package->refresh()];
    }

    private function dose(PhrEhiPackage $package, string $immuneId): PhrEhiProposal
    {
        return PhrEhiProposal::query()->where('package_id', $package->id)->where('subject_key', EhiMappingContext::keyDigest(['IMMUNE_ID' => $immuneId]))->firstOrFail();
    }
}
