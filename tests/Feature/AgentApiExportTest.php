<?php

namespace Tests\Feature;

use App\Models\AgentApiAudit;
use App\Models\PhrExport;
use App\Models\PhrNativeBackup;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

final class AgentApiExportTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Queue::fake();
        Storage::fake('phr_exports');
    }

    public function test_owner_can_request_list_and_explicitly_download_exports_and_native_backups(): void
    {
        $owner = $this->user('export-owner@example.test');
        $patient = $this->patient($owner);
        $client = $this->client();
        $base = "/api/v1/patients/{$patient->id}";

        Passport::actingAs($owner, [AgentApiScopes::EXPORTS_READ], 'api', $client);
        $this->postJson("{$base}/exports", ['formats' => ['fhir']])->assertForbidden();
        $this->getJson("{$base}/exports?limit=1")
            ->assertOk()
            ->assertJsonPath('resource_type', 'export')
            ->assertJsonPath('pagination.limit', 1)
            ->assertJsonCount(0, 'data');

        Passport::actingAs($owner, [AgentApiScopes::EXPORTS_WRITE], 'api', $client);
        $queued = $this->postJson("{$base}/exports", ['formats' => ['fhir', 'pdf']])
            ->assertAccepted()
            ->assertJsonPath('outcome', 'queued')
            ->assertJsonPath('data.formats', ['fhir', 'pdf'])
            ->json('data');
        $this->postJson("{$base}/native-backups")
            ->assertAccepted()
            ->assertJsonPath('resource_type', 'native_backup')
            ->assertJsonPath('outcome', 'queued');

        $export = PhrExport::query()->findOrFail($queued['id']);
        Storage::disk('phr_exports')->put('exports/synthetic-ready.zip', 'synthetic export bytes');
        $export->update([
            'status' => PhrExport::STATUS_READY,
            'storage_path' => 'exports/synthetic-ready.zip',
            'filename' => 'synthetic-ready.zip',
            'file_size_bytes' => 22,
            'generated_at' => now(),
        ]);
        $backup = PhrNativeBackup::query()->latest('id')->firstOrFail();
        Storage::disk('phr_exports')->put('backups/synthetic-ready.zip', 'synthetic backup bytes');
        $backup->update([
            'status' => PhrNativeBackup::STATUS_READY,
            'storage_path' => 'backups/synthetic-ready.zip',
            'file_size_bytes' => 22,
            'generated_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        Passport::actingAs($owner, [AgentApiScopes::EXPORTS_READ], 'api', $client);
        $listedExport = $this->getJson("{$base}/exports")
            ->assertOk()
            ->assertJsonPath('data.0.id', $export->id)
            ->assertJsonMissingPath('data.0.filename')
            ->assertJsonMissingPath('data.0.storage_path')
            ->assertJsonMissingPath('data.0.error_message')
            ->json('data.0');
        $this->assertSame(['fhir', 'pdf'], $listedExport['formats']);
        $this->getJson("{$base}/native-backups")
            ->assertOk()
            ->assertJsonPath('data.0.id', $backup->id)
            ->assertJsonMissingPath('data.0.archive_sha256')
            ->assertJsonMissingPath('data.0.counts');

        $exportAccess = $this->postJson("{$base}/exports/{$export->id}/download-access")
            ->assertOk()
            ->assertJsonPath('id', $export->id)
            ->json();
        $backupAccess = $this->postJson("{$base}/native-backups/{$backup->id}/download-access")
            ->assertOk()
            ->assertJsonPath('id', $backup->id)
            ->json();
        $this->get($exportAccess['download_url'])->assertOk();
        $this->get($backupAccess['download_url'])->assertOk();

        $audit = (string) AgentApiAudit::query()->get()->toJson();
        $this->assertStringNotContainsString('synthetic-ready.zip', $audit);
        $this->assertStringNotContainsString('exports/synthetic-ready.zip', $audit);
    }

    public function test_exports_default_deny_non_owners_and_do_not_disclose_other_patients(): void
    {
        $owner = $this->user('export-owner-denial@example.test');
        $manager = $this->user('export-manager-denial@example.test');
        $other = $this->user('export-other-denial@example.test');
        $patient = $this->patient($owner);
        $hidden = $this->patient($other);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $manager->id,
            'access_level' => PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);
        $client = $this->client();

        Passport::actingAs($manager, [AgentApiScopes::EXPORTS_READ, AgentApiScopes::EXPORTS_WRITE], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/exports")->assertForbidden();
        $this->postJson("/api/v1/patients/{$patient->id}/native-backups")->assertForbidden();
        $this->getJson("/api/v1/patients/{$hidden->id}/exports")->assertNotFound();
    }

    private function user(string $email): User
    {
        return User::factory()->create(['email' => $email, 'user_role' => 'user']);
    }

    private function patient(User $owner): PhrPatient
    {
        return PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic Export Patient']);
    }

    private function client(): Client
    {
        return Client::factory()->create(['name' => 'Synthetic Export Agent']);
    }
}
