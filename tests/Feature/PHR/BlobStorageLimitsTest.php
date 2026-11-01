<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Documents\SignedDocumentUploadService;
use App\Services\PHR\Storage\BlobStorageQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BlobStorageLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_quota_applies_to_a_delegates_signed_upload_before_a_session_is_created(): void
    {
        Cache::flush();
        $owner = User::factory()->create();
        $delegate = User::factory()->create();
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic Patient', 'relationship' => 'self']);
        DB::table('phr_storage_settings')->insert(['id' => 1, 'global_limit_bytes' => null, 'default_user_limit_bytes' => 100]);
        $uploads = app(SignedDocumentUploadService::class);
        $uploads->open($patient, $delegate->id, 'synthetic', $this->declared(80));
        try {
            $uploads->open($patient, $delegate->id, 'synthetic', $this->declared(21));
            $this->fail('The owner quota must include outstanding upload authorizations.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('storage', $exception->errors());
        }
        $this->assertDatabaseCount('phr_document_uploads', 1);
    }

    public function test_only_admins_can_change_limits_and_zero_blocks_new_uploads(): void
    {
        $this->createAdminUser();
        $user = $this->createUser();
        $this->actingAs($user)->putJson('/api/admin/storage', ['global_limit_bytes' => 0, 'default_user_limit_bytes' => null])->assertForbidden();
        $admin = $this->createAdminUser();
        $this->actingAs($admin)->putJson('/api/admin/storage', ['global_limit_bytes' => 0, 'default_user_limit_bytes' => null])->assertOk();
        $patient = PhrPatient::query()->create(['owner_user_id' => $user->id, 'display_name' => 'Synthetic Patient', 'relationship' => 'self']);
        $this->expectException(ValidationException::class);
        app(SignedDocumentUploadService::class)->open($patient, $user->id, 'synthetic', $this->declared(1));
    }

    public function test_global_cap_still_applies_to_a_user_with_an_unlimited_override(): void
    {
        Cache::flush();
        $one = User::factory()->create();
        $two = User::factory()->create();
        $first = PhrPatient::query()->create(['owner_user_id' => $one->id, 'display_name' => 'Synthetic A', 'relationship' => 'self']);
        $second = PhrPatient::query()->create(['owner_user_id' => $two->id, 'display_name' => 'Synthetic B', 'relationship' => 'self']);
        DB::table('phr_storage_settings')->insert(['id' => 1, 'global_limit_bytes' => 100, 'default_user_limit_bytes' => 10]);
        DB::table('phr_user_storage_limits')->insert(['user_id' => $one->id, 'unlimited' => true]);
        app(SignedDocumentUploadService::class)->open($first, $one->id, 'synthetic', $this->declared(90));
        $this->expectException(ValidationException::class);
        app(SignedDocumentUploadService::class)->open($second, $two->id, 'synthetic', $this->declared(11));
    }

    public function test_expiry_releases_authorized_bytes_and_a_zero_user_override_is_not_inheritance(): void
    {
        Cache::flush();
        $owner = User::factory()->create();
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic A', 'relationship' => 'self']);
        DB::table('phr_storage_settings')->insert(['id' => 1, 'global_limit_bytes' => null, 'default_user_limit_bytes' => 100]);
        $uploads = app(SignedDocumentUploadService::class);
        $uploads->open($patient, $owner->id, 'synthetic', $this->declared(100));
        $this->travel(16)->minutes();
        $uploads->open($patient, $owner->id, 'synthetic', $this->declared(100));
        DB::table('phr_user_storage_limits')->insert(['user_id' => $owner->id, 'limit_bytes' => 0]);
        $this->expectException(ValidationException::class);
        $uploads->open($patient, $owner->id, 'synthetic', $this->declared(1));
    }

    public function test_usage_is_cached_metadata_and_includes_retained_deleted_documents_for_the_owner(): void
    {
        Cache::flush();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic A', 'relationship' => 'self']);
        $document = PhrDocument::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'title' => 'Synthetic', 'document_type' => 'other', 'source' => 'manual_upload',
            'storage_disk' => 'phr_documents', 'storage_path' => 'patients/'.$patient->id.'/documents/synthetic.txt', 'byte_size' => 20,
        ]);
        $document->delete();
        $quota = app(BlobStorageQuota::class);
        $this->assertSame(20, $quota->usedBytes($owner->id));
        $this->assertSame(0, $quota->usedBytes($other->id));
        $this->assertSame(20, $quota->usedBytes());
        // The upload path uses a bounded stale estimate, never a filesystem walk.
        $document->forceFill(['byte_size' => 30])->save();
        $this->assertSame(20, $quota->usedBytes());
        $this->travel(61)->seconds();
        $this->assertSame(30, $quota->usedBytes());
    }

    public function test_admin_settings_validate_limits_and_overrides(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createUser();
        $this->actingAs($admin)->putJson('/api/admin/storage', ['global_limit_bytes' => -1, 'default_user_limit_bytes' => null])->assertUnprocessable();
        $this->putJson('/api/admin/storage', ['global_limit_bytes' => null, 'default_user_limit_bytes' => 100])->assertOk();
        $this->putJson('/api/admin/storage/users/'.$user->id, ['limit_bytes' => 20, 'unlimited' => false])->assertOk()->assertJsonPath('effective_limit_bytes', 20);
        $this->putJson('/api/admin/storage/users/'.$user->id, ['limit_bytes' => null, 'unlimited' => false])->assertOk()->assertJsonPath('effective_limit_bytes', 100);
        $this->putJson('/api/admin/storage/users/'.$user->id, ['limit_bytes' => null, 'unlimited' => true])->assertOk()->assertJsonPath('effective_limit_bytes', null);
        $this->actingAs($user)->getJson('/api/admin/storage')->assertForbidden();
        $this->getJson('/api/admin/storage/users')->assertForbidden();
        $this->getJson('/api/admin/storage/users/'.$user->id)->assertForbidden();
        $this->putJson('/api/admin/storage/users/'.$user->id, ['limit_bytes' => null, 'unlimited' => true])->assertForbidden();
    }

    private function declared(int $bytes): array
    {
        return ['kind' => 'other', 'filename' => 'synthetic.txt', 'content_type' => 'text/plain', 'byte_size' => $bytes];
    }
}
