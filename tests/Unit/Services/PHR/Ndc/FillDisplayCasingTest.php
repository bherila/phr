<?php

namespace Tests\Unit\Services\PHR\Ndc;

use App\Services\PHR\Ndc\FillDisplayCasing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FillDisplayCasingTest extends TestCase
{
    /** @return array<string, array{?string, ?string}> */
    public static function names(): array
    {
        return [
            'pharmacy chain and number' => ['CVS PHARMACY #1234', 'CVS Pharmacy #1234'],
            'prescriber with credential' => ['SMITH, JOHN A MD', 'Smith, John A MD'],
            'PA-C' => ['JONES, MARY PA-C', 'Jones, Mary PA-C'],
            'NP and DO' => ['LEE, ANN NP', 'Lee, Ann NP'],
            'DO' => ['PATEL, RAJ DO', 'Patel, Raj DO'],
            'RPh' => ['BROWN, TIM RPH', 'Brown, Tim RPh'],
            'PharmD' => ['KIM, SUE PHARMD', 'Kim, Sue PharmD'],
            'corporate suffixes' => ['MAIN STREET DRUG INC.', 'Main Street Drug Inc.'],
            'LLC and USA' => ['ACME RX USA LLC', 'Acme Rx USA LLC'],
            'apostrophe name' => ["O'BRIEN, PAT MD", "O'Brien, Pat MD"],
            'Mc name' => ['MCDONALD, ROY MD', 'McDonald, Roy MD'],
            'possessive' => ["ST. MARY'S PHARMACY", "St. Mary's Pharmacy"],
            'slash-separated chain' => ['CVS/PHARMACY #55', 'CVS/Pharmacy #55'],
            'chain initialism' => ['HEB PHARMACY', 'HEB Pharmacy'],
            'mixed case is left as written' => ['Smith, john a md', 'Smith, john a md'],
            'proper mixed case is left as written' => ['CVS Pharmacy #1234', 'CVS Pharmacy #1234'],
            'null' => [null, null],
            'blank' => ['  ', null],
        ];
    }

    #[DataProvider('names')]
    public function test_pharmacy_and_prescriber_names_get_proper_name_casing_when_all_caps(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, FillDisplayCasing::pharmacyName($raw));
        $this->assertSame($expected, FillDisplayCasing::prescriberName($raw));
    }

    public function test_locations_keep_state_codes_uppercase(): void
    {
        $this->assertSame('Springfield, IL', FillDisplayCasing::pharmacyLocation('SPRINGFIELD, IL'));
        $this->assertSame('Portland, OR 97201', FillDisplayCasing::pharmacyLocation('PORTLAND, OR 97201'));
        $this->assertSame('Springfield, Il', FillDisplayCasing::pharmacyLocation('Springfield, Il'));
    }

    /** @return array<string, array{string, string}> */
    public static function directions(): array
    {
        return [
            'sentence case' => ['TAKE 1 TABLET BY MOUTH DAILY', 'Take 1 tablet by mouth daily'],
            'abbreviations stay' => ['TAKE 1 TABLET PO BID PRN PAIN', 'Take 1 tablet PO BID PRN pain'],
            'TID' => ['TAKE 2 CAPSULES PO TID', 'Take 2 capsules PO TID'],
            'units' => ['TAKE 5 ML (100 MG) BY MOUTH', 'Take 5 mL (100 mg) by mouth'],
            'units without space' => ['TAKE 10MG AT BEDTIME', 'Take 10mg at bedtime'],
            'every n hours' => ['TAKE 1 TABLET Q4H AS NEEDED', 'Take 1 tablet Q4H as needed'],
            'second sentence' => ['TAKE 1 TABLET DAILY. DO NOT CRUSH', 'Take 1 tablet daily. Do not crush'],
            'mixed case is left as written' => ['Take 1 tablet BY MOUTH daily', 'Take 1 tablet BY MOUTH daily'],
            'lowercase is left as written' => ['take 1 tablet daily', 'take 1 tablet daily'],
        ];
    }

    #[DataProvider('directions')]
    public function test_directions_are_sentence_cased_when_all_caps(string $raw, string $expected): void
    {
        $this->assertSame($expected, FillDisplayCasing::directions($raw));
    }

    /** @return array<string, array{string, string}> */
    public static function drugs(): array
    {
        return [
            'all caps is lowercase' => ['ATORVASTATIN CALCIUM TABLETS', 'atorvastatin calcium tablets'],
            'units keep their case' => ['AMOXICILLIN 250MG/5ML SUSPENSION', 'amoxicillin 250 mg/5 mL suspension'],
            'IU and mEq' => ['VITAMIN D 1000 IU', 'vitamin d 1000 IU'],
            'brand title case is kept' => ['Lipitor 10 mg', 'Lipitor 10 mg'],
        ];
    }

    #[DataProvider('drugs')]
    public function test_drug_as_printed_is_lowercase_with_units_in_standard_case(string $raw, string $expected): void
    {
        $this->assertSame($expected, FillDisplayCasing::drugAsPrinted($raw));
    }
}
