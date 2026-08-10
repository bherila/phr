<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $owner_user_id
 * @property string|null $display_name
 * @property string|null $relationship
 * @property Carbon|null $birth_date
 * @property string|null $sex_at_birth
 * @property string|null $notes
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PhrPatientUserAccess> $accessGrants
 * @property-read Collection<int, PhrDicomStudy> $dicomStudies
 * @property-read Collection<int, PhrDicomUpload> $dicomUploads
 * @property-read Collection<int, PhrDocument> $documents
 * @property-read Collection<int, PhrExport> $exports
 * @property-read Collection<int, PhrHealthLog> $healthLogs
 * @property-read Collection<int, PhrHealthLogEntry> $healthLogEntries
 * @property-read Collection<int, PhrNegativeAssertion> $negativeAssertions
 * @property-read Collection<int, PhrPortalMessage> $portalMessages
 * @property-read Collection<int, PhrEob> $eobs
 */
class PhrPatient extends Model
{
    use SerializesDatesAsLocal;

    protected $fillable = [
        'owner_user_id',
        'display_name',
        'relationship',
        'birth_date',
        'sex_at_birth',
        'notes',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'owner_user_id' => 'integer',
            'birth_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<PhrPatientUserAccess, $this> */
    public function accessGrants(): HasMany
    {
        return $this->hasMany(PhrPatientUserAccess::class, 'patient_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function sharedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'phr_patient_user_access', 'patient_id', 'user_id')
            ->withPivot(['access_level', 'granted_by_user_id', 'granted_at'])
            ->withTimestamps();
    }

    /** @return HasMany<PhrLabResult, $this> */
    public function labResults(): HasMany
    {
        return $this->hasMany(PhrLabResult::class, 'patient_id');
    }

    /** @return HasMany<PhrPatientVital, $this> */
    public function vitals(): HasMany
    {
        return $this->hasMany(PhrPatientVital::class, 'patient_id');
    }

    /** @return HasMany<PhrRespiratoryEvent, $this> */
    public function respiratoryEvents(): HasMany
    {
        return $this->hasMany(PhrRespiratoryEvent::class, 'phr_patient_id');
    }

    /** @return HasOne<PhrSinusSetting, $this> */
    public function sinusSettings(): HasOne
    {
        return $this->hasOne(PhrSinusSetting::class, 'phr_patient_id');
    }

    /** @return HasMany<PhrSinusEnrollment, $this> */
    public function sinusEnrollments(): HasMany
    {
        return $this->hasMany(PhrSinusEnrollment::class, 'phr_patient_id');
    }

    /** @return HasMany<PhrDicomStudy, $this> */
    public function dicomStudies(): HasMany
    {
        return $this->hasMany(PhrDicomStudy::class, 'patient_id');
    }

    /** @return HasMany<PhrDicomUpload, $this> */
    public function dicomUploads(): HasMany
    {
        return $this->hasMany(PhrDicomUpload::class, 'patient_id');
    }

    /** @return HasMany<PhrDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(PhrDocument::class, 'patient_id');
    }

    /** @return HasMany<PhrExport, $this> */
    public function exports(): HasMany
    {
        return $this->hasMany(PhrExport::class, 'patient_id');
    }

    /** @return HasMany<PhrHealthLog, $this> */
    public function healthLogs(): HasMany
    {
        return $this->hasMany(PhrHealthLog::class, 'patient_id');
    }

    /** @return HasMany<PhrHealthLogEntry, $this> */
    public function healthLogEntries(): HasMany
    {
        return $this->hasMany(PhrHealthLogEntry::class, 'patient_id');
    }

    /** @return HasMany<PhrPortalMessage, $this> */
    public function portalMessages(): HasMany
    {
        return $this->hasMany(PhrPortalMessage::class, 'patient_id');
    }

    /** @return HasMany<PhrNegativeAssertion, $this> */
    public function negativeAssertions(): HasMany
    {
        return $this->hasMany(PhrNegativeAssertion::class, 'patient_id');
    }

    /** @return HasMany<PhrEob, $this> */
    public function eobs(): HasMany
    {
        return $this->hasMany(PhrEob::class, 'patient_id');
    }

    /**
     * @param  Builder<PhrPatient>  $query
     * @return Builder<PhrPatient>
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('owner_user_id', $userId);
    }

    /**
     * @param  Builder<PhrPatient>  $query
     * @return Builder<PhrPatient>
     */
    public function scopeAccessibleBy(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $nested) use ($userId): void {
            $nested
                ->where('owner_user_id', $userId)
                ->orWhereHas('accessGrants', function (Builder $accessQuery) use ($userId): void {
                    $accessQuery->where('user_id', $userId);
                });
        });
    }
}
