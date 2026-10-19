<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiBlob;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiTableAccount;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\Import\Ehi\EhiBlobVerifier;
use App\Services\PHR\Import\Ehi\EhiDirectorySource;
use App\Services\PHR\Import\Ehi\EhiEntry;
use App\Services\PHR\Import\Ehi\EhiIntakeFailed;
use App\Services\PHR\Import\Ehi\EhiIntakeService;
use App\Support\Storage\PhrStorageKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

class EhiIntakeRecoveryTest extends TestCase
{
    use EhiIntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    public function test_crash_mid_preservation_resumes_and_then_replays(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $synthetic = EhiSyntheticPackage::standard();
        $dir = $this->ehiDirectory($synthetic);

        $failing = $this->ehiFailingDisk(3);
        try {
            $this->ehiIntake(new EhiDirectorySource($dir), $patient, $owner->id);
            self::fail('The simulated storage failure should surface.');
        } catch (RuntimeException $failure) {
            self::assertSame('Simulated storage failure.', $failure->getMessage());
        }

        $package = PhrEhiPackage::query()->sole();
        self::assertSame(PhrEhiPackage::PRESERVATION_FAILED, $package->preservation_status);
        self::assertSame(PhrEhiPackage::ACCOUNTING_PENDING, $package->accounting_status);
        self::assertSame(EhiIntakeService::FAILURE_UNEXPECTED, $package->failure_category);
        self::assertNull($package->lease_token, 'a failed intake releases its lease');
        self::assertSame(2, PhrEhiPackageMember::query()->count());
        self::assertSame(2, PhrEhiBlob::query()->count());
        self::assertCount(2, $this->ehiStoredObjects($patient), 'the object written by the failed step was compensated');
        self::assertSame(0, PhrEhiTableAccount::query()->count());

        $this->ehiRestoreDisk($failing);
        $resumed = $this->ehiIntake(new EhiDirectorySource($dir), $patient, $owner->id);
        self::assertTrue($resumed->resumed);
        self::assertFalse($resumed->replayed);
        self::assertSame($package->id, $resumed->package->id);
        self::assertSame(2, $resumed->package->intake_count);
        self::assertSame(PhrEhiPackage::PRESERVATION_PRESERVED, $resumed->package->preservation_status);
        self::assertSame(PhrEhiPackage::ACCOUNTING_ACCOUNTED, $resumed->package->accounting_status);
        self::assertNull($resumed->package->failure_category);
        self::assertSame(EhiSyntheticPackage::MEMBERS, $resumed->package->checkpoint_json['members_preserved'] ?? null);

        $replayed = $this->ehiIntake(new EhiDirectorySource($dir), $patient, $owner->id);
        self::assertTrue($replayed->replayed);
        self::assertSame($package->id, $replayed->package->id);
        self::assertEquals($resumed->summary, $replayed->summary);

        // Converges on exactly what a clean intake produces.
        $clean = $this->ehiIntakeDirectory($synthetic, $this->ehiPatient($owner), $owner->id, 'clean');
        self::assertEquals($clean->summary, $replayed->summary);
        self::assertSame($clean->package->manifest_digest, $replayed->package->manifest_digest);
        self::assertSame(EhiSyntheticPackage::MEMBERS, PhrEhiPackageMember::query()->where('package_id', $package->id)->count());
        self::assertSame(EhiSyntheticPackage::DISTINCT_BLOBS, PhrEhiBlob::query()->where('patient_id', $patient->id)->count());
        self::assertCount(EhiSyntheticPackage::DISTINCT_BLOBS, $this->ehiStoredObjects($patient));
    }

