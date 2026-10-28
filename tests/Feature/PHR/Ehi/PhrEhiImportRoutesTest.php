<?php

namespace Tests\Feature\PHR\Ehi;

use App\Jobs\PHR\IntakeEhiUploadJob;
use App\Models\PhrEhiBlob;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiUpload;
use App\Models\PhrImmunization;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\DataHub\PhrPatientDeletionCleanupService;
use App\Services\PHR\DataHub\PhrPatientDeletionService;
use App\Services\PHR\Import\Ehi\EhiUploadService;
use App\Services\PHR\Import\Ehi\Mapping\EhiDocumentMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiImmunizationMapping;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

/**
 * The browser EHI import routes (#235, PR 1): chunked upload, queued intake,
 * binding, preview, and per-proposal review, each over the service its
 * `phr:ehi:*` command uses. All synthetic.
 */
class PhrEhiImportRoutesTest extends TestCase
{
    use EhiIntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Storage::fake('phr_exports');
        Queue::fake();
        config(['phr.ehi_mappings' => [EhiImmunizationMapping::class, EhiDocumentMapping::class]]);
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    public function test_upload_intake_bind_preview_and_review_from_the_browser(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $zip = $this->zip(EhiClinicalPackage::standard()->build());

        $upload = $this->uploadZip($owner, $patient, $zip, chunks: 3);
        Queue::assertPushed(IntakeEhiUploadJob::class, fn (IntakeEhiUploadJob $job): bool => $job->uploadId === $upload->id);
        self::assertSame(PhrEhiUpload::STATUS_QUEUED, $upload->refresh()->status);
        $staged = (string) $upload->storage_path;
        self::assertSame(filesize($zip), strlen((string) Storage::disk((string) config('phr.ehi_disk'))->get($staged)));

        $this->runIntake($upload);
        $shown = $this->actingAs($owner)->getJson($this->base($patient)."/uploads/{$upload->id}")->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')->json('upload');
        self::assertSame(PhrEhiUpload::STATUS_COMPLETED, $shown['status']);
        self::assertFalse($shown['replayed']);
        $package = PhrEhiPackage::query()->sole();
        self::assertSame($package->id, $shown['package_id']);
        self::assertNull($upload->refresh()->storage_path, 'the staged archive is forgotten once intake finishes');
        self::assertFalse(Storage::disk((string) config('phr.ehi_disk'))->exists($staged), 'and deleted');

        $status = $this->actingAs($owner)->getJson($this->base($patient)."/packages/{$package->id}")->assertOk()->json('package');
        self::assertSame(PhrEhiPackage::PRESERVATION_PRESERVED, $status['preservation_status']);
        self::assertSame(PhrEhiPackage::ACCOUNTING_ACCOUNTED, $status['accounting_status']);
        self::assertSame(PhrEhiPackage::BINDING_UNBOUND, $status['binding_status']);
        self::assertStringNotContainsString(EhiClinicalPackage::PATIENT_KEY, (string) $status['source_patient_key']);
        self::assertSame('not_started', $status['preview']['state']);

        $this->actingAs($owner)->getJson($this->base($patient)."/packages/{$package->id}/binding")->assertOk()
            ->assertJsonPath('suggestions', []);
        $this->actingAs($owner)->postJson($this->base($patient)."/packages/{$package->id}/binding", ['new_source_label' => 'Synthetic Clinic'])
            ->assertOk()->assertJsonPath('package.binding_status', PhrEhiPackage::BINDING_BOUND)
            ->assertJsonPath('package.source.label', 'Synthetic Clinic');

        $preview = $this->actingAs($owner)->postJson($this->base($patient)."/packages/{$package->id}/preview")->assertOk();
        $preview->assertJsonPath('package.clinical_status', PhrEhiPackage::CLINICAL_PROJECTED)
            ->assertJsonPath('package.preview.state', 'complete')
            ->assertJsonPath('counts.immunization.create', 4)
            ->assertJsonPath('statuses.pending', 9);
        self::assertSame(0, PhrImmunization::query()->count(), 'a preview writes no clinical rows');

        $page = $this->actingAs($owner)->getJson($this->base($patient)."/packages/{$package->id}/proposals?status=pending&limit=4")->assertOk();
        self::assertCount(4, $page->json('proposals'));
        self::assertNotNull($page->json('next_after_id'));
        $rest = $this->actingAs($owner)->getJson($this->base($patient)."/packages/{$package->id}/proposals?status=pending&after_id=".$page->json('next_after_id'))->assertOk();
        self::assertCount(5, $rest->json('proposals'));
        self::assertNull($rest->json('next_after_id'));

        $create = $this->pendingProposal($package, 'immunization', PhrEhiProposal::KIND_CREATE);
        $listed = collect([...$page->json('proposals'), ...$rest->json('proposals')])->firstWhere('id', $create->id);
        self::assertTrue($listed['reviewable']);
        self::assertContains(PhrEhiProposal::DECISION_ACCEPT, $listed['allowed_decisions']);
        $stored = $create->proposed_json;
        unset($stored['_candidate_summary']);
        self::assertNotSame([], $stored);
        self::assertSame($stored, $listed['proposed'], 'the reviewer sees the proposed values');
        self::assertSame($create->preview_digest, $listed['preview_digest']);

        $reviewed = $this->review($owner, $patient, $create, PhrEhiProposal::DECISION_ACCEPT)->assertOk();
        $reviewed->assertJsonPath('proposal.status', PhrEhiProposal::STATUS_ACCEPTED)
            ->assertJsonPath('record.table', 'phr_immunizations');
        self::assertSame(1, PhrImmunization::query()->whereKey($reviewed->json('record.id'))->count(), 'a decision takes effect immediately');
        // The same decision again is idempotent, not a second record.
        $this->review($owner, $patient, $create, PhrEhiProposal::DECISION_ACCEPT)->assertOk();
        self::assertSame(1, PhrImmunization::query()->count());

        $this->actingAs($owner)->getJson($this->base($patient)."/packages/{$package->id}")->assertOk()
            ->assertJsonPath('package.proposals.by_status.accepted', 1)
            ->assertJsonPath('package.proposals.by_status.pending', 8);
    }

