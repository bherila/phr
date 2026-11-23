<?php

namespace App\Models;

use App\Services\PHR\Medications\PhrMedicationTransitionIntegrity;
use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * What happened to a home medication during a stay (#414): at admission or discharge, it
 * was continued, held, changed or stopped, or an order was new. A clinical record, reviewed
 * like the others. The medications and the visit it names are records of the same patient,
 * and the action holds the ones it needs; the database checks both, and this model checks
 * what the database cannot (each medication's setting) before it saves.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $user_id
 * @property int $office_visit_id
 * @property int|null $home_medication_id
 * @property int|null $order_medication_id
 * @property string $phase
 * @property string $action
 * @property string|null $note
 * @property string|null $import_source
 * @property string|null $external_id
 * @property int|null $source_document_id
 * @property string $review_status
 * @property Carbon|null $retracted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PhrOfficeVisit|null $officeVisit
 * @property-read PhrMedication|null $homeMedication
 * @property-read PhrMedication|null $orderMedication
 */
class PhrMedicationTransition extends Model
{
    use SerializesDatesAsLocal;
    use SoftDeletes;

    public const string ADMISSION = 'admission';

    public const string DISCHARGE = 'discharge';

    /** @var list<string> */
    public const array PHASES = [self::ADMISSION, self::DISCHARGE];

    public const string CONTINUED = 'continued';

    public const string HELD = 'held';

    public const string CHANGED = 'changed';

    public const string STOPPED = 'stopped';

    public const string NEW = 'new';

    /** @var list<string> */
    public const array ACTIONS = [self::CONTINUED, self::HELD, self::CHANGED, self::STOPPED, self::NEW];

    /** Actions that name a home medication and no order. */
    public const array WITHOUT_ORDER = [self::HELD, self::STOPPED];

    /** Actions that name both a home medication and the order that carries it on. */
    public const array WITH_BOTH = [self::CONTINUED, self::CHANGED];

    /** The columns a transition's meaning rests on; a change to one is checked again. */
    public const array SHAPE = ['patient_id', 'office_visit_id', 'home_medication_id', 'order_medication_id', 'phase', 'action'];

    protected $fillable = [
        'patient_id',
        'user_id',
        'import_source',
        'external_id',
        'source_document_id',
        'review_status',
        'office_visit_id',
        'home_medication_id',
        'order_medication_id',
        'phase',
        'action',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'retracted_at' => 'datetime',
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
            'office_visit_id' => 'integer',
            'home_medication_id' => 'integer',
            'order_medication_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Review, retraction and deletion leave a transition's meaning alone and are never
        // refused for what its medications became since; a write that changes it is checked.
        static::saving(static function (self $transition): void {
            if (! $transition->exists || $transition->isDirty(self::SHAPE)) {
                app(PhrMedicationTransitionIntegrity::class)->assertCoherent($transition);
            }
        });
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<PhrOfficeVisit, $this> */
    public function officeVisit(): BelongsTo
    {
        return $this->belongsTo(PhrOfficeVisit::class, 'office_visit_id');
    }

    /** @return BelongsTo<PhrMedication, $this> */
    public function homeMedication(): BelongsTo
    {
        return $this->belongsTo(PhrMedication::class, 'home_medication_id');
    }

    /** @return BelongsTo<PhrMedication, $this> */
    public function orderMedication(): BelongsTo
    {
        return $this->belongsTo(PhrMedication::class, 'order_medication_id');
    }

    /** @return BelongsTo<PhrDocument, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(PhrDocument::class, 'source_document_id');
    }
}
