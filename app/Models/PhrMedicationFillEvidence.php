<?php

namespace App\Models;

use App\Services\PHR\Fills\FillEobLinker;
use App\Services\PHR\Fills\FillEobLinkTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of evidence for a fill besides the document it was first read from:
 * another document (the receipt of a fill first read from a label) or the EOB
 * line that adjudicated it.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $fill_id
 * @property int|null $document_id
 * @property int|null $eob_line_id
 * @property string $source_kind
 * @property string|null $source_locator
 * @property string $evidence_key
 */
class PhrMedicationFillEvidence extends Model
{
    /**
     * A link the server inferred, not one a person or client made: {@see FillEobLinker}.
     * `eob_line_id` is the line and `source_locator` names the rule that produced it.
     */
    public const string KIND_AUTO_EOB_LINK = 'auto_eob_link';

    /**
     * A flag for a person: this fill has a same-day pharmacy claim the server would not decide
     * on. `source_locator` says why. It carries no `eob_line_id`, which would read as a link;
     * the claims on offer are worked out when the queue is read.
     */
    public const string KIND_EOB_LINK_OPEN = 'eob_link_open';

    /** A person said this fill has none of the claims on offer: not asked again, never auto-linked. */
    public const string KIND_EOB_LINK_DISMISSED = 'eob_link_dismissed';

    /** The kinds the linker owns. Everything else belongs to a person or client and it never touches them. */
    public const array LINKER_KINDS = [self::KIND_AUTO_EOB_LINK, self::KIND_EOB_LINK_OPEN, self::KIND_EOB_LINK_DISMISSED];

    protected $table = 'phr_medication_fill_evidence';

    protected $fillable = ['patient_id', 'fill_id', 'document_id', 'eob_line_id', 'source_kind', 'source_locator', 'evidence_key', 'import_source', 'external_id', 'related_fill_id'];

    protected static function booted(): void
    {
        // A person's or client's link settles the fill and takes the claim; the linker's own rows are not news.
        $changed = static function (self $evidence): void {
            if (! in_array($evidence->source_kind, self::LINKER_KINDS, true)
                && ($evidence->eob_line_id !== null || $evidence->getOriginal('eob_line_id') !== null)) {
                FillEobLinkTrigger::schedule((int) $evidence->patient_id);
            }
        };
        static::saved($changed);
        static::deleted($changed);
    }

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'fill_id' => 'integer',
            'document_id' => 'integer',
            'eob_line_id' => 'integer',
            'related_fill_id' => 'integer',
        ];
    }

    /** The same evidence, however many times a source is read again. */
    public static function keyFor(?int $documentId, ?int $eobLineId, string $sourceKind, ?string $locator): string
    {
        return substr(hash('sha256', implode('|', [$documentId ?? '', $eobLineId ?? '', $sourceKind, $locator ?? ''])), 0, 40);
    }

    /** @return BelongsTo<PhrMedicationFill, $this> */
    public function medicationFill(): BelongsTo
    {
        return $this->belongsTo(PhrMedicationFill::class, 'fill_id');
    }
}
