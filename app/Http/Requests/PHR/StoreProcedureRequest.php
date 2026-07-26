<?php

namespace App\Http\Requests\PHR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProcedureRequest extends FormRequest
{
    /** @var list<string> */
    private const array STATUSES = [
        'preparation',
        'in_progress',
        'completed',
        'cancelled',
        'entered_in_error',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
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
            'name' => [$this->isMethod('PATCH') ? 'sometimes' : 'required', 'string', 'max:255'],
            'cpt_code' => ['nullable', 'string', 'max:20'],
            'snomed_code' => ['nullable', 'string', 'max:50'],
            'performed_at' => ['nullable', 'date'],
            'performed_on' => ['nullable', 'date'],
            'performer_name' => ['nullable', 'string', 'max:255'],
            'performer_specialty' => ['nullable', 'string', 'max:100'],
            'facility_name' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'reason' => ['nullable', 'string', 'max:10000'],
            'outcome' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'raw_text' => ['nullable', 'string'],
        ];
    }
}
