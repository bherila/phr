<?php

namespace App\Rules;

use App\Support\AgentApi\AgentApiJson;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;

/** Require a JSON-style list with sequential integer keys. */
final class JsonList implements ValidationRule
{
    private function __construct(private readonly ?bool $wireValueIsList = null) {}

    public static function fromRequest(Request $request, string $field): self
    {
        if (! $request->isJson()) {
            return new self;
        }
        $payload = AgentApiJson::decodeRaw($request->getContent());
        if (! is_object($payload) || ! property_exists($payload, $field)) {
            return new self;
        }

        return new self(is_array($payload->{$field}));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->wireValueIsList === false || ! is_array($value) || ! array_is_list($value)) {
            $fail('The :attribute field must be a JSON array.');
        }
    }
}
