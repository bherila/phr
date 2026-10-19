<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrEhiPackage;
use App\Models\PhrPatient;
use App\Services\PHR\Import\Ehi\EhiPackageSummary;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared output for the phr:ehi:* commands. Everything printed is an id, a count,
 * a fixed status or code, or a user-declared source label: never a member path,
 * a table or column name, a cell value, or an unmasked source patient key.
 */
abstract class BaseEhiCommand extends BasePhrCommand
{
    protected function optionalIntOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        return $this->intOptionRequired($name);
    }

    protected function optionalStringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return Collection<int, PhrEhiPackage> */
    protected function packagesFor(PhrPatient $patient, ?int $packageId): Collection
    {
        $query = PhrEhiPackage::query()->where('patient_id', $patient->id)->orderBy('id');
        if ($packageId !== null) {
            $query->whereKey($packageId);
            $packages = $query->get();
            if ($packages->isEmpty()) {
                throw new \InvalidArgumentException('--package does not name an EHI package of this patient.');
            }

            return $packages;
        }

        return $query->get();
    }

    protected function linePackage(PhrEhiPackage $package, EhiPackageSummary $summary): void
    {
        $this->line("package: {$package->id}");
        $this->line("preservation: {$package->preservation_status}");
        $this->line("accounting: {$package->accounting_status}");
        $this->line("clinical: {$package->clinical_status}");
        $this->line("binding: {$package->binding_status}".($package->source_id !== null ? " (source {$package->source_id})" : ''));
        if ($package->failure_category !== null) {
            $this->line("failure: {$package->failure_category}");
        }
        $this->line("intakes: {$package->intake_count}");
        $this->line('source patient key: '.self::maskKey($package->detected_source_patient_key));
        $this->line("members: {$summary->members} (".$this->counts($summary->membersByKind).')');
        $this->line("tables: {$summary->tables} (".$this->counts($summary->tablesByState).')');
        $this->line("rows: source {$summary->sourceRows}, malformed {$summary->malformedRows}, ".$this->counts($summary->dispositions));
        $this->line('findings: '.($summary->findingsByCode === [] ? '(none)' : $this->counts($summary->findingsByCode)));
        if ($summary->unreconciledTables > 0) {
            $this->warn("unreconciled tables: {$summary->unreconciledTables}");
        }
    }

    /**
     * At most the last four characters, and never more than half of the key, so
     * a short key is not printed whole.
     */
    protected static function maskKey(?string $key): string
    {
        if ($key === null || $key === '') {
            return '(none)';
        }
        $length = mb_strlen($key);
        $shown = min(4, intdiv($length, 2));

        $tail = $shown > 0 ? (string) preg_replace('/[^\x20-\x7E]/', '?', mb_substr($key, -$shown)) : '';

        return str_repeat('*', max(4, $length - $shown)).$tail;
    }

    /** @param  array<string, int>  $counts */
    protected function counts(array $counts): string
    {
        return implode(', ', array_map(static fn (string $key, int $count): string => "{$key} {$count}", array_keys($counts), $counts));
    }
}
