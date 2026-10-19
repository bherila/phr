<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiFinding;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiTableAccount;

/**
 * Counts describing one package, read back from its persisted rows. Every key is
 * a fixed code (member kind, table state, disposition, finding code), so a summary
 * is always safe to print.
 */
final readonly class EhiPackageSummary
{
    public const array TABLE_STATES = [
        PhrEhiTableAccount::STATE_PARSED,
        PhrEhiTableAccount::STATE_HEADER_ONLY,
        PhrEhiTableAccount::STATE_EMPTY,
        PhrEhiTableAccount::STATE_MALFORMED,
        PhrEhiTableAccount::STATE_MISSING,
    ];

    public function __construct(
        public int $members,
        /** @var array<string, int> every EhiMemberKind, in EhiMemberKind::ALL order */
        public array $membersByKind,
        public int $tables,
        /** @var array<string, int> every table state */
        public array $tablesByState,
        public int $sourceRows,
        public int $malformedRows,
        /** @var array<string, int> both dispositions */
        public array $dispositions,
        /** @var array<string, int> finding codes present, sorted */
        public array $findingsByCode,
        /** Tables whose source_row_count != malformed + sum(dispositions). */
        public int $unreconciledTables,
    ) {}

    public static function forPackage(PhrEhiPackage $package): self
    {
        $byKind = array_fill_keys(EhiMemberKind::ALL, 0);
        $kinds = PhrEhiPackageMember::query()->where('package_id', $package->id)
            ->selectRaw('kind, count(*) as aggregate')->groupBy('kind')->pluck('aggregate', 'kind');
        foreach ($kinds as $kind => $count) {
            $byKind[(string) $kind] = (int) $count;
        }

        $states = array_fill_keys(self::TABLE_STATES, 0);
        $dispositions = [PhrEhiTableAccount::DISPOSITION_RETAINED_UNMAPPED => 0, PhrEhiTableAccount::DISPOSITION_OUT_OF_SCOPE => 0];
        $sourceRows = 0;
        $malformedRows = 0;
        $unreconciled = 0;
        $tables = 0;
        PhrEhiTableAccount::query()->where('package_id', $package->id)->orderBy('id')
            ->each(function (PhrEhiTableAccount $account) use (&$states, &$dispositions, &$sourceRows, &$malformedRows, &$unreconciled, &$tables): void {
                $tables++;
                $states[$account->state] = ($states[$account->state] ?? 0) + 1;
                $sourceRows += $account->source_row_count;
                $malformedRows += $account->malformed_row_count;
                $disposed = 0;
                foreach ($account->disposition_counts_json ?? [] as $disposition => $count) {
                    $dispositions[$disposition] = ($dispositions[$disposition] ?? 0) + (int) $count;
                    $disposed += (int) $count;
                }
                if ($account->source_row_count !== $account->malformed_row_count + $disposed) {
                    $unreconciled++;
                }
            });

        $findings = [];
        $codes = PhrEhiFinding::query()->where('package_id', $package->id)
            ->selectRaw('code, count(*) as aggregate')->groupBy('code')->pluck('aggregate', 'code');
        foreach ($codes as $code => $count) {
            $findings[(string) $code] = (int) $count;
        }
        ksort($findings, SORT_STRING);

        return new self(
            members: array_sum($byKind),
            membersByKind: $byKind,
            tables: $tables,
            tablesByState: $states,
            sourceRows: $sourceRows,
            malformedRows: $malformedRows,
            dispositions: $dispositions,
            findingsByCode: $findings,
            unreconciledTables: $unreconciled,
        );
    }
}
