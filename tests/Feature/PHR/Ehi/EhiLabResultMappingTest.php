<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiFinding;
use App\Models\PhrEhiOccurrence;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiProposalDecision;
use App\Models\PhrEhiProposalRevision;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiSource;
use App\Models\PhrEhiTableAccount;
use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiFindingCode;
use App\Services\PHR\Import\Ehi\EhiProjectionRefused;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\EhiReviewRefused;
use App\Services\PHR\Import\Ehi\EhiZipSource;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyOutcome;
use App\Services\PHR\Import\Ehi\Mapping\EhiLabResultMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingRegistry;
use App\Services\PHR\Import\Ehi\Mapping\EhiProposalDraft;
use App\Services\PHR\Import\Ehi\Mapping\EhiSourceIdBridge;
use App\Services\PHR\Import\Ehi\Mapping\EhiTargetDigest;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

/**
 * The lab mapping over real intake, projection, and preview of synthetic packages:
 * every row here went through the TSV reader and the package's own dictionary.
 */
class EhiLabResultMappingTest extends TestCase
{
    use EhiIntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Storage::fake('phr_exports');
        Queue::fake();
        config(['phr.ehi_mappings' => [EhiLabResultMapping::class]]);
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    public function test_components_are_proposed_per_full_key_with_exact_values_units_ranges_and_comments(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);

        $ldl = $this->proposal($package, '8101', '66000.01', '1');
        self::assertSame(PhrEhiProposal::KIND_CREATE, $ldl->kind);
        $attributes = $ldl->proposed_json['attributes'];
        self::assertSame('ORDER_RESULTS:8101:66000.01:1', $attributes['external_id']);
        self::assertSame('SYNTHETIC LDL', $attributes['analyte']);
        self::assertSame('SYNTHETIC PANEL', $attributes['test_name']);
        self::assertSame('<50', $attributes['value'], 'the inequality stays in the value');
        self::assertSame('<', $attributes['value_comparator']);
        self::assertNull($attributes['value_numeric'], 'an inequality is not a measurement, and 9999999 is never read');
        self::assertSame('mg/dL', $attributes['unit']);
        self::assertSame('Below 100', $attributes['reference_range_text']);
        self::assertSame('Low', $attributes['abnormal_flag']);
        self::assertSame('Final', $attributes['result_status']);
        self::assertSame('SYNTHETIC PROVIDER', $attributes['ordering_provider']);
        self::assertSame('SYNTHETIC LAB', $attributes['resulting_lab']);
        self::assertSame('41', $attributes['analyte_code']);
        self::assertSame(EhiLabResultMapping::ANALYTE_CODE_SYSTEM, $attributes['analyte_code_system']);
        self::assertSame(
            "Synthetic short comment\nSynthetic comment line 1\nSynthetic comment line 2\nSynthetic comment line 10",
            $attributes['result_comment'],
            'comment lines in numeric order, however the rows were stored',
        );
        self::assertSame("Synthetic narrative line 1\nSynthetic narrative line 2", $attributes['notes']);
        self::assertTrue($ldl->proposed_json['ehi']['comment_line_gaps']);

        // Wall clock as written, with what the source documents about it.
        self::assertSame('2024-03-05 09:15:00', $attributes['collection_datetime']);
        self::assertSame('2024-03-05 09:15', $attributes['collected_at_source']);
        self::assertSame('minute', $attributes['collected_at_precision']);
        self::assertSame('local', $attributes['collected_at_timezone']);
        self::assertSame('2024-03-05 11:40:30', $attributes['result_datetime']);
        self::assertSame('second', $attributes['resulted_at_precision']);

        $glucose = $this->proposal($package, '8101', '66000.01', '2')->proposed_json['attributes'];
        self::assertSame('95.0', $glucose['value']);
        self::assertSame('95,0', $glucose['value_raw'], 'the raw value keeps its comma');
        self::assertSame('95.0', $glucose['value_numeric']);
        self::assertSame('70.00', $glucose['range_min']);
        self::assertSame('99', $glucose['range_max']);
        self::assertNull($glucose['notes'], 'a narrative is recorded once, on the lowest line');
        self::assertSame('2024-03-05', $glucose['resulted_at_source']);
        self::assertSame('date', $glucose['resulted_at_precision']);
        self::assertSame('unspecified', $glucose['resulted_at_timezone'], 'RESULT_DATE documents no zone, and none is applied');

