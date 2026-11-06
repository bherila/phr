<?php

namespace Tests\Contracts;

use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\HttpContractFixtures;
use Tests\TestCase;

/** Dedicated producer: ordinary Unit/Feature suites never generate these files. */
final class HttpResponseFixturesTest extends TestCase
{
    use EhiHttpContractScenarios;
    use NativeHttpContractScenarios;

    public function test_generate_authenticated_http_fixtures(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 2)->setTime(3, 4, 5));
        config(['app.debug' => false]);
        Storage::fake('phr_exports');
        $writer = new HttpContractFixtures;

        $withoutPatients = $this->createUser();
        $response = $this->actingAs($withoutPatients)->getJson('/api/phr/data-hub');
        $writer->capture('inventory-no-patients', $response);
        $body = $this->body($response);
        HttpContractFixtures::require($body->owned_patients === [] && $body->shared_patients === [], 'inventory-no-patients', 'inventory');

        $emptyOwner = $this->createUser();
        $this->patient($emptyOwner);
        $response = $this->actingAs($emptyOwner)->getJson('/api/phr/data-hub');
        $writer->capture('inventory-owned-empty', $response);
        $counts = get_object_vars($this->body($response)->owned_patients[0]->record_counts);
        $catalog = json_decode(file_get_contents(base_path('tests/contracts/catalog.json')), true, 32, JSON_THROW_ON_ERROR);
        $expectedKeys = $catalog['inventory_keys'];
        $actualKeys = array_keys($counts);
        sort($expectedKeys);
        sort($actualKeys);
        HttpContractFixtures::require($actualKeys === $expectedKeys && count($counts) === 24
            && array_filter($counts, static fn ($count): bool => $count !== 0) === [], 'inventory-owned-empty', 'category_keys');

        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $this->fill($patient, $owner);
        $response = $this->actingAs($owner)->getJson('/api/phr/data-hub');
        $writer->capture('inventory-owned-populated', $response);
        HttpContractFixtures::require($this->body($response)->owned_patients[0]->record_counts->medication_fills === 1,
            'inventory-owned-populated', 'medication_fill_count');

        $otherOwner = $this->createUser();
        $shared = $this->patient($otherOwner);
        $this->fill($shared, $otherOwner);
        foreach (['viewer', 'manager'] as $level) {
            $actor = $this->createUser();
            PhrPatientUserAccess::query()->create([
                'patient_id' => $shared->id, 'user_id' => $actor->id, 'access_level' => $level,
                'granted_by_user_id' => $otherOwner->id, 'granted_at' => now(),
            ]);
            $response = $this->actingAs($actor)->getJson('/api/phr/data-hub');
            $id = 'inventory-shared-'.$level;
            $writer->capture($id, $response);
            $body = $this->body($response);
            HttpContractFixtures::require($body->owned_patients === [] && count($body->shared_patients) === 1
                && $body->shared_patients[0]->access_level === $level, $id, 'role');
            // Zod object parsing may strip extras: privacy is checked on raw decoded fields too.
            $fields = get_object_vars($body->shared_patients[0]);
            HttpContractFixtures::require(! array_key_exists('record_counts', $fields)
                && ! array_key_exists('storage_bytes', $fields), $id, 'private_field');
        }

        $response = $this->actingAs($owner)->postJson('/api/phr/data-hub/native-restores/uploads', [
            'source_file_size_bytes' => 4, 'restore_access_grants' => false,
        ]);
        // Do not assert/re-encode the empty map here: the real Zod consumer must detect its kind.
        $writer->capture('restore-uploading', $response);
        HttpContractFixtures::require($this->body($response)->restore->status === 'uploading', 'restore-uploading', 'state');

        $response = $this->actingAs($emptyOwner)->getJson('/api/phr/data-hub/native-restores');
        $writer->capture('restore-index-empty', $response);
        HttpContractFixtures::require($this->body($response)->restores === [], 'restore-index-empty', 'state');
        $response = $this->actingAs($owner)->getJson('/api/phr/data-hub/native-restores');
        $writer->capture('restore-index-uploading', $response);
        $body = $this->body($response);
        HttpContractFixtures::require(count($body->restores) === 1 && $body->restores[0]->status === 'uploading', 'restore-index-uploading', 'state');
        $this->nativeScenarios($writer);
        $this->ehiScenarios($writer);
        $this->assertSame(66, $writer->finish());
    }

    private function patient(User $owner): PhrPatient
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id, 'display_name' => 'Synthetic contract patient', 'relationship' => 'self',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => 'owner',
            'granted_by_user_id' => $owner->id, 'granted_at' => now(),
        ]);

        return $patient;
    }

    private function fill(PhrPatient $patient, User $owner): void
    {
        PhrMedicationFill::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'source_kind' => 'receipt',
            'review_status' => 'confirmed', 'pharmacy_name' => 'Synthetic contract pharmacy', 'filled_on' => '2026-01-01', 'quantity' => '1.000',
        ]);
    }

    /** @param TestResponse<Response> $response */
    private function body(TestResponse $response): object
    {
        return json_decode((string) $response->getContent(), false, 32, JSON_THROW_ON_ERROR);
    }
}
