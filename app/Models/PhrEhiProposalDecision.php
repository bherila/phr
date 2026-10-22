<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only review history for an EHI proposal.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $proposal_id
 * @property int|null $actor_user_id
 * @property string $decision
 * @property string $previous_status
 * @property string $mapping_version
 * @property string $revision_digest
 * @property int|null $linked_immunization_id
 * @property int|null $linked_document_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrEhiProposalDecision extends Model
{
    use SerializesDatesAsLocal;

    protected $table = 'phr_ehi_proposal_decisions';

    protected $fillable = ['patient_id', 'proposal_id', 'actor_user_id', 'decision', 'previous_status', 'mapping_version', 'revision_digest', 'linked_immunization_id', 'linked_document_id'];

    protected function casts(): array
    {
        return ['linked_immunization_id' => 'integer', 'linked_document_id' => 'integer'];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<PhrEhiProposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(PhrEhiProposal::class, 'proposal_id');
    }
}
