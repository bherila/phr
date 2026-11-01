<?php

namespace App\Http\Requests\PHR;

use App\Services\PHR\Storage\BlobStorageQuota;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStorageSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $limit = ['present', 'nullable', 'integer', 'min:0', 'max:'.BlobStorageQuota::MAX_LIMIT_BYTES];

        return $this->route('user') !== null
            ? ['limit_bytes' => $limit, 'unlimited' => ['required', 'boolean']]
            : ['global_limit_bytes' => $limit, 'default_user_limit_bytes' => $limit];
    }
}
