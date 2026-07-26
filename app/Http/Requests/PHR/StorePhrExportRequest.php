<?php

namespace App\Http\Requests\PHR;

use App\Services\PHR\Export\PhrExportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePhrExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'formats' => ['sometimes', 'array', 'min:1'],
            'formats.*' => ['string', Rule::in(PhrExportService::FORMATS)],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function formats(): array
    {
        $formats = $this->validated('formats', ['zip']);

        return is_array($formats) ? array_values($formats) : ['zip'];
    }
}
