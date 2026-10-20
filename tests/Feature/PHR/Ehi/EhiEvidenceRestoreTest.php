<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrImmunization;
use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\EhiReviewRefused;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use App\Services\PHR\NativeBackup\PhrNativeTableCompatibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EhiProjectionFixtures;
use Tests\TestCase;

/**
 * A reviewed EHI package survives a native backup and production restore with
 * its evidence, links, and decisions; a restored pending proposal is refused
 * until re-previewed, and the re-preview converges on the restored rows.
 */
class EhiEvidenceRestoreTest extends TestCase
{
    use EhiProjectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhiProjection();
        Storage::fake('phr_dicom');
        Storage::fake('phr_exports');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownEhiProjection();
        parent::tearDown();
    }

    public function test_reviewed_evidence_round_trips_and_restored_pending_proposals_need_a_re_preview(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $package = $this->boundPackage($this->itemPackage($this->standardItems()), $patient, $owner);
        $service = app(EhiProposalService::class);
        $service->preview($package, $patient, $owner->id);
        $accepted = $this->proposalFor($package, '100', '1');
        $service->review($accepted, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $accepted->preview_digest);
        $pending = $this->proposalFor($package, '100', '2');
        $counts = $this->evidenceCounts((int) $patient->id);

        $backup = app(PhrNativeBackupService::class)->generate(app(PhrNativeBackupService::class)->createQueuedBackup($patient, $owner->id));
        $archive = tempnam(sys_get_temp_dir(), 'ehi-restore-test-');
        self::assertIsString($archive);
        self::assertTrue(copy(Storage::disk('phr_exports')->path((string) $backup->storage_path), $archive));
        DB::table('phr_patients')->where('id', $patient->id)->delete();

        $restore = app(PhrNativeRestoreService::class);
        $attempt = $restore->preview($restore->createPreview(new UploadedFile($archive, 'synthetic-native.zip', 'application/zip', null, true), $owner->id, false));
        self::assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);
        $restore->apply($attempt->refresh());
        self::assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $attempt->refresh()->status);
        @unlink($archive);

        $restoredId = (int) $attempt->target_patient_root_id;
        self::assertSame([], DB::select('PRAGMA foreign_key_check'));
        self::assertSame($counts, $this->evidenceCounts($restoredId));
        $record = PhrImmunization::query()->where('patient_id', $restoredId)->sole();
        self::assertSame($record->id, (int) DB::table('phr_ehi_record_links')->where('patient_id', $restoredId)->value('immunization_id'));
        self::assertSame($record->id, (int) DB::table('phr_ehi_proposal_decisions')->where('patient_id', $restoredId)->value('linked_immunization_id'));

        $restoredPatient = PhrPatient::query()->findOrFail($restoredId);
        PhrPatientUserAccess::query()->firstOrCreate(['patient_id' => $restoredId, 'user_id' => $owner->id], ['access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $restoredPackage = PhrEhiPackage::query()->where('patient_id', $restoredId)->sole();
        $restoredPending = $this->proposalFor($restoredPackage, '100', '2');
        try {
            $service->review($restoredPending, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $pending->preview_digest);
            self::fail('A restored pending proposal must be re-previewed first.');
        } catch (EhiReviewRefused $refused) {
            // Preview generations are not archived: no restored proposal is part of a completed preview.
            self::assertSame(EhiReviewRefused::PREVIEW_INCOMPLETE, $refused->reason);
        }

        $service->preview($restoredPackage, $restoredPatient, $owner->id);
        self::assertSame($counts, $this->evidenceCounts($restoredId), 're-projection converges on the restored rows');
        $restoredPending->refresh();
        $service->review($restoredPending, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $restoredPending->preview_digest);
        self::assertSame(2, PhrImmunization::query()->where('patient_id', $restoredId)->count());
    }

    public function test_a_proposal_the_patient_already_holds_under_another_identity_blocks_the_restore(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $package = $this->boundPackage($this->itemPackage($this->standardItems()), $patient, $owner);
        app(EhiProposalService::class)->preview($package, $patient, $owner->id);
        $backup = app(PhrNativeBackupService::class)->generate(app(PhrNativeBackupService::class)->createQueuedBackup($patient, $owner->id));
        $archive = tempnam(sys_get_temp_dir(), 'ehi-restore-test-');
        self::assertIsString($archive);
        self::assertTrue(copy(Storage::disk('phr_exports')->path((string) $backup->storage_path), $archive));
        // A later preview re-created the same proposal subject under a fresh id;
        // restoring the archived row would hit (package_id, mapping, subject_key).
        $pending = $this->proposalFor($package, '100', '2');
        $row = (array) DB::table('phr_ehi_proposals')->where('id', $pending->id)->sole();
        DB::table('phr_ehi_proposals')->where('id', $pending->id)->delete();
        DB::table('phr_ehi_proposals')->insert([...$row, 'id' => null]);

        $restore = app(PhrNativeRestoreService::class);
        $attempt = $restore->preview($restore->createPreview(new UploadedFile($archive, 'synthetic-native.zip', 'application/zip', null, true), $owner->id, false));
        @unlink($archive);

        self::assertContains('record_conflict', $attempt->plan_counts_json['blockers']);
        self::assertSame(1, $attempt->plan_counts_json['tables']['phr_ehi_proposals']['block']);
    }

    /** @return array<string, int> */
    private function evidenceCounts(int $patientId): array
    {
        $counts = [];
        foreach (PhrNativeTableCompatibility::LATER_GENERATIONS['ehi_evidence'] as $table) {
            $counts[$table] = DB::table($table)->where('patient_id', $patientId)->count();
        }

        return $counts;
    }

    private function proposalFor(PhrEhiPackage $package, string $item, string $line): PhrEhiProposal
    {
        return PhrEhiProposal::query()->where('package_id', $package->id)
            ->where('subject_key', EhiMappingContext::keyDigest(['ITEM_ID' => $item, 'LINE' => $line]))->sole();
    }
}
