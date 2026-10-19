<?php

namespace Tests\Support;

use App\Models\PhrEhiBlob;
use App\Models\PhrEhiFinding;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiSource;
use App\Models\PhrEhiTableAccount;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiDirectorySource;
use App\Services\PHR\Import\Ehi\EhiEntry;
use App\Services\PHR\Import\Ehi\EhiIntakeResult;
use App\Services\PHR\Import\Ehi\EhiIntakeService;
use App\Services\PHR\Import\Ehi\EhiLimits;
use App\Services\PHR\Import\Ehi\EhiPackageSource;
use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Shared setup for EHI intake feature tests. Uses a fake EHI disk and a private
 * temp root per test.
 */
trait EhiIntakeFixtures
{
    protected string $ehiRoot;

    protected function setUpEhi(): void
    {
        Storage::fake((string) config('phr.ehi_disk'));
        $this->ehiRoot = sys_get_temp_dir().'/ehi-intake-'.bin2hex(random_bytes(8));
        mkdir($this->ehiRoot, 0700);
    }

    protected function tearDownEhi(): void
    {
        $this->removeEhiTree($this->ehiRoot);
    }

    protected function ehiPatient(User $owner): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Synthetic Patient',
            'relationship' => 'self',
        ]);
    }

    protected function ehiDirectory(EhiSyntheticPackage $package, string $name = 'package'): string
    {
        return $package->writeDirectory($this->ehiRoot.'/'.$name);
    }

    protected function ehiIntake(EhiPackageSource $source, PhrPatient $patient, int $actorUserId): EhiIntakeResult
    {
        try {
            return app(EhiIntakeService::class)->intake($source, $patient, $actorUserId);
        } finally {
            $source->close();
        }
    }

    protected function ehiIntakeDirectory(EhiSyntheticPackage $package, PhrPatient $patient, int $actorUserId, string $name = 'package'): EhiIntakeResult
    {
        return $this->ehiIntake(new EhiDirectorySource($this->ehiDirectory($package, $name)), $patient, $actorUserId);
    }

    /**
     * Wrap a source so a callback runs before each open() once the package row
     * exists, i.e. during the second pass. The callback receives the 1-based count
     * of such opens.
     *
     * @param  Closure(int, EhiEntry): void  $beforeOpen
     */
    protected function ehiSecondPassHook(EhiPackageSource $inner, Closure $beforeOpen): EhiPackageSource
    {
        return new class($inner, $beforeOpen) implements EhiPackageSource
        {
            private int $opens = 0;

            /** @param Closure(int, EhiEntry): void $beforeOpen */
            public function __construct(private readonly EhiPackageSource $inner, private readonly Closure $beforeOpen) {}

            public function kind(): string
            {
                return $this->inner->kind();
            }

            public function entries(?int $maxEntries = null): array
            {
                return $this->inner->entries($maxEntries);
            }

            public function open(EhiEntry $entry)
            {
                if (PhrEhiPackage::query()->exists()) {
                    ($this->beforeOpen)(++$this->opens, $entry);
                }

                return $this->inner->open($entry);
            }

            public function originalArchive(EhiLimits $limits): ?array
            {
                return $this->inner->originalArchive($limits);
            }

            public function close(): void
            {
                $this->inner->close();
            }
        };
    }

    /**
     * Replace the EHI disk with one whose Nth put() fails. With $afterWrite the
     * bytes land first, so the failure happens after an object was published.
     */
    protected function ehiFailingDisk(int $failOnPut, bool $afterWrite = true): FilesystemAdapter
    {
        $name = (string) config('phr.ehi_disk');
        /** @var FilesystemAdapter $fake */
        $fake = Storage::disk($name);
        $disk = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public int $puts = 0;

            public int $failOnPut = 0;

            public bool $afterWrite = true;

            public function put($path, $contents, $options = [])
            {
                $this->puts++;
                if ($this->puts === $this->failOnPut) {
                    if ($this->afterWrite) {
                        parent::put($path, $contents, $options);
                    }

                    throw new RuntimeException('Simulated storage failure.');
                }

                return parent::put($path, $contents, $options);
            }
        };
        $disk->failOnPut = $failOnPut;
        $disk->afterWrite = $afterWrite;
        Storage::set($name, $disk);

        return $disk;
    }

    /** Restore the plain fake disk (same root) after ehiFailingDisk(). */
    protected function ehiRestoreDisk(FilesystemAdapter $failing): void
    {
        Storage::set((string) config('phr.ehi_disk'), new FilesystemAdapter($failing->getDriver(), $failing->getAdapter(), $failing->getConfig()));
    }

    /** @return list<string> */
    protected function ehiStoredObjects(PhrPatient|int $patient): array
    {
        $id = $patient instanceof PhrPatient ? $patient->id : $patient;

        return Storage::disk((string) config('phr.ehi_disk'))->allFiles("patients/{$id}/ehi");
    }

    protected function assertNoEhiRows(): void
    {
        foreach ([PhrEhiPackage::class, PhrEhiBlob::class, PhrEhiPackageMember::class, PhrEhiTableAccount::class, PhrEhiFinding::class, PhrEhiSource::class] as $model) {
            self::assertSame(0, $model::query()->count(), class_basename($model).' rows were persisted');
        }
        self::assertSame([], Storage::disk((string) config('phr.ehi_disk'))->allFiles(), 'objects were stored');
    }

    private function removeEhiTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeEhiTree($path.'/'.$entry);
            }
        }
        rmdir($path);
    }
}
