<?php

namespace Tests\Feature\PHR\Ehi;

use App\Jobs\PHR\IntakeEhiUploadJob;
use App\Jobs\PHR\PreviewEhiPackageJob;
use App\Models\PhrCondition;
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
use App\Models\PhrEhiUpload;
use App\Models\PhrEob;
use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrProcedure;
use App\Models\User;
use App\Services\PHR\DataHub\PhrPatientDeletionCleanupService;
use App\Services\PHR\DataHub\PhrPatientDeletionService;
use App\Services\PHR\Export\PhrExportDataService;
use App\Services\PHR\Export\PhrFhirExporter;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiFindingCode;
use App\Services\PHR\Import\Ehi\EhiPreviewQueue;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\EhiReviewRefused;
use App\Services\PHR\Import\Ehi\EhiUploadService;
use App\Services\PHR\Import\Ehi\Mapping\EhiEncounterMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiSourceIdBridge;
use App\Services\PHR\Import\Ehi\Mapping\EhiTargetDigest;
use App\Services\PHR\Import\Ehi\Mapping\Encounter\EhiEncounterCategory;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use App\Services\PHR\Review\PhrReviewQueueService;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

/**
 * The encounter mapping over real intake, projection, and preview of synthetic
 * packages, and one flow through the browser routes to the visit's own page.
 */
class EhiEncounterMappingTest extends TestCase
{
    use EhiIntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Storage::fake('phr_exports');
        Storage::fake('phr_dicom');
        Queue::fake();
        config(['phr.ehi_mappings' => [EhiEncounterMapping::class]]);
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    public function test_only_a_clinical_encounter_is_proposed_as_a_visit_and_the_rest_stay_distinct_evidence(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->build(), $owner, $patient);

