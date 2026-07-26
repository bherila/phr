<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrHealthLogEntry;
use App\Services\PHR\HealthLog\Data\HealthLogEntryData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HealthLogEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrHealthLogEntry $entry */
        $entry = $this->resource;

        return HealthLogEntryData::fromModel($entry)->toArray();
    }
}
