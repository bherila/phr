<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiProposal;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrImmunization;
use App\Models\PhrPatient;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyOutcome;
use App\Services\PHR\Import\Ehi\Mapping\EhiImmunizationMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiProposalDraft;
use App\Services\PHR\Import\Ehi\Mapping\EhiTargetDigest;
use App\Services\PHR\Import\Ehi\Mapping\Immunization\EhiImmunizationApplyRefused;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\EhiImmunizationFixtures;
use Tests\TestCase;

class EhiImmunizationMappingTest extends TestCase
{
    use EhiImmunizationFixtures;

    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        // A zone far from UTC: no mapped date may move because of it.
        $this->previousTimezone = date_default_timezone_get();
        config(['app.timezone' => 'Pacific/Kiritimati']);
        date_default_timezone_set('Pacific/Kiritimati');
        $this->setUpEhiImmunization();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    public function test_declares_name_version_and_keys(): void
    {
        $mapping = new EhiImmunizationMapping;

        self::assertSame('immunization', $mapping->name());
        self::assertSame('ehi-imm-1', $mapping->version());
        self::assertSame(['IMMUNE' => ['IMMUNE_ID'], 'PAT_IMMUNIZATIONS' => ['PAT_ID', 'LINE']], $mapping->expectedKeys());
        self::assertSame('unspecified', EhiImmunizationMapping::IMMUNE_DATE_TIMEZONE);
        self::assertSame('local', EhiImmunizationMapping::IMMUNIZATION_TIME_TIMEZONE);
    }

    public function test_owned_given_row_maps_every_field_exactly_and_applies_as_confirmed(): void
    {
        $immune = $this->immOwnedImmune('501', [
            'IMMUNE_DATE' => '2024-03-05',
            'IMMUNIZATION_TIME' => '10:15',
            'DOSE' => '0.5 mL synthetic free text',
            'IMMNZTN_DOSE_AMOUNT' => '0.50',
            'IMMNZTN_DOSE_UNIT_C_NAME' => 'mL',
            'LOT' => 'SYN-LOT-0042',
            'MFG_C_NAME' => 'Synthetic Pharma',
            'ROUTE_C_NAME' => 'Intramuscular',
            'SITE_C_NAME' => 'Left deltoid',
            'IMM_CSN' => '7770001',
            'NDC_NUM_ID_NDC_CODE' => '00000-1111-22',
        ]);
        $link = $this->immPatientLink(self::IMM_PATIENT_KEY, '2', '501');

        $context = $this->immContext();
        $drafts = $this->immPropose($context);

        self::assertCount(1, $drafts);
        $draft = $drafts[0];
        self::assertSame(PhrEhiProposal::KIND_CREATE, $draft->kind);
        self::assertSame($immune->entity->key_digest, $draft->subjectKey);
        self::assertSame($immune->id, $draft->revisionIds[0], 'the root revision comes first');
        self::assertCount(3, $draft->revisionIds, 'both PAT_IMMUNIZATIONS lines are included');
        self::assertContains($link->id, $draft->revisionIds);
        self::assertSame([], $context->findings());

        $proposed = $draft->proposed ?? [];
        self::assertSame('ehi:'.$this->immSource->id, $proposed['import_source']);
        self::assertSame('IMMUNE:501', $proposed['external_id']);
        self::assertSame('confirmed', $proposed['review_status']);
        self::assertSame('SYNTHETIC VACCINE A', $proposed['vaccine_name']);
        self::assertSame('2024-03-05', $proposed['administered_on']);
        self::assertSame('2024-03-05|10:15', $proposed['administered_at_source']);
        self::assertSame('minute', $proposed['administered_at_precision']);
        self::assertSame('local', $proposed['administered_at_timezone']);
        self::assertSame('given', $proposed['administration_status']);
        self::assertSame('0.50', $proposed['dose_amount']);
        self::assertSame('mL', $proposed['dose_unit']);
        self::assertSame('SYN-LOT-0042', $proposed['lot_number']);
        self::assertSame('Synthetic Pharma', $proposed['manufacturer']);
        self::assertSame('Intramuscular', $proposed['route']);
        self::assertSame('Left deltoid', $proposed['site']);
        self::assertNull($proposed['cvx_code'], 'IMMUNE has no CVX column; none is invented');
        self::assertNull($proposed['dose_number'], 'a dose volume is never a dose number');
        self::assertStringContainsString("DOSE: 0.5 mL synthetic free text\n", $proposed['raw_text']);
        self::assertStringContainsString('NDC_NUM_ID_NDC_CODE: 00000-1111-22', $proposed['raw_text']);
        self::assertStringContainsString('IMM_CSN: 7770001', $proposed['raw_text']);

        $outcome = $this->immApply($this->immPersist($draft), PhrEhiProposal::DECISION_ACCEPT);

        self::assertSame('phr_immunizations', $outcome->recordTable);
        self::assertSame(PhrEhiRecordLink::KIND_PROJECTED, $outcome->linkKind);
        $record = PhrImmunization::query()->findOrFail($outcome->recordId);
        self::assertSame($this->immPatient->id, $record->patient_id);
        self::assertSame($this->immOwner->id, $record->user_id);
        self::assertSame('confirmed', $record->review_status);
        self::assertSame('2024-03-05', $record->administered_on?->format('Y-m-d'));
        self::assertSame('0.50', $record->dose_amount);
        self::assertNull($record->dose_number);
        self::assertNull($record->cvx_code);
        self::assertSame('IMMUNE:501', $record->external_id);
    }

