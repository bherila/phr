<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrDocumentRelation;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Export\PhrCcdaExporter;
use App\Services\PHR\Export\PhrExportDataService;
use App\Services\PHR\Export\PhrFhirExporter;
use App\Services\PHR\Import\PhrCcdaImporter;
use App\Services\PHR\Import\PhrFhirImporter;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UsesObjectStoreDisks;
use Tests\TestCase;

/**
 * Clinical notes through FHIR and C-CDA (phr#409), with the synthetic fixture of
 * phr#405: Pat Example's daily progress note by Jordan Fellow, MD, cosigned by
 * Casey Consultant, MD, its addendum, and Robin Nurse, RN's plan-of-care note.
 */
final class PhrClinicalNoteInteropTest extends TestCase
{
    use RefreshDatabase;
    use UsesObjectStoreDisks;

    private const string NOTE = "# Daily Progress Note\n\n## Nephrology Follow-Up\n\n**S:** Feels better, eating.\n\n| Test | Result | Flag |\n|---|---|---|\n| Creatinine | 1.8 mg/dL | H |\n| Potassium | 3.2 mmol/L | L |\n\n**Recommendations:**\n- Continue IV fluids\n- Replete potassium\n";

    private const string ADDENDUM = "Addendum: potassium repleted; recheck BMP in the morning.\n";