    public function test_manage_access_is_required_to_upload_preview_and_review_and_ownership_to_bind(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewedPackage($owner, $patient, bind: false);
        $viewer = $this->grantee($patient, PhrPatientUserAccess::LEVEL_VIEWER);
        $manager = $this->grantee($patient, PhrPatientUserAccess::LEVEL_MANAGER);
        $stranger = $this->createUser();
        $base = $this->base($patient);

        // Viewers read; they do not write.
        $this->actingAs($viewer)->getJson("{$base}/packages")->assertOk()->assertJsonCount(1, 'packages');
        $this->actingAs($viewer)->getJson("{$base}/uploads")->assertOk();
        $this->actingAs($viewer)->postJson("{$base}/uploads", ['file_size_bytes' => 100])->assertForbidden();
        $this->actingAs($viewer)->postJson("{$base}/packages/{$package->id}/preview")->assertForbidden();
        $this->actingAs($viewer)->postJson("{$base}/packages/{$package->id}/binding", ['new_source_label' => 'Viewer'])->assertForbidden();

        // Managers upload and preview, but only the owner binds.
        $this->actingAs($manager)->postJson("{$base}/uploads", ['file_size_bytes' => 100])->assertCreated();
        $this->actingAs($manager)->postJson("{$base}/packages/{$package->id}/binding", ['new_source_label' => 'Manager'])->assertForbidden();
        self::assertSame(PhrEhiPackage::BINDING_UNBOUND, $package->refresh()->binding_status);
        $this->actingAs($owner)->postJson("{$base}/packages/{$package->id}/binding", ['new_source_label' => 'Synthetic Clinic'])->assertOk();
        $this->actingAs($manager)->postJson("{$base}/packages/{$package->id}/preview")->assertOk();

        $proposal = $this->pendingProposal($package, 'immunization', PhrEhiProposal::KIND_CREATE);
        $this->review($viewer, $patient, $proposal, PhrEhiProposal::DECISION_ACCEPT)->assertForbidden();
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $proposal->refresh()->status);

