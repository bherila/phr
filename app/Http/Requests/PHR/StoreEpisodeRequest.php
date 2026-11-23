<?php

namespace App\Http\Requests\PHR;

use App\Support\PHR\Validation\EpisodeDataRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreEpisodeRequest extends FormRequest
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
        return EpisodeDataRules::rules($this->isMethod('PATCH'));
    }
}
