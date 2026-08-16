<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrEob;
use App\Models\PhrEobLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EobSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrEob $eob */
        $eob = $this->resource;
        $lines = $eob->relationLoaded('lines') ? $eob->lines : collect();
        $serviceStart = $lines
            ->map(fn (PhrEobLine $line) => $line->service_start)
            ->filter()
            ->min();
        $serviceEnd = $lines
            ->map(fn (PhrEobLine $line) => $line->service_end ?? $line->service_start)
            ->filter()
            ->max();

        return [
            'id' => $eob->id,
            'claim_number' => $eob->claim_number,
            'claim_type' => $eob->claim_type,
            'provider_name' => $eob->provider_name,
            'administrator' => $eob->administrator,
            'service_start' => $serviceStart?->toDateString(),
            'service_end' => $serviceEnd?->toDateString(),
            'processed_date' => $eob->processed_date?->toDateString(),
            'source_document_id' => $eob->source_document_id,
            'source_document_url' => $eob->source_document_id
                ? url("/api/phr/patients/{$eob->patient_id}/documents/{$eob->source_document_id}/file")
                : null,
        ];
    }
}
