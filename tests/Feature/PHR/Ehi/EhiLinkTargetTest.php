<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiImmunizationMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiLabResultMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

/**
 * A record a person rejected stays out of their working list, so no EHI review may
 * land on it, whichever mapping or decision names it. All through the review route.
 */
class EhiLinkTargetTest extends TestCase
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

    public function test_a_lab_rejected_after_the_preview_cannot_be_linked_and_can_still_be_added_beside(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $candidate = PhrLabResult::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'review_status' => PhrReviewStatus::CONFIRMED, 'analyte' => 'SYNTHETIC GLUCOSE', 'value' => '95', 'value_numeric' => '95', 'collection_datetime' => '2024-03-05 08:00:00']);
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $proposal = $this->labProposal($package, '8101', '66000.01', '2');
        self::assertSame([$candidate->id], $proposal->candidate_ids_json);
        $this->reject($owner, $patient, 'lab-results', $candidate->id);

        $this->link($owner, $patient, $proposal, $candidate->id)->assertStatus(409)->assertExactJson(['error' => 'target_withdrawn']);

        self::assertSame(PhrEhiProposal::STATUS_PENDING, $proposal->refresh()->status);
        self::assertSame(0, PhrEhiRecordLink::query()->where('lab_result_id', $candidate->id)->count());
        $this->actingAs($owner)->postJson($this->url($patient, $proposal), ['decision' => 'accept', 'preview_digest' => $proposal->preview_digest])->assertOk();
        self::assertSame(PhrReviewStatus::REJECTED, $candidate->refresh()->review_status);
    }

    public function test_an_immunization_rejected_after_the_preview_cannot_be_linked(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $candidate = PhrImmunization::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'review_status' => PhrReviewStatus::CONFIRMED, 'vaccine_name' => 'Synthetic Vaccine D', 'administered_on' => '2023-10-01']);
        $package = $this->previewed(EhiClinicalPackage::standard()->build(), $owner, $patient);
        $proposal = PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', EhiImmunizationMapping::NAME)
            ->where('subject_key', EhiMappingContext::keyDigest(['IMMUNE_ID' => '606']))->firstOrFail();
        self::assertSame([$candidate->id], $proposal->candidate_ids_json);
        $this->reject($owner, $patient, 'immunizations', $candidate->id);

        $this->link($owner, $patient, $proposal, $candidate->id)->assertStatus(409)->assertExactJson(['error' => 'target_withdrawn']);

        self::assertSame(0, PhrEhiRecordLink::query()->where('immunization_id', $candidate->id)->count());
    }

    public function test_a_rejected_immunization_is_not_offered_as_a_duplicate_candidate(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        PhrImmunization::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'review_status' => PhrReviewStatus::REJECTED, 'vaccine_name' => 'Synthetic Vaccine D', 'administered_on' => '2023-10-01']);
        $package = $this->previewed(EhiClinicalPackage::standard()->build(), $owner, $patient);

        $proposal = PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', EhiImmunizationMapping::NAME)
            ->where('subject_key', EhiMappingContext::keyDigest(['IMMUNE_ID' => '606']))->firstOrFail();
        self::assertSame(PhrEhiProposal::KIND_CREATE, $proposal->kind);
        self::assertNull($proposal->candidate_ids_json);
    }

    public function test_a_lab_rejected_after_it_was_accepted_gets_no_update_from_a_later_export(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $accepted = $this->labProposal($package, '8102', '66001', '1');
        $recordId = $this->actingAs($owner)->postJson($this->url($patient, $accepted), ['decision' => 'accept', 'preview_digest' => $accepted->preview_digest])->assertOk()->json('record.id');
        $this->reject($owner, $patient, 'lab-results', $recordId);
        $sourceId = (int) $package->source_id;

        $changed = EhiClinicalPackage::withLabs()->changing('ORDER_RESULTS', ['ORDER_PROC_ID' => '8102', 'LINE' => '1'], ['ORD_VALUE' => 'Light growth'])->build();
        $second = $this->previewed($changed, $owner, $patient, 'package-2', $sourceId);

        $retained = $this->labProposal($second, '8102', '66001', '1');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $retained->kind);
        self::assertSame(EhiLabResultMapping::FINDING_LINKED_RECORD_WITHDRAWN, $retained->proposed_json['retain_reason']);
        self::assertSame('No growth', PhrLabResult::query()->findOrFail($recordId)->value);
    }

    public function test_an_immunization_rejected_after_it_was_accepted_gets_no_update_from_a_later_export(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::standard()->build(), $owner, $patient);
        $subject = EhiMappingContext::keyDigest(['IMMUNE_ID' => '601']);
        $accepted = PhrEhiProposal::query()->where('package_id', $package->id)->where('subject_key', $subject)->firstOrFail();
        $recordId = $this->actingAs($owner)->postJson($this->url($patient, $accepted), ['decision' => 'accept', 'preview_digest' => $accepted->preview_digest])->assertOk()->json('record.id');
        $this->reject($owner, $patient, 'immunizations', $recordId);

        $changed = EhiClinicalPackage::standard()->changing('IMMUNE', ['IMMUNE_ID' => '601'], ['LOT' => 'SYN-LOT-CHANGED'])->build();
        $second = $this->previewed($changed, $owner, $patient, 'package-2', (int) $package->source_id);

        $retained = PhrEhiProposal::query()->where('package_id', $second->id)->where('subject_key', $subject)->firstOrFail();
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $retained->kind);
        self::assertSame(EhiImmunizationMapping::FINDING_LINKED_RECORD_WITHDRAWN, $retained->proposed_json['retain_reason']);
        self::assertSame('SYN-LOT-1', PhrImmunization::query()->findOrFail($recordId)->lot_number);
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

    private function labProposal(PhrEhiPackage $package, string $order, string $contact, string $line): PhrEhiProposal
    {
        return PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', EhiLabResultMapping::NAME)
            ->where('subject_key', EhiMappingContext::keyDigest(['ORDER_PROC_ID' => $order, 'ORD_DATE_REAL' => $contact, 'LINE' => $line]))
            ->firstOrFail();
    }

    private function url(PhrPatient $patient, PhrEhiProposal $proposal): string
    {
        return "/api/phr/patients/{$patient->id}/ehi/proposals/{$proposal->id}/review";
    }

    private function link(User $owner, PhrPatient $patient, PhrEhiProposal $proposal, int $recordId): TestResponse
    {
        return $this->actingAs($owner)->postJson($this->url($patient, $proposal), ['decision' => 'link_existing', 'preview_digest' => $proposal->preview_digest, 'link_id' => $recordId]);
    }

    private function reject(User $owner, PhrPatient $patient, string $segment, int $recordId): void
    {
        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patient->id}/{$segment}/{$recordId}/review", ['review_status' => PhrReviewStatus::REJECTED])->assertOk();
    }
}
