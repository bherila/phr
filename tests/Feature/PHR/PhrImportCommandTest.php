<?php

namespace Tests\Feature\PHR;

use App\Models\PhrCondition;
use App\Models\PhrDocument;
use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\PhrPatientVital;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhrImportCommandTest extends TestCase
{
    public function test_fhir_command_imports_rows_idempotently(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $path = $this->writeTempJson([
            'resourceType' => 'Bundle',
            'type' => 'collection',
            'entry' => [
                [
                    'resource' => [
                        'resourceType' => 'Observation',
                        'id' => 'lab-1',
                        'category' => [['coding' => [['code' => 'laboratory']]]],
                        'code' => ['text' => 'Hemoglobin'],
                        'effectiveDateTime' => '2026-01-15T10:00:00-08:00',
                        'valueQuantity' => ['value' => 13.2, 'unit' => 'g/dL'],
                    ],
                ],
                [
                    'resource' => [
                        'resourceType' => 'Observation',
                        'id' => 'vital-1',
                        'category' => [['coding' => [['code' => 'vital-signs']]]],
                        'code' => ['text' => 'Heart Rate'],
                        'effectiveDateTime' => '2026-01-15T10:05:00-08:00',
                        'valueQuantity' => ['value' => 72, 'unit' => 'beats/min'],
                    ],
                ],
                [
                    'resource' => [
                        'resourceType' => 'Condition',
                        'id' => 'condition-1',
                        'code' => ['text' => 'Hypertension'],
                        'clinicalStatus' => ['text' => 'active'],
                        'verificationStatus' => ['text' => 'confirmed'],
                    ],
                ],
            ],
        ]);

        $this->artisan('phr:import:fhir', [
            '--patient' => $patient->id,
            '--actor' => $owner->id,
            '--file' => $path,
        ])->assertExitCode(0);

        $this->artisan('phr:import:fhir', [
            '--patient' => $patient->id,
            '--actor' => $owner->id,
            '--file' => $path,
        ])->assertExitCode(0);

        $this->assertSame(1, PhrLabResult::query()->where('patient_id', $patient->id)->count());
        $this->assertSame(1, PhrPatientVital::query()->where('patient_id', $patient->id)->count());
        $this->assertSame(1, PhrCondition::query()->where('patient_id', $patient->id)->count());
        $this->assertSame('Hemoglobin', PhrLabResult::query()->where('patient_id', $patient->id)->sole()->analyte);
    }

    public function test_pdf_extraction_command_imports_structured_rows(): void
    {
        Storage::fake('phr_documents');

        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $sourcePdf = tempnam(sys_get_temp_dir(), 'phr-source-');
        $this->assertIsString($sourcePdf);
        file_put_contents($sourcePdf, '%PDF-1.4 source');

        $jsonPath = $this->writeTempJson([
            'summary' => 'Imported lab PDF',
            'extracted_text' => 'Hemoglobin 14.1 g/dL',
            'records' => [
                [
                    'external_id' => 'pdf-lab-1',
                    'test_name' => 'Lab Report',
                    'analyte' => 'Hemoglobin',
                    'value' => '14.1',
                    'unit' => 'g/dL',
                ],
            ],
        ]);

        $this->artisan('phr:import:pdf', [
            '--patient' => $patient->id,
            '--actor' => $owner->id,
            '--file' => $sourcePdf,
            '--type' => 'phr_lab_result',
            '--json' => $jsonPath,
        ])->assertExitCode(0);

        $this->assertSame(1, PhrDocument::query()->where('patient_id', $patient->id)->count());
        $this->assertSame(1, PhrLabResult::query()->where('patient_id', $patient->id)->count());
        $this->assertSame('Hemoglobin', PhrLabResult::query()->where('patient_id', $patient->id)->sole()->analyte);
    }

    private function createPatient(User $owner): PhrPatient
    {
        return PhrPatient::create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Test Patient',
            'relationship' => 'self',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeTempJson(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phr-import-');
        $this->assertIsString($path);
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));

        return $path;
    }
}
