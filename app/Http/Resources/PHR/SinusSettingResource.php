<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrSinusSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SinusSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrSinusSetting $setting */
        $setting = $this->resource;

        return [
            'settings' => $setting->settings,
            'updated_at' => $setting->settings_updated_at->toIso8601String(),
            'received_at' => $setting->received_at->toIso8601String(),
            'updated_by_device' => $setting->updated_by_device,
        ];
    }
}
