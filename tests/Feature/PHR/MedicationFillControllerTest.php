<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\User;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

/** The browser API for fills: manual entry with NDC autofill, edits, review and history. */
final class MedicationFillControllerTest extends TestCase
{
    use FakesOpenFda;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->fakeOpenFda(['00020-800-01']);
    }

    /** @return array{owner: User, viewer: User, patientId: int} */
    private function patientWithViewer(): array
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Fill Patient', 'relationship' => 'self'])
            ->assertCreated()->json('patient.id');
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/access", ['email' => $viewer->email, 'access_level' => 'viewer'])->assertCreated();

        return compact('owner', 'viewer', 'patientId');
    }

    /** @return array<string, mixed> */
    private function body(array $overrides = []): array
    {
        return [
            'ndc' => '58151-0155-77', 'filled_on' => '2026-10-28', 'quantity' => '90', 'quantity_unit' => 'tablet',
            'paid_cents' => 1000, 'retail_cents' => 18999, 'pharmacy_name' => 'Example Pharmacy', ...$overrides,
        ];
    }

    public function test_manual_entry_autofills_the_drug_and_links_a_new_medication(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();

        $response = $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/medication-fills", $this->body(['source_kind' => 'receipt_pdf']))
            ->assertCreated()
            ->assertJsonPath('medication_fill.source_kind', 'manual')
            ->assertJsonPath('medication_fill.review_status', PhrReviewStatus::CONFIRMED)
            ->assertJsonPath('medication_fill.ndc_status', 'resolved')
            ->assertJsonPath('medication_fill.ndc_10', '58151-155-77')
            ->assertJsonPath('medication_fill.ndc_11', '58151015577')
            ->assertJsonPath('medication_fill.drug.brand_name', 'Lipitor')
            ->assertJsonPath('medication_fill.drug.dosage_form', 'tablet, film coated')
            ->assertJsonPath('medication_fill.drug.route', 'oral')
            ->assertJsonPath('medication_fill.quantity', '90')
            ->assertJsonPath('medication_fill.medication.name', 'atorvastatin calcium trihydrate')
            ->json('medication_fill');

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $response['version']);
        $medication = PhrMedication::query()->findOrFail($response['medication_id']);
        $this->assertSame(PhrReviewStatus::PENDING, $medication->review_status, 'an auto-created medication waits for review');
        $this->assertSame('phr-fill', $medication->import_source);
        $this->assertSame('fill:'.$response['id'], $medication->external_id);
        $this->assertSame('2026-10-28', $medication->started_on->toDateString());

        // A later fill of the same drug links the existing entry.
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/medication-fills", $this->body(['filled_on' => '2026-11-25']))
            ->assertCreated()
            ->assertJsonPath('medication_fill.medication_id', $medication->id);
        $this->assertSame(1, PhrMedication::query()->count());
    }

    public function test_an_ambiguous_ndc_asks_for_a_choice_and_the_choice_resolves_it(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $url = "/api/phr/patients/{$patientId}/medication-fills";

        $this->actingAs($owner)->postJson($url, $this->body(['ndc' => '0002080001']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.ndc_candidate.0', 'Choose one of the matching packages');
        $this->assertSame(0, PhrMedicationFill::query()->count());

        $this->actingAs($owner)->postJson($url, $this->body(['ndc' => '0002080001', 'ndc_candidate' => '00020-800-01']))
            ->assertCreated()
            ->assertJsonPath('medication_fill.ndc_status', 'resolved')
            ->assertJsonPath('medication_fill.ndc_10', '00020-800-01');
        $this->actingAs($owner)->postJson($url, $this->body(['ndc' => '0002080001', 'ndc_candidate' => '58151-155-77']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ndc_candidate');
    }

    public function test_an_unlisted_ndc_is_refused_unless_the_person_saves_it_unresolved(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $url = "/api/phr/patients/{$patientId}/medication-fills";

        $this->actingAs($owner)->postJson($url, $this->body(['ndc' => '12345-6789-0']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.ndc.0', 'No FDA listing matches this NDC');
        // Even allowed, the drug needs a name to be listed under.
        $this->actingAs($owner)->postJson($url, $this->body(['ndc' => '12345-6789-0', 'allow_unresolved' => true]))
            ->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, $this->body(['ndc' => '12345-6789-0', 'allow_unresolved' => true, 'drug_as_printed' => 'Mystery capsules']))
            ->assertCreated()
            ->assertJsonPath('medication_fill.ndc_status', 'unresolved')
            ->assertJsonPath('medication_fill.drug.brand_name', null)
            ->assertJsonPath('medication_fill.medication.name', 'Mystery capsules');
    }

    public function test_an_outage_is_not_reported_as_an_unlisted_ndc(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $this->fakeOpenFda(down: true);

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/medication-fills", $this->body())
            ->assertUnprocessable()
            ->assertJsonPath('errors.ndc.0', fn (string $message): bool => str_contains($message, 'could not be reached'));
    }

    public function test_a_fill_can_be_entered_without_an_ndc(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $url = "/api/phr/patients/{$patientId}/medication-fills";
        $body = $this->body(['ndc' => null]);

        $this->actingAs($owner)->postJson($url, $body)->assertUnprocessable()->assertJsonValidationErrors('ndc');
        $this->actingAs($owner)->postJson($url, [...$body, 'drug_as_printed' => 'Vitamin D 1000 IU'])
            ->assertCreated()
            ->assertJsonPath('medication_fill.ndc_status', 'manual')
            ->assertJsonPath('medication_fill.ndc_10', null)
            ->assertJsonPath('medication_fill.medication.name', 'Vitamin D 1000 IU');
    }

    public function test_the_person_can_choose_the_medication_but_only_one_of_the_patients(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $url = "/api/phr/patients/{$patientId}/medication-fills";
        $mine = PhrMedication::factory()->create(['patient_id' => $patientId, 'user_id' => $owner->id, 'name' => 'My statin', 'rxnorm_code' => null]);
        $otherPatient = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Elsewhere'])->json('patient.id');
        $theirs = PhrMedication::factory()->create(['patient_id' => $otherPatient, 'user_id' => $owner->id, 'name' => 'Their statin']);

        $this->actingAs($owner)->postJson($url, $this->body(['medication_id' => $theirs->id]))->assertUnprocessable()->assertJsonValidationErrors('medication_id');
        $this->actingAs($owner)->postJson($url, $this->body(['medication_id' => $mine->id]))
            ->assertCreated()
            ->assertJsonPath('medication_fill.medication_id', $mine->id);
        $this->assertSame(0, PhrMedication::query()->where('review_status', PhrReviewStatus::PENDING)->count());
    }

    public function test_validation_covers_the_fill_fields(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/medication-fills", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filled_on', 'quantity']);
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/medication-fills", $this->body([
            'quantity' => -1, 'paid_cents' => 12.5, 'days_supply' => -3, 'filled_on' => 'someday',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['quantity', 'paid_cents', 'days_supply', 'filled_on']);
    }

    public function test_update_requires_the_current_version_and_keeps_the_medication_link(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $created = $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/medication-fills", $this->body())->assertCreated()->json('medication_fill');
        $url = "/api/phr/patients/{$patientId}/medication-fills/{$created['id']}";

        $this->actingAs($owner)->patchJson($url, ['version' => str_repeat('b', 64), 'quantity' => '30'])
            ->assertConflict()
            ->assertJsonStructure(['message']);
        $this->assertSame('90.000', PhrMedicationFill::query()->findOrFail($created['id'])->quantity);

        $updated = $this->actingAs($owner)->patchJson($url, ['version' => $created['version'], 'quantity' => '30', 'medication_id' => null, 'days_supply' => 30])
            ->assertOk()
            ->assertJsonPath('medication_fill.quantity', '30')
            ->assertJsonPath('medication_fill.medication_id', $created['medication_id'])
            ->json('medication_fill');
        $this->assertNotSame($created['version'], $updated['version']);

        // Without a version the edit is unconditional, like the other record types.
        $this->actingAs($owner)->patchJson($url, ['pharmacy_name' => 'Elsewhere Pharmacy'])
            ->assertOk()
            ->assertJsonPath('medication_fill.pharmacy_name', 'Elsewhere Pharmacy');
    }

    public function test_changing_the_ndc_re_resolves_the_drug_and_relinks_only_when_the_medication_no_longer_fits(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $created = $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/medication-fills", $this->body())->assertCreated()->json('medication_fill');
        $url = "/api/phr/patients/{$patientId}/medication-fills/{$created['id']}";

        // The same drug under another package of the same product keeps the link.
        $this->actingAs($owner)->patchJson($url, ['ndc' => '58151-0155-77'])
            ->assertOk()
            ->assertJsonPath('medication_fill.medication_id', $created['medication_id']);

        $changed = $this->actingAs($owner)->patchJson($url, ['ndc' => '60505-0134-00'])
            ->assertOk()
            ->assertJsonPath('medication_fill.drug.brand_name', 'Cyclosporine')
            ->json('medication_fill');
        $this->assertNotSame($created['medication_id'], $changed['medication_id']);
        $this->assertSame('cyclosporine', $changed['medication']['name']);

        $this->actingAs($owner)->patchJson($url, ['ndc' => '12345-6789-0'])->assertUnprocessable()->assertJsonValidationErrors('ndc');
        $this->assertSame('60505-134-00', PhrMedicationFill::query()->findOrFail($created['id'])->ndc_10, 'a refused edit changes nothing');
    }

    public function test_index_show_history_review_and_delete(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $base = "/api/phr/patients/{$patientId}";
        $one = $this->actingAs($owner)->postJson("{$base}/medication-fills", $this->body(['filled_on' => '2026-08-01']))->assertCreated()->json('medication_fill');
        $two = $this->actingAs($owner)->postJson("{$base}/medication-fills", $this->body(['filled_on' => '2026-10-01', 'ndc' => '60505-0134-00']))->assertCreated()->json('medication_fill');
        $three = $this->actingAs($owner)->postJson("{$base}/medication-fills", $this->body(['filled_on' => '2026-09-01']))->assertCreated()->json('medication_fill');

        $ids = fn (string $query = ''): array => array_column(
            $this->actingAs($owner)->getJson("{$base}/medication-fills{$query}")->assertOk()->assertJsonPath('can_manage', true)->json('medication_fills'),
            'id',
        );
        $this->assertSame([$two['id'], $three['id'], $one['id']], $ids(), 'newest fill first');
        $this->assertSame([$three['id'], $one['id']], $ids('?medication_id='.$one['medication_id']));
        $this->assertSame([$three['id']], $ids('?from=2026-08-15&to=2026-09-30'));
        $this->assertSame([], $ids('?ndc_status=unresolved'));
        $this->actingAs($owner)->getJson("{$base}/medication-fills?from=2026-10-01&to=2026-01-01")->assertUnprocessable();

        $this->actingAs($owner)->getJson("{$base}/medication-fills/{$one['id']}")
            ->assertOk()->assertJsonPath('medication_fill.id', $one['id'])->assertJsonPath('can_manage', true);
        $history = $this->actingAs($owner)->getJson("{$base}/medications/{$one['medication_id']}/fills")->assertOk()->json('medication_fills');
        $this->assertSame([$three['id'], $one['id']], array_column($history, 'id'));
        $this->actingAs($owner)->getJson("{$base}/medications/999999/fills")->assertNotFound();

        $this->actingAs($owner)->patchJson("{$base}/medication-fills/{$one['id']}/review", ['review_status' => 'rejected', 'version' => $one['version']])
            ->assertOk()->assertJsonPath('medication_fill.review_status', 'rejected');
        $this->assertNotContains($one['id'], $ids(), 'rejected fills leave the working list');
        $this->assertContains($one['id'], $ids('?include_rejected=1'));
        $this->actingAs($owner)->patchJson("{$base}/medication-fills/{$three['id']}/review", ['review_status' => 'confirmed', 'version' => str_repeat('c', 64)])->assertConflict();

        $this->actingAs($owner)->deleteJson("{$base}/medication-fills/{$two['id']}")->assertNoContent();
        $this->assertNotContains($two['id'], $ids());
        $this->assertSoftDeleted('phr_medication_fills', ['id' => $two['id']]);
    }

    public function test_viewers_read_but_cannot_write_and_strangers_see_nothing(): void
    {
        ['owner' => $owner, 'viewer' => $viewer, 'patientId' => $patientId] = $this->patientWithViewer();
        $base = "/api/phr/patients/{$patientId}/medication-fills";
        $fill = $this->actingAs($owner)->postJson($base, $this->body())->assertCreated()->json('medication_fill');
        $stranger = $this->createUser();

        $this->actingAs($viewer)->getJson($base)->assertOk()->assertJsonPath('can_manage', false);
        $this->actingAs($viewer)->postJson($base, $this->body())->assertForbidden();
        $this->actingAs($viewer)->patchJson("{$base}/{$fill['id']}", ['quantity' => 1])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("{$base}/{$fill['id']}")->assertForbidden();
        $this->actingAs($viewer)->patchJson("{$base}/{$fill['id']}/review", ['review_status' => 'confirmed'])->assertForbidden();
        $this->actingAs($stranger)->getJson($base)->assertNotFound();
        $this->actingAs($stranger)->getJson("{$base}/{$fill['id']}")->assertNotFound();
        $this->actingAs($stranger)->postJson($base, $this->body())->assertNotFound();
        $this->assertSame(1, PhrMedicationFill::query()->count());

        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->getJson($base)->assertUnauthorized();
        $this->getJson('/api/ndc/lookup?ndc=58151-0155-77')->assertUnauthorized();
        $this->getJson('/api/user/display-preferences')->assertUnauthorized();
    }

    public function test_fills_of_a_patient_are_not_listed_under_another_patients_medication(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $fill = $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/medication-fills", $this->body())->assertCreated()->json('medication_fill');
        $other = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Other'])->json('patient.id');

        $this->actingAs($owner)->getJson("/api/phr/patients/{$other}/medications/{$fill['medication_id']}/fills")->assertNotFound();
        $this->actingAs($owner)->getJson("/api/phr/patients/{$other}/medication-fills/{$fill['id']}")->assertNotFound();
    }

    public function test_the_review_summary_counts_a_pending_fill(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        PhrMedicationFill::factory()->create(['patient_id' => $patientId, 'user_id' => $owner->id, 'review_status' => PhrReviewStatus::PENDING, 'external_id' => 'x', 'import_source' => 's']);

        $review = $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/review")
            ->assertOk()
            ->assertJsonPath('summary.sections.medication-fills', ['pending' => 1, 'records' => 1])
            ->json();

        $this->assertStringContainsString('"review_segment":"medication-fills"', json_encode($review, JSON_THROW_ON_ERROR));
    }

    // ---- NDC endpoints and display preference ---------------------------------------------

    public function test_the_ndc_endpoints_autofill_and_search_for_the_form(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->getJson('/api/ndc/lookup?ndc=60505-0134-00')
            ->assertOk()
            ->assertJsonPath('status', 'resolved')
            ->assertJsonPath('input', '60505-0134-00')
            ->assertJsonPath('selected.ndc_11', '60505013400')
            ->assertJsonPath('selected.drug.labeler_name', 'Apotex Corp.');
        $this->actingAs($user)->getJson('/api/ndc/lookup?ndc=0002080001')
            ->assertOk()
            ->assertJsonPath('status', 'ambiguous')
            ->assertJsonPath('selected', null)
            ->assertJsonCount(2, 'candidates');
        $this->actingAs($user)->getJson('/api/ndc/lookup?ndc=1')->assertOk()->assertJsonPath('status', 'unresolved');
        $this->actingAs($user)->getJson('/api/ndc/lookup')->assertUnprocessable();

        $search = $this->actingAs($user)->getJson('/api/ndc/search?'.http_build_query(['name' => 'atorvastatin', 'strength' => '10 mg', 'labeler' => 'viatris', 'package' => '90']))
            ->assertOk()->json('candidates');
        $this->assertSame('58151-155-77', $search[0]['ndc_10']);
        $this->assertContains('package size matches', $search[0]['match_reasons']);
        $this->actingAs($user)->getJson('/api/ndc/search')->assertUnprocessable();
    }

    public function test_force_lowercase_is_a_stored_per_user_display_preference(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();

        $this->actingAs($user)->getJson('/api/user/display-preferences')->assertOk()->assertExactJson(['force_lowercase' => false]);
        $this->actingAs($user)->putJson('/api/user/display-preferences', ['force_lowercase' => true])->assertOk()->assertExactJson(['force_lowercase' => true]);
        $this->actingAs($user)->getJson('/api/user/display-preferences')->assertExactJson(['force_lowercase' => true]);
        $this->actingAs($other)->getJson('/api/user/display-preferences')->assertExactJson(['force_lowercase' => false]);
        $this->actingAs($user)->putJson('/api/user/display-preferences', [])->assertUnprocessable();
        $this->actingAs($user)->putJson('/api/user/display-preferences', ['force_lowercase' => 'yes please'])->assertUnprocessable();

        // It reaches the page the way other per-user settings do, and changes no stored text.
        $fill = PhrMedicationFill::factory()->create(['brand_name' => 'Lipitor', 'labeler_name' => 'Viatris Specialty LLC']);
        $this->actingAs($user)->get('/phr/patients')->assertOk()->assertSee('"force_lowercase_display":true', false);
        $this->assertSame('Lipitor', $fill->fresh()->brand_name);
        $this->assertTrue((bool) $user->fresh()->force_lowercase_display);
    }

    // ---- review fixes ----------------------------------------------------------------------

    public function test_clearing_the_ndc_removes_the_drug_and_needs_the_drug_name(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $created = $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/medication-fills", $this->body())->assertCreated()->json('medication_fill');
        $url = "/api/phr/patients/{$patientId}/medication-fills/{$created['id']}";

        // An explicit null is "remove the NDC", and the fill then needs some other drug text.
        $this->actingAs($owner)->patchJson($url, ['ndc' => null])->assertUnprocessable()->assertJsonValidationErrors('ndc');
        $this->assertSame('resolved', PhrMedicationFill::query()->findOrFail($created['id'])->ndc_status);

        $cleared = $this->actingAs($owner)->patchJson($url, ['ndc' => null, 'drug_as_printed' => 'Lipitor 10 mg tablets'])
            ->assertOk()
            ->assertJsonPath('medication_fill.ndc_status', 'manual')
            ->assertJsonPath('medication_fill.ndc_as_captured', null)
            ->assertJsonPath('medication_fill.ndc_10', null)
            ->assertJsonPath('medication_fill.drug.brand_name', null)
            ->assertJsonPath('medication_fill.drug.substance_names', [])
            ->json('medication_fill');
        $fill = PhrMedicationFill::query()->findOrFail($created['id']);
        $this->assertNull($fill->fda_snapshot);
        $this->assertNull($fill->fda_fetched_at);
        $this->assertNull($fill->rxcui);
        $this->assertSame('Lipitor 10 mg tablets', $cleared['medication']['name'], 'the medication no longer fits the drug, so it is re-linked');
    }

    public function test_an_edit_that_leaves_the_ndc_alone_cannot_blank_the_drug_text_of_an_unresolved_fill(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        foreach (['unresolved', 'ambiguous', 'manual'] as $status) {
            $fill = PhrMedicationFill::factory()->create([
                'patient_id' => $patientId, 'user_id' => $owner->id, 'ndc_status' => $status, 'ndc_10' => null, 'ndc_11' => null,
                'substance_names' => null, 'strengths' => null, 'brand_name' => null, 'rxcui' => null,
                'ndc_as_captured' => $status === 'manual' ? null : '12345-6789-0', 'drug_as_printed' => 'Printed drug',
            ]);
            $url = "/api/phr/patients/{$patientId}/medication-fills/{$fill->id}";

            $this->actingAs($owner)->patchJson($url, ['drug_as_printed' => null])->assertUnprocessable()->assertJsonValidationErrors('drug_as_printed');
            $this->actingAs($owner)->patchJson($url, ['drug_as_printed' => '   '])->assertUnprocessable();
            $this->actingAs($owner)->patchJson($url, ['quantity' => '5'])->assertOk();
            $this->assertSame('Printed drug', $fill->fresh()->drug_as_printed, $status);
        }
    }

    public function test_choosing_another_medication_withdraws_the_unreviewed_entry_only_this_fill_kept(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $base = "/api/phr/patients/{$patientId}/medication-fills";
        $mine = PhrMedication::factory()->create(['patient_id' => $patientId, 'user_id' => $owner->id, 'name' => 'My statin', 'rxnorm_code' => null, 'review_status' => PhrReviewStatus::CONFIRMED]);
        $created = $this->actingAs($owner)->postJson($base, $this->body())->assertCreated()->json('medication_fill');
        $auto = PhrMedication::query()->findOrFail($created['medication_id']);
        $this->assertSame(PhrReviewStatus::PENDING, $auto->review_status);

        $this->actingAs($owner)->patchJson("{$base}/{$created['id']}", ['medication_id' => $mine->id])
            ->assertOk()
            ->assertJsonPath('medication_fill.medication_id', $mine->id);

        $this->assertSoftDeleted('phr_medications', ['id' => $auto->id]);
        $this->assertNotSoftDeleted('phr_medications', ['id' => $mine->id]);
    }

    public function test_a_shared_or_reviewed_auto_created_medication_survives_a_relink(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $base = "/api/phr/patients/{$patientId}/medication-fills";
        $mine = PhrMedication::factory()->create(['patient_id' => $patientId, 'user_id' => $owner->id, 'name' => 'My statin', 'rxnorm_code' => null]);
        $one = $this->actingAs($owner)->postJson($base, $this->body())->assertCreated()->json('medication_fill');
        $two = $this->actingAs($owner)->postJson($base, $this->body(['filled_on' => '2026-11-25']))->assertCreated()->json('medication_fill');
        $this->assertSame($one['medication_id'], $two['medication_id']);

        $this->actingAs($owner)->patchJson("{$base}/{$one['id']}", ['medication_id' => $mine->id])->assertOk();
        $this->assertNotSoftDeleted('phr_medications', ['id' => $one['medication_id']]);

        // Once a person confirms the entry, it is theirs.
        PhrMedication::query()->whereKey($one['medication_id'])->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $this->actingAs($owner)->patchJson("{$base}/{$two['id']}", ['medication_id' => $mine->id])->assertOk();
        $this->assertNotSoftDeleted('phr_medications', ['id' => $one['medication_id']]);
    }

    public function test_deleting_a_fill_withdraws_the_unreviewed_medication_it_created(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $base = "/api/phr/patients/{$patientId}/medication-fills";
        $one = $this->actingAs($owner)->postJson($base, $this->body())->assertCreated()->json('medication_fill');
        $two = $this->actingAs($owner)->postJson($base, $this->body(['filled_on' => '2026-11-25']))->assertCreated()->json('medication_fill');

        $this->actingAs($owner)->deleteJson("{$base}/{$one['id']}")->assertNoContent();
        $this->assertNotSoftDeleted('phr_medications', ['id' => $one['medication_id']]);

        $this->actingAs($owner)->deleteJson("{$base}/{$two['id']}")->assertNoContent();
        $this->assertSoftDeleted('phr_medications', ['id' => $two['medication_id']]);
    }

    public function test_the_ndc_endpoints_are_rate_limited_per_user_and_search_answers_come_from_cache(): void
    {
        config(['services.openfda.web_requests_per_minute' => 3]);
        $user = $this->createUser();
        $other = $this->createUser();

        foreach (range(1, 3) as $_) {
            $this->actingAs($user)->getJson('/api/ndc/lookup?ndc=58151-0155-77')->assertOk();
        }
        $this->actingAs($user)->getJson('/api/ndc/lookup?ndc=58151-0155-77')->assertStatus(429);
        $this->actingAs($user)->getJson('/api/ndc/search?name=atorvastatin')->assertStatus(429);
        $this->actingAs($other)->getJson('/api/ndc/lookup?ndc=58151-0155-77')->assertOk();

        config(['services.openfda.web_requests_per_minute' => 100]);
        $this->actingAs($other)->getJson('/api/ndc/search?name=atorvastatin')->assertOk();
        $requests = count($this->openFdaRequests);
        $this->actingAs($other)->getJson('/api/ndc/search?name=atorvastatin')->assertOk();
        $this->assertSame($requests, count($this->openFdaRequests), 'a repeated search is served from the short cache');
    }

    public function test_a_person_edited_medication_survives_orphan_cleanup(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $base = "/api/phr/patients/{$patientId}/medication-fills";
        $created = $this->actingAs($owner)->postJson($base, $this->body())->assertCreated()->json('medication_fill');

        // The person adds a frequency and a reason before confirming the generated entry.
        $this->travel(10)->seconds();
        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientId}/medications/{$created['medication_id']}", ['frequency' => 'daily', 'reason_for_use' => 'cholesterol'])->assertOk();
        $this->assertSame(PhrReviewStatus::PENDING, PhrMedication::query()->findOrFail($created['medication_id'])->review_status, 'edited but not yet confirmed');

        $this->actingAs($owner)->deleteJson("{$base}/{$created['id']}")->assertNoContent();

        $this->assertNotSoftDeleted('phr_medications', ['id' => $created['medication_id']]);
        $this->assertSame('cholesterol', PhrMedication::query()->findOrFail($created['medication_id'])->reason_for_use);
    }

    public function test_correcting_an_unresolved_drug_name_moves_the_fill_to_the_matching_medication(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $base = "/api/phr/patients/{$patientId}/medication-fills";
        $b12 = PhrMedication::factory()->create(['patient_id' => $patientId, 'user_id' => $owner->id, 'name' => 'Vitamin B12', 'dose' => null, 'dose_unit' => null, 'rxnorm_code' => null]);
        $created = $this->actingAs($owner)->postJson($base, $this->body(['ndc' => null, 'drug_as_printed' => 'Vitamin D']))->assertCreated()->json('medication_fill');
        $this->assertSame('Vitamin D', $created['medication']['name']);

        // Whitespace is not a correction: the link stays.
        $this->actingAs($owner)->patchJson("{$base}/{$created['id']}", ['drug_as_printed' => ' Vitamin D '])
            ->assertOk()->assertJsonPath('medication_fill.medication_id', $created['medication_id']);

        $this->actingAs($owner)->patchJson("{$base}/{$created['id']}", ['drug_as_printed' => 'vitamin b12'])
            ->assertOk()
            ->assertJsonPath('medication_fill.medication_id', $b12->id);
        $this->assertSoftDeleted('phr_medications', ['id' => $created['medication_id']]);

        // A name nothing matches gets its own entry rather than staying on the old drug.
        $moved = $this->actingAs($owner)->patchJson("{$base}/{$created['id']}", ['drug_as_printed' => 'Fish oil'])
            ->assertOk()->json('medication_fill');
        $this->assertSame('Fish oil', $moved['medication']['name']);
        $this->assertNotSame($b12->id, $moved['medication_id']);
    }

    public function test_a_resolved_fills_printed_text_is_only_a_note(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->patientWithViewer();
        $base = "/api/phr/patients/{$patientId}/medication-fills";
        $created = $this->actingAs($owner)->postJson($base, $this->body(['drug_as_printed' => 'LIPITOR 10']))->assertCreated()->json('medication_fill');

        $this->actingAs($owner)->patchJson("{$base}/{$created['id']}", ['drug_as_printed' => 'Something else entirely'])
            ->assertOk()
            ->assertJsonPath('medication_fill.medication_id', $created['medication_id']);
    }
}
