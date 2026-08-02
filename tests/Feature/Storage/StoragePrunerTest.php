<?php

namespace Tests\Feature\Storage;

use App\Models\PhrDicomUpload;
use App\Support\Storage\BlobReferences;
use App\Support\Storage\StoragePruner;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Behaviour of the orphan sweeper. Every test here corresponds to a way this could
 * destroy data that is still wanted.
 */
class StoragePrunerTest extends TestCase
{
    private function disk(): Filesystem
    {
        return Storage::disk('phr_dicom');
    }

    /**
     * Objects are written "now"; the clock then moves forward so they read as old.
     * Anything wanting to look freshly written is put after this call.
     */
    private function ageEverything(): void
    {
        Carbon::setTestNow(Carbon::now()->addDays(2));
    }

    private function pruner(int $minAgeHours = 24, float $maxRatio = 1.0): StoragePruner
    {
        return new StoragePruner(
            $this->disk(),
            BlobReferences::make()
                ->from('phr_dicom_files', 'r2_key')
                ->from('phr_dicom_uploads', 'r2_prefix')->asPrefix(),
            ['phr/dicom'],
            $minAgeHours,
            $maxRatio,
        );
    }

    private int $patientId;

    private int $uploadId;

    /**
     * phr_dicom_files carries foreign keys to a patient and an upload, so the sweeper's
     * fixtures need a real chain rather than bare ids.
     */
    private function seedOwnerPatientAndUpload(): void
    {
        $owner = $this->createUser();

        $this->patientId = (int) $this->actingAs($owner)
            ->postJson('/api/phr/patients', ['display_name' => 'Primary', 'relationship' => 'self'])
            ->assertCreated()
            ->json('patient.id');

        $this->uploadId = (int) PhrDicomUpload::create([
            'patient_id' => $this->patientId,
            'uploaded_by_user_id' => $owner->id,
            'status' => PhrDicomUpload::STATUS_PROCESSED,
            'stored_files' => 0,
            'r2_prefix' => 'phr/dicom/patients/'.$this->patientId.'/uploads/seed',
        ])->id;
    }

    private function referenceKey(string $key): void
    {
        DB::table('phr_dicom_files')->insert([
            'patient_id' => $this->patientId,
            'upload_id' => $this->uploadId,
            'file_kind' => 'dicom',
            'r2_key' => $key,
            'original_relative_path' => basename($key),
            'original_path_hash' => hash('sha256', $key),
            'original_filename' => basename($key),
            'mime_type' => 'application/dicom',
            'file_size_bytes' => 4,
            'sha256' => hash('sha256', $key),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_referenced_objects_are_never_orphaned(): void
    {
        Storage::fake('phr_dicom');
        $this->seedOwnerPatientAndUpload();
        $this->disk()->put('phr/dicom/kept.dcm', 'aaaa');
        $this->referenceKey('phr/dicom/kept.dcm');
        $this->ageEverything();

        $plan = $this->pruner()->plan();

        $this->assertSame(1, $plan->scanned);
        $this->assertSame([], $plan->orphans);
    }

    public function test_unreferenced_objects_are_orphans_once_old_enough(): void
    {
        Storage::fake('phr_dicom');
        $this->disk()->put('phr/dicom/stray.dcm', 'aaaa');
        $this->ageEverything();

        $plan = $this->pruner()->plan();

        $this->assertSame(['phr/dicom/stray.dcm'], $plan->orphans);
    }

    public function test_recently_written_objects_are_left_alone(): void
    {
        Storage::fake('phr_dicom');
        // No clock advance: an upload that landed in storage before its row was written.
        $this->disk()->put('phr/dicom/inflight.dcm', 'aaaa');

        $plan = $this->pruner()->plan();

        $this->assertSame([], $plan->orphans, 'An in-flight upload must not be reaped.');
        $this->assertSame(1, $plan->skippedTooNew);
    }

    public function test_a_prefix_reference_protects_everything_beneath_it(): void
    {
        Storage::fake('phr_dicom');
        $this->seedOwnerPatientAndUpload();
        $this->disk()->put('phr/dicom/uploads/abc/one.dcm', 'aaaa');
        $this->disk()->put('phr/dicom/uploads/abc/two.dcm', 'aaaa');
        DB::table('phr_dicom_uploads')->where('id', $this->uploadId)
            ->update(['r2_prefix' => 'phr/dicom/uploads/abc']);
        $this->ageEverything();

        $plan = $this->pruner()->plan();

        $this->assertSame([], $plan->orphans);
    }

    public function test_quarantine_moves_rather_than_deletes(): void
    {
        Storage::fake('phr_dicom');
        $this->disk()->put('phr/dicom/stray.dcm', 'payload');
        $this->ageEverything();

        $pruner = $this->pruner();
        $result = $pruner->quarantine($pruner->plan(), '2026-08-02-000000');

        $this->assertSame(1, $result['moved']);
        $this->assertFalse($this->disk()->exists('phr/dicom/stray.dcm'));
        $this->assertSame(
            'payload',
            $this->disk()->get(StoragePruner::QUARANTINE_ROOT.'/2026-08-02-000000/phr/dicom/stray.dcm'),
            'The bytes must survive quarantine — that is the entire point.',
        );
    }

    public function test_quarantined_objects_are_not_swept_again(): void
    {
        Storage::fake('phr_dicom');
        $this->disk()->put(StoragePruner::QUARANTINE_ROOT.'/2026-01-01-000000/phr/dicom/old.dcm', 'aaaa');
        $this->disk()->put('phr/dicom/stray.dcm', 'aaaa');
        $this->ageEverything();

        $pruner = new StoragePruner(
            $this->disk(),
            BlobReferences::make()->from('phr_dicom_files', 'r2_key'),
            ['phr/dicom', StoragePruner::QUARANTINE_ROOT],
            24,
            1.0,
        );
        $plan = $pruner->plan();

        $this->assertSame(['phr/dicom/stray.dcm'], $plan->orphans);
        $this->assertSame(1, $plan->scanned, 'Quarantined objects must not inflate the ratio.');
    }

    public function test_an_implausible_orphan_share_trips_the_safety_threshold(): void
    {
        Storage::fake('phr_dicom');
        $this->seedOwnerPatientAndUpload();
        foreach (range(1, 4) as $i) {
            $this->disk()->put("phr/dicom/stray{$i}.dcm", 'aaaa');
        }
        $this->disk()->put('phr/dicom/kept.dcm', 'aaaa');
        $this->referenceKey('phr/dicom/kept.dcm');
        $this->ageEverything();

        $plan = $this->pruner(minAgeHours: 24, maxRatio: 0.25)->plan();

        $this->assertSame(4, $plan->orphanCount());
        $this->assertTrue(
            $plan->exceedsSafetyThreshold(),
            'A missing reference column looks exactly like this and must stop the run.',
        );
    }

    public function test_purge_only_removes_batches_past_the_holding_period(): void
    {
        Storage::fake('phr_dicom');
        $this->disk()->put(StoragePruner::QUARANTINE_ROOT.'/2026-01-01-000000/old.dcm', 'aaaa');
        $this->disk()->put(StoragePruner::QUARANTINE_ROOT.'/'.Carbon::now()->format('Y-m-d-His').'/fresh.dcm', 'aaaa');

        $result = $this->pruner()->purgeQuarantine(holdDays: 30, apply: true);

        $this->assertSame(['2026-01-01-000000'], $result['batches']);
        $this->assertTrue($this->disk()->exists(
            StoragePruner::QUARANTINE_ROOT.'/'.Carbon::now()->format('Y-m-d-His').'/fresh.dcm'
        ));
    }
}