        // Someone without access cannot tell the patient exists.
        $this->actingAs($stranger)->getJson("{$base}/packages")->assertNotFound();
        $this->actingAs($stranger)->getJson("{$base}/packages/{$package->id}/proposals")->assertNotFound();
        $this->review($stranger, $patient, $proposal, PhrEhiProposal::DECISION_ACCEPT)->assertNotFound();

        // An id of another patient's package or upload is not found.
        [$otherOwner, $otherPatient] = $this->ownerAndPatient();
        $this->actingAs($otherOwner)->getJson($this->base($otherPatient)."/packages/{$package->id}")->assertNotFound();
        $this->review($otherOwner, $otherPatient, $proposal, PhrEhiProposal::DECISION_ACCEPT)->assertNotFound();
    }

    public function test_review_rechecks_authorization_when_it_is_recorded(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $manager = $this->grantee($patient, PhrPatientUserAccess::LEVEL_MANAGER);
        $package = $this->previewedPackage($owner, $patient);
        $proposal = $this->pendingProposal($package, 'immunization', PhrEhiProposal::KIND_CREATE);

        // The manager loaded the review screen, then lost manage access.
        $this->actingAs($manager)->getJson($this->base($patient)."/packages/{$package->id}/proposals")->assertOk();
        PhrPatientUserAccess::query()->where('patient_id', $patient->id)->where('user_id', $manager->id)
            ->update(['access_level' => PhrPatientUserAccess::LEVEL_VIEWER]);
        $this->review($manager, $patient, $proposal, PhrEhiProposal::DECISION_ACCEPT)->assertForbidden();

        PhrPatientUserAccess::query()->where('patient_id', $patient->id)->where('user_id', $manager->id)->delete();
        $this->review($manager, $patient, $proposal, PhrEhiProposal::DECISION_ACCEPT)->assertNotFound();

        self::assertSame(PhrEhiProposal::STATUS_PENDING, $proposal->refresh()->status);
        self::assertSame(0, PhrImmunization::query()->count());
    }

    public function test_a_stale_preview_digest_is_refused(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewedPackage($owner, $patient);
        $proposal = $this->pendingProposal($package, 'immunization', PhrEhiProposal::KIND_CREATE);

        $this->review($owner, $patient, $proposal, PhrEhiProposal::DECISION_ACCEPT, digest: str_repeat('0', 64))
            ->assertStatus(409)->assertExactJson(['error' => 'stale_preview']);
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $proposal->refresh()->status);
        self::assertSame(0, PhrImmunization::query()->count());

        // A decision the proposal kind does not allow is refused the same way.
        $this->review($owner, $patient, $proposal, PhrEhiProposal::DECISION_APPROVE_UPDATE)
            ->assertStatus(422)->assertExactJson(['error' => 'decision_not_allowed']);
    }

    public function test_archived_patients_are_refused(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $package = $this->previewedPackage($owner, $patient);
        $proposal = $this->pendingProposal($package, 'immunization', PhrEhiProposal::KIND_CREATE);
        $zip = $this->zip(EhiClinicalPackage::standard()->build());
        $queued = $this->uploadZip($owner, $patient, $zip, chunks: 1);
        $base = $this->base($patient);

        $patient->update(['archived_at' => now()]);

        $this->actingAs($owner)->postJson("{$base}/uploads", ['file_size_bytes' => 100])
            ->assertStatus(409)->assertExactJson(['error' => 'patient_archived']);
        $this->actingAs($owner)->postJson("{$base}/packages/{$package->id}/preview")
            ->assertStatus(409)->assertExactJson(['error' => 'patient_archived']);
        $this->review($owner, $patient, $proposal, PhrEhiProposal::DECISION_ACCEPT)
            ->assertStatus(409)->assertExactJson(['error' => 'patient_archived']);
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $proposal->refresh()->status);

        // An upload queued before the patient was archived is not taken in.
        $this->runIntake($queued);
        self::assertSame(PhrEhiUpload::STATUS_FAILED, $queued->refresh()->status);
        self::assertSame('patient_archived', $queued->failure_reason);
        self::assertNotNull($queued->storage_path, 'kept so intake can be queued again after unarchiving');

        // Reading stays available.
        $this->actingAs($owner)->getJson("{$base}/packages/{$package->id}")->assertOk();
    }

    public function test_a_multi_patient_export_is_refused_and_reported(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $upload = $this->uploadZip($owner, $patient, $this->zip(EhiSyntheticPackage::standard()->withSecondPatient()));
        $staged = (string) $upload->storage_path;

        $this->runIntake($upload);

        $this->actingAs($owner)->getJson($this->base($patient)."/uploads/{$upload->id}")->assertOk()
            ->assertJsonPath('upload.status', PhrEhiUpload::STATUS_REJECTED)
            ->assertJsonPath('upload.failure_reason', 'multiple_patients')
            ->assertJsonPath('upload.package_id', null);
        $this->assertNoEhiRows();
        self::assertFalse(Storage::disk((string) config('phr.ehi_disk'))->exists($staged), 'a refused archive is not kept');
        $this->actingAs($owner)->postJson($this->base($patient)."/uploads/{$upload->id}/intake")
            ->assertStatus(409)->assertExactJson(['error' => 'not_queueable']);
    }

    public function test_an_identical_reupload_creates_no_duplicates(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $zip = $this->zip(EhiClinicalPackage::standard()->build());
        $first = $this->uploadZip($owner, $patient, $zip);
        $this->runIntake($first);
        $snapshot = [PhrEhiPackage::query()->count(), PhrEhiPackageMember::query()->count(), PhrEhiBlob::query()->count(), count($this->ehiStoredObjects($patient))];

        $second = $this->uploadZip($owner, $patient, $zip, chunks: 2);
        $this->runIntake($second);

        self::assertSame(PhrEhiUpload::STATUS_COMPLETED, $second->refresh()->status);
        self::assertSame($first->refresh()->package_id, $second->package_id);
        self::assertTrue($second->result_json['replayed']);
        self::assertSame($snapshot, [PhrEhiPackage::query()->count(), PhrEhiPackageMember::query()->count(), PhrEhiBlob::query()->count(), count($this->ehiStoredObjects($patient))]);
        self::assertSame(2, PhrEhiPackage::query()->sole()->intake_count);
    }

    public function test_chunks_must_continue_the_upload_and_intake_waits_for_every_byte(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $base = $this->base($patient);
        $limit = $this->actingAs($owner)->postJson("{$base}/uploads", ['file_size_bytes' => PHP_INT_MAX])->assertStatus(422);
        $limit->assertExactJson(['error' => 'package_too_large']);

        $id = $this->actingAs($owner)->postJson("{$base}/uploads", ['file_size_bytes' => 10])->assertCreated()->json('upload.id');
        $this->chunk($owner, $patient, $id, 'abcd', 4)->assertStatus(409)->assertExactJson(['error' => 'upload_state_invalid']);
        $this->chunk($owner, $patient, $id, 'abcd', 0)->assertOk()->assertJsonPath('upload.uploaded_bytes', 4);
        $this->chunk($owner, $patient, $id, 'abcd', 0)->assertStatus(409);
        $this->chunk($owner, $patient, $id, 'efghijklmnop', 4)->assertStatus(409);
        $this->actingAs($owner)->postJson("{$base}/uploads/{$id}/intake")->assertStatus(409)->assertExactJson(['error' => 'upload_incomplete']);
        Queue::assertNothingPushed();

        $this->chunk($owner, $patient, $id, 'efghij', 4)->assertOk()->assertJsonPath('upload.uploaded_bytes', 10);
        $this->actingAs($owner)->postJson("{$base}/uploads/{$id}/intake")->assertStatus(202)->assertJsonPath('upload.status', PhrEhiUpload::STATUS_QUEUED);
        $this->chunk($owner, $patient, $id, 'k', 10)->assertStatus(409);

        // Not a ZIP at all: refused, never an empty import.
        $this->runIntake(PhrEhiUpload::query()->findOrFail($id));
        self::assertSame(PhrEhiUpload::STATUS_REJECTED, PhrEhiUpload::query()->findOrFail($id)->status);
        $this->assertNoEhiRows();
    }

    public function test_bytes_of_an_append_that_never_committed_are_dropped_so_the_resend_succeeds(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $id = $this->actingAs($owner)->postJson($this->base($patient).'/uploads', ['file_size_bytes' => 8])->assertCreated()->json('upload.id');
        $this->chunk($owner, $patient, $id, 'abcd', 0)->assertOk();
        $disk = Storage::disk((string) config('phr.ehi_disk'));
        $staged = (string) PhrEhiUpload::query()->findOrFail($id)->storage_path;
        // A second append reached the file, but its row update rolled back.
        file_put_contents($disk->path($staged), 'efgh', FILE_APPEND);

        $this->chunk($owner, $patient, $id, 'efgh', 4)->assertOk()->assertJsonPath('upload.uploaded_bytes', 8);
        self::assertSame('abcdefgh', $disk->get($staged));
    }

    public function test_patient_deletion_removes_a_staged_archive(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $id = $this->actingAs($owner)->postJson($this->base($patient).'/uploads', ['file_size_bytes' => 10])->assertCreated()->json('upload.id');
        $this->chunk($owner, $patient, $id, 'abcd', 0)->assertOk();
        $staged = (string) PhrEhiUpload::query()->findOrFail($id)->storage_path;

        $deletions = app(PhrPatientDeletionService::class);
        $plan = $deletions->preview($patient);
        self::assertContains($staged, array_column($plan->artifacts, 'key'));
        $deletion = $deletions->delete($patient, $owner->id, $plan->digest, true);
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);

        self::assertSame(0, PhrEhiUpload::query()->count());
        self::assertFalse(Storage::disk((string) config('phr.ehi_disk'))->exists($staged));
    }

    public function test_the_purge_deletes_expired_archives_but_keeps_a_live_intake(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $disk = Storage::disk((string) config('phr.ehi_disk'));
        $make = function (string $status, bool $expired, bool $fresh = true) use ($owner, $patient, $disk): PhrEhiUpload {
            $id = $this->actingAs($owner)->postJson($this->base($patient).'/uploads', ['file_size_bytes' => 10])->assertCreated()->json('upload.id');
            $upload = PhrEhiUpload::query()->findOrFail($id);
            $upload->update(['status' => $status, 'expires_at' => $expired ? now()->subMinute() : now()->addDay()]);
            if (! $fresh) {
                PhrEhiUpload::query()->whereKey($id)->update(['updated_at' => now()->subHours(3)]);
            }
            self::assertTrue($disk->exists((string) $upload->storage_path));

            return $upload->refresh();
        };
        $abandoned = $make(PhrEhiUpload::STATUS_UPLOADING, expired: true);
        $live = $make(PhrEhiUpload::STATUS_PROCESSING, expired: true);
        $dead = $make(PhrEhiUpload::STATUS_PROCESSING, expired: true, fresh: false);
        $current = $make(PhrEhiUpload::STATUS_UPLOADING, expired: false);
        $finished = $make(PhrEhiUpload::STATUS_COMPLETED, expired: false);

        $this->artisan('phr:ehi-uploads:purge')->assertSuccessful();

        foreach ([$abandoned, $dead] as $upload) {
            $path = (string) $upload->storage_path;
            $upload->refresh();
            self::assertNull($upload->storage_path);
            self::assertFalse($disk->exists($path));
            self::assertSame(PhrEhiUpload::STATUS_FAILED, $upload->status);
            self::assertSame('upload_expired', $upload->failure_reason);
        }
        self::assertNull($finished->refresh()->storage_path, 'a finished upload whose delete failed is retried');
        self::assertSame(PhrEhiUpload::STATUS_COMPLETED, $finished->status);
        foreach ([$live, $current] as $upload) {
            self::assertTrue($disk->exists((string) $upload->refresh()->storage_path));
        }
    }

    // ---- helpers

    /** @return array{0: User, 1: PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);

        return [$owner, $patient];
    }

    private function grantee(PhrPatient $patient, string $level): User
    {
        $user = $this->createUser();
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $user->id, 'access_level' => $level, 'granted_by_user_id' => $patient->owner_user_id, 'granted_at' => now()]);

        return $user;
    }

    private function base(PhrPatient $patient): string
    {
        return "/api/phr/patients/{$patient->id}/ehi";
    }

    private function zip(EhiSyntheticPackage $package): string
    {
        return $package->writeZip($this->ehiRoot.'/'.bin2hex(random_bytes(4)).'.zip');
    }

    /** Upload a ZIP in `$chunks` pieces and queue its intake. */
    private function uploadZip(User $actor, PhrPatient $patient, string $zip, int $chunks = 1): PhrEhiUpload
    {
        $bytes = (string) file_get_contents($zip);
        $id = $this->actingAs($actor)->postJson($this->base($patient).'/uploads', ['file_size_bytes' => strlen($bytes)])
            ->assertCreated()->assertJsonPath('upload.status', PhrEhiUpload::STATUS_UPLOADING)
            ->assertJsonPath('upload.transport', 'chunks')->json('upload.id');
        $size = (int) ceil(strlen($bytes) / $chunks);
        for ($offset = 0; $offset < strlen($bytes); $offset += $size) {
            $this->chunk($actor, $patient, $id, substr($bytes, $offset, $size), $offset)->assertOk();
        }
        $this->actingAs($actor)->postJson($this->base($patient)."/uploads/{$id}/intake")->assertStatus(202);

        return PhrEhiUpload::query()->findOrFail($id);
    }

    /** @return TestResponse<Response> */
    private function chunk(User $actor, PhrPatient $patient, int $upload, string $bytes, int $offset): TestResponse
    {
        return $this->actingAs($actor)->post($this->base($patient)."/uploads/{$upload}/chunks", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', $bytes),
            'offset' => $offset,
        ], ['Accept' => 'application/json']);
    }

    private function runIntake(PhrEhiUpload $upload): void
    {
        (new IntakeEhiUploadJob($upload->id))->handle(app(EhiUploadService::class));
    }

    private function previewedPackage(User $owner, PhrPatient $patient, bool $bind = true): PhrEhiPackage
    {
        $upload = $this->uploadZip($owner, $patient, $this->zip(EhiClinicalPackage::standard()->build()));
        $this->runIntake($upload);
        $package = PhrEhiPackage::query()->findOrFail($upload->refresh()->package_id);
        if ($bind) {
            $this->actingAs($owner)->postJson($this->base($patient)."/packages/{$package->id}/binding", ['new_source_label' => 'Synthetic Clinic'])->assertOk();
            $this->actingAs($owner)->postJson($this->base($patient)."/packages/{$package->id}/preview")->assertOk();
        }

        return $package->refresh();
    }

    private function pendingProposal(PhrEhiPackage $package, string $mapping, string $kind): PhrEhiProposal
    {
        return PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', $mapping)
            ->where('kind', $kind)->where('status', PhrEhiProposal::STATUS_PENDING)->orderBy('id')->firstOrFail();
    }

    /** @return TestResponse<Response> */
    private function review(User $actor, PhrPatient $patient, PhrEhiProposal $proposal, string $decision, ?string $digest = null): TestResponse
    {
        return $this->actingAs($actor)->postJson($this->base($patient)."/proposals/{$proposal->id}/review", [
            'decision' => $decision,
            'preview_digest' => $digest ?? $proposal->refresh()->preview_digest,
        ]);
    }
}
