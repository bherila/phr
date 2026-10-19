<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where a revision was seen in a package. Line/offset are locators, never identity.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $package_id
 * @property int $member_id
 * @property int $revision_id
 * @property int $line_number
 * @property int $byte_offset
 * @property string $disposition
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrEhiOccurrence extends Model
{
    use SerializesDatesAsLocal;

    public const string DISPOSITION_PROJECTED = 'projected';

    public const string DISPOSITION_CORROBORATING = 'corroborating';

    public const string DISPOSITION_PENDING_REVIEW = 'pending_review';

    public const string DISPOSITION_QUARANTINED = 'quarantined';

    /** Reviewed and kept as evidence only (rejected or retain_evidence); no clinical record. */
    public const string DISPOSITION_RETAINED = 'retained';

    protected $table = 'phr_ehi_occurrences';

    protected $fillable = ['patient_id', 'package_id', 'member_id', 'revision_id', 'line_number', 'byte_offset', 'disposition']; // sensitive-scan-ignore: package member FK, not an insurance member id

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'byte_offset' => 'integer'];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<PhrEhiRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(PhrEhiRevision::class, 'revision_id');
    }

    /** @return BelongsTo<PhrEhiPackageMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(PhrEhiPackageMember::class, 'member_id');
    }
}
