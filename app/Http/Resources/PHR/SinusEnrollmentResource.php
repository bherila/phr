<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrSinusEnrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SinusEnrollmentResource extends JsonResource
{
    /**
     * The uuid and embedding are stored as raw binary; JSON cannot carry raw
     * bytes, so both are base64-encoded on the way out. The device decodes them
     * straight back into its SQLite BLOBs — no float formatting anywhere in the
     * round trip.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrSinusEnrollment $enrollment */
        $enrollment = $this->resource;

        return [
            'id' => $enrollment->id,
            'client_enrollment_uuid' => base64_encode($enrollment->client_enrollment_uuid),
            'class' => $enrollment->class,
            'is_negative' => $enrollment->is_negative,
            'negative_scoped' => $enrollment->negative_scoped,
            'embedding' => base64_encode($enrollment->embedding),
            'embedding_dim' => $enrollment->embedding_dim,
            'model_version' => $enrollment->model_version,
            'similarity' => $enrollment->similarity,
            'separation' => $enrollment->separation,
            'peak_dbfs' => $enrollment->peak_dbfs,
            'source_event_uuid' => $enrollment->source_event_uuid,
            'device_id' => $enrollment->device_id,
            'captured_at' => $enrollment->captured_at->toIso8601String(),
        ];
    }
}