        // A text value, and a date written another way, kept as the source wrote it.
        $text = $this->proposal($package, '8102', '66001', '1')->proposed_json['attributes'];
        self::assertSame('No growth', $text['value']);
        self::assertNull($text['value_numeric']);
        self::assertSame('2024-03-06 00:00:00', $text['result_datetime']);
        self::assertSame('3/6/2024', $text['resulted_at_source']);
    }

    public function test_the_contact_is_part_of_the_identity_so_same_day_repeats_each_stay_a_result(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);

        $first = $this->proposal($package, '8101', '66000.01', '1');
        $repeat = $this->proposal($package, '8101', '66000.02', '1');
        self::assertNotSame($first->subject_key, $repeat->subject_key);
        $this->accept($first, $owner);
        // A later preview sees the accepted sibling, and still does not call it a duplicate.
        app(EhiProposalService::class)->preview($package, $patient, $owner->id);
        self::assertSame(PhrEhiProposal::KIND_CREATE, $repeat->refresh()->kind);
        $this->accept($repeat, $owner);

        self::assertEqualsCanonicalizing(['<50', '52'], PhrLabResult::query()->where('analyte', 'SYNTHETIC LDL')->pluck('value')->all());
        self::assertSame(2, PhrLabResult::query()->where('collected_at_source', '2024-03-05 09:15')->orWhere('resulted_at_source', '2024-03-05')->count());
    }

    public function test_a_contact_key_keeps_its_exact_decimal_spelling(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(
            EhiClinicalPackage::withLabs()
                ->adding('ORDER_PROC', ['ORDER_PROC_ID' => '8200', 'PAT_ID' => EhiClinicalPackage::PATIENT_KEY])
                ->adding('ORDER_RESULTS', ['ORDER_PROC_ID' => '8200', 'ORD_DATE_REAL' => '66000.10', 'LINE' => '1', 'COMPONENT_ID_NAME' => 'SYNTHETIC ONE', 'ORD_VALUE' => '1'])
                ->adding('ORDER_RESULTS', ['ORDER_PROC_ID' => '8200', 'ORD_DATE_REAL' => '66000.1', 'LINE' => '1', 'COMPONENT_ID_NAME' => 'SYNTHETIC TWO', 'ORD_VALUE' => '2'])
                ->adding('ORDER_RESULTS', ['ORDER_PROC_ID' => '0008200', 'ORD_DATE_REAL' => '66000.1', 'LINE' => '1', 'COMPONENT_ID_NAME' => 'SYNTHETIC THREE', 'ORD_VALUE' => '3'])
                ->build(),
            $owner,
            $patient,
        );

        self::assertSame('ORDER_RESULTS:8200:66000.10:1', $this->proposal($package, '8200', '66000.10', '1')->proposed_json['attributes']['external_id']);
        self::assertSame('ORDER_RESULTS:8200:66000.1:1', $this->proposal($package, '8200', '66000.1', '1')->proposed_json['attributes']['external_id']);
        $leadingZeros = $this->proposal($package, '0008200', '66000.1', '1');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $leadingZeros->kind, '0008200 and 8200 are different orders, and that one was not supplied');
        self::assertSame(EhiLabResultMapping::FINDING_ORPHAN, $leadingZeros->proposed_json['retain_reason']);
        self::assertSame('ORDER_RESULTS:a%3Ab:1%3A2:3', EhiLabResultMapping::externalId('a:b', '1:2', '3'), 'a part cannot run into the next');
    }

    public function test_rows_that_assert_no_result_are_retained_with_their_reason(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);

        foreach ([
            ['8103', '66002', 'order_withdrawn'],
            ['8105', '66003', 'status_incomplete'],
            ['8106', '66004', EhiLabResultMapping::FINDING_ORPHAN],
            ['8107', '66005', 'order_type_not_laboratory'],
        ] as [$order, $contact, $reason]) {
            $proposal = $this->proposal($package, $order, $contact, '1');
            self::assertSame(PhrEhiProposal::KIND_RETAIN, $proposal->kind, $order);
            self::assertSame($reason, $proposal->proposed_json['retain_reason'], $order);
        }
        self::assertSame(0, PhrLabResult::query()->count(), 'a preview writes no clinical rows');
        self::assertSame(1, PhrEhiFinding::query()->where('package_id', $package->id)->where('code', EhiLabResultMapping::FINDING_ORPHAN)->count());
        self::assertSame(1, PhrEhiFinding::query()->where('package_id', $package->id)->where('code', EhiLabResultMapping::FINDING_ORDERS_WITHOUT_RESULTS)->count());
        self::assertSame(1, PhrEhiFinding::query()->where('package_id', $package->id)->where('code', EhiLabResultMapping::FINDING_UNMATCHED_NARRATIVE)->count());
    }

    public function test_a_value_the_columns_cannot_hold_is_retained_not_cut(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $long = str_repeat('x', 256);
        $package = $this->previewed(
            EhiClinicalPackage::withLabs()
                ->changing('ORDER_RESULTS', ['ORDER_PROC_ID' => '8102', 'LINE' => '1'], ['ORD_VALUE' => $long])
                ->changing('ORDER_RESULTS', ['ORDER_PROC_ID' => '8101', 'ORD_DATE_REAL' => '66000.02', 'LINE' => '1'], ['RESULT_DATE' => 'sometime in spring'])
                ->build(),
            $owner,
            $patient,
        );

        $tooLong = $this->proposal($package, '8102', '66001', '1');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $tooLong->kind);
        self::assertSame(EhiLabResultMapping::FINDING_VALUE_TOO_LONG, $tooLong->proposed_json['retain_reason']);
        self::assertSame($long, $tooLong->proposed_json['attributes']['value']);
        $badDate = $this->proposal($package, '8101', '66000.02', '1');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $badDate->kind);
        self::assertSame(EhiLabResultMapping::FINDING_UNPARSEABLE_DATE, $badDate->proposed_json['retain_reason']);
        self::assertSame('sometime in spring', $badDate->proposed_json['attributes']['resulted_at_source'], 'the text survives what could not be read');
    }

    public function test_keys_come_from_the_packages_own_dictionary(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        // A dictionary that documents the older order-and-line key: nothing may be
        // keyed on it, so the results are retained in their preserved bytes.
        $package = $this->previewed(
            EhiClinicalPackage::withLabs()->buildWithDocumentedKey('ORDER_RESULTS', ['ORDER_PROC_ID', 'LINE']),
            $owner,
            $patient,
        );

        self::assertSame(0, PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', EhiLabResultMapping::NAME)->count());
        self::assertSame(1, PhrEhiFinding::query()->where('package_id', $package->id)->where('code', EhiFindingCode::MAPPING_KEY_MISMATCH)->count());
        $account = PhrEhiTableAccount::query()->where('package_id', $package->id)->where('table_name', 'ORDER_RESULTS')->sole();
        self::assertSame([PhrEhiTableAccount::DISPOSITION_RETAINED_UNMAPPED => 8], $account->disposition_counts_json);
    }

    public function test_every_row_keeps_one_disposition_and_a_row_nothing_relies_on_is_not_left_pending(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);

        $accounts = PhrEhiTableAccount::query()->where('package_id', $package->id)->whereIn('table_name', ['ORDER_RESULTS', 'ORDER_PROC', 'ORDER_RES_COMMENT', 'ORDER_NARRATIVE', 'PAT_ENC'])->get()->keyBy('table_name');
        foreach ($accounts as $account) {
            self::assertSame($account->source_row_count - $account->malformed_row_count, array_sum($account->disposition_counts_json ?? []), $account->table_name);
        }
        self::assertSame(8, $accounts['ORDER_RESULTS']->disposition_counts_json[PhrEhiOccurrence::DISPOSITION_PENDING_REVIEW]);
        self::assertSame(1, $accounts['ORDER_PROC']->disposition_counts_json[PhrEhiOccurrence::DISPOSITION_RETAINED], 'order 8104 has no result to review');
        self::assertSame(1, $accounts['ORDER_NARRATIVE']->disposition_counts_json[PhrEhiOccurrence::DISPOSITION_RETAINED], 'its narrative neither');
        self::assertSame(1, $accounts['PAT_ENC']->disposition_counts_json[PhrEhiOccurrence::DISPOSITION_RETAINED], 'encounter 7002 is named by no result');

        // Deciding moves a row along, and the account follows.
        $this->accept($this->proposal($package, '8102', '66001', '1'), $owner);
        $accounts['ORDER_RESULTS']->refresh();
        self::assertSame(1, $accounts['ORDER_RESULTS']->disposition_counts_json[PhrEhiOccurrence::DISPOSITION_PROJECTED]);
        self::assertSame(7, $accounts['ORDER_RESULTS']->disposition_counts_json[PhrEhiOccurrence::DISPOSITION_PENDING_REVIEW]);
    }

    public function test_a_row_retained_as_unreferenced_goes_back_to_pending_once_a_proposal_relies_on_it(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $member = PhrEhiPackageMember::query()->where('package_id', $package->id)->where('table_name', 'PAT_ENC')->sole();
        self::assertSame(1, PhrEhiOccurrence::query()->where('member_id', $member->id)->where('disposition', PhrEhiOccurrence::DISPOSITION_RETAINED)->count());

        config(['phr.ehi_mappings' => [EhiLabResultMapping::class, EhiEveryEncounterMapping::class]]);
        app()->forgetInstance(EhiMappingRegistry::class);
        app()->forgetInstance(EhiProposalService::class);
        app(EhiProposalService::class)->preview($package, $patient, $owner->id);

        self::assertSame(0, PhrEhiOccurrence::query()->where('member_id', $member->id)->where('disposition', PhrEhiOccurrence::DISPOSITION_RETAINED)->count());
    }

    public function test_a_probable_duplicate_goes_to_review_and_a_different_value_the_same_day_does_not(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $sameValue = $this->legacy($owner, $patient, ['import_source' => 'fhir', 'external_id' => 'Observation/opaque-1', 'analyte' => 'synthetic glucose', 'value' => '95 mg/dL', 'value_numeric' => '95', 'unit' => 'mg/dL', 'result_datetime' => '2024-03-05 10:00:00']);
        $otherValue = $this->legacy($owner, $patient, ['import_source' => 'ccda', 'external_id' => 'ccda-lab-'.sha1('a'), 'analyte' => 'SYNTHETIC LDL', 'value' => '140', 'value_numeric' => '140', 'result_datetime' => '2024-03-05 10:00:00']);
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);

        $duplicate = $this->proposal($package, '8101', '66000.01', '2');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $duplicate->kind);
        self::assertSame([$sameValue->id], $duplicate->candidate_ids_json);
        self::assertSame(PhrEhiProposal::KIND_CREATE, $this->proposal($package, '8101', '66000.02', '1')->kind, 'LDL 52 is not LDL 140');

        // Same-day repetition stays possible: a person can say it is a different result.
        $digest = EhiTargetDigest::of($sameValue->refresh());
        $this->accept($duplicate, $owner);
        self::assertSame(2, PhrLabResult::query()->where('analyte', '!=', 'SYNTHETIC LDL')->count());
        self::assertSame($digest, EhiTargetDigest::of($sameValue->refresh()));
        self::assertSame('Observation/opaque-1', $sameValue->external_id);
        self::assertSame(0, PhrEhiRecordLink::query()->where('lab_result_id', $sameValue->id)->count());
        self::assertNotNull($otherValue->refresh()->id);
    }

    public function test_a_fhir_instant_a_day_off_the_local_date_is_still_a_probable_duplicate(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $fhir = $this->legacy($owner, $patient, ['import_source' => 'fhir', 'external_id' => 'Observation/opaque-2', 'analyte' => 'SYNTHETIC GLUCOSE', 'value' => '95.00', 'value_numeric' => '95', 'result_datetime' => '2024-03-06 01:00:00']);
        $ccda = $this->legacy($owner, $patient, ['import_source' => 'ccda', 'external_id' => 'ccda-lab-'.sha1('b'), 'analyte' => 'SYNTHETIC GLUCOSE', 'value' => '95', 'value_numeric' => '95', 'result_datetime' => '2024-03-06 01:00:00']);
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);

        $proposal = $this->proposal($package, '8101', '66000.01', '2');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $proposal->kind);
        self::assertSame([$fhir->id], $proposal->candidate_ids_json, 'only FHIR stores an instant; a C-CDA date a day off is another day');
        self::assertNotNull($ccda->id);
    }

    public function test_a_person_can_link_a_probable_duplicate_without_changing_the_record(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $manual = $this->legacy($owner, $patient, ['analyte' => 'Synthetic Glucose Long Name', 'value' => '95', 'value_numeric' => '95', 'collection_datetime' => '2024-03-05 08:00:00', 'notes' => 'Synthetic note a person wrote']);
        $digest = EhiTargetDigest::of($manual->refresh());
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);

        $duplicate = $this->proposal($package, '8101', '66000.01', '2');
        self::assertSame([$manual->id], $duplicate->candidate_ids_json, 'matched through the LOINC long name, with no identifier in common');
        app(EhiProposalService::class)->review($duplicate, PhrEhiProposal::DECISION_LINK, $owner->id, $duplicate->preview_digest, $manual->id);

        self::assertSame(PhrEhiProposal::STATUS_LINKED, $duplicate->refresh()->status);
        self::assertSame($digest, EhiTargetDigest::of($manual->refresh()), 'linking evidence changes nothing on the record');
        $link = PhrEhiRecordLink::query()->where('lab_result_id', $manual->id)->firstOrFail();
        self::assertSame(PhrEhiRecordLink::KIND_BRIDGED, $link->link_kind);
        self::assertSame(EhiSourceIdBridge::VERSION, $link->bridge_version);
        self::assertSame(EhiSourceIdBridge::RULE_PERSON_CONFIRMED, $link->bridge_rule);
        self::assertSame($manual->id, PhrEhiProposalDecision::query()->where('proposal_id', $duplicate->id)->sole()->linked_lab_result_id);
    }

    public function test_a_compatible_identifier_links_evidence_and_preserves_the_earlier_identity(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $earlier = $this->legacy($owner, $patient, ['import_source' => 'ccda', 'external_id' => '1.2.3.4|8101', 'analyte' => 'synthetic glucose', 'value' => '95', 'value_numeric' => '95', 'unit' => 'mg/dL', 'result_datetime' => '2024-03-05 10:00:00']);
        $digest = EhiTargetDigest::of($earlier->refresh());
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);

        $bridge = $this->proposal($package, '8101', '66000.01', '2');
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $bridge->kind);
        self::assertSame($earlier->id, $bridge->target_id);
        self::assertSame(['version' => EhiSourceIdBridge::VERSION, 'rule' => EhiSourceIdBridge::RULE_CCDA_ORDER_EXTENSION], $bridge->proposed_json['bridge']);
        self::assertSame(PhrEhiProposal::KIND_CREATE, $this->proposal($package, '8101', '66000.01', '1')->kind, 'the order id alone does not reach another analyte');

        $this->accept($bridge, $owner);
        self::assertSame($digest, EhiTargetDigest::of($earlier->refresh()), 'corroboration is not modification');
        self::assertSame('1.2.3.4|8101', $earlier->external_id);
        self::assertSame(PhrLabResult::query()->where('patient_id', $patient->id)->count(), 1, 'no second record');
        $link = PhrEhiRecordLink::query()->where('lab_result_id', $earlier->id)->firstOrFail();
        self::assertSame(PhrEhiRecordLink::KIND_BRIDGED, $link->link_kind);
        self::assertSame(EhiSourceIdBridge::RULE_CCDA_ORDER_EXTENSION, $link->bridge_rule);
    }

    public function test_a_compatible_identifier_with_different_values_is_an_explicit_conflict_for_a_person(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $earlier = $this->legacy($owner, $patient, ['import_source' => 'ccda', 'external_id' => '8101', 'analyte' => 'SYNTHETIC GLUCOSE', 'value' => '101', 'value_numeric' => '101', 'unit' => 'mg/dL', 'result_datetime' => '2024-03-05 10:00:00']);
        $digest = EhiTargetDigest::of($earlier->refresh());
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);

        $conflict = $this->proposal($package, '8101', '66000.01', '2');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $conflict->kind);
        self::assertSame([$earlier->id], $conflict->candidate_ids_json);
        self::assertSame(['value'], $conflict->proposed_json['bridge']['conflicts']);
        self::assertSame(1, PhrEhiFinding::query()->where('package_id', $package->id)->where('code', EhiLabResultMapping::FINDING_BRIDGE_CONFLICT)->count());
        self::assertSame($digest, EhiTargetDigest::of($earlier->refresh()));
    }

    public function test_a_comparator_is_part_of_the_value_a_compatible_identifier_must_agree_on(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        // The source says "below 95", in VALUE_NORMALIZED only. The earlier record says exactly 95.
        $exact = $this->legacy($owner, $patient, ['import_source' => 'ccda', 'external_id' => '8101', 'analyte' => 'SYNTHETIC GLUCOSE', 'value' => '95', 'value_numeric' => '95', 'unit' => 'mg/dL', 'result_datetime' => '2024-03-05 10:00:00']);
        $digest = EhiTargetDigest::of($exact->refresh());
        // And an earlier record that states the same bound, for the result that says "<50".
        $bound = $this->legacy($owner, $patient, ['import_source' => 'ccda', 'external_id' => '1.2.3|8101', 'analyte' => 'SYNTHETIC LDL', 'value' => '<50', 'unit' => 'mg/dL', 'result_datetime' => '2024-03-05 10:00:00']);
        $package = $this->previewed(
            EhiClinicalPackage::withLabs()->changing('ORDER_RESULTS', ['ORDER_PROC_ID' => '8101', 'ORD_DATE_REAL' => '66000.01', 'LINE' => '2'], ['ORD_VALUE' => '95', 'VALUE_NORMALIZED' => "<\x1095"])->build(),
            $owner,
            $patient,
        );

        $conflict = $this->proposal($package, '8101', '66000.01', '2');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $conflict->kind, 'an upper bound is not the measurement it bounds');
        self::assertSame(['value'], $conflict->proposed_json['bridge']['conflicts']);
        self::assertSame([$exact->id], $conflict->candidate_ids_json);

        // Accepting it adds the bound as its own result, never as evidence for the exact one.
        $this->accept($conflict, $owner);
        self::assertSame(0, PhrEhiRecordLink::query()->where('lab_result_id', $exact->id)->count());
        self::assertSame($digest, EhiTargetDigest::of($exact->refresh()));
        $added = PhrLabResult::query()->where('external_id', 'ORDER_RESULTS:8101:66000.01:2')->sole();
        self::assertSame('<95', $added->valueWithComparator());

        // Both sides stating the same bound still corroborate, however each wrote it.
        $agreed = $this->proposal($package, '8101', '66000.01', '1');
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $agreed->kind);
        self::assertSame($bound->id, $agreed->target_id);
    }

    public function test_a_stated_bound_does_not_corroborate_an_earlier_exact_value_either_way_round(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        // The earlier record states a bound the source reports as an exact value.
        $bound = $this->legacy($owner, $patient, ['import_source' => 'ccda', 'external_id' => '8102', 'analyte' => 'SYNTHETIC EXACT', 'value' => '>=7', 'result_datetime' => '2024-03-06 10:00:00']);
        $package = $this->previewed(
            EhiClinicalPackage::withLabs()->changing('ORDER_RESULTS', ['ORDER_PROC_ID' => '8102', 'LINE' => '1'], ['COMPONENT_ID_NAME' => 'SYNTHETIC EXACT', 'ORD_VALUE' => '7'])->build(),
            $owner,
            $patient,
        );

        $conflict = $this->proposal($package, '8102', '66001', '1');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $conflict->kind);
        self::assertSame(['value'], $conflict->proposed_json['bridge']['conflicts']);
        self::assertSame([$bound->id], $conflict->candidate_ids_json);
    }

    public function test_an_identifier_is_compared_exactly_and_another_installations_keys_mean_nothing(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $caseDiffers = $this->legacy($owner, $patient, ['import_source' => 'ccda', 'external_id' => 'A8101', 'analyte' => 'SYNTHETIC CULTURE RESULT', 'value' => 'other', 'result_datetime' => '2024-03-06 10:00:00']);
        $hash = $this->legacy($owner, $patient, ['import_source' => 'ccda', 'external_id' => 'ccda-lab-8101', 'analyte' => 'SYNTHETIC GLUCOSE', 'value' => '1', 'result_datetime' => '2020-01-01 10:00:00']);
        $otherSource = $this->legacy($owner, $patient, ['import_source' => 'ehi:999999', 'external_id' => 'ORDER_RESULTS:8101:66000.01:2', 'analyte' => 'SYNTHETIC GLUCOSE', 'value' => '1', 'result_datetime' => '2020-01-01 10:00:00']);
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);

        self::assertSame(PhrEhiProposal::KIND_CREATE, $this->proposal($package, '8101', '66000.01', '2')->kind);
        self::assertSame(PhrEhiProposal::KIND_CREATE, $this->proposal($package, '8102', '66001', '1')->kind);
        foreach ([$caseDiffers, $hash, $otherSource] as $record) {
            self::assertSame(0, PhrEhiRecordLink::query()->where('lab_result_id', $record->id)->count());
        }
    }

    public function test_a_deleted_or_rejected_record_is_never_a_target_nor_silently_recreated(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $deletedBridge = $this->legacy($owner, $patient, ['import_source' => 'ccda', 'external_id' => '8101', 'analyte' => 'SYNTHETIC GLUCOSE', 'value' => '95', 'value_numeric' => '95', 'result_datetime' => '2024-03-05 10:00:00']);
        $deletedBridge->delete();
        $rejected = $this->legacy($owner, $patient, ['import_source' => 'fhir', 'external_id' => 'Observation/opaque-3', 'analyte' => 'SYNTHETIC CULTURE RESULT', 'value' => 'No growth', 'result_datetime' => '2024-03-06 10:00:00', 'review_status' => PhrReviewStatus::REJECTED]);
        $retracted = $this->legacy($owner, $patient, ['analyte' => 'SYNTHETIC LDL', 'value' => '52', 'value_numeric' => '52', 'result_datetime' => '2024-03-05 10:00:00', 'retracted_at' => now()]);
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);

        $bridge = $this->proposal($package, '8101', '66000.01', '2');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $bridge->kind);
        self::assertSame(EhiLabResultMapping::FINDING_BRIDGE_WITHDRAWN, $bridge->proposed_json['retain_reason']);

        // Only looks the same: not linkable, and held back from any bulk accept.
        foreach ([$this->proposal($package, '8102', '66001', '1'), $this->proposal($package, '8101', '66000.02', '1')] as $lookalike) {
            self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $lookalike->kind);
            self::assertNull($lookalike->candidate_ids_json);
            self::assertTrue($lookalike->proposed_json['withdrawn_match']);
        }
        self::assertSame(PhrReviewStatus::REJECTED, $rejected->refresh()->review_status);
        self::assertNotNull($retracted->refresh()->retracted_at);
        self::assertNotNull(PhrLabResult::withTrashed()->findOrFail($deletedBridge->id)->deleted_at);

        $this->expectExceptionObject(new EhiReviewRefused('target_withdrawn', 'the mapping refused to apply the proposal'));
        $proposal = $this->proposal($package, '8102', '66001', '1');
        app(EhiProposalService::class)->review($proposal, PhrEhiProposal::DECISION_LINK, $owner->id, $proposal->preview_digest, $retracted->id);
    }

    public function test_changed_source_content_is_an_explicit_update_and_a_persons_edit_stays(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $original = $this->proposal($package, '8102', '66001', '1');
        $this->accept($original, $owner);
        $record = PhrLabResult::query()->where('external_id', 'ORDER_RESULTS:8102:66001:1')->sole();
        self::assertSame(PhrReviewStatus::CONFIRMED, $record->review_status);
        $record->forceFill(['notes' => 'Synthetic note a person wrote'])->save();

        // Same key, new content: a revision, not a new result.
        $changed = EhiClinicalPackage::withLabs()->changing('ORDER_RESULTS', ['ORDER_PROC_ID' => '8102', 'LINE' => '1'], ['ORD_VALUE' => 'Light growth', 'RESULT_STATUS_C_NAME' => 'Corrected']);
        $second = $this->previewed($changed->build(), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id);
        $update = $this->proposal($second, '8102', '66001', '1');
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);
        self::assertSame(['value', 'result_status'], $update->proposed_json['changed']);
        self::assertSame(['value' => 'No growth', 'result_status' => 'Final'], $update->before_json);
        self::assertSame('No growth', $record->refresh()->value, 'nothing changes until a person approves');
        self::assertSame(PhrEhiProposal::KIND_CREATE, $this->proposal($second, '8101', '66000.01', '1')->kind, 'a row nobody accepted is still simply new');

        app(EhiProposalService::class)->review($update, PhrEhiProposal::DECISION_APPROVE_UPDATE, $owner->id, $update->preview_digest);
        $record->refresh();
        self::assertSame('Light growth', $record->value);
        self::assertSame('Corrected', $record->result_status);
        self::assertSame('Synthetic note a person wrote', $record->notes);
        self::assertSame(1, PhrLabResult::query()->where('external_id', 'ORDER_RESULTS:8102:66001:1')->count());
        self::assertSame(PhrEhiProposal::STATUS_ACCEPTED, $original->refresh()->status, 'the earlier decision stands');
        self::assertEqualsCanonicalizing(
            array_values(array_unique([...PhrEhiProposalRevision::idsFor($original), ...PhrEhiProposalRevision::idsFor($update)])),
            PhrEhiRecordLink::query()->where('lab_result_id', $record->id)->pluck('revision_id')->all(),
            'both revisions stay linked: the history is kept',
        );
    }

    public function test_a_changed_value_keeps_the_comparator_the_source_still_states(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $this->accept($this->proposal($package, '8101', '66000.01', '1'), $owner);
        $changed = EhiClinicalPackage::withLabs()->changing('ORDER_RESULTS', ['ORDER_PROC_ID' => '8101', 'ORD_DATE_REAL' => '66000.01', 'LINE' => '1'], ['ORD_VALUE' => '<60', 'VALUE_NORMALIZED' => "<\x1060"]);
        $second = $this->previewed($changed->build(), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id);

        $update = $this->proposal($second, '8101', '66000.01', '1');
        self::assertSame(['value'], $update->proposed_json['changed'], 'the comparator did not change');
        app(EhiProposalService::class)->review($update, PhrEhiProposal::DECISION_APPROVE_UPDATE, $owner->id, $update->preview_digest);

        $record = PhrLabResult::query()->where('external_id', 'ORDER_RESULTS:8101:66000.01:1')->sole();
        self::assertSame('<60', $record->value);
        self::assertSame('<', $record->value_comparator);
    }

    public function test_a_preview_past_the_work_limit_is_refused_and_leaves_nothing_reviewable(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->ehiIntakeDirectory(EhiClinicalPackage::withLabs()->build(), $patient, (int) $owner->id)->package;
        $package = app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, null, 'Synthetic Clinic');
        config(['phr.ehi_preview_max_rows' => 5]);

        try {
            app(EhiProposalService::class)->preview($package, $patient, $owner->id);
            self::fail('the preview must refuse work past its limit');
        } catch (EhiProjectionRefused $refused) {
            self::assertSame(EhiProjectionRefused::RESOURCE_LIMIT, $refused->reason);
        }
        self::assertFalse($package->refresh()->previewCompleted());
        self::assertSame(0, PhrLabResult::query()->count());
    }

    public function test_a_correction_only_in_joined_rows_reaches_the_record_through_an_approved_update(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $this->accept($this->proposal($package, '8101', '66000.01', '1'), $owner);
        $record = PhrLabResult::query()->where('external_id', 'ORDER_RESULTS:8101:66000.01:1')->sole();
        $record->forceFill(['abnormal_flag' => 'Edited by a person'])->save();
        $sourceId = PhrEhiSource::query()->sole()->id;

        // ORDER_RESULTS is byte-identical; only a comment, the narrative, and the order changed.
        $corrected = EhiClinicalPackage::withLabs()
            ->changing('ORDER_RES_COMMENT', ['ORDER_ID' => '8101', 'CONTACT_DATE_REAL' => '66000.01', 'LINE' => '1', 'LINE_COMMENT' => '2'], ['RESULTS_CMT' => 'Synthetic corrected comment'])
            ->changing('ORDER_NARRATIVE', ['ORDER_PROC_ID' => '8101', 'ORD_DATE_REAL' => '66000.01', 'LINE' => '1'], ['NARRATIVE' => 'Synthetic corrected narrative'])
            ->changing('ORDER_PROC', ['ORDER_PROC_ID' => '8101'], ['AUTHRZING_PROV_ID_PROV_NAME' => 'SYNTHETIC CORRECTED PROVIDER']);
        $second = $this->previewed($corrected->build(), $owner, $patient, 'package-2', $sourceId);

        $update = $this->proposal($second, '8101', '66000.01', '1');
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);
        self::assertEqualsCanonicalizing(['result_comment', 'notes', 'ordering_provider'], $update->proposed_json['changed']);
        self::assertStringContainsString('Synthetic comment line 2', (string) $update->before_json['result_comment']);
        self::assertStringNotContainsString('Synthetic corrected comment', (string) $record->refresh()->result_comment, 'nothing changes until a person approves');

        app(EhiProposalService::class)->review($update, PhrEhiProposal::DECISION_APPROVE_UPDATE, $owner->id, $update->preview_digest);
        $record->refresh();
        self::assertStringContainsString('Synthetic corrected comment', (string) $record->result_comment);
        self::assertStringContainsString('Synthetic corrected narrative', (string) $record->notes);
        self::assertSame('SYNTHETIC CORRECTED PROVIDER', $record->ordering_provider);
        self::assertSame('Edited by a person', $record->abnormal_flag, 'what the source did not change stays as a person left it');

        // The same content again corroborates, and a person's later edit is not proposed away.
        $record->forceFill(['ordering_provider' => 'Edited by a person too'])->save();
        $third = $this->previewed($corrected->adding('ORDER_PROC', ['ORDER_PROC_ID' => '8300', 'PAT_ID' => EhiClinicalPackage::PATIENT_KEY])->build(), $owner, $patient, 'package-3', $sourceId);
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $this->proposal($third, '8101', '66000.01', '1')->kind);
    }

    public function test_an_update_is_refused_if_the_record_changed_since_the_preview(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $this->accept($this->proposal($package, '8102', '66001', '1'), $owner);
        $changed = EhiClinicalPackage::withLabs()->changing('ORDER_RESULTS', ['ORDER_PROC_ID' => '8102', 'LINE' => '1'], ['ORD_VALUE' => 'Light growth']);
        $second = $this->previewed($changed->build(), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id);
        $update = $this->proposal($second, '8102', '66001', '1');
        $record = PhrLabResult::query()->where('external_id', 'ORDER_RESULTS:8102:66001:1')->sole();
        $record->forceFill(['value' => 'A person corrected this', 'updated_at' => now()->addMinute()])->save();

        try {
            app(EhiProposalService::class)->review($update, PhrEhiProposal::DECISION_APPROVE_UPDATE, $owner->id, $update->preview_digest);
            self::fail('an update on a changed record must be refused');
        } catch (EhiReviewRefused $refused) {
            self::assertSame(EhiReviewRefused::STALE_TARGET, $refused->reason);
        }
        self::assertSame('A person corrected this', $record->refresh()->value);
    }

    public function test_a_later_cancellation_or_deletion_never_touches_or_revives_the_record(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $this->accept($this->proposal($package, '8102', '66001', '1'), $owner);
        $this->accept($this->proposal($package, '8101', '66000.02', '1'), $owner);
        $kept = PhrLabResult::query()->where('external_id', 'ORDER_RESULTS:8102:66001:1')->sole();
        $digest = EhiTargetDigest::of($kept);
        $deleted = PhrLabResult::query()->where('external_id', 'ORDER_RESULTS:8101:66000.02:1')->sole();
        $deleted->delete();

        $sourceId = PhrEhiSource::query()->sole()->id;
        $next = EhiClinicalPackage::withLabs()
            ->changing('ORDER_PROC', ['ORDER_PROC_ID' => '8102'], ['ORDER_STATUS_C_NAME' => 'Canceled'])
            ->changing('ORDER_RESULTS', ['ORDER_PROC_ID' => '8101', 'ORD_DATE_REAL' => '66000.02', 'LINE' => '1'], ['ORD_VALUE' => '53']);
        $second = $this->previewed($next->build(), $owner, $patient, 'package-2', $sourceId);

        $cancelled = $this->proposal($second, '8102', '66001', '1');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $cancelled->kind);
        self::assertSame(EhiLabResultMapping::FINDING_LINKED_NOT_CLINICAL, $cancelled->proposed_json['retain_reason']);
        self::assertSame($digest, EhiTargetDigest::of($kept->refresh()));

        $withdrawn = $this->proposal($second, '8101', '66000.02', '1');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $withdrawn->kind);
        self::assertSame(EhiLabResultMapping::FINDING_LINKED_RECORD_WITHDRAWN, $withdrawn->proposed_json['retain_reason']);
        self::assertNotNull(PhrLabResult::withTrashed()->findOrFail($deleted->id)->deleted_at);

        // Absence is not deletion: a package without a result leaves its record alone.
        $third = EhiClinicalPackage::withLabs();
        $third = $third->without('ORDER_RESULTS', ['ORDER_PROC_ID' => '8102', 'LINE' => '1']);
        $this->previewed($third->build(), $owner, $patient, 'package-3', $sourceId);
        self::assertSame($digest, EhiTargetDigest::of($kept->refresh()));
    }

    public function test_replaying_the_same_or_a_repacked_export_changes_nothing(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $build = EhiClinicalPackage::withLabs()->build();
        $package = $this->previewed($build, $owner, $patient);
        $this->accept($this->proposal($package, '8102', '66001', '1'), $owner);
        $records = PhrLabResult::query()->count();
        $proposals = PhrEhiProposal::query()->count();
        $digests = PhrEhiProposal::query()->orderBy('id')->pluck('preview_digest', 'id')->all();

        // The same files in another container and order are the same package.
        $repacked = $build->writeZip($this->ehiRoot.'/repacked.zip', reverse: true);
        $replay = $this->ehiIntake(new EhiZipSource($repacked), $patient, (int) $owner->id);
        self::assertTrue($replay->replayed);
        self::assertSame($package->id, $replay->package->id);
        app(EhiProposalService::class)->preview($replay->package, $patient, $owner->id);

        self::assertSame(1, PhrEhiPackage::query()->count());
        self::assertSame($records, PhrLabResult::query()->count());
        self::assertSame($proposals, PhrEhiProposal::query()->count());
        self::assertSame($digests, PhrEhiProposal::query()->orderBy('id')->pluck('preview_digest', 'id')->all());
    }

    public function test_purging_a_record_removes_its_evidence_links_and_keeps_the_decision_history(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $proposal = $this->proposal($package, '8102', '66001', '1');
        $this->accept($proposal, $owner);
        $record = PhrLabResult::query()->sole();
        self::assertGreaterThan(0, PhrEhiRecordLink::query()->where('lab_result_id', $record->id)->count());

        $record->forceDelete();

        self::assertSame(0, PhrEhiRecordLink::query()->where('lab_result_id', $record->id)->count());
        $decision = PhrEhiProposalDecision::query()->where('proposal_id', $proposal->id)->sole();
        self::assertNull($decision->linked_lab_result_id);
    }

    public function test_an_accepted_proposal_is_idempotent_and_a_second_decision_is_refused(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $proposal = $this->proposal($package, '8102', '66001', '1');
        $service = app(EhiProposalService::class);

        $service->review($proposal, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $proposal->preview_digest);
        $service->review($proposal, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $proposal->preview_digest);
        self::assertSame(1, PhrLabResult::query()->count());
        self::assertSame(1, PhrEhiProposalDecision::query()->where('proposal_id', $proposal->id)->count());

        $this->expectException(EhiReviewRefused::class);
        $service->review($proposal, PhrEhiProposal::DECISION_REJECT, $owner->id, $proposal->preview_digest);
    }

    public function test_two_racing_acceptances_of_one_identity_leave_one_record(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $proposal = $this->proposal($package, '8102', '66001', '1');
        // The other worker's accept committed between this one's preview and its write.
        $winner = PhrLabResult::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'import_source' => EhiLabResultMapping::importSource(PhrEhiSource::query()->sole()->id),
            'external_id' => 'ORDER_RESULTS:8102:66001:1',
            'review_status' => PhrReviewStatus::CONFIRMED,
            'analyte' => 'SYNTHETIC CULTURE RESULT',
        ]);

        try {
            app(EhiProposalService::class)->review($proposal, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $proposal->preview_digest);
            self::fail('a held identity must refuse the second acceptance');
        } catch (EhiReviewRefused $refused) {
            self::assertSame('identity_exists', $refused->reason);
        }
        self::assertSame([$winner->id], PhrLabResult::query()->pluck('id')->all());
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $proposal->refresh()->status);
    }

    public function test_an_ambiguous_join_is_never_guessed_at(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        // Two rows claim one order id: both are quarantined, so the result has no owner.
        $package = $this->previewed(
            EhiClinicalPackage::withLabs()
                ->adding('ORDER_PROC', ['ORDER_PROC_ID' => '8102', 'PAT_ID' => EhiClinicalPackage::PATIENT_KEY, 'DISPLAY_NAME' => 'SYNTHETIC OTHER ORDER'])
                ->adding('ORDER_RES_COMMENT', ['ORDER_ID' => '8101', 'CONTACT_DATE_REAL' => '66000.02', 'LINE' => '1', 'LINE_COMMENT' => 'not-a-number', 'RESULTS_CMT' => 'Synthetic unordered comment'])
                ->build(),
            $owner,
            $patient,
        );

        $orphan = $this->proposal($package, '8102', '66001', '1');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $orphan->kind);
        self::assertSame(EhiLabResultMapping::FINDING_ORPHAN, $orphan->proposed_json['retain_reason']);
        $member = PhrEhiPackageMember::query()->where('package_id', $package->id)->where('table_name', 'ORDER_PROC')->sole();
        self::assertSame(2, PhrEhiOccurrence::query()->where('member_id', $member->id)->where('disposition', PhrEhiOccurrence::DISPOSITION_QUARANTINED)->count());

        // A comment line that cannot be ordered holds the result back rather than misorder it.
        $unordered = $this->proposal($package, '8101', '66000.02', '1');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $unordered->kind);
        self::assertSame(EhiLabResultMapping::FINDING_UNORDERED_LINES, $unordered->proposed_json['retain_reason']);
    }

    public function test_a_foreign_order_is_retained(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $source = PhrEhiSource::query()->sole();
        // The intake refuses a second PAT_ID in one package, so this is the source's
        // own key moving: from the mapping's view every order now names another.
        $source->forceFill(['source_patient_key' => 'Z00000999'])->save();
        app(EhiProposalService::class)->preview($package, $patient, $owner->id);

        $proposal = $this->proposal($package, '8102', '66001', '1');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $proposal->kind);
        self::assertSame(EhiLabResultMapping::FINDING_FOREIGN_PATIENT, $proposal->proposed_json['retain_reason']);
    }

    public function test_a_preview_context_only_sees_what_this_package_supplied(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $source = PhrEhiSource::query()->sole();
        $second = $this->previewed(EhiClinicalPackage::standard()->build(), $owner, $patient, 'package-2', $source->id);

        self::assertSame(0, PhrEhiProposal::query()->where('package_id', $second->id)->where('mapping', EhiLabResultMapping::NAME)->count());
        $context = new EhiMappingContext($second, $patient, $source);
        self::assertSame(0, $context->lazyRevisions('ORDER_RESULTS')->count());
        self::assertSame(8, (new EhiMappingContext($package, $patient, $source))->lazyRevisions('ORDER_RESULTS')->count());
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

    private function proposal(PhrEhiPackage $package, string $order, string $contact, string $line): PhrEhiProposal
    {
        return PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', EhiLabResultMapping::NAME)
            ->where('subject_key', EhiMappingContext::keyDigest(['ORDER_PROC_ID' => $order, 'ORD_DATE_REAL' => $contact, 'LINE' => $line]))
            ->firstOrFail();
    }

    private function accept(PhrEhiProposal $proposal, User $owner): void
    {
        $proposal->refresh();
        app(EhiProposalService::class)->review($proposal, PhrEhiProposal::DECISION_ACCEPT, (int) $owner->id, $proposal->preview_digest);
    }

    /** @param  array<string, mixed>  $attributes */
    private function legacy(User $owner, PhrPatient $patient, array $attributes): PhrLabResult
    {
        return PhrLabResult::query()->forceCreate([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'review_status' => PhrReviewStatus::CONFIRMED,
            ...$attributes,
        ]);
    }
}

/** Proposes one retained draft per PAT_ENC row, so every encounter row is relied on. */
final class EhiEveryEncounterMapping implements EhiMapping
{
    public function name(): string
    {
        return 'every_encounter';
    }

    public function version(): string
    {
        return 'test-1';
    }

    public function expectedKeys(): array
    {
        return ['PAT_ENC' => ['PAT_ENC_CSN_ID']];
    }

    public function propose(EhiMappingContext $context): iterable
    {
        foreach ($context->lazyRevisions('PAT_ENC', withEntity: true) as $revision) {
            yield new EhiProposalDraft($revision->entity->key_digest, PhrEhiProposal::KIND_RETAIN, [$revision->id], ['retain_reason' => 'synthetic']);
        }
    }

    public function apply(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome
    {
        throw new \LogicException('Retained drafts are never applied.');
    }
}
