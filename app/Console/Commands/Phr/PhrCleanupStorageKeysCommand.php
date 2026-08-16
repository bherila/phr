<?php

namespace App\Console\Commands\Phr;

use App\Support\Storage\PhrBlobCleanupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use InvalidArgumentException;

#[Signature('phr:storage:cleanup-legacy-keys
    {--apply : Delete expired, verified legacy copies and close their ledger rows}
    {--disk= : Limit to phr_documents, phr_dicom, or phr_exports}
    {--artifact= : Limit to documents, dicom-originals, dicom-derived, exports, or native-backups}
    {--patient= : Limit to one internal patient id}')]
#[Description('Plan or apply expiry-gated cleanup of verified legacy PHR blobs')]
final class PhrCleanupStorageKeysCommand extends BasePhrCommand
{
    public function handle(PhrBlobCleanupService $cleanup): int
    {
        try {
            $disk = $this->validatedChoice('disk', PhrBlobCleanupService::DISKS);
            $artifact = $this->validatedChoice('artifact', PhrBlobCleanupService::ARTIFACT_NAMES);
            $patientId = $this->optionalPatientId();
            $this->validateCompatibleScope($disk, $artifact);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        if (! $apply) {
            $this->warn('DRY RUN — no objects or ledger rows will be changed. Pass --apply to clean up.');
        }

        $summary = $cleanup->run(
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
            'PHR legacy cleanup %s: examined=%d retained=%d planned=%d deleted=%d already_deleted=%d failed=%d bytes=%d.',
            $apply ? 'applied' : 'planned',
            $summary->examined,
            $summary->retained,
            $summary->planned,
            $summary->deleted,
            $summary->alreadyDeleted,
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
