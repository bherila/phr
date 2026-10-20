<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrNativeRecordIdentity;
use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Identity\PhrPublicIds;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhrEobDeepLinkTest extends TestCase
{
    /** @return array<string, array{string|null}> */
    public static function readableGrants(): array
    {
        return [
            'actual owner without a grant row' => [null],
            'owner grant' => [PhrPatientUserAccess::LEVEL_OWNER],
            'manager grant' => [PhrPatientUserAccess::LEVEL_MANAGER],
            'viewer grant' => [PhrPatientUserAccess::LEVEL_VIEWER],
        ];
    }

    #[DataProvider('readableGrants')]
    public function test_readable_grants_resolve_to_a_real_patient_scoped_view(?string $level): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $eob = $this->eob($patient, $owner);
        $actor = $owner;
        if ($level !== null) {
            $actor = $this->createUser();
            $this->grant($patient, $actor, $owner, $level);
        }

        $destination = "/phr/patient/{$patient->id}/eobs/{$eob->id}";
        $this->actingAs($actor)->get($this->link($eob))->assertRedirect($destination);
        $this->get($destination)->assertOk()->assertViewIs('phr.eob')
            ->assertSee('Synthetic EOB provider')->assertSee('SYNTHETIC-CLAIM')
            ->assertSee('No source document attached.')->assertDontSee('synthetic private raw text')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_unknown_and_inaccessible_public_ids_return_the_same_404(): void
    {
        config(['app.debug' => false]);
        $owner = $this->createUser();
        $eob = $this->eob($this->patient($owner), $owner);
        $this->actingAs($this->createUser());

        $denied = $this->get($this->link($eob))->assertNotFound();
        $unknown = $this->get('/eobs/'.Str::uuid())->assertNotFound();

        $this->assertSame($unknown->getContent(), $denied->getContent());
        $denied->assertDontSee('Synthetic EOB provider')->assertDontSee('SYNTHETIC-CLAIM');
    }

    public function test_a_guest_is_sent_to_login_with_the_stable_link_as_the_intended_url(): void
    {
        $link = '/eobs/'.Str::uuid();

        $this->get($link)->assertRedirect('/login')->assertSessionHas('url.intended', url($link));
    }

    public function test_revocation_is_rechecked_at_both_the_resolver_and_destination(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $patient = $this->patient($owner);
        $eob = $this->eob($patient, $owner);
        $grant = $this->grant($patient, $viewer, $owner, PhrPatientUserAccess::LEVEL_VIEWER);
        $link = $this->link($eob);
        $destination = "/phr/patient/{$patient->id}/eobs/{$eob->id}";
        $this->actingAs($viewer)->get($link)->assertRedirect($destination);

        $grant->delete();

        $this->get($link)->assertNotFound();
        $this->get($destination)->assertNotFound();
    }

    public function test_the_destination_does_not_accept_an_eob_from_another_patient(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $otherPatient = $this->patient($owner);
        $eob = $this->eob($otherPatient, $owner);

        $this->actingAs($owner)->get("/phr/patient/{$patient->id}/eobs/{$eob->id}")->assertNotFound();
    }

    public function test_source_document_links_stay_within_the_patient_and_exclude_deleted_documents(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $eob = $this->eob($patient, $owner);
        $document = PhrDocument::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id,
            'title' => 'Synthetic EOB source', 'document_type' => 'insurance',
            'original_filename' => 'synthetic-eob.pdf', 'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'fixtures/synthetic-eob.pdf', 'mime_type' => 'application/pdf', 'byte_size' => 0,
            'source' => 'manual_upload',
        ]);
        $eob->update(['source_document_id' => $document->id]);
        $destination = "/phr/patient/{$patient->id}/eobs/{$eob->id}";
        $sourceUrl = route('phr.patients.documents.file', ['patient' => $patient->id, 'document' => $document->id]);

        $this->actingAs($owner)->get($destination)->assertOk()->assertSee('href="'.$sourceUrl.'"', false);

        $document->delete();
        $this->get($destination)->assertOk()->assertDontSee('View source document')->assertSee('No source document attached.');

        $document->restore();
        $document->update(['patient_id' => $this->patient($owner)->id]);
        $this->get($destination)->assertOk()->assertDontSee('View source document')->assertSee('No source document attached.');
    }

    public function test_a_mismatched_identity_or_non_eob_public_id_never_resolves(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $otherPatient = $this->patient($owner);
        $eob = $this->eob($patient, $owner);
        $link = $this->link($eob);
        PhrNativeRecordIdentity::query()->where('record_table', PhrPublicIds::EOBS)
            ->where('record_id', $eob->id)->update(['patient_id' => $otherPatient->id]);

        $this->actingAs($owner)->get($link)->assertNotFound();
        $this->get('/eobs/'.app(PhrPublicIds::class)->assign($patient))->assertNotFound();
    }

    public function test_both_browser_routes_declare_session_authentication_and_are_inventoried(): void
    {
        $inventory = (string) file_get_contents(base_path('docs/patient-authorization-inventory.md'));
        foreach (['phr.eobs.resolve' => 'eobs/{public_id}', 'phr.eobs.show' => 'phr/patient/{patient}/eobs/{eob}'] as $name => $uri) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertSame($uri, $route->uri());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertStringContainsString('GET /'.$uri, $inventory);
        }
    }

    public function test_a_native_restore_preserves_the_link_while_integer_ids_change(): void
    {
        Storage::fake('phr_documents');
        Storage::fake('phr_dicom');
        Storage::fake('phr_exports');
        Queue::fake();
        $owner = $this->createUser();
        $patient = $this->patient($owner);
        $eob = $this->eob($patient, $owner);
        $link = $this->link($eob);
        $this->eob($this->patient($owner), $owner);
        $this->actingAs($owner)->get($link)->assertRedirect("/phr/patient/{$patient->id}/eobs/{$eob->id}");
        $backups = app(PhrNativeBackupService::class);
        $backup = $backups->generate($backups->createQueuedBackup($patient, (int) $owner->id));
        $archivePath = tempnam(sys_get_temp_dir(), 'phr-eob-link-test-');
        $this->assertIsString($archivePath);

        try {
            $this->assertTrue(copy(Storage::disk('phr_exports')->path((string) $backup->storage_path), $archivePath));
            DB::table('phr_patients')->where('id', $patient->id)->delete();
            $restores = app(PhrNativeRestoreService::class);
            $upload = new UploadedFile($archivePath, 'synthetic-native.zip', 'application/zip', null, true);
            $attempt = $restores->preview($restores->createPreview($upload, (int) $owner->id, false));
            $this->assertSame([], $attempt->plan_counts_json['blockers']);
            $attempt->update(['status' => PhrNativeRestoreAttempt::STATUS_PENDING]);
            $this->assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $restores->apply($attempt)->status);

            $restoredEob = PhrEob::query()->where('external_id', $eob->external_id)->sole();
            $this->assertNotSame($patient->id, $restoredEob->patient_id);
            $this->assertNotSame($eob->id, $restoredEob->id);
            $destination = "/phr/patient/{$restoredEob->patient_id}/eobs/{$restoredEob->id}";
            $this->get($link)->assertRedirect($destination);
            $this->get($destination)->assertOk()->assertSee('SYNTHETIC-CLAIM');
        } finally {
            unlink($archivePath);
        }
    }

    private function patient(User $owner): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $owner->id, 'display_name' => 'Synthetic EOB patient', 'relationship' => 'self',
        ]);
    }

    private function eob(PhrPatient $patient, User $owner): PhrEob
    {
        return PhrEob::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id,
            'import_source' => 'synthetic-eob-link', 'external_id' => (string) Str::uuid(),
            'claim_type' => 'medical', 'claim_number' => 'SYNTHETIC-CLAIM',
            'provider_name' => 'Synthetic EOB provider', 'raw_text' => 'synthetic private raw text',
        ]);
    }

    private function link(PhrEob $eob): string
    {
        return '/eobs/'.app(PhrPublicIds::class)->assign($eob);
    }

    private function grant(PhrPatient $patient, User $actor, User $owner, string $level): PhrPatientUserAccess
    {
        return PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $actor->id, 'access_level' => $level,
            'granted_by_user_id' => $owner->id, 'granted_at' => now(),
        ]);
    }
}
