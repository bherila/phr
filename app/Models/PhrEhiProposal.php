<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A reviewable proposal produced deterministically by a versioned mapping contract.
 *
 * target_id, revision_ids_json, and candidate_ids_json hold database ids as of
 * the preview. They are not remapped by a native restore; a restored pending
 * proposal then fails its revision or target digest check and needs a re-preview.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $package_id
 * @property string $mapping
 * @property string $mapping_version
 * @property string $subject_key
 * @property string $revision_digest
 * @property list<int> $revision_ids_json
 * @property string $kind
 * @property string|null $target_table
 * @property int|null $target_id
 * @property string|null $target_digest
 * @property array<string, mixed>|null $proposed_json
 * @property array<string, mixed>|null $before_json
 * @property list<int>|null $candidate_ids_json
 * @property string $status
 * @property string $preview_digest
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrEhiProposal extends Model
{
    use SerializesDatesAsLocal;

    /** A new clinical record. */
    public const string KIND_CREATE = 'create';

    /** An explicit before/after change to an existing record. */
    public const string KIND_UPDATE = 'update';

    /** Same entity and content already linked: evidence only, nothing to change. */
    public const string KIND_CORROBORATE = 'corroborate';

    /** A probable clinical duplicate of an unlinked record; a person must choose. */
    public const string KIND_POSSIBLE_DUPLICATE = 'possible_duplicate';

    /** Source content kept as evidence; no clinical mapping applies (e.g. deleted). */
    public const string KIND_RETAIN = 'retain';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_ACCEPTED = 'accepted';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_RETAINED = 'retained';

    public const string STATUS_LINKED = 'linked';

    public const string STATUS_SUPERSEDED = 'superseded';

    public const string DECISION_ACCEPT = 'accept';

    public const string DECISION_REJECT = 'reject';

    public const string DECISION_RETAIN = 'retain_evidence';

    public const string DECISION_LINK = 'link_existing';

    public const string DECISION_APPROVE_UPDATE = 'approve_update';

    protected $table = 'phr_ehi_proposals';

    protected $fillable = ['patient_id', 'package_id', 'mapping', 'mapping_version', 'subject_key', 'revision_digest', 'revision_ids_json', 'kind', 'target_table', 'target_id', 'target_digest', 'proposed_json', 'before_json', 'candidate_ids_json', 'status', 'preview_digest', 'decided_by_user_id', 'decided_at'];

    protected function casts(): array
    {
        return ['revision_ids_json' => 'array', 'proposed_json' => 'array', 'before_json' => 'array', 'candidate_ids_json' => 'array', 'target_id' => 'integer', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<PhrEhiPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(PhrEhiPackage::class, 'package_id');
    }

    /** @return HasMany<PhrEhiProposalDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(PhrEhiProposalDecision::class, 'proposal_id');
    }
}
