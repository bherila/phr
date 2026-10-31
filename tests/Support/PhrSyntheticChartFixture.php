<?php

namespace Tests\Support;

use App\Models\PhrCondition;
use App\Models\PhrLabResult;
use App\Models\PhrPatient;

/**
 * A synthetic patient whose chart looks like real data, for layout checks and screenshots.
 *
 * - "Synthetic Respiratory and Chemistry Panel": 20 results mixing text values (Not Detected,
 *   Negative, Nonreactive), numeric values with units and ranges, and high and low flags.
 * - "Synthetic Extended Lipid and Inflammatory Marker Panel": long analyte names.
 * - Conditions with long names and ICD-10 codes, one of them resolved.
 * - A few records pending review (two lab results and a condition).
 *
 * Every value is invented. Used by PlaywrightSeeder; call seed() from any test that needs it.
 */
final class PhrSyntheticChartFixture
{
    public const PATIENT_NAME = 'Synthetic Chart Patient';

    public const RESPIRATORY_PANEL = 'Synthetic Respiratory and Chemistry Panel';

    public const LIPID_PANEL = 'Synthetic Extended Lipid and Inflammatory Marker Panel';

    public static function seed(int $ownerUserId, string $displayName = self::PATIENT_NAME): PhrPatient
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $ownerUserId,
            'display_name' => $displayName,
            'relationship' => 'self',
        ]);

        foreach (self::respiratoryPanel() as $index => $row) {
            self::labResult($patient, self::RESPIRATORY_PANEL, '2026-03-14 08:30:00', $row, $index < 2 ? 'pending_review' : 'confirmed');
        }
        foreach (self::lipidPanel() as $row) {
            self::labResult($patient, self::LIPID_PANEL, '2026-02-02 07:45:00', $row, 'confirmed');
        }

        foreach (self::conditions() as $condition) {
            PhrCondition::query()->create([
                'patient_id' => $patient->id,
                'user_id' => $ownerUserId,
                'clinical_status' => 'active',
                'verification_status' => 'confirmed',
                'review_status' => 'confirmed',
                ...$condition,
            ]);
        }

        return $patient;
    }

    /**
     * A row is analyte, value, unit, range low (or the range as text), range high, flag.
     *
     * @param  array{0: string, 1: string, 2: ?string, 3: ?string, 4: ?string, 5: ?string}  $row
     */
    private static function labResult(PhrPatient $patient, string $panel, string $collectedAt, array $row, string $reviewStatus): void
    {
        [$analyte, $value, $unit, $min, $max, $flag] = $row;
        $numeric = is_numeric($value);
        $numericRange = is_numeric($min) && is_numeric($max);

        PhrLabResult::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'review_status' => $reviewStatus,
            'test_name' => $panel,
            'collection_datetime' => $collectedAt,
            'result_datetime' => $collectedAt,
            'result_status' => 'final',
            'resulting_lab' => 'Synthetic Reference Laboratory',
            'analyte' => $analyte,
            'value' => $value,
            'value_numeric' => $numeric ? $value : null,
            'unit' => $unit,
            'range_min' => $numericRange ? $min : null,
            'range_max' => $numericRange ? $max : null,
            'range_unit' => $numericRange ? $unit : null,
            'reference_range_text' => $numericRange ? null : $min,
            'abnormal_flag' => $flag ?? 'N',
        ]);
    }

    /** @return list<array{0: string, 1: string, 2: ?string, 3: ?string, 4: ?string, 5: ?string}> */
    private static function respiratoryPanel(): array
    {
        return [
            ['Influenza A RNA', 'Not Detected', null, 'Not Detected', null, null],
            ['Influenza B RNA', 'Not Detected', null, 'Not Detected', null, null],
            ['SARS-CoV-2 RNA', 'Not Detected', null, 'Not Detected', null, null],
            ['RSV RNA', 'Detected', null, 'Not Detected', null, 'A'],
            ['Strep A Antigen', 'Negative', null, 'Negative', null, null],
            ['HIV-1/2 Antigen and Antibody', 'Nonreactive', null, 'Nonreactive', null, null],
            ['Hepatitis C Antibody', 'Nonreactive', null, 'Nonreactive', null, null],
            ['Glucose', '112', 'mg/dL', '70', '99', 'H'],
            ['Sodium', '139', 'mmol/L', '135', '145', null],
            ['Potassium', '3.3', 'mmol/L', '3.5', '5.1', 'L'],
            ['Chloride', '101', 'mmol/L', '98', '107', null],
            ['Carbon Dioxide', '24', 'mmol/L', '22', '29', null],
            ['Blood Urea Nitrogen', '15', 'mg/dL', '7', '20', null],
            ['Creatinine', '0.92', 'mg/dL', '0.6', '1.3', null],
            ['Calcium', '9.4', 'mg/dL', '8.5', '10.2', null],
            ['White Blood Cell Count', '11.8', '10*3/uL', '4.0', '10.5', 'H'],
            ['Hemoglobin', '13.9', 'g/dL', '12.0', '16.0', null],
            ['Platelet Count', '142', '10*3/uL', '150', '400', 'L'],
            ['C-Reactive Protein', '4.1', 'mg/L', '0', '5', null],
            ['Procalcitonin', '0.04', 'ng/mL', '0', '0.1', null],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: ?string, 3: ?string, 4: ?string, 5: ?string}> */
    private static function lipidPanel(): array
    {
        return [
            ['Low-Density Lipoprotein Cholesterol, Calculated by Friedewald Equation', '131', 'mg/dL', '0', '99', 'H'],
            ['High-Density Lipoprotein Cholesterol, Direct Measurement', '52', 'mg/dL', '40', '100', null],
            ['Non-High-Density Lipoprotein Cholesterol (Total minus HDL)', '158', 'mg/dL', '0', '129', 'H'],
            ['High-Sensitivity C-Reactive Protein, Cardiovascular Risk Assessment', '1.2', 'mg/L', '0', '3', null],
            ['Lipoprotein(a) Mass Concentration', 'Below reportable range', null, 'Less than 30 mg/dL', null, null],
        ];
    }

    /** @return list<array<string, string|null>> */
    private static function conditions(): array
    {
        return [
            ['name' => 'Type 2 diabetes mellitus without complications, managed with diet and oral agents', 'icd10_code' => 'E11.9', 'onset_date' => '2019-04-01'],
            ['name' => 'Mild intermittent asthma, uncomplicated, with seasonal exacerbations', 'icd10_code' => 'J45.20', 'onset_date' => '2012-09-15'],
            ['name' => 'Seasonal allergic rhinitis due to pollen', 'icd10_code' => 'J30.1', 'onset_date' => '2010-05-01', 'review_status' => 'pending_review'],
            ['name' => 'Essential (primary) hypertension', 'icd10_code' => 'I10', 'onset_date' => '2021-11-20', 'severity' => 'moderate'],
            ['name' => 'Acute maxillary sinusitis, unspecified, following upper respiratory infection', 'icd10_code' => 'J01.00', 'onset_date' => '2025-12-01', 'abated_date' => '2026-01-10', 'clinical_status' => 'resolved'],
        ];
    }
}
