<?php

namespace Tests\Support;

use App\Models\PhrPatient;
use App\Services\PHR\Export\PhrCcdaExporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class PhrCcdaSyntheticDocument
{
    public static function xml(): string
    {
        Carbon::setTestNow('2026-01-02 03:04:05 UTC');
        Str::createUuidsUsingSequence([
            Uuid::fromString('00000000-0000-4000-8000-000000000001'),
        ]);

        try {
            $patient = new PhrPatient;
            $patient->setRawAttributes([
                'id' => 1,
                'display_name' => 'Synthetic Patient',
                'birth_date' => '2000-01-01',
                'sex_at_birth' => 'female',
            ], true);

            $data = [
                'patient' => $patient,
                'lab_results' => collect([
                    (object) [
                        'id' => 1,
                        'patient_id' => 1,
                        'result_datetime' => Carbon::parse('2026-01-01 08:30:00 UTC'),
                        'collection_datetime' => null,
                        'result_status' => 'final',
                        'analyte' => 'Synthetic analyte',
                        'test_name' => 'Synthetic panel',
                        'value' => '1',
                        'value_numeric' => null,
                        'unit' => 'unit',
                        'reference_range_text' => null,
                        'range_min' => '0',
                        'range_max' => '2',
                        'range_min_inclusive' => true,
                        'range_max_inclusive' => false,
                        'range_unit' => 'unit',
                        'abnormal_flag' => 'H',
                    ],
                    (object) [
                        'id' => 2,
                        'patient_id' => 1,
                        'result_datetime' => Carbon::parse('2026-01-01 08:30:00 UTC'),
                        'collection_datetime' => null,
                        'result_status' => 'final',
                        'analyte' => 'Synthetic comparison',
                        'test_name' => 'Synthetic panel',
                        'value' => '<5',
                        'value_numeric' => null,
                        'unit' => 'mg/dL',
                        'reference_range_text' => null,
                        'range_min' => null,
                        'range_max' => '200.0000000000',
                        'range_min_inclusive' => null,
                        'range_max_inclusive' => false,
                        'range_unit' => 'mg/dL',
                        'abnormal_flag' => 'N',
                    ],
                    (object) [
                        'id' => 3,
                        'patient_id' => 1,
                        'result_datetime' => Carbon::parse('2026-01-01 08:30:00 UTC'),
                        'collection_datetime' => null,
                        'result_status' => 'final',
                        'analyte' => 'Synthetic count',
                        'test_name' => 'Synthetic panel',
                        'value' => '5.2',
                        'value_numeric' => null,
                        'unit' => 'x10E3/uL',
                        'reference_range_text' => null,
                        'range_min' => '4',
                        'range_max' => '11',
                        'range_min_inclusive' => null,
                        'range_max_inclusive' => null,
                        'range_unit' => 'thousand per uL',
                        'abnormal_flag' => 'L',
                    ],
                    (object) [
                        'id' => 4,
                        'patient_id' => 1,
                        'result_datetime' => null,
                        'collection_datetime' => null,
                        'result_status' => null,
                        'analyte' => 'Synthetic qualitative',
                        'test_name' => null,
                        'value' => 'Negative',
                        'value_numeric' => null,
                        'unit' => null,
                        'reference_range_text' => 'Negative',
                        'range_min' => null,
                        'range_max' => null,
                        'range_min_inclusive' => null,
                        'range_max_inclusive' => null,
                        'range_unit' => null,
                        'abnormal_flag' => 'Abnormal',
                    ],
                ]),
                'vitals' => collect([(object) [
                    'observed_at' => null,
                    'vital_date' => null,
                    'vital_name' => 'Synthetic vital',
                    'vital_value' => '1',
                    'value_numeric' => null,
                    'unit' => 'unit',
                ]]),
                'conditions' => collect([(object) [
                    'name' => 'Synthetic condition',
                    'icd10_code' => null,
                    'clinical_status' => 'active',
                    'onset_date' => null,
                ]]),
                'medications' => collect([(object) [
                    'name' => 'Synthetic medication',
                    'dose' => '1',
                    'dose_unit' => 'unit',
                    'frequency' => 'daily',
                    'status' => 'active',
                ]]),
                'allergies' => collect([(object) [
                    'substance' => 'Synthetic substance',
                    'reaction' => 'Synthetic reaction',
                    'severity' => 'mild',
                ]]),
                'procedures' => collect([(object) [
                    'name' => 'Synthetic procedure',
                    'performed_at' => null,
                    'performed_on' => null,
                    'status' => 'completed',
                ]]),
                'immunizations' => collect([(object) [
                    'vaccine_name' => 'Synthetic vaccine',
                    'administered_on' => null,
                    'lot_number' => null,
                ]]),
                'office_visits' => collect([(object) [
                    'visit_started_at' => null,
                    'visit_date' => null,
                    'visit_type' => 'Synthetic visit',
                    'provider_name' => null,
                    'chief_complaint' => null,
                    'assessment' => null,
                ]]),
            ];

            foreach (['portal_messages', 'negative_assertions', 'dicom_studies', 'documents'] as $key) {
                $data[$key] = collect();
            }

            return (new PhrCcdaExporter)->documentXml($data);
        } finally {
            Str::createUuidsNormally();
            Carbon::setTestNow();
        }
    }
}
