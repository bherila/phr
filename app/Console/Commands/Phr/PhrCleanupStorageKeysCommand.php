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
    public function handle(PhrBlobCleanupService $cleanup, PhrStorageScopeOptions $options): int
    {
        try {
            ['disk' => $disk, 'artifact' => $artifact, 'patientId' => $patientId] = $options->parse(
                $this->option('disk'),
                $this->option('artifact'),
                $this->option('patient'),
                PhrBlobCleanupService::DISKS,
                PhrBlobCleanupService::ARTIFACT_NAMES,
            );
        } catch (PhrStorageOptionException $exception) {
            // Fixed, code-owned validation text only; see PhrStorageOptionException.
            $this->error($exception->getMessage());

            return self::INVALID;
        } catch (InvalidArgumentException) {
            $this->error('Invalid storage cleanup options.');

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
}
