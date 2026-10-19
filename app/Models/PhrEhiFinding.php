<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A structural finding with a locator. Never stores source cell values.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $package_id
 * @property int|null $member_id
 * @property string $code
 * @property string $severity
 * @property int|null $line_number
 * @property int|null $byte_offset
 * @property string|null $column_name
 * @property array<string, int|string|bool|null>|null $detail_json
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrEhiFinding extends Model
{
    use SerializesDatesAsLocal;

    public const string SEVERITY_INFO = 'info';

    public const string SEVERITY_WARNING = 'warning';

    public const string SEVERITY_ERROR = 'error';

    protected $table = 'phr_ehi_findings';

    protected $fillable = [
        'patient_id',
        'package_id',
        'member_id',
        'code',
        'severity',
        'line_number',
        'byte_offset',
        'column_name',
        'detail_json',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'byte_offset' => 'integer',
            'detail_json' => 'array',
        ];
    }

    /** @return BelongsTo<PhrEhiPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(PhrEhiPackage::class, 'package_id');
    }
}
