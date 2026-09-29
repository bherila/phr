<?php

namespace App\Console\Commands\Phr;

use App\Support\Storage\PhrBlobMigrationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use InvalidArgumentException;

#[Signature('phr:storage:migrate-keys
    {--apply : Copy verified objects and compare-and-swap database references}
    {--disk= : Limit to phr_documents, phr_dicom, or phr_exports}
    {--artifact= : Limit to documents, dicom-originals, dicom-derived, exports, or native-backups}
    {--patient= : Limit to one internal patient id}')]
#[Description('Plan or apply the non-destructive migration of legacy PHR blob keys')]
final class PhrMigrateStorageKeysCommand extends BasePhrCommand
{
    public function handle(PhrBlobMigrationService $migration, PhrStorageScopeOptions $options): int
    {
        try {
            ['disk' => $disk, 'artifact' => $artifact, 'patientId' => $patientId] = $options->parse(
                $this->option('disk'),
                $this->option('artifact'),
                $this->option('patient'),
                PhrBlobMigrationService::DISKS,
                PhrBlobMigrationService::ARTIFACTS,
            );
        } catch (PhrStorageOptionException $exception) {
            // Fixed, code-owned validation text only; see PhrStorageOptionException.
            $this->error($exception->getMessage());

            return self::INVALID;
        } catch (InvalidArgumentException) {
            $this->error('Invalid storage migration options.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        if (! $apply) {
            $this->warn('DRY RUN — no objects or database references will be changed. Pass --apply to migrate.');
        }

        $summary = $migration->run(
            $apply,
            $disk,
            $artifact,
            $patientId,
            function (array $outcome): void {
                $this->line(sprintf(
                    'artifact=%s reference=%s#%d status=%s',
                    $outcome['artifact'],
                    $outcome['table'],
                    $outcome['id'],
                    $outcome['status'],
                ));
            },
        );

        $this->info(sprintf(
            'PHR blob migration %s: examined=%d planned=%d migrated=%d canonical=%d skipped=%d failed=%d bytes=%d.',
            $apply ? 'applied' : 'planned',
            $summary->examined,
            $summary->planned,
            $summary->migrated,
            $summary->alreadyCanonical,
            $summary->skipped,
            $summary->failed,
            $summary->bytes,
        ));

        return $summary->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
