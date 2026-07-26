<?php

namespace App\Http\Requests\PHR;

class UpdateHealthLogRequest extends StoreHealthLogRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->healthLogRules(true);
    }
}
