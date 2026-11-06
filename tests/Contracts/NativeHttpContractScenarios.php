<?php

namespace Tests\Contracts;

use App\Models\PhrDocument;
use App\Models\PhrNativeBackup;
use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\HttpContractFixtures;

/** Wire contracts for fixture-state polls and separate actual native route journeys. */
trait NativeHttpContractScenarios
{
    private function nativeScenarios(HttpContractFixtures $writer): void
    {
        Storage::fake('phr_documents');
        Storage::fake('phr_dicom');
        $realQueue = Queue::getFacadeRoot();
        Queue::fake();
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $this->fill($patient, $owner);
        $actors = [];
        foreach (['viewer', 'manager', 'outsider'] as $role) {
            $actors[$role] = $actor = $this->createUser();
            if ($role !== 'outsider') {
                PhrPatientUserAccess::query()->create([
                    'patient_id' => $patient->id, 'user_id' => $actor->id, 'access_level' => $role,
                    'granted_by_user_id' => $owner->id, 'granted_at' => now(),
                ]);
            }
        }
        $bytes = 'synthetic native contract artifact';
        Storage::disk('phr_documents')->put('contracts/synthetic.bin', $bytes);
        PhrDocument::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'uploaded_by_user_id' => $owner->id,
            'title' => 'Synthetic contract document', 'document_type' => 'other', 'original_filename' => 'synthetic.bin',
            'storage_disk' => 'phr_documents', 'storage_path' => 'contracts/synthetic.bin', 'mime_type' => 'application/octet-stream',
            'byte_size' => strlen($bytes), 'file_hash' => hash('sha256', $bytes), 'source' => 'manual_upload',
        ]);
        $base = '/api/phr/patients/'.$patient->id.'/native-backups';
        $writer->capture('backup-index-empty', $this->actingAs($owner)->getJson($base));
        $response = $this->actingAs($owner)->postJson($base);
        $writer->capture('backup-queued', $response);
        $backup = PhrNativeBackup::query()->findOrFail($this->body($response)->backup->id);
        HttpContractFixtures::require($backup->status === 'pending' && $backup->expires_at === null, 'backup-queued', 'state');
        $backup->update(['status' => 'processing']);
        $writer->capture('backup-processing', $this->actingAs($owner)->postJson($base));
        $backup = app(PhrNativeBackupService::class)->generate($backup);
        $url = URL::temporarySignedRoute('phr.native-backups.download', now()->addMinutes(15), ['backup' => $backup->id]);
        $writer->assertHttp('backup-download-owner', $this->actingAs($owner)->get($url));
        $writer->assertHttp('backup-download-unsigned', $this->actingAs($owner)->get(explode('?', $url)[0]));
        foreach ($actors as $role => $actor) {
            $writer->assertHttp('backup-index-'.$role, $this->actingAs($actor)->getJson($base));
            $writer->assertHttp('backup-create-'.$role, $this->actingAs($actor)->postJson($base));
            $writer->assertHttp('backup-download-'.$role, $this->actingAs($actor)->get($url));
        }
        foreach (['pending', 'processing', 'failed', 'ready'] as $state) {
            PhrNativeBackup::query()->create([
                'patient_id' => $patient->id, 'requested_by_user_id' => $owner->id, 'status' => $state,
                'schema_version' => 1, 'storage_disk' => 'phr_exports',
                'storage_path' => $state === 'ready' ? $backup->storage_path : null,
                'file_size_bytes' => $state === 'ready' ? $backup->file_size_bytes : null,
                'archive_sha256' => $state === 'ready' ? $backup->archive_sha256 : null,
                'counts_json' => $state === 'ready' ? $backup->counts_json : null,
                'failure_category' => $state === 'failed' ? 'queue_failure' : null,
                'generated_at' => $state === 'ready' ? now()->subDays(2) : null,
                'expires_at' => $state === 'ready' ? now()->subDay() : null,
            ]);
        }
        $response = $this->actingAs($owner)->getJson($base);
        $writer->capture('backup-index-states', $response);
        $backups = $this->body($response)->backups;
        HttpContractFixtures::require(count($backups) === 5 && $backups[0]->download_url === null
            && $backups[4]->download_url !== null && $backups[1]->failure_category === 'queue_failure'
            && $backups[2]->counts === null && $backups[4]->counts->phr_medication_fills === 1,
            'backup-index-states', 'metadata');
        $expired = PhrNativeBackup::query()->latest('id')->firstOrFail();
        $expiredUrl = URL::temporarySignedRoute('phr.native-backups.download', now()->addMinutes(15), ['backup' => $expired->id]);
        $writer->assertHttp('backup-download-expired', $this->actingAs($owner)->get($expiredUrl));

        $this->nativePollStates($writer);
        $copy = tempnam(sys_get_temp_dir(), 'phr-contract-native-');
        HttpContractFixtures::require(is_string($copy), 'backup-queued', 'archive');
        HttpContractFixtures::require(copy(Storage::disk('phr_exports')->path((string) $backup->storage_path), $copy), 'backup-queued', 'archive');
        try {
            // Actor ownership is independent of patient access. Each actor may stage their own
            // upload, but this archive targets a patient they do not actually own.
            Queue::swap($realQueue);
            foreach ($actors as $role => $actor) {
                $response = $this->nativeUpload($actor, $copy, true);
                $writer->capture('restore-upload-'.$role, $response);
                $id = $this->body($response)->restore->id;
                $this->nativeChunk($actor, $id, $copy)->assertOk();
                $response = $this->actingAs($actor)->postJson('/api/phr/data-hub/native-restores/'.$id.'/preview');
                $writer->capture('restore-unowned-preview-'.$role, $response);
                $restore = $this->body($response)->restore;
                HttpContractFixtures::require($restore->status === 'preview_ready'
                    && in_array('patient_not_owned', $restore->blockers, true), 'restore-unowned-preview-'.$role, 'ownership');
                $writer->assertHttp('restore-unowned-apply-'.$role, $this->actingAs($actor)->postJson('/api/phr/data-hub/native-restores/'.$id.'/apply', [
                    'confirmation' => 'RESTORE', 'plan_digest' => $restore->plan_digest, 'restore_access_grants' => true,
                ]));
            }
            Queue::fake();
            // A real archive restores into a new root, then a second synchronous pass exercises
            // existing-root skip counts and opt-in grant restoration.
            DB::table('phr_patients')->where('id', $patient->id)->delete();
            $response = $this->nativeUpload($owner, $copy, false);
            $id = $this->body($response)->restore->id;
            foreach ($actors as $role => $actor) {
                $route = '/api/phr/data-hub/native-restores/'.$id;
                $writer->assertHttp('restore-foreign-poll-'.$role, $this->actingAs($actor)->getJson($route));
                $writer->assertHttp('restore-foreign-chunk-'.$role, $this->nativeChunk($actor, $id, $copy));
                $writer->assertHttp('restore-foreign-preview-'.$role, $this->actingAs($actor)->postJson($route.'/preview'));
                $writer->assertHttp('restore-foreign-apply-'.$role, $this->actingAs($actor)->postJson($route.'/apply', [
                    'confirmation' => 'RESTORE', 'plan_digest' => str_repeat('a', 64), 'restore_access_grants' => false,
                ]));
            }
            $writer->assertHttp('restore-bad-offset', $this->nativeChunk($owner, $id, $copy, 1));
            $writer->capture('restore-chunk-queued', $this->nativeChunk($owner, $id, $copy));
            $route = '/api/phr/data-hub/native-restores/'.$id;
            $writer->capture('restore-preview-queued', $this->actingAs($owner)->postJson($route.'/preview'));
            app(PhrNativeRestoreService::class)->preview(PhrNativeRestoreAttempt::query()->findOrFail($id));
            $response = $this->actingAs($owner)->getJson($route);
            $writer->capture('restore-preview-poll', $response);
            $restore = $this->body($response)->restore;
            HttpContractFixtures::require($restore->status === 'preview_ready' && $restore->target === 'new_patient'
                && $restore->tables->phr_patients->create === 1 && $restore->tables->phr_medication_fills->create === 1
                && $restore->artifacts->create === 1 && $restore->access_grant_count === 2
                && ! $restore->restore_access_grants && $restore->blockers === [], 'restore-preview-poll', 'counts');
            $apply = ['confirmation' => 'RESTORE', 'plan_digest' => $restore->plan_digest, 'restore_access_grants' => false];
            $writer->assertHttp('restore-stale-digest', $this->actingAs($owner)->postJson($route.'/apply', [...$apply, 'plan_digest' => str_repeat('0', 64)]));
            $writer->assertHttp('restore-changed-grants', $this->actingAs($owner)->postJson($route.'/apply', [...$apply, 'restore_access_grants' => true]));
            $writer->capture('restore-apply-queued', $this->actingAs($owner)->postJson($route.'/apply', $apply));
            app(PhrNativeRestoreService::class)->apply(PhrNativeRestoreAttempt::query()->findOrFail($id));
            $response = $this->actingAs($owner)->getJson($route);
            $writer->capture('restore-completed-poll', $response);
            HttpContractFixtures::require($this->body($response)->restore->status === 'completed', 'restore-completed-poll', 'state');
            Queue::swap($realQueue);
            $response = $this->nativeUpload($owner, $copy, true);
            $id = $this->body($response)->restore->id;
            $route = '/api/phr/data-hub/native-restores/'.$id;
            $writer->capture('restore-chunk-sync', $this->nativeChunk($owner, $id, $copy));
            $response = $this->actingAs($owner)->postJson($route.'/preview');
            $writer->capture('restore-preview-sync', $response);
            $restore = $this->body($response)->restore;
            HttpContractFixtures::require($restore->status === 'preview_ready' && $restore->target === 'existing_patient'
                && $restore->tables->phr_medication_fills->skip === 1 && $restore->artifacts->skip === 1
                && $restore->tables->phr_patient_user_access->create === 2 && $restore->restore_access_grants,
                'restore-preview-sync', 'counts');
            $response = $this->actingAs($owner)->postJson($route.'/apply', [
                'confirmation' => 'RESTORE', 'plan_digest' => $restore->plan_digest, 'restore_access_grants' => true,
            ]);
            $writer->capture('restore-apply-sync', $response);
            HttpContractFixtures::require($this->body($response)->restore->status === 'completed', 'restore-apply-sync', 'state');
            $writer->capture('restore-sync-completed-poll', $this->actingAs($owner)->getJson($route));
            $syncPatient = $this->patient($owner);
            $response = $this->actingAs($owner)->postJson('/api/phr/patients/'.$syncPatient->id.'/native-backups');
            $writer->capture('backup-sync', $response);
            HttpContractFixtures::require($this->body($response)->backup->status === 'ready', 'backup-sync', 'state');
        } finally {
            @unlink($copy);
            Queue::swap($realQueue);
        }
    }

    private function nativePollStates(HttpContractFixtures $writer): void
    {
        $actor = $this->createUser();
        // Independent scenario constants intentionally include all nine wire states. These are
        // persisted fixture states, not a claim that a worker was observed between transitions.
        foreach (['uploading', 'preview_pending', 'preview_processing', 'preview_ready', 'pending_restore', 'restore_processing', 'restore_finalizing', 'restore_failed', 'completed'] as $index => $state) {
            $populated = $index >= 3;
            $attempt = PhrNativeRestoreAttempt::query()->create([
                'actor_user_id' => $actor->id, 'source_storage_disk' => 'phr_exports', 'source_storage_path' => null,
                'source_file_size_bytes' => 4, 'uploaded_bytes' => $populated ? 4 : 0,
                'schema_version' => $populated ? 1 : null,
                'patient_native_id' => $populated ? '00000000-0000-4000-8000-000000000001' : null,
                'target_patient_root_id' => null, 'plan_digest' => $populated ? str_repeat('b', 64) : null,
                'plan_counts_json' => $populated ? [
                    'tables' => ['phr_patients' => ['create' => 1, 'skip' => 2, 'block' => 3]],
                    'artifacts' => ['create' => 1, 'skip' => 2, 'block' => 3, 'bytes' => 4],
                    'blockers' => ['record_conflict'],
                ] : null,
                'access_grant_count' => $populated ? 2 : 0, 'restore_access_grants' => $index % 2 === 0,
                'status' => $state, 'failure_category' => $state === 'restore_failed' ? 'preview_changed' : null,
                'expires_at' => now()->addDay(), 'completed_at' => in_array($state, ['completed', 'restore_failed'], true) ? now() : null,
            ]);
            $response = $this->actingAs($actor)->getJson('/api/phr/data-hub/native-restores/'.$attempt->id);
            $id = 'restore-poll-'.str_replace('_', '-', $state);
            $writer->capture($id, $response);
            $body = $this->body($response)->restore;
            HttpContractFixtures::require($body->status === $state && $body->restore_access_grants === ($index % 2 === 0), $id, 'state');
            if ($populated) {
                HttpContractFixtures::require($body->tables->phr_patients->create === 1 && $body->tables->phr_patients->skip === 2
                    && $body->tables->phr_patients->block === 3 && $body->artifacts->bytes === 4
                    && $body->blockers === ['record_conflict'], $id, 'counts');
            }
        }
        $response = $this->actingAs($actor)->getJson('/api/phr/data-hub/native-restores');
        $writer->capture('restore-index-all-states', $response);
        HttpContractFixtures::require(count($this->body($response)->restores) === 9, 'restore-index-all-states', 'state');
    }

    /** @return TestResponse<Response> */
    private function nativeUpload(User $actor, string $archive, bool $grants): TestResponse
    {
        return $this->actingAs($actor)->postJson('/api/phr/data-hub/native-restores/uploads', [
            'source_file_size_bytes' => filesize($archive), 'restore_access_grants' => $grants,
        ]);
    }

    /** @return TestResponse<Response> */
    private function nativeChunk(User $actor, int $id, string $archive, int $offset = 0): TestResponse
    {
        return $this->actingAs($actor)->post('/api/phr/data-hub/native-restores/'.$id.'/chunks', [
            'chunk' => new UploadedFile($archive, 'synthetic-native.zip', 'application/zip', null, true), 'offset' => $offset,
        ], ['Accept' => 'application/json']);
    }
}