    public function test_orphans_and_foreign_patient_links_are_retained_with_value_free_findings(): void
    {
        $orphan = $this->immImmune('601');
        $foreign = $this->immImmune('602');
        $this->immPatientLink(self::IMM_OTHER_PATIENT_KEY, '1', '602');
        $mixed = $this->immImmune('603');
        $this->immPatientLink(self::IMM_PATIENT_KEY, '3', '603');
        $foreignLink = $this->immPatientLink(self::IMM_OTHER_PATIENT_KEY, '3', '603');
        $dangling = $this->immPatientLink(self::IMM_PATIENT_KEY, '9', '699');
        $missing = $this->immPatientLink(self::IMM_PATIENT_KEY, '10', '');

        $context = $this->immContext();
        $drafts = $this->draftsBySubject($this->immPropose($context));

        self::assertCount(3, $drafts);
        $orphanDraft = $drafts[$orphan->entity->key_digest];
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $orphanDraft->kind);
        self::assertSame('orphan_immunization', $orphanDraft->proposed['retain_reason'] ?? null);
        self::assertSame([$orphan->id], $orphanDraft->revisionIds);
        $foreignDraft = $drafts[$foreign->entity->key_digest];
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $foreignDraft->kind);
        self::assertSame('foreign_patient_link', $foreignDraft->proposed['retain_reason'] ?? null);
        $mixedDraft = $drafts[$mixed->entity->key_digest];
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $mixedDraft->kind, 'an IMMUNE row also linked to another patient is never mapped');
        self::assertContains($foreignLink->id, $mixedDraft->revisionIds);

        $findings = $context->findings();
        self::assertSame(
            [
                ['dangling_patient_immunization', $missing->id],
                ['orphan_immunization', $orphan->id],
                ['foreign_patient_link', $foreign->id],
                ['foreign_patient_link', $mixed->id],
                ['dangling_patient_immunization', $dangling->id],
            ],
            $this->sortedFindingPairs($findings, [$missing->id, $orphan->id, $foreign->id, $mixed->id, $dangling->id]),
        );
        $encoded = json_encode($findings, JSON_THROW_ON_ERROR);
        foreach (['SYNTHETIC VACCINE', self::IMM_PATIENT_KEY, self::IMM_OTHER_PATIENT_KEY, '2024-03-05', '699'] as $value) {
            self::assertStringNotContainsString($value, $encoded, 'findings never carry source values');
        }
        self::assertSame(0, PhrImmunization::query()->count());
    }

    public function test_status_distinctions_drive_kind_and_are_preserved(): void
    {
        $cases = [
            '701' => ['Given', 'N', PhrEhiProposal::KIND_CREATE, 'given'],
            '702' => ['Given', 'Y', PhrEhiProposal::KIND_CREATE, 'historical'],
            '703' => ['Given', 'Yes', PhrEhiProposal::KIND_CREATE, 'historical'],
            '704' => ['Partially Administered', 'N', PhrEhiProposal::KIND_CREATE, 'partial'],
            '705' => ['Deleted', 'N', PhrEhiProposal::KIND_RETAIN, 'deleted'],
            '706' => ['Deferred', 'N', PhrEhiProposal::KIND_RETAIN, 'deferred'],
            '707' => ['Refused', 'N', PhrEhiProposal::KIND_RETAIN, 'refused'],
            '708' => ['Incomplete', 'N', PhrEhiProposal::KIND_RETAIN, 'incomplete'],
            '709' => ['Invalid', 'N', PhrEhiProposal::KIND_RETAIN, 'invalid'],
            '710' => ['Synthetic Unknown', 'N', PhrEhiProposal::KIND_RETAIN, 'unknown'],
            '711' => [null, null, PhrEhiProposal::KIND_RETAIN, 'unknown'],
            '712' => ['Deleted', 'Y', PhrEhiProposal::KIND_RETAIN, 'deleted'],
        ];
        foreach ($cases as $immuneId => [$status, $historical]) {
            // Distinct dates keep the clinical rows from being each other's duplicates.
            $this->immOwnedImmune((string) $immuneId, [
                'IMMNZTN_STATUS_C_NAME' => $status,
                'IMM_HISTORIC_ADM_YN' => $historical,
                'IMMUNE_DATE' => '2024-01-'.substr((string) $immuneId, 1),
            ]);
        }

        $context = $this->immContext();
        $drafts = $this->draftsByExternalId($this->immPropose($context));

        foreach ($cases as $immuneId => [, , $kind, $administrationStatus]) {
            $draft = $drafts['IMMUNE:'.$immuneId];
            self::assertSame($kind, $draft->kind, "IMMUNE {$immuneId}");
            self::assertSame($administrationStatus, $draft->proposed['administration_status'] ?? null, "IMMUNE {$immuneId}");
            if ($kind === PhrEhiProposal::KIND_RETAIN) {
                self::assertSame('status_'.$administrationStatus, $draft->proposed['retain_reason'] ?? null);
            }
        }
        $codes = array_column($context->findings(), 'code');
        self::assertSame(['unknown_immunization_status', 'unknown_immunization_status'], $codes);
    }

    public function test_unrecognized_historical_flag_and_missing_name_are_retained(): void
    {
        $this->immOwnedImmune('721', ['IMM_HISTORIC_ADM_YN' => 'Maybe']);
        $this->immOwnedImmune('722', ['IMMUNZATN_ID_NAME' => '  ', 'IMMUNE_DATE' => '2024-03-06']);

        $context = $this->immContext();
        $drafts = $this->draftsByExternalId($this->immPropose($context));

        self::assertSame(PhrEhiProposal::KIND_RETAIN, $drafts['IMMUNE:721']->kind);
        self::assertSame('unrecognized_historical_flag', $drafts['IMMUNE:721']->proposed['retain_reason'] ?? null);
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $drafts['IMMUNE:722']->kind);
        self::assertSame('missing_vaccine_name', $drafts['IMMUNE:722']->proposed['retain_reason'] ?? null);
        self::assertEqualsCanonicalizing(['unrecognized_historical_flag', 'missing_vaccine_name'], array_column($context->findings(), 'code'));
    }

    public function test_dose_amount_is_exact_text_and_free_text_dose_is_never_a_dose_number(): void
    {
        $this->immOwnedImmune('731', ['IMMNZTN_DOSE_AMOUNT' => '0.50', 'IMMUNE_DATE' => '2024-02-01']);
        $this->immOwnedImmune('732', ['IMMNZTN_DOSE_AMOUNT' => '007.2500', 'IMMUNE_DATE' => '2024-02-02']);
        $this->immOwnedImmune('733', ['DOSE' => '2', 'IMMUNE_DATE' => '2024-02-03']);
        $this->immOwnedImmune('734', ['IMMNZTN_DOSE_AMOUNT' => '0,5', 'IMMUNE_DATE' => '2024-02-04']);
        $this->immOwnedImmune('735', ['IMMNZTN_DOSE_AMOUNT' => '-1', 'IMMUNE_DATE' => '2024-02-05']);
        $this->immOwnedImmune('736', ['IMMNZTN_DOSE_AMOUNT' => '.5', 'IMMUNE_DATE' => '2024-02-06']);

        $context = $this->immContext();
        $drafts = $this->draftsByExternalId($this->immPropose($context));

        self::assertSame('0.50', $drafts['IMMUNE:731']->proposed['dose_amount'] ?? null);
        self::assertSame('007.2500', $drafts['IMMUNE:732']->proposed['dose_amount'] ?? null);
        self::assertSame('.5', $drafts['IMMUNE:736']->proposed['dose_amount'] ?? null);
        $freeText = $drafts['IMMUNE:733'];
        self::assertSame(PhrEhiProposal::KIND_CREATE, $freeText->kind);
        self::assertArrayHasKey('dose_amount', $freeText->proposed ?? []);
        self::assertNull($freeText->proposed['dose_amount']);
        self::assertArrayHasKey('dose_number', $freeText->proposed ?? []);
        self::assertNull($freeText->proposed['dose_number']);
        self::assertStringContainsString('DOSE: 2', $freeText->proposed['raw_text'] ?? '');
        foreach (['IMMUNE:734', 'IMMUNE:735'] as $externalId) {
            self::assertSame(PhrEhiProposal::KIND_RETAIN, $drafts[$externalId]->kind);
            self::assertSame('invalid_dose_amount', $drafts[$externalId]->proposed['retain_reason'] ?? null);
        }

        $record = PhrImmunization::query()->findOrFail($this->immApply($this->immPersist($drafts['IMMUNE:732']), PhrEhiProposal::DECISION_ACCEPT)->recordId);
        self::assertSame('007.2500', $record->refresh()->dose_amount);
    }

    public function test_date_precision_and_timezone_provenance_without_server_timezone_shift(): void
    {
        $cases = [
            '741' => [['IMMUNE_DATE' => '2024-03-05'], '2024-03-05', '2024-03-05', 'date', 'unspecified'],
            '742' => [['IMMUNE_DATE' => '3/5/2024 11:30 PM'], '2024-03-05', '3/5/2024 11:30 PM', 'minute', 'unspecified'],
            '743' => [['IMMUNE_DATE' => '2024-03-05 23:59:59'], '2024-03-05', '2024-03-05 23:59:59', 'second', 'unspecified'],
            '744' => [['IMMUNE_DATE' => '3/5/2024', 'IMMUNIZATION_TIME' => '11:45:10 PM'], '2024-03-05', '3/5/2024|11:45:10 PM', 'second', 'local'],
            '745' => [['IMMUNE_DATE' => '2024-03-05', 'IMMUNIZATION_TIME' => '3/5/2024 8:05 AM'], '2024-03-05', '2024-03-05|3/5/2024 8:05 AM', 'minute', 'local'],
            '746' => [['IMMUNE_DATE' => '12/31/2023 12:00:00 AM'], '2023-12-31', '12/31/2023 12:00:00 AM', 'second', 'unspecified'],
        ];
        foreach ($cases as $immuneId => [$overrides]) {
            // Distinct vaccine names keep same-day rows from being each other's duplicates.
            $this->immOwnedImmune((string) $immuneId, [...$overrides, 'IMMUNZATN_ID_NAME' => 'SYNTHETIC VACCINE '.$immuneId]);
        }

        $drafts = $this->draftsByExternalId($this->immPropose());

        foreach ($cases as $immuneId => [, $date, $source, $precision, $timezone]) {
            $proposed = $drafts['IMMUNE:'.$immuneId]->proposed ?? [];
            self::assertSame(PhrEhiProposal::KIND_CREATE, $drafts['IMMUNE:'.$immuneId]->kind, "IMMUNE {$immuneId}");
            self::assertSame($date, $proposed['administered_on'], "IMMUNE {$immuneId}");
            self::assertSame($source, $proposed['administered_at_source'], "IMMUNE {$immuneId}");
            self::assertSame($precision, $proposed['administered_at_precision'], "IMMUNE {$immuneId}");
            self::assertSame($timezone, $proposed['administered_at_timezone'], "IMMUNE {$immuneId}");
        }

        $record = PhrImmunization::query()->findOrFail($this->immApply($this->immPersist($drafts['IMMUNE:742']), PhrEhiProposal::DECISION_ACCEPT)->recordId);
        $stored = DB::table('phr_immunizations')->where('id', $record->id)->value('administered_on');
        self::assertStringStartsWith('2024-03-05', (string) $stored, 'a late-evening source date is not shifted by the app timezone');
        self::assertSame('2024-03-05', $record->refresh()->administered_on?->format('Y-m-d'));
        self::assertSame('3/5/2024 11:30 PM', $record->refresh()->administered_at_source);
    }

    public function test_unparseable_or_conflicting_dates_are_retained_with_findings(): void
    {
        $cases = [
            '751' => [['IMMUNE_DATE' => '2024-02-30'], 'unparseable_date', 'IMMUNE_DATE'],
            '752' => [['IMMUNE_DATE' => '05-03-2024'], 'unparseable_date', 'IMMUNE_DATE'],
            '753' => [['IMMUNE_DATE' => '2024-03-05T10:00:00Z'], 'unparseable_date', 'IMMUNE_DATE'],
            '754' => [['IMMUNE_DATE' => '2024-03-05', 'IMMUNIZATION_TIME' => '25:00'], 'unparseable_date', 'IMMUNIZATION_TIME'],
            '755' => [['IMMUNE_DATE' => null, 'IMMUNIZATION_TIME' => '10:00'], 'unparseable_date', 'IMMUNIZATION_TIME'],
            '756' => [['IMMUNE_DATE' => '2024-03-05', 'IMMUNIZATION_TIME' => '3/6/2024 8:05 AM'], 'conflicting_administration_time', 'IMMUNIZATION_TIME'],
            '757' => [['IMMUNE_DATE' => '13/1/2024'], 'unparseable_date', 'IMMUNE_DATE'],
        ];
        foreach ($cases as $immuneId => [$overrides]) {
            $this->immOwnedImmune((string) $immuneId, $overrides);
        }

        $context = $this->immContext();
        $drafts = $this->draftsByExternalId($this->immPropose($context));

        $findings = [];
        foreach ($context->findings() as $finding) {
            $findings[$finding['revision_id']] = $finding;
        }
        foreach ($cases as $immuneId => [, $code, $column]) {
            $draft = $drafts['IMMUNE:'.$immuneId];
            self::assertSame(PhrEhiProposal::KIND_RETAIN, $draft->kind, "IMMUNE {$immuneId}");
            self::assertSame($code, $draft->proposed['retain_reason'] ?? null, "IMMUNE {$immuneId}");
            $finding = $findings[$draft->revisionIds[0]];
            self::assertSame($code, $finding['code']);
            self::assertSame($column, $finding['detail']['column'] ?? null);
        }
        self::assertSame('2024-02-30', $drafts['IMMUNE:751']->proposed['administered_at_source'] ?? null, 'the source text is kept, never repaired');
        self::assertArrayHasKey('administered_on', $drafts['IMMUNE:751']->proposed ?? []);
        self::assertNull($drafts['IMMUNE:751']->proposed['administered_on']);
    }

    public function test_two_same_day_doses_from_the_source_each_propose_a_create(): void
    {
        $this->immOwnedImmune('761', ['LOT' => 'SYN-LOT-1']);
        $this->immOwnedImmune('762', ['LOT' => 'SYN-LOT-1']);

        $drafts = $this->immPropose();

        self::assertCount(2, $drafts);
        self::assertSame([PhrEhiProposal::KIND_CREATE, PhrEhiProposal::KIND_CREATE], array_map(static fn (EhiProposalDraft $draft): string => $draft->kind, $drafts));
        self::assertSame([[], []], array_map(static fn (EhiProposalDraft $draft): array => $draft->candidateIds, $drafts));
    }

    public function test_possible_duplicate_of_an_earlier_fhir_row_is_never_merged(): void
    {
        $fhir = $this->immRecord(['import_source' => 'fhir', 'external_id' => 'Immunization/synthetic-1', 'vaccine_name' => 'synthetic vaccine a']);
        $byLot = $this->immRecord(['import_source' => 'fhir', 'external_id' => 'Immunization/synthetic-2', 'vaccine_name' => 'Other Name', 'lot_number' => 'syn-lot-9']);
        $this->immRecord(['vaccine_name' => 'SYNTHETIC VACCINE A', 'administered_on' => '2024-03-06']);
        $retracted = $this->immRecord(['vaccine_name' => 'SYNTHETIC VACCINE A']);
        $retracted->forceFill(['retracted_at' => Carbon::now()])->save();
        $trashed = $this->immRecord(['vaccine_name' => 'SYNTHETIC VACCINE A']);
        $trashed->delete();
        $before = EhiTargetDigest::of($fhir->fresh() ?? $fhir);
        $this->immOwnedImmune('771', ['LOT' => 'SYN-LOT-9']);

        $drafts = $this->immPropose();

        self::assertCount(1, $drafts);
        $draft = $drafts[0];
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $draft->kind);
        self::assertSame([$fhir->id, $byLot->id], $draft->candidateIds);
        self::assertNull($draft->targetId);
        self::assertSame($before, EhiTargetDigest::of($fhir->fresh() ?? $fhir), 'proposing never touches the candidate');

        $proposal = $this->immPersist($draft);
        $bridged = $this->immApply($proposal, PhrEhiProposal::DECISION_LINK, $fhir->id);
        self::assertSame(PhrEhiRecordLink::KIND_BRIDGED, $bridged->linkKind);
        self::assertSame($fhir->id, $bridged->recordId);
        self::assertSame($before, EhiTargetDigest::of($fhir->fresh() ?? $fhir), 'linking never modifies the existing record');

        $created = $this->immApply($proposal, PhrEhiProposal::DECISION_ACCEPT);
        self::assertNotSame($fhir->id, $created->recordId, 'accepting a possible duplicate creates a separate record');
        self::assertSame(1, PhrImmunization::query()->where('external_id', 'IMMUNE:771')->count());
    }

    public function test_unchanged_linked_revision_corroborates_and_changed_one_proposes_a_scoped_update(): void
    {
        $first = $this->immOwnedImmune('781', ['LOT' => 'SYN-LOT-1', 'SITE_C_NAME' => 'Left deltoid']);
        $record = PhrImmunization::query()->findOrFail($this->immApply($this->immPersist($this->immPropose()[0]), PhrEhiProposal::DECISION_ACCEPT)->recordId);
        $this->immLink($first, $record);

        // A later package re-supplies the identical revision: corroboration only.
        $second = $this->immNewPackage();
        $this->immOwnedImmune('781', ['LOT' => 'SYN-LOT-1', 'SITE_C_NAME' => 'Left deltoid'], $second);
        $corroborate = $this->immPropose($this->immContext($second));
        self::assertCount(1, $corroborate);
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $corroborate[0]->kind);
        self::assertSame('phr_immunizations', $corroborate[0]->targetTable);
        self::assertSame($record->id, $corroborate[0]->targetId);
        $digest = EhiTargetDigest::of($record->fresh() ?? $record);
        $outcome = $this->immApply($this->immPersist($corroborate[0], $second), PhrEhiProposal::DECISION_ACCEPT);
        self::assertSame(PhrEhiRecordLink::KIND_CORROBORATING, $outcome->linkKind);
        self::assertSame($record->id, $outcome->recordId);
        self::assertSame($digest, EhiTargetDigest::of($record->fresh() ?? $record), 'corroboration writes nothing');

        // A person edits the site; a third package changes the lot.
        $record->refresh()->forceFill(['site' => 'Right deltoid'])->save();
        $third = $this->immNewPackage();
        $changed = $this->immOwnedImmune('781', ['LOT' => 'SYN-LOT-2', 'SITE_C_NAME' => 'Left deltoid'], $third);
        $update = $this->immPropose($this->immContext($third));
        self::assertCount(1, $update);
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update[0]->kind);
        self::assertSame($record->id, $update[0]->targetId);
        self::assertSame(['lot_number', 'raw_text'], array_keys($update[0]->proposed ?? []));
        self::assertSame(['lot_number' => 'SYN-LOT-1'], array_intersect_key($update[0]->before ?? [], ['lot_number' => 1]));
        self::assertSame('SYN-LOT-2', $update[0]->proposed['lot_number'] ?? null);
        self::assertContains($changed->id, $update[0]->revisionIds);

        $proposal = $this->immPersist($update[0], $third);
        $this->assertRefused(EhiImmunizationApplyRefused::DECISION_NOT_APPLICABLE, fn () => $this->immApply($proposal, PhrEhiProposal::DECISION_ACCEPT));
        self::assertSame('SYN-LOT-1', $record->refresh()->lot_number, 'a change needs approve_update');

        $applied = $this->immApply($proposal, PhrEhiProposal::DECISION_APPROVE_UPDATE);
        self::assertSame(PhrEhiRecordLink::KIND_PROJECTED, $applied->linkKind);
        $fresh = $record->refresh();
        self::assertSame('SYN-LOT-2', $fresh->lot_number);
        self::assertSame('given', $fresh->administration_status);
        self::assertSame('Right deltoid', $fresh->site, 'a field the source did not change keeps the person\'s edit');
        self::assertSame('confirmed', $fresh->review_status);
    }

    public function test_linked_record_whose_source_turns_non_clinical_is_retained_never_updated(): void
    {
        $cases = [
            '791' => ['Deleted', 'N', 'deleted'],
            '792' => ['Refused', 'N', 'refused'],
            '793' => ['Invalid', 'N', 'invalid'],
            '794' => ['Deferred', 'N', 'deferred'],
            '795' => ['Incomplete', 'N', 'incomplete'],
            '796' => ['Synthetic Unlisted Status', 'N', 'unknown'],
        ];
        $records = [];
        foreach (array_keys($cases) as $immuneId) {
            $given = $this->immOwnedImmune((string) $immuneId, ['LOT' => 'SYN-LOT-1']);
            $records[$immuneId] = $this->immRecord([
                'import_source' => EhiImmunizationMapping::importSource($this->immSource->id),
                'external_id' => 'IMMUNE:'.$immuneId,
                'administration_status' => 'given',
                'lot_number' => 'SYN-LOT-1',
            ]);
            $this->immLink($given, $records[$immuneId]);
        }
        // An unlinked record holding the import identity is a target too.
        $records['797'] = $this->immRecord([
            'import_source' => EhiImmunizationMapping::importSource($this->immSource->id),
            'external_id' => 'IMMUNE:797',
            'administration_status' => 'given',
        ]);
        $digests = [];
        foreach (PhrImmunization::query()->get() as $existing) {
            $digests[$existing->id] = EhiTargetDigest::of($existing);
        }

        $later = $this->immNewPackage();
        $revisions = [];
        foreach ($cases as $immuneId => [$status, $historic]) {
            $revisions[$immuneId] = $this->immOwnedImmune((string) $immuneId, [
                'LOT' => 'SYN-LOT-2',
                'IMMNZTN_STATUS_C_NAME' => $status,
                'IMM_HISTORIC_ADM_YN' => $historic,
            ], $later);
        }
        $revisions['797'] = $this->immOwnedImmune('797', ['IMMNZTN_STATUS_C_NAME' => 'Refused'], $later);

        $context = $this->immContext($later);
        $drafts = $this->draftsBySubject($this->immPropose($context));

        self::assertCount(7, $drafts);
        $cases += ['797' => ['Refused', 'N', 'refused']];
        foreach ($cases as $immuneId => [, , $administrationStatus]) {
            $draft = $drafts[$revisions[$immuneId]->entity->key_digest];
            self::assertSame(PhrEhiProposal::KIND_RETAIN, $draft->kind, "IMMUNE {$immuneId} is never an update");
            self::assertNull($draft->targetId);
            self::assertNull($draft->before);
            self::assertSame(EhiImmunizationMapping::FINDING_LINKED_STATUS_NOT_CLINICAL, $draft->proposed['retain_reason'] ?? null);
            self::assertSame($administrationStatus, $draft->proposed['administration_status'] ?? null, 'the source status is still carried');
            self::assertContains($revisions[$immuneId]->id, $draft->revisionIds);
        }

        $statusFindings = array_values(array_filter($context->findings(), static fn (array $finding): bool => $finding['code'] === EhiImmunizationMapping::FINDING_LINKED_STATUS_NOT_CLINICAL));
        self::assertCount(7, $statusFindings);
        $byRevision = array_column($statusFindings, null, 'revision_id');
        foreach ($cases as $immuneId => [, , $administrationStatus]) {
            self::assertSame(
                ['status' => $administrationStatus, 'record_id' => $records[$immuneId]->id],
                $byRevision[$revisions[$immuneId]->id]['detail'] ?? null,
            );
        }
        self::assertContains(EhiImmunizationMapping::FINDING_UNKNOWN_STATUS, array_column($context->findings(), 'code'));

        foreach (PhrImmunization::query()->get() as $existing) {
            self::assertSame($digests[$existing->id], EhiTargetDigest::of($existing), 'proposing never writes the record');
            self::assertSame('given', $existing->administration_status);
        }

        // A stored update that would write a non-clinical status is refused at apply.
        $forged = $this->immPersist(new EhiProposalDraft(
            subjectKey: $revisions['791']->entity->key_digest,
            kind: PhrEhiProposal::KIND_UPDATE,
            revisionIds: [$revisions['791']->id],
            proposed: ['administration_status' => 'deleted'],
            targetTable: 'phr_immunizations',
            targetId: $records['791']->id,
            before: ['administration_status' => 'given'],
        ), $later);
        $this->assertRefused(EhiImmunizationApplyRefused::MALFORMED_PROPOSAL, fn () => $this->immApply($forged, PhrEhiProposal::DECISION_APPROVE_UPDATE));
        self::assertSame('given', $records['791']->refresh()->administration_status);
    }

    public function test_update_apply_refuses_a_stale_digest_and_a_withdrawn_target(): void
    {
        $first = $this->immOwnedImmune('791', ['LOT' => 'SYN-LOT-1']);
        $record = $this->immRecord(['import_source' => 'fhir', 'external_id' => 'Immunization/synthetic-791', 'lot_number' => 'SYN-LOT-1']);
        $this->immLink($first, $record, PhrEhiRecordLink::KIND_BRIDGED);
        $second = $this->immNewPackage();
        $this->immOwnedImmune('791', ['LOT' => 'SYN-LOT-2'], $second);
        $draft = $this->immPropose($this->immContext($second))[0];
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $draft->kind);
        $proposal = $this->immPersist($draft, $second);

        // Changed after preview.
        DB::table('phr_immunizations')->where('id', $record->id)->update(['notes' => 'edited after preview']);
        $this->assertRefused(EhiImmunizationApplyRefused::TARGET_CHANGED, fn () => $this->immApply($proposal, PhrEhiProposal::DECISION_APPROVE_UPDATE));
        self::assertSame('SYN-LOT-1', $record->refresh()->lot_number);

        // Retracted, then deleted.
        DB::table('phr_immunizations')->where('id', $record->id)->update(['retracted_at' => Carbon::now()]);
        $this->assertRefused(EhiImmunizationApplyRefused::TARGET_WITHDRAWN, fn () => $this->immApply($proposal, PhrEhiProposal::DECISION_APPROVE_UPDATE));
        DB::table('phr_immunizations')->where('id', $record->id)->update(['retracted_at' => null, 'deleted_at' => Carbon::now()]);
        $this->assertRefused(EhiImmunizationApplyRefused::TARGET_WITHDRAWN, fn () => $this->immApply($proposal, PhrEhiProposal::DECISION_APPROVE_UPDATE));
        self::assertSame('SYN-LOT-1', PhrImmunization::query()->withTrashed()->findOrFail($record->id)->lot_number);
        self::assertNotNull(PhrImmunization::query()->withTrashed()->findOrFail($record->id)->getAttribute('deleted_at'), 'never resurrected');
    }

    public function test_withdrawn_linked_record_is_retained_never_resurrected(): void
    {
        $deletedRevision = $this->immOwnedImmune('801');
        $deleted = $this->immRecord(['import_source' => 'fhir', 'external_id' => 'Immunization/synthetic-801']);
        $this->immLink($deletedRevision, $deleted, PhrEhiRecordLink::KIND_BRIDGED);
        $deleted->delete();
        $retractedRevision = $this->immOwnedImmune('802', ['IMMUNE_DATE' => '2024-04-01']);
        $retracted = $this->immRecord(['import_source' => 'fhir', 'external_id' => 'Immunization/synthetic-802', 'administered_on' => '2024-04-01']);
        $this->immLink($retractedRevision, $retracted, PhrEhiRecordLink::KIND_BRIDGED);
        DB::table('phr_immunizations')->where('id', $retracted->id)->update(['retracted_at' => Carbon::now()]);

        $context = $this->immContext();
        $drafts = $this->draftsByExternalId($this->immPropose($context));

        foreach (['IMMUNE:801', 'IMMUNE:802'] as $externalId) {
            self::assertSame(PhrEhiProposal::KIND_RETAIN, $drafts[$externalId]->kind);
            self::assertSame('linked_record_withdrawn', $drafts[$externalId]->proposed['retain_reason'] ?? null);
            self::assertNull($drafts[$externalId]->targetId);
        }
        self::assertSame(['linked_record_withdrawn', 'linked_record_withdrawn'], array_column($context->findings(), 'code'));
        self::assertEqualsCanonicalizing(['deleted', 'retracted'], array_map(static fn (array $finding): mixed => $finding['detail']['lifecycle'] ?? null, $context->findings()));
    }

    public function test_unlinked_external_identity_is_the_target_and_a_withdrawn_one_is_retained(): void
    {
        $matched = $this->immOwnedImmune('811');
        $existing = $this->immRecord([
            'import_source' => EhiImmunizationMapping::importSource($this->immSource->id),
            'external_id' => 'IMMUNE:811',
            'lot_number' => 'SYN-LOT-OLD',
        ]);
        $retained = $this->immOwnedImmune('812', ['IMMUNE_DATE' => '2024-05-01']);
        $withdrawn = $this->immRecord([
            'import_source' => EhiImmunizationMapping::importSource($this->immSource->id),
            'external_id' => 'IMMUNE:812',
            'administered_on' => '2024-05-01',
        ]);
        $withdrawn->delete();

        $context = $this->immContext();
        $drafts = $this->draftsBySubject($this->immPropose($context));

        self::assertSame(PhrEhiProposal::KIND_UPDATE, $drafts[$matched->entity->key_digest]->kind);
        self::assertSame($existing->id, $drafts[$matched->entity->key_digest]->targetId);
        self::assertSame('SYN-LOT-OLD', $drafts[$matched->entity->key_digest]->before['lot_number'] ?? null);
        self::assertArrayHasKey('lot_number', $drafts[$matched->entity->key_digest]->proposed ?? []);
        self::assertNull($drafts[$matched->entity->key_digest]->proposed['lot_number']);
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $drafts[$retained->entity->key_digest]->kind);
        self::assertSame('immunization_identity_withdrawn', $drafts[$retained->entity->key_digest]->proposed['retain_reason'] ?? null);
        self::assertEqualsCanonicalizing(['matched_external_identity', 'immunization_identity_withdrawn'], array_column($context->findings(), 'code'));
    }

    public function test_create_apply_refuses_a_withdrawn_or_held_identity(): void
    {
        $this->immOwnedImmune('821');
        $proposal = $this->immPersist($this->immPropose()[0]);
        $identity = [
            'import_source' => EhiImmunizationMapping::importSource($this->immSource->id),
            'external_id' => 'IMMUNE:821',
        ];

        // Created after the preview, then deleted: never resurrected.
        $held = $this->immRecord($identity);
        $this->assertRefused(EhiImmunizationApplyRefused::IDENTITY_EXISTS, fn () => $this->immApply($proposal, PhrEhiProposal::DECISION_ACCEPT));
        $held->delete();
        $this->assertRefused(EhiImmunizationApplyRefused::IDENTITY_WITHDRAWN, fn () => $this->immApply($proposal, PhrEhiProposal::DECISION_ACCEPT));
        DB::table('phr_immunizations')->where('id', $held->id)->update(['deleted_at' => null, 'retracted_at' => Carbon::now()]);
        $this->assertRefused(EhiImmunizationApplyRefused::IDENTITY_WITHDRAWN, fn () => $this->immApply($proposal, PhrEhiProposal::DECISION_ACCEPT));

        self::assertSame(1, PhrImmunization::query()->withTrashed()->where('external_id', 'IMMUNE:821')->count());
        self::assertNotNull(PhrImmunization::query()->findOrFail($held->id)->getAttribute('retracted_at'));
    }

    public function test_link_apply_validates_the_target_and_other_decisions_are_refused(): void
    {
        $this->immOwnedImmune('831');
        $proposal = $this->immPersist($this->immPropose()[0]);

        $otherOwner = $this->createUser();
        $otherPatient = PhrPatient::query()->create(['owner_user_id' => $otherOwner->id, 'display_name' => 'Other Synthetic', 'relationship' => 'self']);
        $foreign = PhrImmunization::query()->create([
            'patient_id' => $otherPatient->id,
            'user_id' => $otherOwner->id,
            'review_status' => 'confirmed',
            'vaccine_name' => 'SYNTHETIC VACCINE A',
            'administered_on' => '2024-03-05',
        ]);
        $this->assertRefused(EhiImmunizationApplyRefused::TARGET_NOT_FOUND, fn () => $this->immApply($proposal, PhrEhiProposal::DECISION_LINK, $foreign->id));
        $this->assertRefused(EhiImmunizationApplyRefused::TARGET_NOT_FOUND, fn () => $this->immApply($proposal, PhrEhiProposal::DECISION_LINK, null));

        $retracted = $this->immRecord(['vaccine_name' => 'Unrelated Synthetic']);
        DB::table('phr_immunizations')->where('id', $retracted->id)->update(['retracted_at' => Carbon::now()]);
        $this->assertRefused(EhiImmunizationApplyRefused::TARGET_WITHDRAWN, fn () => $this->immApply($proposal, PhrEhiProposal::DECISION_LINK, $retracted->id));

        // Any live immunization of the patient may be chosen, not only a candidate.
        $live = $this->immRecord(['vaccine_name' => 'Unrelated Synthetic', 'administered_on' => '2020-01-01']);
        self::assertSame($live->id, $this->immApply($proposal, PhrEhiProposal::DECISION_LINK, $live->id)->recordId);

        $this->assertRefused(EhiImmunizationApplyRefused::DECISION_NOT_APPLICABLE, fn () => $this->immApply($proposal, PhrEhiProposal::DECISION_APPROVE_UPDATE));
        $this->assertRefused(EhiImmunizationApplyRefused::DECISION_NOT_APPLICABLE, fn () => $this->immApply($proposal, PhrEhiProposal::DECISION_REJECT));

        $this->immImmune('832');
        $retain = $this->immPersist($this->draftsByExternalId($this->immPropose())['IMMUNE:832']);
        $this->assertRefused(EhiImmunizationApplyRefused::DECISION_NOT_APPLICABLE, fn () => $this->immApply($retain, PhrEhiProposal::DECISION_ACCEPT));

        $foreignApply = new EhiApplyContext($otherPatient, $otherOwner->id, PhrEhiProposal::DECISION_ACCEPT, null, Carbon::now());
        $this->assertRefused(EhiImmunizationApplyRefused::WRONG_SCOPE, fn () => DB::transaction(fn () => (new EhiImmunizationMapping)->apply($proposal, $foreignApply)));
        self::assertSame(0, PhrImmunization::query()->where('external_id', 'IMMUNE:831')->count());
    }

    public function test_ambiguous_revisions_for_one_immune_id_are_retained(): void
    {
        $a = $this->immOwnedImmune('841', ['LOT' => 'SYN-LOT-A']);
        $b = $this->immImmune('841', ['LOT' => 'SYN-LOT-B']);

        $context = $this->immContext();
        $drafts = $this->immPropose($context);

        self::assertCount(1, $drafts);
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $drafts[0]->kind);
        self::assertSame([min($a->id, $b->id), max($a->id, $b->id)], array_slice($drafts[0]->revisionIds, 0, 2));
        self::assertSame(['ambiguous_immunization_revision'], array_column($context->findings(), 'code'));
    }

    public function test_an_immune_id_too_long_for_the_record_identity_is_retained_without_copying_it(): void
    {
        $long = str_repeat('9', 300);
        $this->immOwnedImmune($long);

        $context = $this->immContext();
        $drafts = $this->immPropose($context);

        self::assertCount(1, $drafts);
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $drafts[0]->kind);
        self::assertSame(['retain_reason' => EhiImmunizationMapping::FINDING_IDENTITY_TOO_LONG], $drafts[0]->proposed);
        self::assertSame([EhiImmunizationMapping::FINDING_IDENTITY_TOO_LONG], array_column($context->findings(), 'code'));
        self::assertStringNotContainsString($long, (string) json_encode($context->findings()));
    }

    public function test_proposals_are_deterministic(): void
    {
        $this->immOwnedImmune('851', ['IMMUNIZATION_TIME' => '9:00 AM', 'IMMNZTN_DOSE_AMOUNT' => '0.50']);
        $this->immOwnedImmune('852', ['IMMNZTN_STATUS_C_NAME' => 'Refused']);
        $this->immImmune('853');
        $this->immRecord(['import_source' => 'fhir', 'external_id' => 'Immunization/synthetic-dup']);

        $firstContext = $this->immContext();
        $first = $this->immPropose($firstContext);
        $secondContext = $this->immContext();
        $second = $this->immPropose($secondContext);

        self::assertCount(3, $first);
        self::assertEquals($first, $second);
        self::assertSame(serialize($first), serialize($second));
        self::assertSame($firstContext->findings(), $secondContext->findings());
    }

    private function immApply(PhrEhiProposal $proposal, string $decision, ?int $linkTargetId = null): EhiApplyOutcome
    {
        $context = new EhiApplyContext($this->immPatient, $this->immOwner->id, $decision, $linkTargetId, Carbon::now());

        return DB::transaction(fn (): EhiApplyOutcome => (new EhiImmunizationMapping)->apply($proposal->fresh() ?? $proposal, $context));
    }

    private function assertRefused(string $reason, Closure $callback): void
    {
        try {
            $callback();
        } catch (EhiImmunizationApplyRefused $refused) {
            self::assertSame($reason, $refused->reason);

            return;
        }
        self::fail("Expected the apply to be refused ({$reason}).");
    }

    /**
     * @param  list<EhiProposalDraft>  $drafts
     * @return array<string, EhiProposalDraft>
     */
    private function draftsBySubject(array $drafts): array
    {
        $bySubject = [];
        foreach ($drafts as $draft) {
            $bySubject[$draft->subjectKey] = $draft;
        }

        return $bySubject;
    }

    /**
     * @param  list<EhiProposalDraft>  $drafts
     * @return array<string, EhiProposalDraft>
     */
    private function draftsByExternalId(array $drafts): array
    {
        $byExternalId = [];
        foreach ($drafts as $draft) {
            $externalId = $draft->proposed['external_id'] ?? null;
            self::assertIsString($externalId);
            $byExternalId[$externalId] = $draft;
        }

        return $byExternalId;
    }

    /**
     * Finding (code, revision) pairs, ordered by the given revision order so the
     * assertion does not depend on key-digest ordering.
     *
     * @param  list<array{code: string, revision_id: int|null, detail: array<string, int|string|bool|null>}>  $findings
     * @param  list<int>  $order
     * @return list<array{0: string, 1: int|null}>
     */
    private function sortedFindingPairs(array $findings, array $order): array
    {
        $pairs = array_map(static fn (array $finding): array => [$finding['code'], $finding['revision_id']], $findings);
        usort($pairs, static fn (array $a, array $b): int => array_search($a[1], $order, true) <=> array_search($b[1], $order, true));

        return $pairs;
    }
}
