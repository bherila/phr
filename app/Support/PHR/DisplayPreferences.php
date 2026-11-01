<?php

namespace App\Support\PHR;

/**
 * How a person likes PHR laid out, saved on the logged-in user and never on a patient, so it
 * follows them across patients and two people on one record can differ. It changes what they
 * see, never what is stored or what an API or export returns.
 */
final class DisplayPreferences
{
    /** The Fills columns a person can hide. */
    public const array FILL_COLUMNS = ['no_insurance', 'prescriber', 'pharmacy'];

    /**
     * What is stored, with defaults filled in and anything unknown dropped.
     *
     * @return array{hide_medication_list: bool, hidden_fill_columns: list<string>}
     */
    public static function normalize(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $hidden = is_array($stored['hidden_fill_columns'] ?? null) ? $stored['hidden_fill_columns'] : [];

        return [
            'hide_medication_list' => ($stored['hide_medication_list'] ?? false) === true,
            'hidden_fill_columns' => array_values(array_intersect(self::FILL_COLUMNS, $hidden)),
        ];
    }
}
