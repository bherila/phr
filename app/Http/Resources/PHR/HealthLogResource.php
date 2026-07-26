<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrHealthLog;
use App\Services\PHR\HealthLog\Data\HealthLogData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HealthLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrHealthLog $healthLog */
        $healthLog = $this->resource;

        return HealthLogData::fromModel($healthLog)->toArray();
    }
}
