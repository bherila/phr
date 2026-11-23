<?php

namespace App\Models;

use App\Services\PHR\Fills\FillEobLinkTrigger;
use App\Traits\HasPublicId;
use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $user_id
 * @property int|null $source_document_id
 * @property string $import_source
 * @property string $external_id
 * @property string|null $claim_fingerprint
 * @property string|null $claim_number
 * @property string $claim_type
 * @property Carbon|null $submission_date
 * @property Carbon|null $print_date
 * @property Carbon|null $processed_date
 * @property array<string, mixed>|null $parsed_data
 * @property string $review_status
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, PhrEobLine> $lines
 *
 * A deleted EOB (#444) is soft-deleted: it leaves every read, list, export and the
 * fill linker, and changes.list reports it as a tombstone. What hangs off it stays
 * stored, unchanged, and stops counting, as for every other soft-deleted record:
 * - its lines and correction history are reached only through the claim, so they
 *   leave every read with it ({@see PhrEobLine::scopeOnStandingClaims()} for the
 *   paths that reach a line directly);
 * - visit and procedure links (phr_office_visit_eobs, phr_procedure_eobs) stay,
 *   and every read of them leaves the deleted claim out;
 * - a fill a person or client linked to one of its lines keeps that evidence row,
 *   which no read reports any more, so the fill is touched for clients syncing on
 *   updated_at; an automatic link is the linker's own derived row, and the linker,
 *   which no longer sees the claim, withdraws it after the delete commits.
 * Keeping the rows rather than removing them keeps a soft delete what it is
 * everywhere else, and a native backup holds the deleted claim with its links
 * exactly as they were.
 */
class PhrEob extends Model
{
    use HasPublicId;
    use SerializesDatesAsLocal;
    use SoftDeletes;

    protected $fillable = [
        'patient_id',
        'user_id',
        'source_document_id',
        'import_source',
        'external_id',
        'claim_fingerprint',
        'claim_number',
        'claim_type',
        'administrator',
        'carrier',
        'plan_name',
        'group_number',
        'member_id',
        'participant_name',
        'patient_name',
        'provider_name',
        'provider_phone',
        'payment_to',
        'provider_tin',
        'check_number',
        'check_amount',
        'submission_date',
        'print_date',
        'processed_date',
        'total_accepted_fee',
        'total_charges',
        'total_provider_discount',
        'total_ineligible_amount',
        'total_deductible_applied',
        'total_copay_applied',
        'total_benefit_percent',
        'total_carrier_payment',
        'total_plan_payment',
        'total_patient_responsibility',
        'parsed_data',
        'raw_text',
        'parser_version',
        'review_status',
        'lines_digest',
    ];

    protected static function booted(): void
    {
        // A pharmacy claim changed, or one stopped being a pharmacy claim. Other claims never concern the linker.
        $changed = static function (self $eob): void {
            if ($eob->claim_type === 'pharmacy' || $eob->getOriginal('claim_type') === 'pharmacy') {
                FillEobLinkTrigger::schedule((int) $eob->patient_id);
            }
        };
        static::saved(static function (self $eob) use ($changed): void {
            if ($eob->wasRecentlyCreated || $eob->wasChanged()) {
                $changed($eob);
            }
        });
        static::deleted($changed);
        static::softDeleted(static function (self $eob): void {
            // The fills a person or client linked to this claim no longer show the link.
            DB::table('phr_medication_fills')
                ->where('patient_id', $eob->patient_id)
                ->whereIn('id', static fn ($evidence) => $evidence->select('fill_id')->from('phr_medication_fill_evidence')
                    ->where('patient_id', $eob->patient_id)
                    ->whereNotIn('source_kind', PhrMedicationFillEvidence::LINKER_KINDS)
                    ->whereIn('eob_line_id', static fn ($lines) => $lines->select('id')->from('phr_eob_lines')
                        ->where('patient_id', $eob->patient_id)->where('eob_id', $eob->id)))
                ->update(['updated_at' => $eob->deleted_at]);
        });
    }

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
            'check_amount' => 'decimal:2',
            'submission_date' => 'date',
            'print_date' => 'date',
            'processed_date' => 'date',
            'total_accepted_fee' => 'decimal:2',
            'total_charges' => 'decimal:2',
            'total_provider_discount' => 'decimal:2',
            'total_ineligible_amount' => 'decimal:2',
            'total_deductible_applied' => 'decimal:2',
            'total_copay_applied' => 'decimal:2',
            'total_benefit_percent' => 'decimal:2',
            'total_carrier_payment' => 'decimal:2',
            'total_plan_payment' => 'decimal:2',
            'total_patient_responsibility' => 'decimal:2',
            'parsed_data' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<PhrDocument, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(PhrDocument::class, 'source_document_id');
    }

    /** @return HasMany<PhrEobLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PhrEobLine::class, 'eob_id');
    }

    /** @return BelongsToMany<PhrOfficeVisit, $this> */
    public function officeVisits(): BelongsToMany
    {
        return $this->belongsToMany(PhrOfficeVisit::class, 'phr_office_visit_eobs', 'eob_id', 'office_visit_id')
            ->withPivot('patient_id')
            ->withTimestamps();
    }

    /** @return BelongsToMany<PhrProcedure, $this> */
    public function procedures(): BelongsToMany
    {
        return $this->belongsToMany(PhrProcedure::class, 'phr_procedure_eobs', 'eob_id', 'procedure_id')
            ->withPivot('patient_id')
            ->withTimestamps();
    }
}
