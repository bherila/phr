<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Explicit evidence link from a source revision to exactly one clinical record.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $revision_id
 * @property int|null $proposal_id
 * @property int|null $immunization_id
 * @property int|null $document_id
 * @property int|null $lab_result_id
 * @property string $link_kind
 * @property string $mapping_version
 * @property string|null $bridge_version
 * @property string|null $bridge_rule
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrEhiRecordLink extends Model
{
    use SerializesDatesAsLocal;

    /** The record was created or updated from this revision. */
    public const string KIND_PROJECTED = 'projected';

    /** The revision supports an existing record without changing it. */
    public const string KIND_CORROBORATING = 'corroborating';

    /**
     * The revision is linked to a record that predates it (e.g. a FHIR import), by a
     * person or by a compatible identifier a person accepted. The record's own
     * identifiers and content are untouched; bridge_version and bridge_rule say why.
     */
    public const string KIND_BRIDGED = 'bridged';

    protected $table = 'phr_ehi_record_links';

    protected $fillable = ['patient_id', 'revision_id', 'proposal_id', 'immunization_id', 'document_id', 'lab_result_id', 'link_kind', 'mapping_version', 'bridge_version', 'bridge_rule', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['immunization_id' => 'integer', 'document_id' => 'integer', 'lab_result_id' => 'integer', 'proposal_id' => 'integer'];
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
}
