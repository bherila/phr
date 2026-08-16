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
    public function handle(PhrBlobMigrationService $migration): int
    {
        try {
            $disk = $this->validatedChoice('disk', PhrBlobMigrationService::DISKS);
            $artifact = $this->validatedChoice('artifact', PhrBlobMigrationService::ARTIFACTS);
            $patientId = $this->optionalPatientId();
            $this->validateCompatibleScope($disk, $artifact);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

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

    /** @param list<string> $allowed */
    private function validatedChoice(string $name, array $allowed): ?string
    {
        $value = $this->option($name);
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("--{$name} must be one of: ".implode(', ', $allowed).'.');
        }

        return $value;
    }

    private function optionalPatientId(): ?int
    {
        $value = $this->option('patient');
        if ($value === null) {
            return null;
        }
        if (! ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException('--patient must be a positive integer.');
        }

        return (int) $value;
    }

    private function validateCompatibleScope(?string $disk, ?string $artifact): void
    {
        if ($disk === null || $artifact === null) {
            return;
        }

        $artifactDisk = match ($artifact) {
            'documents' => 'phr_documents',
            'dicom-originals', 'dicom-derived' => 'phr_dicom',
            'exports', 'native-backups' => 'phr_exports',
            default => throw new InvalidArgumentException('Unknown artifact scope.'),
        };
        if ($artifactDisk !== $disk) {
            throw new InvalidArgumentException('--disk and --artifact select incompatible storage areas.');
        }
    }
}
