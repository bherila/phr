<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrExport;
use App\Models\PhrLabResult;
use App\Models\PhrNegativeAssertion;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrPatientVital;
use App\Models\PhrPortalMessage;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\TestCase;
use ZipArchive;

class PhrExportTest extends TestCase
{
    public function test_owner_can_generate_zip_export_with_standard_artifacts(): void
    {
        Storage::fake('phr_documents');
        Storage::fake('phr_exports');

        $owner = $this->createUser();
        $patient = $this->createPatient($owner);

        PhrLabResult::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'test_name' => 'CBC',
            'analyte' => 'Hemoglobin',
            'value' => '13.2',
            'unit' => 'g/dL',
            'result_datetime' => '2026-01-15 10:00:00',
        ]);

        PhrPatientVital::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'vital_name' => 'Blood Pressure',
            'vital_value' => '120/80',
            'unit' => 'mmHg',
            'observed_at' => '2026-01-15 10:05:00',
        ]);

        PhrPortalMessage::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'message_at' => '2026-01-16 12:30:00',
            'direction' => 'inbound',
            'subject' => 'Post visit update',
            'sender_name' => 'Care Team',
            'recipient_name' => 'Test Patient',
            'summary' => 'Increase fluids and call if symptoms worsen.',
            'clinical_relevance' => 'Care coordination follow-up',
        ]);

        PhrNegativeAssertion::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'assertion_type' => 'allergy_absence',
            'statement' => 'No known allergies documented.',
            'scope' => 'allergies',
            'observed_on' => '2026-01-15',
            'notes' => 'Explicitly documented in portal bundle.',
        ]);

        PhrNegativeAssertion::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'assertion_type' => 'medication_absence',
            'statement' => 'No current statin therapy.',
            'scope' => 'statin medications',
            'observed_on' => '2026-01-15',
        ]);

        Storage::disk('phr_documents')->put('source/lab.pdf', '%PDF-1.4 test');
        $document = PhrDocument::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'title' => 'Lab PDF',
            'document_type' => 'lab_report',
            'original_filename' => 'lab.pdf',
            'storage_disk' => 'phr_documents',
            'storage_path' => 'source/lab.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen('%PDF-1.4 test'),
            'file_hash' => hash('sha256', '%PDF-1.4 test'),
        ]);

        $response = $this->actingAs($owner)->postJson("/api/phr/patients/{$patient->id}/exports", [
            'formats' => ['zip'],
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('export.status', PhrExport::STATUS_READY)
            ->assertJsonPath('export.format', 'zip')
            ->assertJsonPath('export.formats.0', 'zip');

        $downloadUrl = $response->json('export.download_url');
        $this->assertIsString($downloadUrl);
        $this->assertNotSame('', $downloadUrl);

        $export = PhrExport::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame(PhrExport::STATUS_READY, $export->status);
        $this->assertNotNull($export->storage_path);
        Storage::disk('phr_exports')->assertExists($export->storage_path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('phr_exports')->path($export->storage_path)));
        $this->assertNotFalse($zip->locateName('fhir.json'));
        $this->assertNotFalse($zip->locateName('ccda.xml'));
        $this->assertNotFalse($zip->locateName('summary.pdf'));
        $this->assertNotFalse($zip->locateName("documents/{$document->id}-lab.pdf"));

        $fhir = (string) $zip->getFromName('fhir.json');
        $this->assertStringContainsString('Hemoglobin', $fhir);
        $this->assertStringContainsString('"resourceType": "Communication"', $fhir);
        $this->assertStringContainsString('"resourceType": "AllergyIntolerance"', $fhir);
        $this->assertStringContainsString('"resourceType": "Observation"', $fhir);
        $this->assertStringContainsString('Increase fluids and call if symptoms worsen.', $fhir);
        $this->assertStringContainsString('No known allergies documented.', $fhir);
        $this->assertStringContainsString('No current statin therapy.', $fhir);

        $ccda = (string) $zip->getFromName('ccda.xml');
        $this->assertStringContainsString('Portal Messages', $ccda);
        $this->assertStringContainsString('Negative Assertions', $ccda);
        $this->assertStringContainsString('Post visit update', $ccda);
        $this->assertStringContainsString('No known allergies documented.', $ccda);

        $summaryPdf = $zip->getFromName('summary.pdf');
        $this->assertIsString($summaryPdf);
        $summaryText = preg_replace('/\s+/', ' ', (new Parser)->parseContent($summaryPdf)->getText()) ?? '';
        $this->assertStringContainsString('Portal Messages', $summaryText);
        $this->assertStringContainsString('2026-01-16 | inbound | Post visit update | Care Team -> Test Patient | Increase fluids and call if symptoms worsen. | Care coordination follow-up', $summaryText);
        $this->assertStringContainsString('Negative Assertions', $summaryText);
        $this->assertStringContainsString('2026-01-15 | allergy_absence | allergies | No known allergies documented. | Explicitly documented in portal bundle.', $summaryText);
        $zip->close();
    }

    public function test_manager_cannot_export_patient_record(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $patient = $this->createPatient($owner);

        PhrPatientUserAccess::create([
            'patient_id' => $patient->id,
            'user_id' => $manager->id,
            'access_level' => PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patient->id}/exports", [
            'formats' => ['zip'],
        ])->assertForbidden();
    }

    private function createPatient(User $owner): PhrPatient
    {
        return PhrPatient::create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Test Patient',
            'relationship' => 'self',
        ]);
    }
}
