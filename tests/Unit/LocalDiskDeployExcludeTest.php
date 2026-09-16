<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Every local disk root under storage/ must be protected by the shared deploy contract.
 *
 * The deploy runs `rsync -av --delete ... storage ...`. A local disk root that is not
 * excluded is not in the repo, so rsync deletes it on the receiver — silently, with every
 * workflow step still exiting 0. For phr that means patient imaging and uploaded clinical
 * documents that exist nowhere else.
 *
 * The failure only happens on the *next* deploy after a disk is added, by which time the
 * change that caused it is merged and forgotten. So the check lives here, where adding a
 * disk without an exclude fails the build instead.
 */
class LocalDiskDeployExcludeTest extends TestCase
{
    /**
     * Local disk roots under storage/ that the deploy would delete.
     *
     * @return list<string>
     */
    private function unprotectedDiskRoots(bool $ignorePersistentStorage = false): array
    {
        $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));
        $this->assertIsString($workflow, 'Could not read .github/workflows/ci.yml.');

        preg_match_all("/--exclude='([^']+)'/", $workflow, $matches);
        $excludes = $matches[1];
        if (preg_match('/^\s+excludes:\s*\|\R((?:\s{12}\S.*\R?)*)/m', $workflow, $block) === 1) {
            foreach (preg_split('/\R/', trim($block[1])) ?: [] as $line) {
                $excludes[] = trim($line);
            }
        }
        $excluded = array_flip($excludes);
        $keepsRuntimeStorage = str_contains($workflow, 'keep-runtime-storage: true');
        $persistsStorage = ! $ignorePersistentStorage
            && preg_match('/^\s+persistent-paths:\s*\|\R(?:\s{12}\S.*\R?)*\s{12}storage\s*$/m', $workflow) === 1;

        $storageRoot = rtrim(storage_path(), '/').'/';
        $unprotected = [];

        foreach (config('filesystems.disks') as $name => $disk) {
            if (($disk['driver'] ?? null) !== 'local') {
                continue;
            }

            $root = rtrim((string) ($disk['root'] ?? ''), '/');

            if ($root === '' || ! str_starts_with($root.'/', $storageRoot)) {
                continue;
            }

            if ($persistsStorage) {
                continue;
            }

            $relative = substr($root, strlen($storageRoot));

            if ($keepsRuntimeStorage && (
                $relative === 'app'
                || str_starts_with($relative, 'app/')
                || $relative === 'logs'
                || str_starts_with($relative, 'logs/')
                || str_starts_with($relative, 'framework/cache/')
                || str_starts_with($relative, 'framework/sessions/')
                || str_starts_with($relative, 'framework/views/')
            )) {
                continue;
            }

            // storage/app/private and storage/app/public are the transferred tree itself,
            // not directories carved out of it. Only roots nested deeper need an exclude.
            if (in_array($relative, ['app', 'app/private', 'app/public'], true)) {
                continue;
            }

            // rsync matches a bare pattern against any path segment, which is how the
            // existing entries ('phr-dicom' and friends) are written.
            if (! isset($excluded[basename($root)]) && ! isset($excluded[$relative])) {
                $unprotected[] = sprintf('%s (root: %s)', $name, $relative);
            }
        }

        sort($unprotected);

        return $unprotected;
    }

    public function test_every_local_disk_root_under_storage_is_excluded_from_the_deploy(): void
    {
        $unprotected = $this->unprotectedDiskRoots();

        $this->assertSame([], $unprotected, sprintf(
            "These local disks are not protected by the deploy contract:\n  %s\n\n".
            'The next deploy will delete their contents. Add the directory to `excludes` in '.
            '.github/workflows/ci.yml.',
            implode("\n  ", $unprotected),
        ));
    }

    public function test_the_guard_detects_a_disk_that_is_missing_an_exclude(): void
    {
        // A guard that silently matches nothing is worse than no guard. Point a disk at a
        // directory the exclude list does not cover and confirm the same code path that
        // protects the real disks actually reports it.
        config([
            'filesystems.disks.__probe' => [
                'driver' => 'local',
                'root' => storage_path('custom/definitely-not-excluded'),
            ],
        ]);

        $this->assertSame(
            ['__probe (root: custom/definitely-not-excluded)'],
            $this->unprotectedDiskRoots(ignorePersistentStorage: true),
        );
    }

    public function test_disks_that_are_the_transferred_tree_itself_are_not_flagged(): void
    {
        // storage/app/private is rsynced as a whole; only directories carved out of it
        // need their own exclude. Flagging the parent would make the guard unsatisfiable.
        config([
            'filesystems.disks.__parent' => [
                'driver' => 'local',
                'root' => storage_path('app/private'),
            ],
        ]);

        $this->assertSame([], $this->unprotectedDiskRoots());
    }

    public function test_persistent_oauth_keys_are_excluded_and_never_rotated_on_normal_deploys(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));
        $this->assertIsString($workflow);

        $this->assertStringContainsString('/storage/app/private/oauth/', $workflow);
        $this->assertMatchesRegularExpression('/persistent-paths:\s*\|\R\s+storage\R\s+public\/ohif/', $workflow);
        $this->assertStringNotContainsString('keep-runtime-storage: true', $workflow);
        $this->assertStringContainsString('passport-key-directory: storage/app/private/oauth', $workflow);
        $this->assertStringNotContainsString('passport:keys --force ||', $workflow);
    }
}