    public function test_crash_after_preservation_resumes_into_accounting(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $dir = $this->ehiDirectory(EhiSyntheticPackage::standard());
        $this->ehiIntake(new EhiDirectorySource($dir), $patient, $owner->id);

        // As if the worker died between "preserved" and "accounted".
        $package = PhrEhiPackage::query()->sole();
        $package->forceFill(['accounting_status' => PhrEhiPackage::ACCOUNTING_ACCOUNTING, 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->subMinute()])->save();
        PhrEhiTableAccount::query()->delete();
        $before = PhrEhiBlob::query()->count();

        $resumed = $this->ehiIntake(new EhiDirectorySource($dir), $patient, $owner->id);

        self::assertTrue($resumed->resumed);
        self::assertSame(PhrEhiPackage::ACCOUNTING_ACCOUNTED, $resumed->package->accounting_status);
        self::assertSame(10, PhrEhiTableAccount::query()->count());
        self::assertSame($before, PhrEhiBlob::query()->count());
        self::assertNull($resumed->package->lease_token);
    }

    public function test_compensation_never_deletes_pre_existing_blobs(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id, 'first');
        $disk = Storage::disk((string) config('phr.ehi_disk'));

        // A blob row whose object went missing: the next intake re-publishes it.
        $orderProc = PhrEhiBlob::query()->where('sha256', hash('sha256', EhiSyntheticPackage::standard()->files['ORDER_PROC.tsv']))->sole();
        $disk->delete($orderProc->storage_path);

        $changed = EhiSyntheticPackage::standard()->withChangedResults();
        $dir = $this->ehiDirectory($changed, 'second');
        $newKey = PhrStorageKey::ehiBlob($patient->id, hash('sha256', $changed->files['ORDER_RESULTS.tsv']));

        // 1st put re-publishes the referenced ORDER_PROC object, then fails.
        $failing = $this->ehiFailingDisk(1);
        $this->expectFailure(fn () => $this->ehiIntake(new EhiDirectorySource($dir), $patient, $owner->id));
        self::assertTrue($disk->exists($orderProc->storage_path), 'an object a blob row references is never compensated');

        // Next run: the only put is the new ORDER_RESULTS object, which fails after landing.
        $this->ehiRestoreDisk($failing);
        $this->ehiFailingDisk(1);
        $this->expectFailure(fn () => $this->ehiIntake(new EhiDirectorySource($dir), $patient, $owner->id));
        self::assertFalse(Storage::disk((string) config('phr.ehi_disk'))->exists($newKey), 'the unreferenced object this intake wrote is removed');

