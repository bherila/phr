<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One immutable entry in a package's member manifest.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $package_id
 * @property int $blob_id
 * @property string $path
 * @property string $path_sha256
 * @property string $original_path
 * @property int $byte_size
 * @property string $sha256
 * @property string $kind
 * @property string|null $table_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PhrEhiPackage $package
 * @property-read PhrEhiBlob $blob
 */
class PhrEhiPackageMember extends Model
{
    use SerializesDatesAsLocal;

    protected $table = 'phr_ehi_package_members';

    protected $fillable = [
        'patient_id',
        'package_id',
        'blob_id',
        'path',
        'path_sha256',
        'original_path',
        'byte_size',
        'sha256',
        'kind',
        'table_name',
    ];

    protected function casts(): array
    {
        return ['byte_size' => 'integer'];
    }

    /** @return BelongsTo<PhrEhiPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(PhrEhiPackage::class, 'package_id');
    }

    /** @return BelongsTo<PhrEhiBlob, $this> */
    public function blob(): BelongsTo
    {
        return $this->belongsTo(PhrEhiBlob::class, 'blob_id');
    }
}
