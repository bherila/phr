<?php

namespace Tests\Unit\Services\PHR\Ndc;

use App\Services\PHR\Ndc\NdcCasing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NdcCasingTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function lowercase(): array
    {
        return [
            'substance' => ['ATORVASTATIN CALCIUM', 'atorvastatin calcium'],
            'dosage form' => ['TABLET, FILM COATED', 'tablet, film coated'],
            'route' => ['ORAL', 'oral'],
            'mixed case is kept as given' => ['Cyclosporine', 'Cyclosporine'],
            'lowercase is kept as given' => ['tablet', 'tablet'],
        ];
    }

    #[DataProvider('lowercase')]
    public function test_names_forms_and_routes_are_lowercased_only_when_all_caps(string $raw, string $expected): void
    {
        $this->assertSame($expected, NdcCasing::lowercase($raw));
    }

    /** @return array<string, array{string, string}> */
    public static function brands(): array
    {
        return [
            'title case' => ['LIPITOR', 'Lipitor'],
            'two words' => ['SENSODYNE REPAIR', 'Sensodyne Repair'],
            'a release suffix stays uppercase' => ['WELLBUTRIN XL', 'Wellbutrin XL'],
            'a product suffix stays uppercase' => ['ADVIL PM', 'Advil PM'],
            'mixed case is kept as given' => ['Lipitor', 'Lipitor'],
            'odd mixed case is kept as given' => ['pHisoHex', 'pHisoHex'],
        ];
    }

    #[DataProvider('brands')]
    public function test_brand_names_are_title_cased_only_when_all_caps(string $raw, string $expected): void
    {
        $this->assertSame($expected, NdcCasing::brand($raw));
    }

    /** @return array<string, array{string, string}> */
    public static function labelers(): array
    {
        return [
            'Inc.' => ['REMEDYREPACK INC.', 'Remedyrepack Inc.'],
            'LLC' => ['BRYANT RANCH PREPACK LLC', 'Bryant Ranch Prepack LLC'],
            'comma and LLC' => ['NORTHWIND HEALTH COMPANY, LLC', 'Northwind Health Company, LLC'],
            'LP and Rx' => ['PROFICIENT RX LP', 'Proficient Rx LP'],
            'USA and Inc.' => ['TEVA PHARMACEUTICALS USA, INC.', 'Teva Pharmaceuticals USA, Inc.'],
            'L.P.' => ['MYLAN PHARMACEUTICALS L.P.', 'Mylan Pharmaceuticals L.P.'],
            'connectors stay lowercase' => ['ELI LILLY AND COMPANY', 'Eli Lilly and Company'],
            'hyphenated initials' => ['A-S MEDICATION SOLUTIONS', 'A-S Medication Solutions'],
            'possessive' => ["ST. MARY'S MEDICAL PARK PHARMACY", "St. Mary's Medical Park Pharmacy"],
            'parenthesised acronym' => ['LUPIN (USA) LTD', 'Lupin (USA) Ltd.'],
            'mixed case is kept as given' => ['NuCare Pharmaceuticals,Inc.', 'NuCare Pharmaceuticals,Inc.'],
            'already title case' => ['Viatris Specialty LLC', 'Viatris Specialty LLC'],
        ];
    }

    #[DataProvider('labelers')]
    public function test_labelers_are_title_cased_keeping_suffixes_and_acronyms(string $raw, string $expected): void
    {
        $this->assertSame($expected, NdcCasing::labeler($raw));
    }

    /** @return array<string, array{string, string}> */
    public static function measures(): array
    {
        return [
            'a package description keeps its NDC and the lowercase connector' => ['30 TABLET in 1 BOTTLE (70518-1906-1)', '30 tablet in 1 bottle (70518-1906-1)'],
            'film coated' => ['90 TABLET, FILM COATED in 1 BOTTLE (58151-155-77)', '90 tablet, film coated in 1 bottle (58151-155-77)'],
            'a unit already in standard form survives' => ['1 VIAL in 1 CARTON (0002-0800-01)  / 10 mL in 1 VIAL', '1 vial in 1 carton (0002-0800-01) / 10 mL in 1 vial'],
            'strength units' => ['100 MG/ML', '100 mg/mL'],
            'mcg' => ['25 MCG/1', '25 mcg/1'],
            'grams' => ['5 G in 1 TUBE', '5 g in 1 tube'],
            'international units' => ['500 IU/1', '500 IU/1'],
            'percent' => ['2.5 % in 1 TUBE', '2.5 % in 1 tube'],
            'an ordinary strength is untouched' => ['10 mg/1', '10 mg/1'],
            'a mixed-case description is untouched' => ['30 Tablet in 1 Bottle', '30 Tablet in 1 Bottle'],
            'UCUM IU' => ['100 [iU]/mL', '100 IU/mL'],
            'UCUM IU uppercase' => ['100 [IU]/mL', '100 IU/mL'],
            'UCUM USP unit' => ["10 [USP'U]/mL", '10 USP units/mL'],
            'UCUM USP unit in an all-caps description keeps the rest lowercased' => ["10 [USP'U] in 1 VIAL (1-2-3)", '10 USP units in 1 vial (1-2-3)'],
            'homeopathic X' => ['6 [hp_X]/1', '6 X/1'],
            'homeopathic C' => ['30 [hp_C]/1', '30 C/1'],
            'other bracket unit is kept' => ["1000 [arb'U]/mL", "1000 [arb'U]/mL"],
            'meq lowercase' => ['20 meq/15mL', '20 mEq/15 mL'],
            'MEQ' => ['20 MEQ/15ML', '20 mEq/15 mL'],
            'mEQ' => ['20 mEQ/15 mL', '20 mEq/15 mL'],
            'ml lowercase' => ['5 ml', '5 mL'],
            'number-unit spacing' => ['600 mg/5mL', '600 mg/5 mL'],
            'decimal spacing' => ['0.5mg/1', '0.5 mg/1'],
            'denominator 1 kept' => ['10 mg/1', '10 mg/1'],
            'percent spacing' => ['2.5% in 1 TUBE', '2.5 % in 1 tube'],
            'units spacing' => ['100units/mL', '100 units/mL'],
            'package description keeps mL' => ['125 mL in 1 BOTTLE (68071-2852-5)', '125 mL in 1 bottle (68071-2852-5)'],
            'nested package description' => ['5 SYRINGE in 1 CARTON (0002-8798-59) / 3 mL in 1 SYRINGE (0002-8798-01)', '5 syringe in 1 carton (0002-8798-59) / 3 mL in 1 syringe (0002-8798-01)'],
            'words are untouched' => ['Gelatin Mild Milligram Lmg 5 Meqs', 'Gelatin Mild Milligram Lmg 5 Meqs'],
            'word containing ml is untouched' => ['5 mlx html 10gram', '5 mlx html 10gram'],
            'NDC digits in parentheses untouched' => ['1 Bottle (10-20-3mg)', '1 Bottle (10-20-3mg)'],
        ];
    }

    #[DataProvider('measures')]
    public function test_strengths_and_package_descriptions_keep_units_and_codes(string $raw, string $expected): void
    {
        $this->assertSame($expected, NdcCasing::measureText($raw));
    }

    public function test_blank_values_become_null(): void
    {
        $this->assertNull(NdcCasing::lowercase('  '));
        $this->assertNull(NdcCasing::brand(null));
        $this->assertNull(NdcCasing::labeler(''));
        $this->assertNull(NdcCasing::measureText(' '));
    }
}
