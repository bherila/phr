<?php

namespace Tests\Unit\Services\PHR\Fills;

use App\Models\PhrPatient;
use App\Services\PHR\Fills\PatientNameMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class PatientNameMatcherTest extends TestCase
{
    /** @param list<array{0: string, 1: ?string, 2?: list<string>}> $people */
    private function patients(array $people): Collection
    {
        return collect($people)->map(function (array $person, int $index): PhrPatient {
            $patient = new PhrPatient;
            $patient->setRawAttributes([
                'id' => $index + 1,
                'display_name' => $person[0],
                'birth_date' => $person[1],
                'real_names' => isset($person[2]) ? json_encode($person[2]) : null,
            ]);

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

    public function test_explicitly_contradictory_middle_names_do_not_match(): void
    {
        $matcher = new PatientNameMatcher;
        $mary = $this->patients([['Jane Mary Doe', null]]);

        // Nobody else qualifies, yet this must ask rather than attach to the wrong chart.
        $this->assertSame(PatientNameMatcher::NONE, $matcher->match($mary, 'Jane Quincy Doe')['status']);
        $this->assertSame(PatientNameMatcher::NONE, $matcher->match($mary, 'DOE, JANE QUINCY')['status']);
        $this->assertSame(PatientNameMatcher::NONE, $matcher->match($mary, 'Jane Q. Doe')['status'], 'Q is not an initial of Mary');
        $this->assertSame(PatientNameMatcher::NONE, $matcher->match($mary, 'Jane Quincy Doe', '2010-05-06')['status']);
    }

    public function test_a_missing_middle_name_or_a_compatible_initial_still_matches(): void
    {
        $matcher = new PatientNameMatcher;

        $this->assertSame([1], $this->ids($matcher->match($this->patients([['Jane Quincy Doe', null]]), 'Jane Doe')));
        $this->assertSame([1], $this->ids($matcher->match($this->patients([['Jane Doe', null]]), 'Jane Quincy Doe')));
        $this->assertSame([1], $this->ids($matcher->match($this->patients([['Jane Quincy Doe', null]]), 'Jane Q. Doe')));
        $this->assertSame([1], $this->ids($matcher->match($this->patients([['Jane Q Doe', null]]), 'DOE, JANE QUINCY')));
        $this->assertSame([1], $this->ids($matcher->match($this->patients([['Jane Quincy Anne Doe', null]]), 'Jane Quincy Doe')));
    }

    public function test_a_contradictory_middle_name_leaves_the_compatible_patient_the_only_match(): void
    {
        $patients = $this->patients([['Jane Mary Doe', null], ['Jane Quincy Doe', null]]);

        $result = (new PatientNameMatcher)->match($patients, 'Jane Q Doe');

        $this->assertSame(PatientNameMatcher::MATCHED, $result['status']);
        $this->assertSame([2], $this->ids($result));
    }

    public function test_a_nickname_patient_is_found_by_a_listed_real_name(): void
    {
        $patients = $this->patients([['Bean', null, ['Jane Quincy Doe', 'Janie Doe']], ['John Smith', null]]);
        $matcher = new PatientNameMatcher;

        foreach (['JANE QUINCY DOE', 'DOE, JANE Q', 'Jane Doe', 'janie doe', 'Bean'] as $printed) {
            $this->assertSame([1], $this->ids($matcher->match($patients, $printed)), $printed);
        }
        $this->assertSame(PatientNameMatcher::NONE, $matcher->match($patients, 'Jane Mary Doe')['status'], 'a listed name still refuses a contradicting middle name');
    }

    public function test_a_real_name_shared_by_two_patients_is_ambiguous(): void
    {
        $patients = $this->patients([['Bean', null, ['Jane Doe']], ['Sprout', null, ['Jane Doe']]]);

        $this->assertSame(PatientNameMatcher::AMBIGUOUS, (new PatientNameMatcher)->match($patients, 'Jane Doe')['status']);
    }

    public function test_a_birth_date_is_enough_when_only_one_patient_has_it(): void
    {
        $patients = $this->patients([['Bean', '1980-01-02'], ['Sprout', '2010-05-06']]);

        $result = (new PatientNameMatcher)->match($patients, 'JANE QUINCY DOE', '01/02/1980');

        $this->assertSame(PatientNameMatcher::MATCHED, $result['status']);
        $this->assertSame([1], $this->ids($result));
    }

    public function test_a_shared_birth_date_is_not_enough_and_the_name_decides(): void
    {
        $patients = $this->patients([['Bean', '1980-01-02', ['Jane Doe']], ['Sprout', '1980-01-02', ['John Doe']], ['Other', '2010-05-06']]);
        $matcher = new PatientNameMatcher;

        $this->assertSame([2], $this->ids($matcher->match($patients, 'John Doe', '1980-01-02')));

        $unknown = $matcher->match($patients, 'Printed Stranger', '1980-01-02');
        $this->assertSame(PatientNameMatcher::AMBIGUOUS, $unknown['status']);
        $this->assertSame([1, 2], $this->ids($unknown));
    }

    public function test_a_name_that_belongs_to_another_patient_contradicts_the_birth_date(): void
    {
        foreach (['1999-09-09', null] as $otherBirthDate) {
            $patients = $this->patients([['Bean', '1980-01-02'], ['Jane Doe', $otherBirthDate]]);

            $result = (new PatientNameMatcher)->match($patients, 'Jane Doe', '1980-01-02');

            $this->assertSame(PatientNameMatcher::AMBIGUOUS, $result['status']);
            $this->assertSame([1, 2], $this->ids($result));
        }
    }

    public function test_a_patient_the_caller_cannot_see_blocks_a_match_on_the_birth_date_alone_without_being_named(): void
    {
        $everyone = $this->patients([['Bean', '1980-01-02'], ['Sprout', '1980-01-02'], ['Jane Doe', '1999-09-09']]);
        $granted = $everyone->take(1)->values();
        $matcher = new PatientNameMatcher;

        foreach (['Printed Stranger', 'Jane Doe'] as $printed) {
            $result = $matcher->match($granted, $printed, '1980-01-02', $everyone);
            $this->assertSame(PatientNameMatcher::NONE, $result['status'], $printed);
            $this->assertSame([], $result['patients'], $printed);
        }

        // Without a rival on the login, the same grant matches; and a name that agrees is not a birth-date-only match.
        $this->assertSame([1], $this->ids($matcher->match($granted, 'Printed Stranger', '1980-01-02', $granted)));
        $this->assertSame([1], $this->ids($matcher->match($granted, 'Bean', '1980-01-02', $everyone)));
    }

    public function test_a_partial_name_with_the_birth_date_does_not_beat_an_exact_name_that_cannot_confirm_it(): void
    {
        // Patient 1 has the exact name and no recorded birth date, so nothing rules them out.
        // Patient 2 only matches once the middle name is dropped. Neither may win silently.
        $patients = $this->patients([['Jane Quincy Doe', null], ['Jane Doe', '1980-01-02']]);
        $matcher = new PatientNameMatcher;

        $result = $matcher->match($patients, 'Jane Quincy Doe', '1980-01-02');
        $this->assertSame(PatientNameMatcher::AMBIGUOUS, $result['status']);
        $this->assertSame([1, 2], $this->ids($result));

        // The same exact name out of this request's view blocks it too, without being named.
        $hidden = $matcher->match($patients->slice(1)->values(), 'Jane Quincy Doe', '1980-01-02', $patients);
        $this->assertSame(PatientNameMatcher::NONE, $hidden['status']);
        $this->assertSame([], $hidden['patients']);

        // An exact name on the patient with the birth date is not in doubt, and a recorded
        // birth date that disagrees still rules the other patient out.
        $this->assertSame([2], $this->ids($matcher->match($patients, 'Jane Doe', '1980-01-02')));
        $refuted = $this->patients([['Jane Quincy Doe', '1999-09-09'], ['Jane Doe', '1980-01-02']]);
        $this->assertSame([2], $this->ids($matcher->match($refuted, 'Jane Quincy Doe', '1980-01-02')));
    }

    public function test_a_listed_real_name_is_not_a_mismatch_but_someone_elses_name_still_is(): void
    {
        $patient = $this->patients([['Bean', '1980-01-02', ['Jane Quincy Doe']]])->first();
        $matcher = new PatientNameMatcher;

        $this->assertFalse($matcher->mismatches($patient, 'JANE Q DOE'));
        $this->assertTrue($matcher->mismatches($patient, 'John Smith'));
    }
}
