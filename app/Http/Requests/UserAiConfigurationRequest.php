<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UserAiConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isCreate = $this->isMethod('POST') && ! $this->route('id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', 'in:gemini,anthropic,bedrock'],
            'api_key' => [$isCreate ? 'required' : 'nullable', 'string', 'max:500'],
            'region' => ['nullable', 'string', 'max:64', 'required_if:provider,bedrock'],
            'session_token' => ['nullable', 'string'],
            'model' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'api_key.required' => 'An API key is required when creating a configuration.',
            'region.required_if' => 'A region is required for Bedrock configurations.',
            'expires_at.after' => 'The expiry date must be in the future.',
        ];
    }
}
