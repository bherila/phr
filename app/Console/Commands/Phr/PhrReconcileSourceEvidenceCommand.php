<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrPatient;
use App\Support\Storage\PhrSourceReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use InvalidArgumentException;
use Throwable;

#[Signature('phr:storage:reconcile-source-evidence
    {--patient= : Required internal patient id}
    {--source= : Required local source-evidence directory}
    {--extension=* : Optional case-insensitive extensions to include, such as pdf}')]
#[Description('Compare source evidence with verified PHR document blobs by SHA-256')]
final class PhrReconcileSourceEvidenceCommand extends BasePhrCommand
{
    public function handle(PhrSourceReconciliationService $reconciliation): int
    {
        try {
            $patientId = $this->requiredPatientId();
            $source = $this->requiredDirectory();
            $extensions = $this->extensions();
            if (! PhrPatient::query()->whereKey($patientId)->exists()) {
                throw new InvalidArgumentException('--patient must identify an existing patient.');
            }

            $summary = $reconciliation->run(
                $patientId,
                $source,
                $extensions,
                function (array $outcome): void {
                    $this->line(sprintf(
                        'reference=%s#%d status=%s',
                        $outcome['table'],
                        $outcome['id'],
                        $outcome['status'],
                    ));
                },
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        } catch (Throwable) {
            $this->error('Source evidence reconciliation failed; no source paths or object keys were logged.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'PHR source reconciliation: source_files=%d source_bytes=%d source_matched=%d source_unmatched=%d documents=%d document_bytes=%d documents_matched=%d documents_unmatched=%d document_failures=%d.',
            $summary->sourceFiles,
            $summary->sourceBytes,
            $summary->sourceMatched,
            $summary->sourceUnmatched,
            $summary->documents,
            $summary->documentBytes,
            $summary->documentsMatched,
            $summary->documentsUnmatched,
            $summary->documentFailures,
        ));

        return $summary->clean() ? self::SUCCESS : self::FAILURE;
    }

    private function requiredPatientId(): int
    {
        $value = $this->option('patient');
        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException('--patient must be a positive integer.');
        }

        return (int) $value;
    }

    private function requiredDirectory(): string
    {
        $value = $this->option('source');
        if (! is_string($value) || trim($value) === '' || ! is_dir($value) || ! is_readable($value)) {
            throw new InvalidArgumentException('--source must be a readable directory.');
        }

        return $value;
    }

    /** @return list<string> */
    private function extensions(): array
    {
        $extensions = [];
        foreach ($this->option('extension') as $extension) {
            if (! is_string($extension) || preg_match('/^\.?[a-z0-9]+$/i', $extension) !== 1) {
                throw new InvalidArgumentException('--extension values may contain only letters and digits.');
            }
            $extensions[] = $extension;
        }

        return $extensions;
    }
}
