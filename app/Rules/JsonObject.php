<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Require an associative JSON-style object rather than a non-empty list. */
final class JsonObject implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // PHP's associative JSON decoding maps both {} and [] to an empty array;
        // JSON-only callers perform the exact empty-shape check at request level.
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $fail('The :attribute field must be a JSON object.');
        }
    }
}
