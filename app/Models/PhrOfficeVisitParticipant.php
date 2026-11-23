<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One member of a visit's care team (issue #412). It has no review status of its own:
 * it is reviewed, exported and withdrawn with its visit, and is written only as part of
 * the visit's whole list (PhrOfficeVisit's `participants`). See PhrVisitParticipants.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $office_visit_id
 * @property string|null $external_id
 * @property string $name
 * @property string|null $credentials
 * @property string $role_code
 * @property string|null $role_label
 * @property string|null $specialty
 * @property bool $is_primary
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrOfficeVisitParticipant extends Model
{
    use SerializesDatesAsLocal;

    protected $table = 'phr_office_visit_participants';

    protected $fillable = [
        'patient_id',
        'office_visit_id',
        'external_id',
        'name',
        'credentials',
        'role_code',
        'role_label',
        'specialty',
        'is_primary',
        'period_start',
        'period_end',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'office_visit_id' => 'integer',
            'is_primary' => 'boolean',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhrOfficeVisit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(PhrOfficeVisit::class, 'office_visit_id');
    }

    /**
     * The member as PhrVisitParticipants lists one, so a stored team digests and
     * exports the way the list it was written from does.
     *
     * @return array{external_id: string|null, name: string, credentials: string|null, role_code: string, role_label: string|null, specialty: string|null, is_primary: bool, period_start: string|null, period_end: string|null}
     */
    public function toMember(): array
    {
        return [
            'external_id' => $this->external_id,
            'name' => $this->name,
            'credentials' => $this->credentials,
            'role_code' => $this->role_code,
            'role_label' => $this->role_label,
            'specialty' => $this->specialty,
            'is_primary' => $this->is_primary,
            'period_start' => $this->period_start?->toDateTimeString(),
            'period_end' => $this->period_end?->toDateTimeString(),
        ];
    }
}
