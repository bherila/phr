<?php

namespace Tests\Unit\Services\PHR\Fills;

use App\Models\PhrPatient;
use App\Services\PHR\Fills\PatientNameMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class PatientNameMatcherTest extends TestCase
{
    /** @param list<array{string, ?string}> $people */
    private function patients(array $people): Collection
    {
        return collect($people)->map(function (array $person, int $index): PhrPatient {
            $patient = new PhrPatient;
            $patient->setRawAttributes(['id' => $index + 1, 'display_name' => $person[0], 'birth_date' => $person[1]]);

            return $patient;
        });
    }

    /** @return list<int> */
    private function ids(array $result): array
    {
        return array_map(static fn (PhrPatient $patient): int => (int) $patient->id, $result['patients']);
    }

    public function test_an_exact_name_matches_one_patient(): void
    {
        $result = (new PatientNameMatcher)->match($this->patients([['Jane Doe', null], ['John Smith', null]]), 'Jane Doe');

        $this->assertSame(PatientNameMatcher::MATCHED, $result['status']);
        $this->assertSame([1], $this->ids($result));
    }

    public function test_case_spacing_punctuation_and_last_first_order_do_not_matter(): void
    {
        $matcher = new PatientNameMatcher;
        $patients = $this->patients([['Jane Q. Doe', null], ['John Smith', null]]);

        foreach (['JANE Q DOE', '  jane   q.  doe ', 'DOE, JANE Q.', 'Doe,Jane Q'] as $printed) {
            $this->assertSame([1], $this->ids($matcher->match($patients, $printed)), $printed);
        }
        $this->assertSame([2], $this->ids($matcher->match($patients, 'SMITH, JOHN')));
    }

    public function test_accents_are_ignored(): void
    {
        $result = (new PatientNameMatcher)->match($this->patients([['José Núñez', null]]), 'JOSE NUNEZ');

        $this->assertSame(PatientNameMatcher::MATCHED, $result['status']);
    }

    public function test_a_middle_name_may_be_missing_on_either_side(): void
    {
        $matcher = new PatientNameMatcher;

        $this->assertSame([1], $this->ids($matcher->match($this->patients([['Jane Doe', null]]), 'Jane Quincy Doe')));
        $this->assertSame([1], $this->ids($matcher->match($this->patients([['Jane Quincy Doe', null]]), 'DOE, JANE')));
    }

    public function test_an_exact_name_beats_a_name_that_matches_without_its_middle_name(): void
    {
        $patients = $this->patients([['Jane Doe', null], ['Jane Quincy Doe', null]]);

        $this->assertSame([2], $this->ids((new PatientNameMatcher)->match($patients, 'Jane Quincy Doe')));
    }

    public function test_no_match_is_reported_not_guessed(): void
    {
        $result = (new PatientNameMatcher)->match($this->patients([['Jane Doe', null]]), 'Janet Doe');

        $this->assertSame(PatientNameMatcher::NONE, $result['status']);
        $this->assertSame([], $result['patients']);
        $this->assertSame(PatientNameMatcher::NONE, (new PatientNameMatcher)->match($this->patients([['Jane Doe', null]]), '   ')['status']);
    }

    public function test_several_matches_are_ambiguous(): void
    {
        $result = (new PatientNameMatcher)->match($this->patients([['Jane Doe', '1980-01-02'], ['Jane Doe', '2010-05-06']]), 'doe, jane');

        $this->assertSame(PatientNameMatcher::AMBIGUOUS, $result['status']);
        $this->assertSame([1, 2], $this->ids($result));
    }

    public function test_a_date_of_birth_confirms_and_narrows(): void
    {
        $patients = $this->patients([['Jane Doe', '1980-01-02'], ['Jane Doe', '2010-05-06']]);
        $matcher = new PatientNameMatcher;

        $this->assertSame([2], $this->ids($matcher->match($patients, 'Jane Doe', '2010-05-06')));
        $this->assertSame([1], $this->ids($matcher->match($patients, 'Jane Doe', '01/02/1980')));
    }

    public function test_a_date_of_birth_that_contradicts_the_only_name_match_rules_it_out(): void
    {
        $result = (new PatientNameMatcher)->match($this->patients([['Jane Doe', '1980-01-02']]), 'Jane Doe', '1999-09-09');

        $this->assertSame(PatientNameMatcher::NONE, $result['status']);
    }

    public function test_a_patient_without_a_recorded_birth_date_is_not_refuted_by_one(): void
    {
        $result = (new PatientNameMatcher)->match($this->patients([['Jane Doe', null]]), 'Jane Doe', '1999-09-09');

        $this->assertSame(PatientNameMatcher::MATCHED, $result['status']);
    }

    public function test_a_confirmed_birth_date_wins_over_a_patient_who_cannot_confirm(): void
    {
        $patients = $this->patients([['Jane Doe', null], ['Jane Doe', '1980-01-02']]);

        $this->assertSame([2], $this->ids((new PatientNameMatcher)->match($patients, 'Jane Doe', '1980-01-02')));
    }

    public function test_a_birth_date_that_rules_out_every_exact_name_still_allows_a_first_and_last_name_match(): void
    {
        // "Jane Doe" is the exact name but born on another day; "Jane Quincy Doe" shares the
        // first and last name and the printed birth date.
        $patients = $this->patients([['Jane Doe', '1980-01-02'], ['Jane Quincy Doe', '2010-05-06']]);
        $matcher = new PatientNameMatcher;

        $result = $matcher->match($patients, 'Jane Doe', '2010-05-06');

        $this->assertSame(PatientNameMatcher::MATCHED, $result['status']);
        $this->assertSame([2], $this->ids($result));
        // Without a birth date the exact name still wins, and a contradicting one still rules out.
        $this->assertSame([1], $this->ids($matcher->match($patients, 'Jane Doe')));
        $this->assertSame(PatientNameMatcher::NONE, $matcher->match($patients, 'Jane Doe', '1999-09-09')['status']);
    }

    public function test_the_birth_date_filter_applies_to_both_name_tiers_without_changing_ambiguity(): void
    {
        $patients = $this->patients([['Jane Quincy Doe', null], ['Jane Mary Doe', null]]);

        $this->assertSame(PatientNameMatcher::AMBIGUOUS, (new PatientNameMatcher)->match($patients, 'Jane Doe', '2010-05-06')['status']);
    }
}
