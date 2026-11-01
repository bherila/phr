<?php

namespace App\Support\PHR;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * How a person likes PHR laid out, saved on the logged-in user and never on a patient, so it
 * follows them across patients and two people on one record can differ. It changes what they
 * see, never what is stored or what an API or export returns.
 */
final class DisplayPreferences
{
    /** The date formats a person can choose, each with how PHP writes it. */
    public const array DATE_FORMATS = [
        'YYYY-MM-DD' => 'Y-m-d',
        'MM/DD/YYYY' => 'm/d/Y',
        'DD/MM/YYYY' => 'd/m/Y',
        'MMM D, YYYY' => 'M j, Y',
    ];

    public const string DEFAULT_DATE_FORMAT = 'YYYY-MM-DD';

    /** The Fills columns a person can hide. */
    public const array FILL_COLUMNS = ['no_insurance', 'prescriber', 'pharmacy'];

    /**
     * What is stored, with defaults filled in and anything unknown dropped.
     *
     * @return array{date_format: string, hide_medication_list: bool, separate_columns: bool, hidden_fill_columns: list<string>}
     */
    public static function normalize(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $hidden = is_array($stored['hidden_fill_columns'] ?? null) ? $stored['hidden_fill_columns'] : [];

        $format = $stored['date_format'] ?? null;

        return [
            'date_format' => is_string($format) && isset(self::DATE_FORMATS[$format]) ? $format : self::DEFAULT_DATE_FORMAT,
            'hide_medication_list' => ($stored['hide_medication_list'] ?? false) === true,
            'separate_columns' => ($stored['separate_columns'] ?? false) === true,
            'hidden_fill_columns' => array_values(array_intersect(self::FILL_COLUMNS, $hidden)),
        ];
    }

    /** A date in text the server writes for someone to read, in that person's format. */
    public static function writeDate(CarbonInterface $date, ?User $user): string
    {
        $format = $user?->displayPreferences()['date_format'] ?? self::DEFAULT_DATE_FORMAT;

        return $date->format(self::DATE_FORMATS[$format]);
    }
}
