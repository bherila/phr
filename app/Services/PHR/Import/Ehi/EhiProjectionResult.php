<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiPackage;

/**
 * Outcome of projecting one package. Every key is a fixed code, so it is safe to print.
 */
final readonly class EhiProjectionResult
{
    public function __construct(
        public PhrEhiPackage $package,
        /** The package was already projected under the current mappings. */
        public bool $replayed,
        /** Mapped tables whose rows were projected. */
        public int $tablesProjected,
        /** Mapped tables refused for a key mismatch. */
        public int $tablesMismatched,
        /** Mapped tables this package does not supply (or supplies without a header). */
        public int $tablesAbsent,
        /** @var array<string, int> disposition => occurrence-level count over mapped tables */
        public array $dispositions,
    ) {}
}
