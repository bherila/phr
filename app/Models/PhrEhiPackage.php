<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One preserved EHI Tables package. Preservation, accounting, binding, and
 * clinical import are independent states.
 *
 * @property int $id
 * @property int $patient_id
 * @property int|null $source_id
 * @property int|null $created_by_user_id
 * @property int|null $bound_by_user_id
 * @property string $manifest_digest
 * @property string $intake_kind
 * @property string|null $original_archive_sha256
 * @property int|null $original_archive_bytes
 * @property int $member_count
 * @property int $total_bytes
 * @property int $intake_count
 * @property Carbon|null $last_intake_at
 * @property string|null $detected_source_patient_key
 * @property string|null $dictionary_fingerprint
 * @property string|null $dictionary_version
 * @property string|null $source_version
 * @property string $preservation_status
 * @property string $accounting_status
 * @property string $clinical_status
 * @property string $binding_status
 * @property Carbon|null $bound_at
 * @property string|null $failure_category
 * @property string|null $lease_token
 * @property Carbon|null $lease_expires_at
 * @property array<string, mixed>|null $checkpoint_json
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PhrPatient $patient
 * @property-read PhrEhiSource|null $source
 */
class PhrEhiPackage extends Model
{
    use SerializesDatesAsLocal;

    public const string INTAKE_DIRECTORY = 'directory';

    public const string INTAKE_ZIP = 'zip';

    public const string PRESERVATION_PENDING = 'pending';

    public const string PRESERVATION_PRESERVING = 'preserving';

    public const string PRESERVATION_PRESERVED = 'preserved';

    public const string PRESERVATION_FAILED = 'failed';

    public const string ACCOUNTING_PENDING = 'pending';

    public const string ACCOUNTING_ACCOUNTING = 'accounting';

    public const string ACCOUNTING_ACCOUNTED = 'accounted';

    public const string ACCOUNTING_FAILED = 'failed';

    public const string CLINICAL_NOT_STARTED = 'not_started';

    /** Mapped tables are being projected into entities, revisions, and occurrences. */
    public const string CLINICAL_PROJECTING = 'projecting';

    /** Evidence is projected; proposals may be previewed and reviewed. */
    public const string CLINICAL_PROJECTED = 'projected';

    /** Projection stopped; failure_category says why and a retry resumes it. */
    public const string CLINICAL_FAILED = 'failed';

    public const string BINDING_UNBOUND = 'unbound';

    public const string BINDING_BOUND = 'bound';

    public const string BINDING_REFUSED = 'refused';

    protected $table = 'phr_ehi_packages';

    protected $fillable = [
        'patient_id',
        'source_id',
        'created_by_user_id',
        'bound_by_user_id',
        'manifest_digest',
        'intake_kind',
        'original_archive_sha256',
        'original_archive_bytes',
        'member_count',
        'total_bytes',
        'intake_count',
        'last_intake_at',
        'detected_source_patient_key',
        'dictionary_fingerprint',
        'dictionary_version',
        'source_version',
        'preservation_status',
        'accounting_status',
        'clinical_status',
        'binding_status',
        'bound_at',
        'failure_category',
        'lease_token',
        'lease_expires_at',
        'checkpoint_json',
    ];

    protected function casts(): array
    {
        return [
            'original_archive_bytes' => 'integer',
            'member_count' => 'integer',
            'total_bytes' => 'integer',
            'intake_count' => 'integer',
            'last_intake_at' => 'datetime',
            'bound_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'checkpoint_json' => 'array',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<PhrEhiSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(PhrEhiSource::class, 'source_id');
    }

    /** @return HasMany<PhrEhiPackageMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(PhrEhiPackageMember::class, 'package_id');
    }

    /** @return HasMany<PhrEhiTableAccount, $this> */
    public function tableAccounts(): HasMany
    {
        return $this->hasMany(PhrEhiTableAccount::class, 'package_id');
    }

    /** @return HasMany<PhrEhiFinding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(PhrEhiFinding::class, 'package_id');
    }
}
