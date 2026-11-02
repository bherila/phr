<?php

namespace App\Http\Requests\PHR;

/**
 * The names a patient appears under on source documents, as entered by a person.
 * Blank entries are dropped and an empty list is stored as no list.
 */
trait ValidatesPatientRealNames
{
    protected function prepareForValidation(): void
    {
        $names = $this->input('real_names');
        if (! is_array($names)) {
            return;
        }

        $names = array_values(array_filter(
            array_map(static fn (mixed $name): mixed => is_string($name) ? trim($name) : $name, $names),
            static fn (mixed $name): bool => $name !== '' && $name !== null,
        ));
        $this->merge(['real_names' => $names === [] ? null : $names]);
    }

    /** @return array<string, list<string>> */
    private function realNamesRules(): array
    {
        return [
            'real_names' => ['nullable', 'array', 'max:20'],
            'real_names.*' => ['string', 'max:255', 'distinct'],
        ];
    }
}
