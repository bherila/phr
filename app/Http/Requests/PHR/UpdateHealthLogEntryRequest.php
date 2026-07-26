<?php

namespace App\Http\Requests\PHR;

class UpdateHealthLogEntryRequest extends StoreHealthLogEntryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->healthLogEntryRules(true);
    }
}
