<?php

namespace Tests\Unit\Services\PHR;

use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrDicomStudy;
use App\Models\PhrDocument;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrNegativeAssertion;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientVital;
use App\Models\PhrPortalMessage;
use App\Models\PhrProcedure;
use App\Services\PHR\Export\PhrPdfSummaryRenderer;
use Illuminate\Support\Carbon;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class PhrPdfSummaryRendererTest extends TestCase
{
    public function test_render_returns_pdf_bytes_with_expected_sections_and_empty_states(): void
    {
        $this->travelTo(Carbon::parse('2026-02-03 04:05:06'));

        $data = $this->baseExportData();
        $data['lab_results'] = collect([
            new PhrLabResult([
                'result_datetime' => '2026-01-15 10:00:00',
                'analyte' => 'Hemoglobin',
                'value' => '13.2',
                'unit' => 'g/dL',
                'abnormal_flag' => 'normal',
            ]),
        ]);
        $data['vitals'] = collect([
            new PhrPatientVital([
                'observed_at' => '2026-01-15 10:05:00',
                'vital_name' => 'Blood Pressure',
                'vital_value' => '120/80',
                'unit' => 'mmHg',
            ]),
        ]);
        $data['conditions'] = collect([
            new PhrCondition([
                'name' => 'Hypertension',
                'icd10_code' => 'I10',
                'clinical_status' => 'active',
            ]),
        ]);
        $data['medications'] = collect([
            new PhrMedication([
                'name' => 'Lisinopril',
                'dose' => '10',
                'dose_unit' => 'mg',
                'frequency' => 'daily',
                'status' => 'active',
            ]),
        ]);
        $data['procedures'] = collect([
            new PhrProcedure([
                'performed_on' => '2026-01-10',
                'name' => 'Annual exam',
                'status' => 'completed',
            ]),
        ]);
        $data['immunizations'] = collect([
            new PhrImmunization([
                'administered_on' => '2025-10-01',
                'vaccine_name' => 'Influenza',
                'lot_number' => 'LOT123',
            ]),
        ]);
        $data['allergies'] = collect([
            new PhrAllergy([
                'substance' => 'Peanuts',
                'reaction' => 'Hives',
                'severity' => 'moderate',
            ]),
        ]);
        $data['office_visits'] = collect([
            new PhrOfficeVisit([
                'visit_date' => '2026-01-20',
                'provider_name' => 'Primary Care',
                'chief_complaint' => 'Follow up',
                'assessment' => 'Stable',
            ]),
        ]);
        $data['portal_messages'] = collect([
            new PhrPortalMessage([
                'message_at' => '2026-01-21 09:15:00',
                'direction' => 'outbound',
                'subject' => 'Medication question',
                'sender_name' => 'Test Patient',
                'recipient_name' => 'Care Team',
                'summary' => 'Asked whether to continue medication.',
                'clinical_relevance' => 'Medication reconciliation',
            ]),
        ]);
        $data['negative_assertions'] = collect([
            new PhrNegativeAssertion([
                'observed_on' => '2026-01-21',
                'assertion_type' => 'allergy_absence',
                'scope' => 'allergies',
                'statement' => 'No known allergies documented.',
                'notes' => 'Explicit source statement.',
            ]),
        ]);
        $data['dicom_studies'] = collect([
            new PhrDicomStudy([
                'study_date' => '2026-01-22',
                'description' => 'Chest X-Ray',
                'modalities' => 'CR',
            ]),
        ]);

        $pdf = (new PhrPdfSummaryRenderer)->render($data);
        $text = $this->extractPdfText($pdf);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('Personal Health Record Summary', $text);
        $this->assertStringContainsString('Generated 2026-02-03 04:05:06', $text);

        foreach (['Patient', 'Labs', 'Vitals', 'Conditions', 'Medications', 'Procedures', 'Immunizations', 'Allergies', 'Office Visits', 'Portal Messages', 'Negative Assertions', 'Imaging', 'Documents'] as $section) {
            $this->assertStringContainsString($section, $text);
        }

        foreach ([
            'Name | Test Patient',
            'Birth Date | 1980-01-02',
            '2026-01-15 | Hemoglobin | 13.2 g/dL | normal',
            '2026-01-15 | Blood Pressure | 120/80 mmHg',
            'Hypertension | I10 | active',
            'Lisinopril | 10 mg daily | active',
            '2026-01-10 | Annual exam | completed',
            '2025-10-01 | Influenza | LOT123',
            'Peanuts | Hives | moderate',
            '2026-01-20 | Primary Care | Follow up | Stable',
            '2026-01-21 | outbound | Medication question | Test Patient -> Care Team | Asked whether to continue medication. | Medication reconciliation',
            '2026-01-21 | allergy_absence | allergies | No known allergies documented. | Explicit source statement.',
            '2026-01-22 | Chest X-Ray | CR',
            'No records.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    public function test_render_maps_document_rows_with_title_and_filename_fallback(): void
    {
        $data = $this->baseExportData();
        $data['documents'] = collect([
            new PhrDocument([
                'title' => 'Résumé | Pathology',
                'document_type' => 'lab_report',
                'original_filename' => 'ignored-title.pdf',
                'summary' => 'Café result | stable',
            ]),
            new PhrDocument([
                'title' => null,
                'document_type' => 'other',
                'original_filename' => 'fallback-report.pdf',
                'summary' => 'Original filename fallback',
            ]),
        ]);

        $pdf = (new PhrPdfSummaryRenderer)->render($data);
        $text = $this->extractPdfText($pdf);

        $this->assertStringContainsString('Résumé | Pathology | lab_report | Café result | stable', $text);
        $this->assertStringContainsString('fallback-report.pdf | other | Original filename fallback', $text);
        $this->assertStringNotContainsString('ignored-title.pdf', $text);
    }

    public function test_render_caps_each_section_at_sixty_rows_and_includes_overflow_note(): void
    {
        $data = $this->baseExportData();
        $data['lab_results'] = collect(range(1, 61))->map(fn (int $number): PhrLabResult => new PhrLabResult([
            'result_datetime' => '2026-01-15 10:00:00',
            'analyte' => sprintf('Cap Test %02d', $number),
            'value' => (string) $number,
        ]));

        $pdf = (new PhrPdfSummaryRenderer)->render($data);
        $text = $this->extractPdfText($pdf);

        $this->assertStringContainsString('Cap Test 01', $text);
        $this->assertStringContainsString('Cap Test 60', $text);
        $this->assertStringNotContainsString('Cap Test 61', $text);
        $this->assertStringContainsString('Additional records are included in FHIR/CCDA exports.', $text);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseExportData(): array
    {
        $patient = new PhrPatient([
            'display_name' => 'Test Patient',
            'relationship' => 'self',
            'birth_date' => '1980-01-02',
            'sex_at_birth' => 'female',
        ]);
        $patient->id = 42;

        return [
            'patient' => $patient,
            'lab_results' => collect(),
            'vitals' => collect(),
            'conditions' => collect(),
            'medications' => collect(),
            'procedures' => collect(),
            'immunizations' => collect(),
            'allergies' => collect(),
            'office_visits' => collect(),
            'portal_messages' => collect(),
            'negative_assertions' => collect(),
            'dicom_studies' => collect(),
            'documents' => collect(),
        ];
    }

    private function extractPdfText(string $pdf): string
    {
        $text = (new Parser)->parseContent($pdf)->getText();

        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }
}
