<?php

namespace App\Services\PHR\Fills;

use App\Models\PhrPatient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finds the one patient a name printed on a source document refers to.
 *
 * Names are compared ignoring case, accents, punctuation, spacing and
 * "LAST, FIRST" order. A date of birth, when the source prints one, confirms
 * or narrows the match. This never guesses: the result is a match only when
 * exactly one patient qualifies, and the caller is told to ask otherwise.
 */
final class PatientNameMatcher
{
    public const string MATCHED = 'matched';

    public const string NONE = 'none';

    public const string AMBIGUOUS = 'ambiguous';

    /**
     * @param  Collection<int, PhrPatient>  $patients  the patients the caller may access
     * @return array{status: string, patients: list<PhrPatient>}
     */
    public function match(Collection $patients, string $printedName, ?string $birthDate = null): array
    {
        $wanted = self::tokens($printedName);
        if ($wanted === []) {
            return ['status' => self::NONE, 'patients' => []];
        }

        // An exact name beats a name that matches only after dropping middle names.
        $exact = $patients->filter(fn (PhrPatient $patient): bool => self::tokens((string) $patient->display_name) === $wanted)->values();
        $named = $exact->isNotEmpty() ? $exact : $patients->filter(
            fn (PhrPatient $patient): bool => self::firstAndLast(self::tokens((string) $patient->display_name)) === self::firstAndLast($wanted)
                && self::firstAndLast($wanted) !== null,
        )->values();

        $dob = self::date($birthDate);
        if ($dob !== null) {
            // A recorded date of birth that disagrees rules the patient out; a
            // patient with none recorded cannot confirm, but is not refuted.
            $named = $named->filter(
                fn (PhrPatient $patient): bool => $patient->birth_date === null || $patient->birth_date->toDateString() === $dob,
            )->values();
            $confirmed = $named->filter(fn (PhrPatient $patient): bool => $patient->birth_date !== null)->values();
            if ($confirmed->isNotEmpty()) {
                $named = $confirmed;
            }
        }

        /** @var list<PhrPatient> $matches */
        $matches = $named->all();

        return [
            'status' => match (count($matches)) {
                0 => self::NONE,
                1 => self::MATCHED,
                default => self::AMBIGUOUS,
            },
            'patients' => $matches,
        ];
    }

    /**
     * The comparable words of a name: lowercase ASCII letters and digits,
     * "LAST, FIRST MIDDLE" turned into "first middle last".
     *
     * @return list<string>
     */
    public static function tokens(string $name): array
    {
        $name = Str::ascii(trim($name));
        if (substr_count($name, ',') === 1) {
            [$last, $first] = array_map('trim', explode(',', $name));
            $name = $first.' '.$last;
        }
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $name));

        return array_values(array_filter(explode(' ', $name), static fn (string $token): bool => $token !== ''));
    }

    /** @param list<string> $tokens */
    private static function firstAndLast(array $tokens): ?string
    {
        return count($tokens) >= 2 ? $tokens[0].' '.$tokens[array_key_last($tokens)] : null;
    }

    private static function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
