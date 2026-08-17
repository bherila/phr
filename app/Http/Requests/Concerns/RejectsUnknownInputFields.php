<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

trait RejectsUnknownInputFields
{
    /** @param array<string, mixed> $rules */
    protected function rejectUnknownInputFields(Validator $validator, array $rules): void
    {
        $allowed = [];
        foreach (array_keys($rules) as $field) {
            $allowed[explode('.', $field, 2)[0]] = true;
        }
        foreach (array_keys($this->all()) as $field) {
            if (! isset($allowed[$field])) {
                $validator->errors()->add($field, 'The request contains an unsupported field.');
            }
        }
    }
}
