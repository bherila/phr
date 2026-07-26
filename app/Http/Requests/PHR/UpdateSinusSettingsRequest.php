<?php

namespace App\Http\Requests\PHR;

use App\Models\PhrSinusSetting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSinusSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * CSRF-exempt (bearer devices carry no session token), so form-encoded
     * bodies must be refused: a cross-site hidden form could otherwise ride a
     * victim's session cookie. Cross-origin JSON is blocked by CORS preflight.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            abort(415, 'This endpoint only accepts application/json request bodies.');
        }
    }

    /**
     * `updated_at` is the client's clock and decides last-write-wins, so it is
     * bounded: a device whose clock runs fast would otherwise win every race
     * permanently and silently. Unknown keys inside `settings` are dropped
     * rather than rejected (see PhrSinusSetting::filterSyncedKeys) so an older
     * app version syncing an extra key is not a hard error.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $skew = PhrSinusSetting::MAX_CLOCK_SKEW_MINUTES;

        return [
            'settings' => ['required', 'array'],
            'settings.sensitivity' => ['sometimes', 'numeric', 'between:0,1'],
            'settings.quiet_start' => ['sometimes', 'nullable', 'integer', 'between:0,23'],
            'settings.quiet_end' => ['sometimes', 'nullable', 'integer', 'between:0,23'],
            'updated_at' => ['required', 'date', 'before_or_equal:+'.$skew.' minutes'],
            'device_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'updated_at.before_or_equal' => 'updated_at is too far in the future; check this device\'s clock.',
        ];
    }
}