        foreach (['7101', '7102'] as $csn) {
            $proposal = $this->proposal($package, $csn);
            self::assertSame(PhrEhiProposal::KIND_CREATE, $proposal->kind, $csn);
            self::assertSame(EhiEncounterCategory::CLINICAL, $proposal->proposed_json['attributes']['encounter_category']);
        }
        foreach ([
            '7103' => EhiEncounterCategory::TELEPHONE,
            '7104' => EhiEncounterCategory::PORTAL,
            '7105' => EhiEncounterCategory::CANCELLED,
            '7106' => EhiEncounterCategory::CANCELLED,
            '7107' => EhiEncounterCategory::ADMINISTRATIVE,
            '7108' => EhiEncounterCategory::ADMINISTRATIVE,
            '7109' => EhiEncounterCategory::ADMINISTRATIVE,
            '7110' => EhiEncounterCategory::UNKNOWN,
            '7111' => EhiEncounterCategory::UNKNOWN,
        ] as $csn => $category) {
            $proposal = $this->proposal($package, (string) $csn);
            self::assertSame(PhrEhiProposal::KIND_RETAIN, $proposal->kind, (string) $csn);
            self::assertSame('category_'.$category, $proposal->proposed_json['retain_reason'], (string) $csn);
            self::assertSame($category, $proposal->proposed_json['attributes']['encounter_category'], 'the kind of event stays visible');
        }
        // 7110, 7111, and standard()'s two encounters, which state nothing about their kind.
        self::assertSame(4, PhrEhiFinding::query()->where('package_id', $package->id)->where('code', EhiEncounterMapping::FINDING_UNCLASSIFIED)->count());
        self::assertSame(0, PhrOfficeVisit::query()->count(), 'a preview writes no clinical rows');
    }

    public function test_fields_keep_their_text_precision_and_zone_and_never_carry_billing_columns(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->build(), $owner, $patient);

        $attributes = $this->proposal($package, '7101')->proposed_json['attributes'];
        self::assertSame('PAT_ENC:7101', $attributes['external_id']);
        self::assertSame('2024-05-01', $attributes['visit_date']);
        self::assertSame('2024-05-01', $attributes['visit_date_source']);
        self::assertSame('2024-05-01 09:30:00', $attributes['visit_started_at']);
        self::assertSame('2024-05-01 09:30', $attributes['visit_started_source']);
        self::assertSame('minute', $attributes['visit_started_precision']);
        self::assertSame('local', $attributes['visit_started_timezone']);
        self::assertSame('Office Visit', $attributes['visit_type']);
        self::assertSame('Completed', $attributes['encounter_status']);
        self::assertSame('SYNTHETIC PROVIDER', $attributes['provider_name']);
        self::assertSame('SYNTHETIC CLINIC', $attributes['facility_name']);
        self::assertStringContainsString('Synthetic contact comment', (string) $attributes['raw_text']);

        $encoded = json_encode($this->proposal($package, '7101')->proposed_json, JSON_THROW_ON_ERROR);
        foreach (['555001', '25.00', '555002', 'CLAIM_ID', 'COPAY_DUE', 'HSP_ACCOUNT_ID'] as $billing) {
            self::assertStringNotContainsString($billing, $encoded);
        }

        $hospital = $this->proposal($package, '7102')->proposed_json['attributes'];
        self::assertSame('2024-05-02 22:15:00', $hospital['visit_started_at'], 'a local wall clock, not shifted');
        self::assertSame('second', $hospital['visit_started_precision']);
        self::assertSame('2024-05-03 06:00:00', $hospital['visit_ended_at']);
        self::assertSame('local', $hospital['visit_ended_timezone']);
    }

    public function test_diagnoses_stay_on_the_encounter_in_order_and_never_become_conditions(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->build(), $owner, $patient);
        $proposal = $this->proposal($package, '7101');

        $diagnoses = $proposal->proposed_json['attributes']['encounter_diagnoses'];
        self::assertSame(['1', '2', '10'], array_column($diagnoses, 'line'), 'in numeric order, however they were stored');
        self::assertSame(['Synthetic diagnosis one', 'Synthetic diagnosis two', 'Synthetic diagnosis ten'], array_column($diagnoses, 'name'));
        self::assertTrue($diagnoses[0]['primary']);
        self::assertFalse($diagnoses[1]['primary']);
        self::assertTrue($diagnoses[1]['chronic']);
        self::assertNull($diagnoses[0]['chronic'], 'an empty flag is unknown, not false');
        self::assertNull($proposal->proposed_json['attributes']['icd10_codes'], 'no ICD-10 code is invented');

        $this->accept($proposal, $owner);
        $visit = PhrOfficeVisit::query()->sole();
        self::assertSame('Synthetic diagnosis one', $visit->encounter_diagnoses[0]['name'] ?? null);
        self::assertSame([], $visit->icd10_codes ?? []);
        self::assertSame(0, PhrCondition::query()->count(), 'an encounter diagnosis is not a longitudinal condition');
    }

    public function test_billing_and_workflow_never_produce_clinical_records(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->build(), $owner, $patient);
        foreach (PhrEhiProposal::query()->where('package_id', $package->id)->where('status', PhrEhiProposal::STATUS_PENDING)->get() as $proposal) {
            $decision = $proposal->kind === PhrEhiProposal::KIND_RETAIN ? PhrEhiProposal::DECISION_RETAIN : PhrEhiProposal::DECISION_ACCEPT;
            app(EhiProposalService::class)->review($proposal, $decision, $owner->id, $proposal->preview_digest);
        }

        self::assertSame(2, PhrOfficeVisit::query()->count());
        self::assertSame(0, PhrEob::query()->count());
        self::assertSame(0, PhrProcedure::query()->count());
        self::assertSame(0, PhrCondition::query()->count());
        $ledger = PhrEhiTableAccount::query()->where('package_id', $package->id)->where('table_name', 'ARPB_TRANSACTIONS')->sole();
        self::assertSame([PhrEhiTableAccount::DISPOSITION_OUT_OF_SCOPE => 1], $ledger->disposition_counts_json);
        self::assertSame(0, DB::table('phr_ehi_entities')->where('patient_id', $patient->id)->where('table_name', 'ARPB_TRANSACTIONS')->count());
    }

    public function test_every_row_keeps_one_disposition_and_an_orphan_diagnosis_is_reported(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->build(), $owner, $patient);

        foreach (['PAT_ENC', 'PAT_ENC_DX'] as $table) {
            $account = PhrEhiTableAccount::query()->where('package_id', $package->id)->where('table_name', $table)->sole();
            self::assertSame($account->source_row_count - $account->malformed_row_count, array_sum($account->disposition_counts_json ?? []), $table);
        }
        $diagnoses = PhrEhiTableAccount::query()->where('package_id', $package->id)->where('table_name', 'PAT_ENC_DX')->sole();
        self::assertSame(1, $diagnoses->disposition_counts_json[PhrEhiOccurrence::DISPOSITION_RETAINED], 'the diagnosis of an encounter not supplied');
        self::assertSame(4, $diagnoses->disposition_counts_json[PhrEhiOccurrence::DISPOSITION_PENDING_REVIEW]);
        self::assertSame(1, PhrEhiFinding::query()->where('package_id', $package->id)->where('code', EhiEncounterMapping::FINDING_UNMATCHED_DIAGNOSES)->count());
    }

    public function test_keys_come_from_the_dictionary_and_an_encounter_of_another_patient_is_retained(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->buildWithDocumentedKey('PAT_ENC_DX', ['PAT_ENC_CSN_ID']), $owner, $patient);

        self::assertSame(1, PhrEhiFinding::query()->where('package_id', $package->id)->where('code', EhiFindingCode::MAPPING_KEY_MISMATCH)->count());
        self::assertArrayNotHasKey('encounter_diagnoses', $this->proposal($package, '7101')->proposed_json['attributes'], 'a table whose key disagrees supplies nothing');
        $account = PhrEhiTableAccount::query()->where('package_id', $package->id)->where('table_name', 'PAT_ENC_DX')->sole();
        self::assertSame([PhrEhiTableAccount::DISPOSITION_RETAINED_UNMAPPED => 5], $account->disposition_counts_json);

        // A key the source moved away from: every encounter now names another patient.
        PhrEhiSource::query()->sole()->forceFill(['source_patient_key' => 'Z00000999'])->save();
        app(EhiProposalService::class)->preview($package, $patient, $owner->id);
        self::assertSame(EhiEncounterMapping::FINDING_FOREIGN_PATIENT, $this->proposal($package, '7101')->proposed_json['retain_reason']);
    }

    public function test_an_encounter_naming_no_patient_is_retained_as_an_orphan(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        // Intake refuses a package with a blank PAT_ID, so this is a stored revision
        // read by a later preview: the mapping does not rely on intake having said so.
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->build(), $owner, $patient);
        $revision = PhrEhiRevision::query()->whereKey(PhrEhiProposalRevision::idsFor($this->proposal($package, '7102'))[0])->sole();
        $revision->forceFill(['values_json' => [...$revision->values_json, 'PAT_ID' => null]])->save();
        app(EhiProposalService::class)->preview($package, $patient, $owner->id);

        $orphan = $this->proposal($package, '7102');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $orphan->kind);
        self::assertSame(EhiEncounterMapping::FINDING_ORPHAN, $orphan->proposed_json['retain_reason']);
    }

    public function test_a_same_day_visit_from_elsewhere_goes_to_review_and_a_person_can_say_either(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $fhir = $this->legacy($owner, $patient, ['import_source' => 'fhir', 'external_id' => 'Encounter/opaque-1', 'visit_date' => '2024-05-01', 'visit_type' => 'Synthetic ambulatory', 'raw_text' => '{"resourceType":"Encounter","id":"opaque-1"}']);
        $manual = $this->legacy($owner, $patient, ['visit_date' => '2024-05-02', 'provider_name' => 'A person wrote this']);
        $digest = EhiTargetDigest::of($manual->refresh());
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->build(), $owner, $patient);

        $first = $this->proposal($package, '7101');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $first->kind);
        self::assertSame([$fhir->id], $first->candidate_ids_json);
        $this->accept($first, $owner);
        self::assertSame(3, PhrOfficeVisit::query()->count(), 'two visits one day stay possible');
        self::assertSame('Encounter/opaque-1', $fhir->refresh()->external_id);

        $second = $this->proposal($package, '7102');
        self::assertSame([$manual->id], $second->candidate_ids_json);
        app(EhiProposalService::class)->review($second, PhrEhiProposal::DECISION_LINK, $owner->id, $second->preview_digest, $manual->id);
        self::assertSame($digest, EhiTargetDigest::of($manual->refresh()), 'a link changes nothing on the visit');
        $link = PhrEhiRecordLink::query()->where('office_visit_id', $manual->id)->firstOrFail();
        self::assertSame(EhiSourceIdBridge::RULE_PERSON_CONFIRMED, $link->bridge_rule);
        self::assertSame($manual->id, PhrEhiProposalDecision::query()->where('proposal_id', $second->id)->sole()->linked_office_visit_id);
    }

    public function test_a_compatible_identifier_links_evidence_and_a_different_date_is_a_conflict(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $ccda = $this->legacy($owner, $patient, ['import_source' => 'ccda', 'external_id' => '1.2.3|7101', 'visit_date' => '2024-05-01']);
        // UTC instant a day after a local late-evening admission: not a conflict.
        $fhir = $this->legacy($owner, $patient, ['import_source' => 'fhir', 'external_id' => 'Encounter/opaque-2', 'visit_date' => '2024-05-03', 'raw_text' => '{"resourceType":"Encounter","identifier":[{"system":"urn:example.test:csn","value":"7102"}]}']);
        $conflicting = $this->legacy($owner, $patient, ['import_source' => 'ccda', 'external_id' => '7103', 'visit_date' => '2019-01-01']);
        $digests = [EhiTargetDigest::of($ccda->refresh()), EhiTargetDigest::of($fhir->refresh())];
        $package = $this->previewed(
            EhiClinicalPackage::withEncounters()->changing('PAT_ENC', ['PAT_ENC_CSN_ID' => '7103'], ['ENC_TYPE_C_NAME' => 'Office Visit'])->build(),
            $owner,
            $patient,
        );

        $bridged = $this->proposal($package, '7101');
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $bridged->kind);
        self::assertSame($ccda->id, $bridged->target_id);
        self::assertSame(EhiSourceIdBridge::RULE_CCDA_ENCOUNTER_EXTENSION, $bridged->proposed_json['bridge']['rule']);
        $this->accept($bridged, $owner);
        self::assertSame(EhiSourceIdBridge::RULE_CCDA_ENCOUNTER_EXTENSION, PhrEhiRecordLink::query()->where('office_visit_id', $ccda->id)->value('bridge_rule'));
        self::assertSame(EhiSourceIdBridge::VERSION, PhrEhiRecordLink::query()->where('office_visit_id', $ccda->id)->value('bridge_version'));
        self::assertSame(PhrEhiRecordLink::KIND_BRIDGED, PhrEhiRecordLink::query()->where('office_visit_id', $ccda->id)->value('link_kind'));

        $viaFhir = $this->proposal($package, '7102');
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $viaFhir->kind);
        self::assertSame($fhir->id, $viaFhir->target_id);
        self::assertSame(EhiSourceIdBridge::RULE_FHIR_ENCOUNTER_IDENTIFIER, $viaFhir->proposed_json['bridge']['rule']);
        $this->accept($viaFhir, $owner);
        self::assertSame($digests, [EhiTargetDigest::of($ccda->refresh()), EhiTargetDigest::of($fhir->refresh())], 'corroboration is not modification');

        $conflict = $this->proposal($package, '7103');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $conflict->kind);
        self::assertSame([$conflicting->id], $conflict->candidate_ids_json);
        self::assertSame(['date'], $conflict->proposed_json['bridge']['conflicts']);
        self::assertSame(3, PhrOfficeVisit::query()->count());
    }

    public function test_only_a_stored_encounter_resource_can_bridge_a_csn(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        // FHIR ClinicalImpression resources are stored as visits too, and name their own identifiers.
        $impression = $this->legacy($owner, $patient, ['import_source' => 'fhir', 'external_id' => 'ClinicalImpression/opaque-3', 'visit_date' => '2024-05-01', 'raw_text' => '{"resourceType":"ClinicalImpression","identifier":[{"value":"7101"}]}']);
        $encounter = $this->legacy($owner, $patient, ['import_source' => 'fhir', 'external_id' => 'Encounter/opaque-4', 'visit_date' => '2024-05-02', 'raw_text' => '{"resourceType":"Encounter","identifier":[{"value":"7102"}]}']);
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->build(), $owner, $patient);

        $notBridged = $this->proposal($package, '7101');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $notBridged->kind, 'the same date still makes it a question for a person');
        self::assertArrayNotHasKey('bridge', $notBridged->proposed_json);
        self::assertSame([$impression->id], $notBridged->candidate_ids_json);

        $bridged = $this->proposal($package, '7102');
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $bridged->kind);
        self::assertSame($encounter->id, $bridged->target_id);
    }

    public function test_a_deleted_or_rejected_visit_is_never_a_target_nor_silently_recreated(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $bridged = $this->legacy($owner, $patient, ['import_source' => 'ccda', 'external_id' => '7101', 'visit_date' => '2024-05-01']);
        $bridged->delete();
        $lookalike = $this->legacy($owner, $patient, ['visit_date' => '2024-05-02', 'review_status' => PhrReviewStatus::REJECTED]);
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->build(), $owner, $patient);

        $retained = $this->proposal($package, '7101');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $retained->kind);
        self::assertSame(EhiEncounterMapping::FINDING_BRIDGE_WITHDRAWN, $retained->proposed_json['retain_reason']);
        $held = $this->proposal($package, '7102');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $held->kind);
        self::assertNull($held->candidate_ids_json);
        self::assertTrue($held->proposed_json['withdrawn_match']);

        try {
            app(EhiProposalService::class)->review($retained, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $retained->preview_digest);
            self::fail('a retained encounter cannot be accepted');
        } catch (EhiReviewRefused $refused) {
            self::assertSame(EhiReviewRefused::DECISION_NOT_ALLOWED, $refused->reason);
        }
        self::assertNotNull(PhrOfficeVisit::withTrashed()->findOrFail($bridged->id)->deleted_at);
        self::assertSame(PhrReviewStatus::REJECTED, $lookalike->refresh()->review_status);
    }

    public function test_diagnoses_a_package_cannot_vouch_for_are_never_read_as_an_empty_list(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->build(), $owner, $patient);
        $this->accept($this->proposal($package, '7101'), $owner);
        $visit = PhrOfficeVisit::query()->where('external_id', 'PAT_ENC:7101')->sole();
        $original = $visit->encounter_diagnoses;
        $sourceId = PhrEhiSource::query()->sole()->id;
        $withProvider = static fn (string $name): EhiClinicalPackage => EhiClinicalPackage::withEncounters()->changing('PAT_ENC', ['PAT_ENC_CSN_ID' => '7101'], ['VISIT_PROV_ID_PROV_NAME' => $name]);
        $approve = function (PhrEhiPackage $second) use ($owner): array {
            $update = $this->proposal($second, '7101');
            self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);
            app(EhiProposalService::class)->review($update, PhrEhiProposal::DECISION_APPROVE_UPDATE, $owner->id, $update->preview_digest);

            return $update->proposed_json;
        };

        // The table's key disagrees with its dictionary, so it supplies nothing at all.
        $withheld = $this->previewed($withProvider('SYNTHETIC SECOND')->buildWithDocumentedKey('PAT_ENC_DX', ['PAT_ENC_CSN_ID']), $owner, $patient, 'package-2', $sourceId);
        $proposed = $approve($withheld);
        self::assertSame(['provider_name', 'raw_text'], $proposed['changed']);
        self::assertTrue($proposed['ehi']['diagnoses_unavailable']);
        self::assertArrayNotHasKey('encounter_diagnoses', $proposed['attributes']);
        self::assertSame($original, $visit->refresh()->encounter_diagnoses);
        self::assertSame('SYNTHETIC SECOND', $visit->provider_name);

        // The package has no diagnosis table at all.
        $absent = $this->previewed($withProvider('SYNTHETIC THIRD')->build()->without('PAT_ENC_DX.tsv')->without('docs/PAT_ENC_DX.htm'), $owner, $patient, 'package-3', $sourceId);
        self::assertSame(['provider_name', 'raw_text'], $approve($absent)['changed']);
        self::assertSame($original, $visit->refresh()->encounter_diagnoses);

        // One of this encounter's rows repeats a key and is quarantined: the rest are a partial list.
        $repeated = $withProvider('SYNTHETIC FOURTH')->adding('PAT_ENC_DX', ['PAT_ENC_CSN_ID' => '7101', 'LINE' => '1', 'DX_ID_DX_NAME' => 'Synthetic repeated key']);
        $quarantined = $this->previewed($repeated->build(), $owner, $patient, 'package-4', $sourceId);
        self::assertSame(['provider_name', 'raw_text'], $approve($quarantined)['changed']);
        self::assertSame($original, $visit->refresh()->encounter_diagnoses);

        // A row that could not be read could belong to any encounter.
        $malformed = $withProvider('SYNTHETIC FIFTH')->build();
        $malformed = $malformed->with('PAT_ENC_DX.tsv', $malformed->files['PAT_ENC_DX.tsv']."only\ttwo\n");
        $unreadable = $this->previewed($malformed, $owner, $patient, 'package-5', $sourceId);
        self::assertSame(['provider_name', 'raw_text'], $approve($unreadable)['changed']);
        self::assertSame($original, $visit->refresh()->encounter_diagnoses);

        // A complete table with no row for this encounter does say there are none, and a person decides.
        $emptied = $withProvider('SYNTHETIC FIFTH')
            ->without('PAT_ENC_DX', ['PAT_ENC_CSN_ID' => '7101', 'LINE' => '1'])
            ->without('PAT_ENC_DX', ['PAT_ENC_CSN_ID' => '7101', 'LINE' => '2'])
            ->without('PAT_ENC_DX', ['PAT_ENC_CSN_ID' => '7101', 'LINE' => '10']);
        $explicit = $this->previewed($emptied->build(), $owner, $patient, 'package-6', $sourceId);
        $proposed = $approve($explicit);
        self::assertSame(['encounter_diagnoses'], $proposed['changed']);
        self::assertNull($visit->refresh()->encounter_diagnoses);
    }

    public function test_a_first_import_of_an_encounter_says_when_its_diagnoses_are_unavailable(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->buildWithDocumentedKey('PAT_ENC_DX', ['PAT_ENC_CSN_ID']), $owner, $patient);

        $proposal = $this->proposal($package, '7101');
        self::assertSame(PhrEhiProposal::KIND_CREATE, $proposal->kind);
        self::assertTrue($proposal->proposed_json['ehi']['diagnoses_unavailable']);
        self::assertGreaterThan(0, PhrEhiFinding::query()->where('package_id', $package->id)->where('code', EhiEncounterMapping::FINDING_DIAGNOSES_UNAVAILABLE)->count());
    }

    public function test_each_exported_visit_time_follows_its_own_zone_provenance(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->build(), $owner, $patient);
        $this->accept($this->proposal($package, '7102'), $owner);
        $visit = PhrOfficeVisit::query()->where('external_id', 'PAT_ENC:7102')->sole();
        $url = "/api/phr/patients/{$patient->id}/office-visits/{$visit->id}";
        $period = function () use ($patient): array {
            $bundle = json_decode(app(PhrFhirExporter::class)->bundleJson(app(PhrExportDataService::class)->load($patient->refresh())), true, flags: JSON_THROW_ON_ERROR);

            return collect($bundle['entry'])->pluck('resource')->firstWhere('resourceType', 'Encounter')['period'];
        };

        // Both are the source's unzoned wall clock.
        self::assertSame(['start' => '2024-05-02', 'end' => '2024-05-03'], $period());

        // A person states the start: it keeps its time, and the source's end stays unzoned.
        $this->actingAs($owner)->patchJson($url, ['visit_started_at' => '2024-05-02 23:00:00'])->assertOk();
        self::assertSame(['start' => '2024-05-02T23:00:00+00:00', 'end' => '2024-05-03'], $period());

        // And the other way round, on a visit whose start is still the source's.
        $visit->refresh()->forceFill(['visit_started_at' => '2024-05-02 22:15:00', 'visit_started_timezone' => 'local', 'visit_started_source' => '2024-05-02 22:15:00'])->save();
        $this->actingAs($owner)->patchJson($url, ['visit_ended_at' => '2024-05-03 07:00:00'])->assertOk();
        self::assertSame(['start' => '2024-05-02', 'end' => '2024-05-03T07:00:00+00:00'], $period());
    }

    public function test_a_visit_rejected_after_the_preview_cannot_be_linked_and_gets_no_later_update(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $candidate = $this->legacy($owner, $patient, ['visit_date' => '2024-05-02', 'provider_name' => 'A person wrote this']);
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->build(), $owner, $patient);
        $base = "/api/phr/patients/{$patient->id}";

        // Rejected after the preview offered it: a link is refused under the lock.
        $duplicate = $this->proposal($package, '7102');
        self::assertSame([$candidate->id], $duplicate->candidate_ids_json);
        $this->actingAs($owner)->patchJson("{$base}/office-visits/{$candidate->id}/review", ['review_status' => PhrReviewStatus::REJECTED])->assertOk();
        $this->actingAs($owner)->postJson("{$base}/ehi/proposals/{$duplicate->id}/review", ['decision' => 'link_existing', 'preview_digest' => $duplicate->preview_digest, 'link_id' => $candidate->id])
            ->assertStatus(409)->assertExactJson(['error' => 'target_withdrawn']);
        self::assertSame(0, PhrEhiRecordLink::query()->where('office_visit_id', $candidate->id)->count());

        // Accepted, then rejected: a later export's change is kept as evidence, not proposed.
        $accepted = $this->proposal($package, '7101');
        $recordId = $this->actingAs($owner)->postJson("{$base}/ehi/proposals/{$accepted->id}/review", ['decision' => 'accept', 'preview_digest' => $accepted->preview_digest])->assertOk()->json('record.id');
        $this->actingAs($owner)->patchJson("{$base}/office-visits/{$recordId}/review", ['review_status' => PhrReviewStatus::REJECTED])->assertOk();
        $changed = EhiClinicalPackage::withEncounters()->changing('PAT_ENC', ['PAT_ENC_CSN_ID' => '7101'], ['VISIT_PROV_ID_PROV_NAME' => 'SYNTHETIC SECOND PROVIDER']);
        $second = $this->previewed($changed->build(), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id);

        $retained = $this->proposal($second, '7101');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $retained->kind);
        self::assertSame(EhiEncounterMapping::FINDING_LINKED_RECORD_WITHDRAWN, $retained->proposed_json['retain_reason']);
        self::assertSame('SYNTHETIC PROVIDER', PhrOfficeVisit::query()->findOrFail($recordId)->provider_name);
    }

    public function test_changed_source_content_is_an_explicit_update_a_status_change_is_not_and_replay_changes_nothing(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $build = EhiClinicalPackage::withEncounters();
        $package = $this->previewed($build->build(), $owner, $patient);
        $this->accept($this->proposal($package, '7101'), $owner);
        $this->accept($this->proposal($package, '7102'), $owner);
        $visit = PhrOfficeVisit::query()->where('external_id', 'PAT_ENC:7101')->sole();
        $visit->forceFill(['assessment' => 'Synthetic assessment a person wrote'])->save();
        $sourceId = PhrEhiSource::query()->sole()->id;

        // Replay: the same package, nothing moves.
        $replay = $this->ehiIntakeDirectory($build->build(), $patient, (int) $owner->id, 'replay');
        self::assertTrue($replay->replayed);
        $visits = PhrOfficeVisit::query()->count();
        self::assertSame(1, PhrEhiPackage::query()->count());

        $next = $build
            ->changing('PAT_ENC', ['PAT_ENC_CSN_ID' => '7101'], ['VISIT_PROV_ID_PROV_NAME' => 'SYNTHETIC SECOND PROVIDER'])
            ->changing('PAT_ENC', ['PAT_ENC_CSN_ID' => '7102'], ['APPT_STATUS_C_NAME' => 'Canceled'])
            ->changing('PAT_ENC_DX', ['PAT_ENC_CSN_ID' => '7101', 'LINE' => '1'], ['DX_ID_DX_NAME' => 'Synthetic corrected diagnosis']);
        $second = $this->previewed($next->build(), $owner, $patient, 'package-2', $sourceId);

        $update = $this->proposal($second, '7101');
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);
        self::assertEqualsCanonicalizing(['provider_name', 'raw_text', 'encounter_diagnoses'], $update->proposed_json['changed']);
        self::assertSame('SYNTHETIC PROVIDER', $update->before_json['provider_name']);
        self::assertSame('Synthetic diagnosis one', $update->before_json['encounter_diagnoses'][0]['name']);
        self::assertSame('SYNTHETIC PROVIDER', $visit->refresh()->provider_name, 'nothing changes until a person approves');
        app(EhiProposalService::class)->review($update, PhrEhiProposal::DECISION_APPROVE_UPDATE, $owner->id, $update->preview_digest);
        $visit->refresh();
        self::assertSame('SYNTHETIC SECOND PROVIDER', $visit->provider_name);
        self::assertSame('Synthetic corrected diagnosis', $visit->encounter_diagnoses[0]['name'] ?? null);
        self::assertSame('Synthetic assessment a person wrote', $visit->assessment);

        $cancelled = $this->proposal($second, '7102');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $cancelled->kind);
        self::assertSame(EhiEncounterMapping::FINDING_LINKED_NOT_CLINICAL, $cancelled->proposed_json['retain_reason']);
        self::assertSame(EhiEncounterCategory::CLINICAL, PhrOfficeVisit::query()->where('external_id', 'PAT_ENC:7102')->sole()->encounter_category, 'an accepted visit is not replaced by a status change');
        self::assertSame($visits, PhrOfficeVisit::query()->count());

        // A deleted visit is retained and stays deleted; absence from a package touches nothing.
        PhrOfficeVisit::query()->where('external_id', 'PAT_ENC:7102')->sole()->delete();
        $third = $this->previewed($build->changing('PAT_ENC', ['PAT_ENC_CSN_ID' => '7102'], ['VISIT_PROV_ID_PROV_NAME' => 'SYNTHETIC THIRD PROVIDER'])->without('PAT_ENC', ['PAT_ENC_CSN_ID' => '7101'])->build(), $owner, $patient, 'package-3', $sourceId);
        self::assertSame(EhiEncounterMapping::FINDING_LINKED_RECORD_WITHDRAWN, $this->proposal($third, '7102')->proposed_json['retain_reason']);
        self::assertNotNull(PhrOfficeVisit::withTrashed()->where('external_id', 'PAT_ENC:7102')->sole()->deleted_at);
        self::assertSame('SYNTHETIC SECOND PROVIDER', $visit->refresh()->provider_name);
    }

    public function test_a_diagnosis_corrected_with_the_encounter_row_unchanged_reaches_the_visit_through_an_approved_update(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withEncounters()->build(), $owner, $patient);
        $this->accept($this->proposal($package, '7101'), $owner);
        $visit = PhrOfficeVisit::query()->where('external_id', 'PAT_ENC:7101')->sole();
        $visit->forceFill(['provider_name' => 'Edited by a person'])->save();
        $sourceId = PhrEhiSource::query()->sole()->id;

        // PAT_ENC is byte-identical; only a diagnosis row changed.
        $corrected = EhiClinicalPackage::withEncounters()->changing('PAT_ENC_DX', ['PAT_ENC_CSN_ID' => '7101', 'LINE' => '1'], ['DX_ID_DX_NAME' => 'Synthetic corrected diagnosis']);
        $second = $this->previewed($corrected->build(), $owner, $patient, 'package-2', $sourceId);

        $update = $this->proposal($second, '7101');
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);
        self::assertSame(['encounter_diagnoses'], $update->proposed_json['changed'], 'what a person edited and the source did not change stays out of it');
        self::assertSame('Synthetic diagnosis one', $visit->refresh()->encounter_diagnoses[0]['name'] ?? null, 'nothing changes until a person approves');

        app(EhiProposalService::class)->review($update, PhrEhiProposal::DECISION_APPROVE_UPDATE, $owner->id, $update->preview_digest);
        $visit->refresh();
        self::assertSame('Synthetic corrected diagnosis', $visit->encounter_diagnoses[0]['name'] ?? null);
        self::assertSame('Edited by a person', $visit->provider_name);

        // The same content again corroborates.
        $third = $this->previewed($corrected->adding('PAT_ENC_DX', ['PAT_ENC_CSN_ID' => '9998', 'LINE' => '1', 'DX_ID_DX_NAME' => 'Synthetic other orphan'])->build(), $owner, $patient, 'package-3', $sourceId);
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $this->proposal($third, '7101')->kind);
    }

    public function test_an_accepted_encounter_reaches_the_visit_page_from_the_browser_and_survives_backup_and_restore(): void
    {
        config(['phr.ehi_mappings' => (require base_path('config/phr.php'))['ehi_mappings']]);
        self::assertContains(EhiEncounterMapping::class, config('phr.ehi_mappings'), 'the mapping is registered in production config');
        [$owner, $patient] = $this->ownerAndPatient();
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $base = "/api/phr/patients/{$patient->id}";

        $zip = EhiClinicalPackage::withEncounters()->build()->writeZip($this->ehiRoot.'/'.bin2hex(random_bytes(4)).'.zip');
        $bytes = (string) file_get_contents($zip);
        $uploadId = $this->actingAs($owner)->postJson("{$base}/ehi/uploads", ['file_size_bytes' => strlen($bytes)])->assertCreated()->json('upload.id');
        $this->actingAs($owner)->post("{$base}/ehi/uploads/{$uploadId}/chunks", ['chunk' => UploadedFile::fake()->createWithContent('chunk.bin', $bytes), 'offset' => 0], ['Accept' => 'application/json'])->assertOk();
        $this->actingAs($owner)->postJson("{$base}/ehi/uploads/{$uploadId}/intake")->assertStatus(202);
        (new IntakeEhiUploadJob($uploadId))->handle(app(EhiUploadService::class));
        $packageId = PhrEhiUpload::query()->findOrFail($uploadId)->package_id;
        $this->actingAs($owner)->postJson("{$base}/ehi/packages/{$packageId}/binding", ['new_source_label' => 'Synthetic Clinic'])->assertOk();
        $runId = $this->actingAs($owner)->postJson("{$base}/ehi/packages/{$packageId}/preview")->assertStatus(202)->json('preview_run.id');
        (new PreviewEhiPackageJob($runId))->handle(app(EhiPreviewQueue::class));
        $package = PhrEhiPackage::query()->findOrFail($packageId);

        // Both review surfaces offer it, under office visits.
        $this->actingAs($owner)->getJson("{$base}/ehi/pending")->assertOk()->assertJsonPath('pending.encounter.count', 13);
        $group = collect($this->actingAs($owner)->getJson("{$base}/review")->assertOk()->json('groups'))->firstWhere('key', "ehi:{$package->id}");
        self::assertCount(13, collect($group['items'])->where('section', 'office-visits'));
        self::assertSame(13, app(PhrReviewQueueService::class)->summary($patient)['sections']['office-visits']['pending']);

        $proposal = $this->proposal($package, '7101');
        $review = $this->actingAs($owner)->postJson("{$base}/ehi/proposals/{$proposal->id}/review", ['decision' => 'accept', 'preview_digest' => $proposal->preview_digest])->assertOk();
        self::assertSame('phr_office_visits', $review->json('record.table'));
        $recordId = $review->json('record.id');

        $shown = $this->actingAs($owner)->getJson("{$base}/office-visits/{$recordId}")->assertOk()->json('office_visit');
        self::assertSame('2024-05-01', $shown['visit_date']);
        self::assertSame('2024-05-01 09:30:00', $shown['visit_started_at']);
        self::assertSame('local', $shown['visit_started_timezone']);
        self::assertSame('clinical', $shown['encounter_category']);
        self::assertSame('Synthetic diagnosis one', $shown['encounter_diagnoses'][0]['name']);
        self::assertSame('confirmed', $shown['review_status']);
        self::assertSame(1, collect($this->actingAs($owner)->getJson("{$base}/office-visits")->assertOk()->json('office_visits'))->where('id', $recordId)->count());

        // A local time is not exported as UTC.
        $bundle = json_decode(app(PhrFhirExporter::class)->bundleJson(app(PhrExportDataService::class)->load($patient->refresh())), true, flags: JSON_THROW_ON_ERROR);
        $encounter = collect($bundle['entry'])->pluck('resource')->firstWhere('resourceType', 'Encounter');
        self::assertSame('2024-05-01', $encounter['period']['start']);

        // Editing a date on the visit's page drops what the source said of the old one.
        $edited = $this->actingAs($owner)->patchJson("{$base}/office-visits/{$recordId}", ['visit_started_at' => '2024-05-01 10:00:00'])->assertOk()->json('office_visit');
        self::assertNull($edited['visit_started_timezone']);
        self::assertNull($edited['visit_started_source']);

        // ---- native backup, patient deletion, restore.
        $counts = $this->evidenceCounts((int) $patient->id);
        $backups = app(PhrNativeBackupService::class);
        $backup = $backups->generate($backups->createQueuedBackup($patient, $owner->id));
        $archive = tempnam(sys_get_temp_dir(), 'ehi-encounter-e2e-');
        self::assertIsString($archive);
        self::assertTrue(copy(Storage::disk('phr_exports')->path((string) $backup->storage_path), $archive));
        $deletions = app(PhrPatientDeletionService::class);
        $plan = $deletions->preview($patient);
        self::assertSame([], $plan->blockers);
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletions->delete($patient, $owner->id, $plan->digest, true)->id);
        self::assertSame(0, DB::table('phr_ehi_record_links')->count());
        self::assertSame(0, DB::table('phr_office_visits')->where('patient_id', $patient->id)->count());

        $restore = app(PhrNativeRestoreService::class);
        $attempt = $restore->preview($restore->createPreview(new UploadedFile($archive, 'synthetic-native.zip', 'application/zip', null, true), $owner->id, false));
        self::assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);
        $restore->apply($attempt->refresh());
        self::assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $attempt->refresh()->status, (string) $attempt->failure_category);
        @unlink($archive);

        $restoredId = (int) $attempt->target_patient_root_id;
        self::assertSame($counts, $this->evidenceCounts($restoredId));
        $restored = PhrOfficeVisit::query()->where('patient_id', $restoredId)->sole();
        self::assertSame('clinical', $restored->encounter_category);
        self::assertSame('Synthetic diagnosis two', $restored->encounter_diagnoses[1]['name'] ?? null);
        self::assertGreaterThan(0, DB::table('phr_ehi_record_links')->where('patient_id', $restoredId)->where('office_visit_id', $restored->id)->count());
        self::assertSame(1, DB::table('phr_ehi_proposal_decisions')->where('patient_id', $restoredId)->where('linked_office_visit_id', $restored->id)->count());
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();

        return [$owner, $this->ehiPatient($owner)];
    }

    private function previewed(EhiSyntheticPackage $build, User $owner, PhrPatient $patient, string $name = 'package', ?int $sourceId = null): PhrEhiPackage
    {
        $package = $this->ehiIntakeDirectory($build, $patient, (int) $owner->id, $name)->package;
        $package = app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, $sourceId, $sourceId === null ? 'Synthetic Clinic' : null);
        app(EhiProposalService::class)->preview($package, $patient, (int) $owner->id);

        return $package->refresh();
    }

    private function proposal(PhrEhiPackage $package, string $csn): PhrEhiProposal
    {
        return PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', EhiEncounterMapping::NAME)
            ->where('subject_key', EhiMappingContext::keyDigest(['PAT_ENC_CSN_ID' => $csn]))
            ->firstOrFail();
    }

    private function accept(PhrEhiProposal $proposal, User $owner): void
    {
        $proposal->refresh();
        app(EhiProposalService::class)->review($proposal, PhrEhiProposal::DECISION_ACCEPT, (int) $owner->id, $proposal->preview_digest);
    }

    /** @param  array<string, mixed>  $attributes */
    private function legacy(User $owner, PhrPatient $patient, array $attributes): PhrOfficeVisit
    {
        return PhrOfficeVisit::query()->forceCreate([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'review_status' => PhrReviewStatus::CONFIRMED,
            ...$attributes,
        ]);
    }

    /** @return array<string, int> */
    private function evidenceCounts(int $patientId): array
    {
        $counts = [];
        foreach (['phr_ehi_entities', 'phr_ehi_revisions', 'phr_ehi_occurrences', 'phr_ehi_proposals', 'phr_ehi_proposal_revisions', 'phr_ehi_proposal_decisions', 'phr_ehi_record_links', 'phr_office_visits'] as $table) {
            $counts[$table] = DB::table($table)->where('patient_id', $patientId)->count();
        }

        return $counts;
    }
}