    private const string PLAN_OF_CARE = "Plan of care: fall precautions, encourage oral intake.\n";

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useObjectStoreDisks();
        $this->owner = User::factory()->create(['user_role' => 'user']);
    }

    protected function tearDown(): void
    {
        $this->tearDownObjectStoreDisks();
        parent::tearDown();
    }

    public function test_a_progress_note_and_its_addendum_round_trip_fhir_to_phr_to_fhir(): void
    {
        $patient = $this->patient();
        app(PhrFhirImporter::class)->importBundle($this->bundle(), $patient, (int) $this->owner->id);

        $notes = $this->documents($patient);
        $this->assertEqualsCanonicalizing(['Addendum to Daily Progress Note', 'Daily Progress Note', 'Plan of Care', 'POLST'], array_keys($notes));
        $note = $notes['Daily Progress Note'];
        $this->assertSame(PhrReviewStatus::PENDING, $note->review_status);
        $this->assertSame('progress_note', $note->document_type);
        $this->assertSame('11506-3', $note->loinc_code);
        $this->assertSame('Daily Progress Note', $note->source_type_label);
        $this->assertSame('Jordan Fellow, MD', $note->author_name);
        $this->assertSame('Nephrology fellow', $note->author_role);
        $this->assertSame('Casey Consultant, MD', $note->cosigner_name);
        $this->assertSame('2026-02-11 14:30:00', $note->authored_at?->toDateTimeString(), 'an offset is the instant it names');
        $this->assertSame('2026-02-11 21:05:00', $note->signed_at?->toDateTimeString());
        $this->assertSame('final', $note->doc_status);
        // The inline markdown is the stored file, and its text.
        $this->assertSame('text/markdown', $note->mime_type);
        $this->assertSame(self::NOTE, Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $note->storage_path));
        $this->assertSame(self::NOTE, $note->extracted_text);
        $this->assertSame(hash('sha256', self::NOTE), $note->file_hash);
        // The addendum came first in the bundle and still names the note.
        $this->assertSame(['related_document_id' => $note->id, 'relation' => 'appends'], $this->relation($notes['Addendum to Daily Progress Note']));
        $this->assertSame('18776-5', $notes['Plan of Care']->loinc_code, 'the source\'s code, not the nursing-note default');
        // A target PHR does not hold is kept, not guessed.
        $polst = PhrDocumentRelation::query()->where('document_id', $notes['POLST']->id)->sole();
        $this->assertNull($polst->related_document_id);
        $this->assertSame('DocumentReference/synthetic-older-polst', $polst->related_reference);

        // An unchanged re-import changes nothing.
        $before = PhrDocument::query()->where('patient_id', $patient->id)->orderBy('id')->get()->map->getAttributes()->all();
        app(PhrFhirImporter::class)->importBundle($this->bundle(), $patient, (int) $this->owner->id);
        $this->assertEquals($before, PhrDocument::query()->where('patient_id', $patient->id)->orderBy('id')->get()->map->getAttributes()->all());

        // Pending notes are not exported; confirmed ones are.
        $this->assertStringNotContainsString('DocumentReference', $this->fhir($patient));
        PhrDocument::query()->where('patient_id', $patient->id)->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        PhrDocument::query()->whereKey($notes['Plan of Care']->id)->update(['document_type' => 'nursing_note']);
        $exported = json_decode($this->fhir($patient), true, flags: JSON_THROW_ON_ERROR);
        $references = collect($exported['entry'])->pluck('resource')->where('resourceType', 'DocumentReference')->keyBy('id');
        $progress = $references['phr-document-'.$note->id];
        $this->assertSame([['system' => 'http://loinc.org', 'code' => '11506-3'], ['system' => 'urn:phr:document-type', 'code' => 'progress_note']], $progress['type']['coding']);
        $this->assertSame('Daily Progress Note', $progress['type']['text']);
        $this->assertSame('clinical-note', $progress['category'][0]['coding'][0]['code']);
        $this->assertSame(['reference' => '#author', 'display' => 'Jordan Fellow, MD'], $progress['author'][0]);
        $this->assertSame('Nephrology fellow', $progress['contained'][0]['code'][0]['text']);
        $this->assertSame(['display' => 'Casey Consultant, MD'], $progress['authenticator']);
        $this->assertSame('2026-02-11T21:05:00+00:00', $progress['date']);
        $this->assertSame('2026-02-11T14:30:00+00:00', $progress['content'][0]['attachment']['creation']);
        $this->assertSame('final', $progress['docStatus']);
        $this->assertSame(base64_encode(sha1(self::NOTE, true)), $progress['content'][0]['attachment']['hash'], 'base64 SHA-1, not the stored sha256');
        $this->assertSame(self::NOTE, base64_decode($progress['content'][1]['attachment']['data'], true));
        $this->assertSame([['code' => 'appends', 'target' => ['reference' => 'DocumentReference/phr-document-'.$note->id]]], $references['phr-document-'.$notes['Addendum to Daily Progress Note']->id]['relatesTo']);
        $this->assertSame('18776-5', $references['phr-document-'.$notes['Plan of Care']->id]['type']['coding'][0]['code']);
        $this->assertArrayNotHasKey('relatesTo', $references['phr-document-'.$notes['POLST']->id], 'an unresolved link is not exported');

        // ... and back into another patient, everything the note says is intact.
        $other = $this->patient();
        app(PhrFhirImporter::class)->importBundle($exported, $other, (int) $this->owner->id);
        $again = $this->documents($other);
        foreach (['Daily Progress Note', 'Addendum to Daily Progress Note', 'Plan of Care'] as $title) {
            $this->assertSame($this->noteFacts($notes[$title]->refresh()), $this->noteFacts($again[$title]), $title);
        }
        $this->assertSame('nursing_note', $again['Plan of Care']->document_type, 'PHR\'s own type survives beside the source code');
        $this->assertSame(['related_document_id' => $again['Daily Progress Note']->id, 'relation' => 'appends'], $this->relation($again['Addendum to Daily Progress Note']));
        $this->assertSame(self::NOTE, $again['Daily Progress Note']->extracted_text);
    }

    public function test_the_same_notes_round_trip_through_the_ccda_notes_section(): void
    {
        $patient = $this->patient();
        app(PhrFhirImporter::class)->importBundle($this->bundle(), $patient, (int) $this->owner->id);
        PhrDocument::query()->where('patient_id', $patient->id)->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $notes = $this->documents($patient);
        PhrDocument::query()->whereKey($notes['Plan of Care']->id)->update(['document_type' => 'nursing_note']);

        $xml = $this->ccda($patient);
        $this->assertStringContainsString('2.16.840.1.113883.10.20.22.2.65', $xml);
        $this->assertSame(3, substr_count($xml, 'root="2.16.840.1.113883.10.20.22.4.202"'), 'one Note Activity per clinical note');

        $other = $this->patient();
        app(PhrCcdaImporter::class)->importXml($xml, $other, (int) $this->owner->id);
        // A Note Activity has no title: a re-imported note is named by its source label.
        $byLabel = PhrDocument::query()->where('patient_id', $other->id)->get()->keyBy('source_type_label');
        $again = ['Daily Progress Note' => $byLabel['Daily Progress Note'], 'Addendum to Daily Progress Note' => $byLabel['Progress Note Addendum'], 'Plan of Care' => $byLabel['Plan of Care']];
        $this->assertCount(3, $byLabel);
        foreach (['Daily Progress Note', 'Addendum to Daily Progress Note', 'Plan of Care'] as $title) {
            $this->assertSame($this->noteFacts($notes[$title]->refresh()), $this->noteFacts($again[$title]), $title);
            $this->assertSame(PhrReviewStatus::PENDING, $again[$title]->review_status);
            $this->assertSame('ccda_import', $again[$title]->source);
        }
        $this->assertSame('nursing_note', $again['Plan of Care']->document_type);
        $this->assertSame(['related_document_id' => $again['Daily Progress Note']->id, 'relation' => 'appends'], $this->relation($again['Addendum to Daily Progress Note']));
        $this->assertSame(trim(self::NOTE), $again['Daily Progress Note']->extracted_text, 'the lines of the note survive the narrative');
        $this->assertSame(trim(self::NOTE), Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $again['Daily Progress Note']->storage_path));

        // Importing it again changes nothing.
        $before = PhrDocument::query()->where('patient_id', $other->id)->orderBy('id')->get()->map->getAttributes()->all();
        app(PhrCcdaImporter::class)->importXml($xml, $other, (int) $this->owner->id);
        $this->assertEquals($before, PhrDocument::query()->where('patient_id', $other->id)->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_a_vendor_notes_section_and_hospital_course_import_as_notes(): void
    {
        $patient = $this->patient();
        app(PhrCcdaImporter::class)->importXml($this->vendorCcda(), $patient, (int) $this->owner->id);
        $notes = $this->documents($patient);

        $progress = $notes['Daily Progress Note'];
        $this->assertSame('11506-3', $progress->loinc_code);
        $this->assertSame('progress_note', $progress->document_type);
        $this->assertSame('Jordan Fellow, MD', $progress->author_name);
        $this->assertSame('Nephrology Fellow', $progress->author_role);
        $this->assertSame('Casey Consultant, MD', $progress->cosigner_name, 'the legal authenticator participant');
        $this->assertSame('2026-02-11 14:30:00', $progress->authored_at?->toDateTimeString());
        $this->assertSame('2026-02-11 21:05:00', $progress->signed_at?->toDateTimeString());
        $this->assertSame('final', $progress->doc_status);
        $this->assertStringContainsString("Creatinine | 1.8 mg/dL | H\nPotassium | 3.2 mmol/L | L", (string) $progress->extracted_text);

        $nursing = $notes['Plan of Care'];
        $this->assertSame('18776-5', $nursing->loinc_code);
        $this->assertSame('Robin Nurse, RN', $nursing->author_name);
        $this->assertNull($nursing->cosigner_name, 'a note its author signed has no cosigner');
        $this->assertSame('2026-02-12 00:04:00', $nursing->signed_at?->toDateTimeString());

        $course = $notes['Hospital Course'];
        $this->assertSame('8648-8', $course->loinc_code);
        $this->assertSame('discharge_summary', $course->document_type);
        $this->assertStringContainsString('Admitted through the emergency department', (string) $course->extracted_text);
        $this->assertSame(3, PhrDocument::query()->where('patient_id', $patient->id)->count());
    }

    public function test_an_addendum_to_a_document_reference_without_an_id_links_by_its_full_url_and_round_trips(): void
    {
        $patient = $this->patient();
        // Long enough that the importer names the note by the fullUrl's digest.
        $longUrl = 'https://fhir.example.test/'.str_repeat('synthetic-segment/', 14).'note';
        $this->assertGreaterThan(255, strlen('DocumentReference/fullUrl:'.$longUrl));
        $note = static fn (string $title, string $text): array => [
            'resourceType' => 'DocumentReference', 'status' => 'current', 'docStatus' => 'final',
            'type' => ['coding' => [['system' => 'http://loinc.org', 'code' => '11506-3']], 'text' => 'Progress Note'], 'description' => $title,
            'date' => '2026-02-11T16:05:00-05:00',
            'content' => [['attachment' => ['contentType' => 'text/plain', 'title' => str_replace(' ', '-', strtolower($title)).'.txt', 'data' => base64_encode($text)]]],
        ];
        $addendum = static fn (string $title, string $text, string $code, string $target): array => [...$note($title, $text), 'relatesTo' => [['code' => $code, 'target' => ['reference' => $target]]]];
        app(PhrFhirImporter::class)->importBundle(['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            // Neither the notes nor the first addendum has an id: each is named by its fullUrl.
            ['fullUrl' => 'urn:uuid:7a1c0d2e-0000-4000-8000-000000000021', 'resource' => $addendum('Addendum to Night Note', "Addendum: slept well.\n", 'appends', 'urn:uuid:7a1c0d2e-0000-4000-8000-000000000020')],
            ['fullUrl' => 'urn:uuid:7a1c0d2e-0000-4000-8000-000000000020', 'resource' => $note('Night Note', "Night note.\n")],
            ['fullUrl' => 'urn:uuid:7a1c0d2e-0000-4000-8000-000000000023', 'resource' => [...$addendum('Amended Day Note', "Day note, amended.\n", 'replaces', $longUrl), 'id' => 'synthetic-day-amendment']],
            ['fullUrl' => $longUrl, 'resource' => $note('Day Note', "Day note.\n")],
        ]], $patient, (int) $this->owner->id);

        $notes = $this->documents($patient);
        $this->assertSame('DocumentReference/fullUrl:urn:uuid:7a1c0d2e-0000-4000-8000-000000000020', $notes['Night Note']->external_id);
        $this->assertSame('DocumentReference/fullUrl-sha256:'.hash('sha256', $longUrl), $notes['Day Note']->external_id);
        $this->assertSame(['related_document_id' => $notes['Night Note']->id, 'relation' => 'appends'], $this->relation($notes['Addendum to Night Note']));
        $this->assertSame(['related_document_id' => $notes['Day Note']->id, 'relation' => 'replaces'], $this->relation($notes['Amended Day Note']));

        // Across imports: an addendum read before its id-less note, and one read after it.
        app(PhrFhirImporter::class)->importBundle(['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['fullUrl' => 'urn:uuid:7a1c0d2e-0000-4000-8000-000000000031', 'resource' => $addendum('Addendum to Evening Note', "Addendum: evening.\n", 'appends', 'urn:uuid:7a1c0d2e-0000-4000-8000-000000000030')],
            ['fullUrl' => 'urn:uuid:7a1c0d2e-0000-4000-8000-000000000032', 'resource' => $addendum('Second Addendum to Night Note', "Addendum: woke once.\n", 'appends', 'urn:uuid:7a1c0d2e-0000-4000-8000-000000000020')],
        ]], $patient, (int) $this->owner->id);
        $notes = $this->documents($patient);
        $this->assertSame(['related_document_id' => $notes['Night Note']->id, 'relation' => 'appends'], $this->relation($notes['Second Addendum to Night Note']), 'a note imported earlier is found by the identity its fullUrl gave it');
        $this->assertSame(['related_document_id' => null, 'relation' => 'appends'], $this->relation($notes['Addendum to Evening Note']), 'kept until its note arrives');
        app(PhrFhirImporter::class)->importBundle(['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['fullUrl' => 'urn:uuid:7a1c0d2e-0000-4000-8000-000000000030', 'resource' => $note('Evening Note', "Evening note.\n")],
        ]], $patient, (int) $this->owner->id);
        $notes = $this->documents($patient);
        $this->assertSame(['related_document_id' => $notes['Evening Note']->id, 'relation' => 'appends'], $this->relation($notes['Addendum to Evening Note']));

        // Exported, every link names its note, and it survives a second import.
        PhrDocument::query()->where('patient_id', $patient->id)->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $exported = json_decode($this->fhir($patient), true, flags: JSON_THROW_ON_ERROR);
        $references = collect($exported['entry'])->pluck('resource')->where('resourceType', 'DocumentReference')->keyBy('description');
        foreach (['Addendum to Night Note' => ['appends', 'Night Note'], 'Second Addendum to Night Note' => ['appends', 'Night Note'], 'Amended Day Note' => ['replaces', 'Day Note'], 'Addendum to Evening Note' => ['appends', 'Evening Note']] as $title => [$code, $target]) {
            $this->assertSame([['code' => $code, 'target' => ['reference' => 'DocumentReference/phr-document-'.$notes[$target]->id]]], $references[$title]['relatesTo'] ?? null, $title);
        }
        $other = $this->patient();
        app(PhrFhirImporter::class)->importBundle($exported, $other, (int) $this->owner->id);
        $again = $this->documents($other);
        $this->assertSame(['related_document_id' => $again['Night Note']->id, 'relation' => 'appends'], $this->relation($again['Addendum to Night Note']));
        $this->assertSame(['related_document_id' => $again['Day Note']->id, 'relation' => 'replaces'], $this->relation($again['Amended Day Note']));
    }

    /** @return array<string, mixed> */
    private function noteFacts(PhrDocument $document): array
    {
        return [
            'loinc_code' => $document->loinc_code,
            'source_type_label' => $document->source_type_label,
            'author_name' => $document->author_name,
            'author_role' => $document->author_role,
            'cosigner_name' => $document->cosigner_name,
            'authored_at' => $document->authored_at?->toDateTimeString(),
            'signed_at' => $document->signed_at?->toDateTimeString(),
            'doc_status' => $document->doc_status,
        ];
    }

    /** @return array{related_document_id: int|null, relation: string}|null */
    private function relation(PhrDocument $document): ?array
    {
        $relation = PhrDocumentRelation::query()->where('document_id', $document->id)->first();

        return $relation === null ? null : ['related_document_id' => $relation->related_document_id, 'relation' => $relation->relation];
    }

    /** @return array<string, PhrDocument> by title */
    private function documents(PhrPatient $patient): array
    {
        return PhrDocument::query()->where('patient_id', $patient->id)->orderBy('title')->get()->keyBy('title')->all();
    }

    private function patient(): PhrPatient
    {
        return PhrPatient::query()->create(['owner_user_id' => $this->owner->id, 'display_name' => 'Pat Example', 'relationship' => 'self', 'birth_date' => '1950-06-15']);
    }

    private function fhir(PhrPatient $patient): string
    {
        return app(PhrFhirExporter::class)->bundleJson(app(PhrExportDataService::class)->load($patient));
    }

    private function ccda(PhrPatient $patient): string
    {
        return app(PhrCcdaExporter::class)->documentXml(app(PhrExportDataService::class)->load($patient));
    }

    /** @return array<string, mixed> */
    private function bundle(): array
    {
        $markdown = static fn (string $text, string $title): array => [
            'contentType' => 'text/markdown', 'title' => $title, 'data' => base64_encode($text), 'hash' => base64_encode(sha1($text, true)),
        ];

        return ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            // The addendum first: it names a note later in the bundle.
            ['fullUrl' => 'urn:uuid:7a1c0d2e-0000-4000-8000-000000000002', 'resource' => [
                'resourceType' => 'DocumentReference', 'id' => 'synthetic-progress-addendum', 'status' => 'current', 'docStatus' => 'final',
                'type' => ['coding' => [['system' => 'http://loinc.org', 'code' => '11506-3', 'display' => 'Progress note']], 'text' => 'Progress Note Addendum'],
                'category' => [['coding' => [['system' => 'http://hl7.org/fhir/us/core/CodeSystem/us-core-documentreference-category', 'code' => 'clinical-note']]]],
                'description' => 'Addendum to Daily Progress Note',
                'author' => [['reference' => 'PractitionerRole/synthetic-fellow-role']],
                'authenticator' => ['reference' => 'Practitioner/synthetic-consultant'],
                'date' => '2026-02-11T18:10:00-05:00',
                'relatesTo' => [['code' => 'appends', 'target' => ['reference' => 'urn:uuid:7a1c0d2e-0000-4000-8000-000000000001']]],
                'content' => [['attachment' => [...$markdown(self::ADDENDUM, 'progress-note-addendum.md'), 'creation' => '2026-02-11T18:00:00-05:00']]],
            ]],
            ['fullUrl' => 'urn:uuid:7a1c0d2e-0000-4000-8000-000000000001', 'resource' => [
                'resourceType' => 'DocumentReference', 'id' => 'synthetic-progress-note', 'status' => 'current', 'docStatus' => 'final',
                'type' => ['coding' => [['system' => 'http://loinc.org', 'code' => '11506-3', 'display' => 'Progress note']], 'text' => 'Daily Progress Note'],
                'category' => [['coding' => [['system' => 'http://hl7.org/fhir/us/core/CodeSystem/us-core-documentreference-category', 'code' => 'clinical-note']]]],
                'description' => 'Daily Progress Note',
                'author' => [['reference' => 'PractitionerRole/synthetic-fellow-role']],
                'authenticator' => ['reference' => 'Practitioner/synthetic-consultant'],
                'date' => '2026-02-11T16:05:00-05:00',
                'context' => ['period' => ['start' => '2026-02-11T09:30:00-05:00']],
                'content' => [['attachment' => [...$markdown(self::NOTE, 'daily-progress-note.md'), 'creation' => '2026-02-11T09:30:00-05:00']]],
            ]],
            ['resource' => [
                'resourceType' => 'DocumentReference', 'id' => 'synthetic-plan-of-care', 'status' => 'current', 'docStatus' => 'final',
                'type' => ['coding' => [['system' => 'http://loinc.org', 'code' => '18776-5', 'display' => 'Plan of care note']], 'text' => 'Plan of Care'],
                'description' => 'Plan of Care',
                'contained' => [['resourceType' => 'Practitioner', 'id' => 'nurse', 'name' => [['text' => 'Robin Nurse, RN']]]],
                'author' => [['reference' => '#nurse']],
                'date' => '2026-02-11T19:04:00-05:00',
                'content' => [['attachment' => ['contentType' => 'text/plain', 'title' => 'plan-of-care.txt', 'data' => base64_encode(self::PLAN_OF_CARE), 'creation' => '2026-02-11T19:00:00-05:00']]],
            ]],
            ['resource' => [
                'resourceType' => 'DocumentReference', 'id' => 'synthetic-polst', 'status' => 'current',
                'type' => ['text' => 'consent'], 'description' => 'POLST',
                'relatesTo' => [['code' => 'replaces', 'target' => ['reference' => 'DocumentReference/synthetic-older-polst']]],
                // A stated hash that does not match: the bytes are not stored.
                'content' => [['attachment' => ['contentType' => 'text/plain', 'title' => 'polst.txt', 'data' => base64_encode('POLST form'), 'hash' => base64_encode(sha1('something else', true))]]],
            ]],
            ['fullUrl' => 'urn:uuid:7a1c0d2e-0000-4000-8000-000000000010', 'resource' => [
                'resourceType' => 'PractitionerRole', 'id' => 'synthetic-fellow-role',
                'practitioner' => ['reference' => 'Practitioner/synthetic-fellow'], 'code' => [['text' => 'Nephrology fellow']],
            ]],
            ['resource' => ['resourceType' => 'Practitioner', 'id' => 'synthetic-fellow', 'name' => [['given' => ['Jordan'], 'family' => 'Fellow', 'suffix' => ['MD']]]]],
            ['resource' => ['resourceType' => 'Practitioner', 'id' => 'synthetic-consultant', 'name' => [['text' => 'Casey Consultant, MD']]]],
        ]];
    }

    private function vendorCcda(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ClinicalDocument xmlns="urn:hl7-org:v3">
  <id root="2.16.840.1.113883.19.5.99999.1" extension="synthetic-discharge-summary"/>
  <effectiveTime value="20260213120000+0000"/>
  <component><structuredBody>
    <component><section>
      <templateId root="2.16.840.1.113883.10.20.22.2.65" extension="2016-11-01"/>
      <code code="11506-3" codeSystem="2.16.840.1.113883.6.1"/>
      <title>Notes</title>
      <text>
        <paragraph ID="progress">Assessment and plan.<br/>Creatinine improving.</paragraph>
        <table><tbody ID="progress-labs"><tr><td>Creatinine</td><td>1.8 mg/dL</td><td>H</td></tr><tr><td>Potassium</td><td>3.2 mmol/L</td><td>L</td></tr></tbody></table>
        <paragraph ID="nursing">Fall precautions in place.</paragraph>
      </text>
      <entry><act classCode="ACT" moodCode="EVN">
        <templateId root="2.16.840.1.113883.10.20.22.4.202" extension="2016-11-01"/>
        <id root="2.16.840.1.113883.19.5.99999.2" extension="note-1"/>
        <code code="34109-9" codeSystem="2.16.840.1.113883.6.1" displayName="Note">
          <originalText>Daily Progress Note</originalText>
          <translation code="11506-3" codeSystem="2.16.840.1.113883.6.1" displayName="Progress note"/>
        </code>
        <text><reference value="#progress-labs"/></text>
        <statusCode code="completed"/>
        <effectiveTime value="20260211093000-0500"/>
        <author>
          <time value="20260211093000-0500"/>
          <assignedAuthor><id root="2.16.840.1.113883.19.5.99999.3"/><code><originalText>Nephrology Fellow</originalText></code>
            <assignedPerson><name><given>Jordan</given><family>Fellow, MD</family></name></assignedPerson></assignedAuthor>
        </author>
        <participant typeCode="LA">
          <time value="20260211160500-0500"/>
          <participantRole><playingEntity><name>Casey Consultant, MD</name></playingEntity></participantRole>
        </participant>
      </act></entry>
      <entry><act classCode="ACT" moodCode="EVN">
        <templateId root="2.16.840.1.113883.10.20.22.4.202"/>
        <id root="2.16.840.1.113883.19.5.99999.2" extension="note-2"/>
        <code code="18776-5" codeSystem="2.16.840.1.113883.6.1" displayName="Plan of care note"><originalText>Plan of Care</originalText></code>
        <text><reference value="#nursing"/></text>
        <statusCode code="completed"/>
        <effectiveTime value="20260211190000-0500"/>
        <author><time value="20260211190000-0500"/><assignedAuthor><id nullFlavor="NI"/><assignedPerson><name>Robin Nurse, RN</name></assignedPerson></assignedAuthor></author>
        <participant typeCode="LA"><time value="20260211190400-0500"/><participantRole><playingEntity><name>Robin Nurse, RN</name></playingEntity></participantRole></participant>
      </act></entry>
    </section></component>
    <component><section>
      <templateId root="1.3.6.1.4.1.19376.1.5.3.1.3.5"/>
      <code code="8648-8" codeSystem="2.16.840.1.113883.6.1" displayName="Hospital course"/>
      <title>Hospital Course</title>
      <text><paragraph>Admitted through the emergency department with community-acquired pneumonia.</paragraph><paragraph>Treated with IV ceftriaxone.</paragraph></text>
    </section></component>
  </structuredBody></component>
</ClinicalDocument>
XML;
    }
}
