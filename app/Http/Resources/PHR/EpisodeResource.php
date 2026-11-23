<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrEpisode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EpisodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrEpisode $episode */
        $episode = $this->resource;

        return [
            'id' => $episode->id,
            'patient_id' => $episode->patient_id,
            'user_id' => $episode->user_id,
            'import_source' => $episode->import_source,
            'external_id' => $episode->external_id,
            'source_document_id' => $episode->source_document_id,
            'review_status' => $episode->review_status,
            'title' => $episode->title,
            'kind' => $episode->kind,
            'status' => $episode->status,
            'started_at' => $episode->started_at?->toDateTimeString(),
            'ended_at' => $episode->ended_at?->toDateTimeString(),
            'reason' => $episode->reason,
            'created_at' => $episode->created_at?->toDateTimeString(),
            'updated_at' => $episode->updated_at?->toDateTimeString(),
        ];
    }
}
