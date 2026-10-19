<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Parse and disposition accounting for one table of one package.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $package_id
 * @property int|null $member_id
 * @property string $table_name
 * @property string $state
 * @property list<string>|null $header_columns_json
 * @property list<string>|null $unknown_columns_json
 * @property list<string>|null $missing_columns_json
 * @property int $source_row_count
 * @property int $malformed_row_count
 * @property array<string, int>|null $disposition_counts_json
 * @property string $parser_version
 * @property string|null $dictionary_fingerprint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrEhiTableAccount extends Model
{
    use SerializesDatesAsLocal;

    /** Declared by the dictionary but not supplied by the package. */
    public const string STATE_MISSING = 'missing';

    /** A zero-byte member. */
    public const string STATE_EMPTY = 'empty';

    /** A header row and no data rows. */
    public const string STATE_HEADER_ONLY = 'header_only';

    public const string STATE_PARSED = 'parsed';

    /** Unusable as a table (e.g. no decodable header); bytes stay preserved. */
    public const string STATE_MALFORMED = 'malformed';

    public const string DISPOSITION_RETAINED_UNMAPPED = 'retained_unmapped';

    public const string DISPOSITION_OUT_OF_SCOPE = 'out_of_scope';

    protected $table = 'phr_ehi_table_accounts';

    protected $fillable = [
        'patient_id',
        'package_id',
        'member_id',
        'table_name',
        'state',
        'header_columns_json',
        'unknown_columns_json',
        'missing_columns_json',
        'source_row_count',
        'malformed_row_count',
        'disposition_counts_json',
        'parser_version',
        'dictionary_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'header_columns_json' => 'array',
            'unknown_columns_json' => 'array',
            'missing_columns_json' => 'array',
            'source_row_count' => 'integer',
            'malformed_row_count' => 'integer',
            'disposition_counts_json' => 'array',
        ];
    }

    /** @return BelongsTo<PhrEhiPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(PhrEhiPackage::class, 'package_id');
    }

    /** @return BelongsTo<PhrEhiPackageMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(PhrEhiPackageMember::class, 'member_id');
    }
}
