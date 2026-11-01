<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One signed document upload session: bytes staged through a short-lived URL,
 * waiting for the agent to finalize them into a document.
 *
 * @property int $id
 * @property string $public_id
 * @property int $patient_id
 * @property int $actor_user_id
 * @property string $import_source
 * @property string $kind
 * @property string $filename
 * @property string $content_type
 * @property int $byte_size
 * @property string|null $sha256
 * @property string $status
 * @property string $storage_disk
 * @property string $transport
 * @property string|null $staging_path
 * @property int|null $received_size
 * @property string|null $received_sha256
 * @property Carbon $expires_at
 * @property Carbon|null $uploaded_at
 * @property Carbon|null $finalized_at
 * @property int|null $document_id
 */
class PhrDocumentUpload extends Model
{
    use SerializesDatesAsLocal;

    public const string PENDING = 'pending';

    public const string RECEIVING = 'receiving';

    public const string UPLOADED = 'uploaded';

    public const string FINALIZED = 'finalized';

    public const string CANCELLED = 'cancelled';

    public const string EXPIRED = 'expired';

    /** What kind of document an upload is: it picks the stored document's type and tag. */
    public const array KINDS = ['eob', 'pharmacy_receipt', 'rx_label', 'statement', 'other'];

    protected $fillable = [
        'public_id', 'patient_id', 'actor_user_id', 'import_source', 'kind', 'filename', 'content_type',
        'byte_size', 'sha256', 'status', 'staging_path', 'received_size', 'received_sha256',
        'expires_at', 'uploaded_at', 'finalized_at', 'document_id', 'storage_disk', 'transport',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'actor_user_id' => 'integer',
            'byte_size' => 'integer',
            'received_size' => 'integer',
            'document_id' => 'integer',
            'expires_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<PhrDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PhrDocument::class, 'document_id');
    }
}
