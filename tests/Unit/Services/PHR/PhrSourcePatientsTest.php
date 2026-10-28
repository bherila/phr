<?php

namespace Tests\Unit\Services\PHR;

use App\Services\PHR\Import\PhrSourcePatients;
use PHPUnit\Framework\TestCase;

class PhrSourcePatientsTest extends TestCase
{
    public function test_different_birth_dates_are_different_people_across_formats(): void
    {
        $patients = new PhrSourcePatients;
        $patients->addCcda($this->ccda('19840309'));
        self::assertFalse($patients->multiple());

        $patients->addFhirBundle($this->bundle(['1984-03-09']));
        self::assertFalse($patients->multiple(), 'the same person in FHIR and C-CDA');

        $patients->addFhirBundle($this->bundle(['2015-07-01']));
        self::assertTrue($patients->multiple(), 'a dependent');
    }

    public function test_a_partial_birth_date_matches_the_full_one_it_begins(): void
    {
        $patients = new PhrSourcePatients;
        $patients->addFhirBundle($this->bundle(['1984', '1984-03', '1984-03-09']));
        self::assertFalse($patients->multiple());

        $patients->addCcda($this->ccda('1985'));
        self::assertTrue($patients->multiple());
    }

    public function test_records_without_a_birth_date_name_no_one(): void
    {
        $patients = new PhrSourcePatients;
        $patients->addFhirBundle(['resourceType' => 'Bundle', 'entry' => [['resource' => ['resourceType' => 'Patient', 'id' => 'a']], ['resource' => ['resourceType' => 'Condition']]]]);
        $patients->addCcda('<ClinicalDocument xmlns="urn:hl7-org:v3"><recordTarget><patientRole><patient><birthTime nullFlavor="UNK"/></patient></patientRole></recordTarget></ClinicalDocument>');
        $patients->addCcda('not xml');

        self::assertFalse($patients->multiple());
    }

    /**
     * @param  list<string>  $birthDates
     * @return array<string, mixed>
     */
    private function bundle(array $birthDates): array
    {
        return ['resourceType' => 'Bundle', 'entry' => array_map(
            static fn (string $date): array => ['resource' => ['resourceType' => 'Patient', 'id' => 'p-'.$date, 'birthDate' => $date]],
            $birthDates,
        )];
    }

    private function ccda(string $birthTime): string
    {
        return '<ClinicalDocument xmlns="urn:hl7-org:v3"><recordTarget><patientRole><patient><birthTime value="'.$birthTime.'"/></patient></patientRole></recordTarget></ClinicalDocument>';
    }
}
