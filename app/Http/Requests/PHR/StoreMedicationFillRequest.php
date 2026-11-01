<?php

namespace App\Http\Requests\PHR;

use App\Support\PHR\Validation\MedicationFillDataRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Manual entry of a fill. The browser never sends drug fields (the server
 * resolves them from the NDC) and never chooses the source: it is always manual.
 */
class StoreMedicationFillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = MedicationFillDataRules::rules($this->isMethod('PATCH'));
        unset($rules['source_kind'], $rules['source_locator'], $rules['eob_line_id']);

        // The record's opaque version as the form last read it; a stale one is refused with 409.
        $rules['version'] = ['sometimes', 'string', 'size:64'];

        return $rules;
    }
}