        $verifier = new EhiBlobVerifier;
        foreach (PhrEhiBlob::query()->get() as $blob) {
            self::assertSame(EhiBlobVerifier::OK, $verifier->inspect($blob->storage_disk, $blob->storage_path, $blob->sha256, $blob->byte_size));
        }
        self::assertSame(EhiSyntheticPackage::DISTINCT_BLOBS, PhrEhiBlob::query()->count());
    }

    public function test_source_mutation_between_passes_fails_the_intake(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $dir = $this->ehiDirectory(EhiSyntheticPackage::standard());

        $source = $this->ehiSecondPassHook(new EhiDirectorySource($dir), function (int $open, EhiEntry $entry) use ($dir): void {
            if ($open === 1) {
                $path = $dir.'/'.$entry->originalPath;
                $bytes = (string) file_get_contents($path);
                $bytes[0] = $bytes[0] === 'X' ? 'Y' : 'X';
                file_put_contents($path, $bytes);
            }
        });

        try {
            $this->ehiIntake($source, $patient, $owner->id);
            self::fail('A changed source must fail the intake.');
        } catch (EhiIntakeFailed $failed) {
            self::assertSame(EhiIntakeFailed::SOURCE_CHANGED, $failed->reason);
            self::assertStringNotContainsString($dir, $failed->getMessage());
        }

        $package = PhrEhiPackage::query()->sole();
        self::assertSame(PhrEhiPackage::PRESERVATION_FAILED, $package->preservation_status);
        self::assertSame(EhiIntakeFailed::SOURCE_CHANGED, $package->failure_category);
        self::assertSame(0, PhrEhiPackageMember::query()->count());
        self::assertSame([], $this->ehiStoredObjects($patient));
    }

    public function test_patient_deleted_mid_intake_stops_without_orphan_objects(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $dir = $this->ehiDirectory(EhiSyntheticPackage::standard());

        $source = $this->ehiSecondPassHook(new EhiDirectorySource($dir), function (int $open) use ($patient): void {
            if ($open === 3) {
                // What a completed patient deletion leaves behind: no rows, no objects.
                Storage::disk((string) config('phr.ehi_disk'))->deleteDirectory("patients/{$patient->id}/ehi");
                PhrPatient::query()->whereKey($patient->id)->delete();
            }
        });

        try {
            $this->ehiIntake($source, $patient, $owner->id);
            self::fail('The intake must stop once the patient is gone.');
        } catch (ModelNotFoundException $missing) {
            self::assertSame(PhrPatient::class, $missing->getModel());
        }

        $this->assertNoEhiRows();
        self::assertSame([], $this->ehiStoredObjects($patient));
    }

    public function test_write_access_revoked_mid_intake_is_refused(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $grant = PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $manager->id,
            'access_level' => PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);
        $dir = $this->ehiDirectory(EhiSyntheticPackage::standard());

        $source = $this->ehiSecondPassHook(new EhiDirectorySource($dir), function (int $open) use ($grant): void {
            if ($open === 2) {
                $grant->delete();
            }
        });

        $this->expectFailure(fn () => $this->ehiIntake($source, $patient->fresh() ?? $patient, $manager->id), AuthorizationException::class);

        $package = PhrEhiPackage::query()->sole();
        self::assertSame(EhiIntakeService::FAILURE_ACCESS_REVOKED, $package->failure_category);
        self::assertSame(PhrEhiPackage::PRESERVATION_FAILED, $package->preservation_status);
        self::assertNull($package->lease_expires_at);
        self::assertSame(1, PhrEhiPackageMember::query()->count());
        self::assertCount(1, $this->ehiStoredObjects($patient));
    }

    public function test_lease_is_held_during_intake_and_cleared_after(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $dir = $this->ehiDirectory(EhiSyntheticPackage::standard());
        $observed = [];

        $source = $this->ehiSecondPassHook(new EhiDirectorySource($dir), function () use (&$observed): void {
            $package = PhrEhiPackage::query()->sole();
            $observed[] = [$package->lease_token, $package->lease_expires_at?->isFuture(), $package->preservation_status];
        });
        $result = $this->ehiIntake($source, $patient, $owner->id);

        self::assertNotEmpty($observed);
        foreach ($observed as [$token, $future, $status]) {
            self::assertNotNull($token);
            self::assertTrue($future, 'a live lease blocks patient deletion while bytes are being published');
            self::assertSame(PhrEhiPackage::PRESERVATION_PRESERVING, $status);
        }
        self::assertCount(1, array_unique(array_column($observed, 0)));
        self::assertNull($result->package->lease_token);
        self::assertNull($result->package->lease_expires_at);
    }

    public function test_an_intake_that_lost_its_lease_stops_without_touching_the_package(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $dir = $this->ehiDirectory(EhiSyntheticPackage::standard());
        $newer = (string) Str::uuid();

        $source = $this->ehiSecondPassHook(new EhiDirectorySource($dir), function (int $open) use ($newer): void {
            if ($open === 2) {
                PhrEhiPackage::query()->update(['lease_token' => $newer]);
            }
        });

        try {
            $this->ehiIntake($source, $patient, $owner->id);
            self::fail('A lost lease must stop the intake.');
        } catch (EhiIntakeFailed $failed) {
            self::assertSame(EhiIntakeFailed::LEASE_LOST, $failed->reason);
        }

        $package = PhrEhiPackage::query()->sole();
        self::assertSame($newer, $package->lease_token, 'the newer intake keeps its lease');
        self::assertSame(PhrEhiPackage::PRESERVATION_PRESERVING, $package->preservation_status);
        self::assertNull($package->failure_category);
    }

    /** @param class-string<\Throwable> $expected */
    private function expectFailure(callable $intake, string $expected = RuntimeException::class): void
    {
        try {
            $intake();
            self::fail("Expected {$expected}.");
        } catch (\Throwable $failure) {
            self::assertInstanceOf($expected, $failure);
        }
    }
}
