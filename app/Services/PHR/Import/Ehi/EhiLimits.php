<?php

namespace App\Services\PHR\Import\Ehi;

final readonly class EhiLimits
{
    public function __construct(
        public int $maxPackageBytes,
        public int $maxMemberBytes,
        public int $maxMembers,
        public int $maxExpansionRatio,
        public int $maxPathDepth,
        public int $maxPathBytes,
        public int $maxRowBytes,
        public int $maxValueBytes,
        public int $maxDictionaryBytes,
        public int $maxFindingsPerMember,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxPackageBytes: (int) config('phr.ehi_max_package_bytes'),
            maxMemberBytes: (int) config('phr.ehi_max_member_bytes'),
            maxMembers: (int) config('phr.ehi_max_members'),
            maxExpansionRatio: (int) config('phr.ehi_max_expansion_ratio'),
            maxPathDepth: (int) config('phr.ehi_max_path_depth'),
            maxPathBytes: (int) config('phr.ehi_max_path_bytes'),
            maxRowBytes: (int) config('phr.ehi_max_row_bytes'),
            maxValueBytes: (int) config('phr.ehi_max_value_bytes'),
            maxDictionaryBytes: (int) config('phr.ehi_max_dictionary_bytes'),
            maxFindingsPerMember: (int) config('phr.ehi_max_findings_per_member'),
        );
    }
}
