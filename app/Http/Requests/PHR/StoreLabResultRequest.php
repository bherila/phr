<?php

namespace App\Http\Requests\PHR;

use Illuminate\Foundation\Http\FormRequest;

class StoreLabResultRequest extends FormRequest
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
        return [
            'test_name' => ['nullable', 'string', 'max:255'],
            'collection_datetime' => ['nullable', 'date'],
            'result_datetime' => ['nullable', 'date'],
            'result_status' => ['nullable', 'string', 'max:50'],
            'ordering_provider' => ['nullable', 'string', 'max:100'],
            'resulting_lab' => ['nullable', 'string', 'max:100'],
            'analyte' => ['required', 'string', 'max:100'],
            'value' => ['nullable', 'string', 'max:255'],
            'value_numeric' => ['nullable', 'numeric'],
            'unit' => ['nullable', 'string', 'max:50'],
            'range_min' => ['nullable', 'numeric'],
            'range_max' => ['nullable', 'numeric'],
            'range_unit' => ['nullable', 'string', 'max:50'],
            'reference_range_text' => ['nullable', 'string', 'max:255'],
            'normal_value' => ['nullable', 'string', 'max:100'],
            'abnormal_flag' => ['nullable', 'string', 'max:50'],
            'message_from_provider' => ['nullable', 'string', 'max:10000'],
            'result_comment' => ['nullable', 'string', 'max:10000'],
            'lab_director' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
