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
 * A display name is often a nickname, so a patient is known by it and by any real
 * names listed for them. Names are compared ignoring case, accents, punctuation,
 * spacing and "LAST, FIRST" order. A date of birth, when the source prints one, is
 * stable where names are not: it is enough on its own unless another patient shares
 * it or the printed name belongs to someone else. This never guesses: the result is
 * a match only when exactly one patient qualifies, and the caller is told to ask
 * otherwise.
 */
final class PatientNameMatcher
{
    public const string MATCHED = 'matched';

    public const string NONE = 'none';

    public const string AMBIGUOUS = 'ambiguous';

    /**
     * @param  Collection<int, PhrPatient>  $patients  the patients the caller may choose among
     * @param  Collection<int, PhrPatient>|null  $accessible  everyone on the login, when the caller is
     *                                                        limited to some of them: a match on the birth date alone must be unique
     *                                                        among these too, or a client granted one twin would be handed the other's
     *                                                        documents. Refused without naming the patient the caller cannot see.
     * @return array{status: string, patients: list<PhrPatient>}
     */
    public function match(Collection $patients, string $printedName, ?string $birthDate = null, ?Collection $accessible = null): array
    {
        $wanted = self::tokens($printedName);
        if ($wanted === []) {
            return self::result(collect());
        }

        $dob = self::date($birthDate);
        if ($dob === null) {
            return self::result(self::named($patients, $wanted));
        }

        $sameBirthDate = $patients->filter(fn (PhrPatient $patient): bool => $patient->birth_date?->toDateString() === $dob)->values();
        // A recorded date of birth that disagrees rules a patient out. A patient with none
        // recorded cannot confirm it, but is not refuted, so the name alone can still find them.
        if ($sameBirthDate->isEmpty()) {
            return self::result(self::named($patients->filter(fn (PhrPatient $patient): bool => $patient->birth_date === null)->values(), $wanted));
        }

        $confirmed = self::named($sameBirthDate, $wanted);
        if ($confirmed->isNotEmpty()) {
            // A name that fits only once middle names are dropped does not outrank the exact
            // name on a patient with no recorded birth date, whom nothing rules out.
            if (self::exactly($confirmed, $wanted)->isNotEmpty()) {
                return self::result($confirmed);
            }
            $unconfirmed = static fn (Collection $candidates): Collection => self::exactly($candidates->filter(fn (PhrPatient $other): bool => $other->birth_date === null)->values(), $wanted);
            $rivals = $unconfirmed($patients);
            if ($rivals->isNotEmpty()) {
                return self::result($rivals->concat($confirmed)->values());
            }

            return self::result($unconfirmed($accessible ?? $patients)->isNotEmpty() ? collect() : $confirmed);
        }
        if ($sameBirthDate->count() > 1) {
            return self::result($sameBirthDate);
        }

        // One patient has this birth date and the printed name is not one of theirs, which a
        // nickname explains, unless the name belongs to someone else: then the source
        // contradicts itself and a person must decide.
        $patient = $sameBirthDate->first();
        $isOther = fn (PhrPatient $other): bool => (int) $other->id !== (int) $patient->id;
        $rivals = self::named($patients->filter($isOther)->values(), $wanted);
        if ($rivals->isNotEmpty()) {
            return self::result($sameBirthDate->concat($rivals)->values());
        }

        $others = ($accessible ?? $patients)->filter($isOther)->values();
        $hidden = $others->contains(fn (PhrPatient $other): bool => $other->birth_date?->toDateString() === $dob)
            || self::named($others, $wanted)->isNotEmpty();

        return self::result($hidden ? collect() : $sameBirthDate);
    }

    /**
     * The patients a printed name refers to. An exact name beats one that matches only
     * after dropping middle names.
     *
     * @param  Collection<int, PhrPatient>  $patients
     * @param  list<string>  $wanted
     * @return Collection<int, PhrPatient>
     */
    private static function named(Collection $patients, array $wanted): Collection
    {
        $exact = self::exactly($patients, $wanted);
        if ($exact->isNotEmpty()) {
            return $exact;
        }

        return $patients->filter(function (PhrPatient $patient) use ($wanted): bool {
            foreach (self::names($patient) as $name) {
                if (self::compatible($wanted, $name)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * @param  Collection<int, PhrPatient>  $patients
     * @param  list<string>  $wanted
     * @return Collection<int, PhrPatient>
     */
    private static function exactly(Collection $patients, array $wanted): Collection
    {
        return $patients->filter(fn (PhrPatient $patient): bool => in_array($wanted, self::names($patient), true))->values();
    }

    /**
     * The comparable words of every name a patient goes by.
     *
     * @return list<list<string>>
     */
    private static function names(PhrPatient $patient): array
    {
        return array_map(self::tokens(...), [(string) $patient->display_name, ...($patient->real_names ?? [])]);
    }

    /**
     * @param  Collection<int, PhrPatient>  $matches
     * @return array{status: string, patients: list<PhrPatient>}
     */
    private static function result(Collection $matches): array
    {
        /** @var list<PhrPatient> $patients */
        $patients = $matches->values()->all();

        return [
            'status' => match (count($patients)) {
                0 => self::NONE,
                1 => self::MATCHED,
                default => self::AMBIGUOUS,
            },
            'patients' => $patients,
        ];
    }

    /**
     * True when a name printed on a document is present and does not refer to this
     * patient. A blank printed name is not a mismatch.
     */
    public function mismatches(PhrPatient $patient, ?string $printedName): bool
    {
        if ($printedName === null || self::tokens($printedName) === []) {
            return false;
        }

        return $this->match(collect([$patient]), $printedName)['status'] !== self::MATCHED;
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

    /**
     * Whether two names can be one person: the same first and last name, and middle
     * names that do not contradict. A missing middle name is compatible, and so is an
     * initial of the other's middle name (Q and Quincy); Quincy and Mary are not.
     *
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private static function compatible(array $left, array $right): bool
    {
        if (count($left) < 2 || count($right) < 2
            || $left[0] !== $right[0] || $left[array_key_last($left)] !== $right[array_key_last($right)]) {
            return false;
        }
        $leftMiddle = array_slice($left, 1, -1);
        $rightMiddle = array_slice($right, 1, -1);
        foreach (array_slice(array_keys($leftMiddle), 0, min(count($leftMiddle), count($rightMiddle))) as $index) {
            [$a, $b] = [$leftMiddle[$index], $rightMiddle[$index]];
            $initial = strlen($a) === 1 || strlen($b) === 1;
            if ($a !== $b && ! ($initial && $a[0] === $b[0])) {
                return false;
            }
        }

        return true;
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
