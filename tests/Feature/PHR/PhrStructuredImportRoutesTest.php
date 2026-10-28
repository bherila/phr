<?php

namespace Tests\Feature\PHR;

use App\Models\PhrCondition;
use App\Models\PhrImmunization;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PhrCcdaSyntheticDocument;
use Tests\TestCase;

/**
 * One C-CDA or FHIR file from the browser (#235, PR 3): recognized by content,
 * imported directly by the CLI's importers, pending review, and counted.
 */
class PhrStructuredImportRoutesTest extends TestCase
{
    public function test_a_ccda_file_is_recognized_imported_pending_review_and_counted(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();

        $response = $this->send($owner, $patient, 'visit-summary.txt', PhrCcdaSyntheticDocument::xml())->assertOk();

        $response->assertJsonPath('format', 'ccda');
        self::assertGreaterThan(0, $response->json('result.created'));
        self::assertGreaterThan(0, PhrImmunization::query()->where('patient_id', $patient->id)->count());
        self::assertSame(0, PhrImmunization::query()->where('patient_id', $patient->id)->where('review_status', '!=', PhrReviewStatus::PENDING)->count());
        $response->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_a_fhir_bundle_is_recognized_and_a_second_upload_changes_nothing(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $bundle = (string) json_encode(['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['resource' => ['resourceType' => 'Condition', 'id' => 'condition-1', 'code' => ['text' => 'Synthetic condition']]],
        ]]);

        $this->send($owner, $patient, 'bundle.json', $bundle)->assertOk()
            ->assertJsonPath('format', 'fhir')
            ->assertJsonPath('result.created', 1);
        $this->send($owner, $patient, 'bundle-again.json', $bundle)->assertOk()
            ->assertJsonPath('result.created', 0)
            ->assertJsonPath('result.updated', 0)
            ->assertJsonPath('result.unchanged', 1);
        self::assertSame(PhrReviewStatus::PENDING, PhrCondition::query()->where('patient_id', $patient->id)->sole()->review_status);
    }

    public function test_a_byte_order_mark_or_leading_whitespace_does_not_stop_an_import(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $bundle = (string) json_encode(['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['resource' => ['resourceType' => 'Condition', 'id' => 'condition-1', 'code' => ['text' => 'Synthetic condition']]],
        ]]);

        $this->send($owner, $patient, 'bundle.json', "\xEF\xBB\xBF".$bundle)->assertOk()->assertJsonPath('result.created', 1);
        $this->send($owner, $patient, 'summary.xml', "\n  ".PhrCcdaSyntheticDocument::xml())->assertOk()->assertJsonPath('format', 'ccda');
    }

    public function test_content_that_is_neither_format_is_refused_whatever_its_name(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();

        $this->send($owner, $patient, 'summary.xml', '<root><child/></root>')->assertStatus(422)->assertExactJson(['error' => 'unsupported_format']);
        $this->send($owner, $patient, 'bundle.json', '{"resourceType":"Patient"}')->assertStatus(422)->assertExactJson(['error' => 'unsupported_format']);
        $this->send($owner, $patient, 'notes.txt', 'Plain synthetic text')->assertStatus(422)->assertExactJson(['error' => 'unsupported_format']);
        self::assertSame(0, PhrCondition::query()->count());
    }

    public function test_an_entry_that_fails_partway_leaves_nothing_from_the_file_written(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $bundle = (string) json_encode(['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['resource' => ['resourceType' => 'Condition', 'id' => 'condition-1', 'code' => ['text' => 'Synthetic condition']]],
            // Recognized, but its category is not a list, so importing it throws.
            ['resource' => ['resourceType' => 'Observation', 'id' => 'lab-1', 'category' => 'laboratory', 'code' => ['text' => 'Synthetic']]],
        ]]);

        $this->send($owner, $patient, 'bundle.json', $bundle)->assertStatus(422)->assertExactJson(['error' => 'unreadable']);
        self::assertSame(0, PhrCondition::query()->count(), 'the entry before the failure was rolled back');
    }

    public function test_a_file_naming_more_than_one_person_is_refused_and_nothing_is_written(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $bundle = (string) json_encode(['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['resource' => ['resourceType' => 'Patient', 'id' => 'holder', 'birthDate' => '1984-03-09']],
            ['resource' => ['resourceType' => 'Patient', 'id' => 'dependent', 'birthDate' => '2015-07-01']],
            ['resource' => ['resourceType' => 'Condition', 'id' => 'condition-1', 'code' => ['text' => 'Synthetic condition']]],
        ]]);

        $this->send($owner, $patient, 'family.json', $bundle)->assertStatus(422)->assertExactJson(['error' => 'multiple_patients']);
        self::assertSame(0, PhrCondition::query()->count());
    }

    public function test_a_file_is_imported_under_the_patient_write_lock(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $bundle = (string) json_encode(['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['resource' => ['resourceType' => 'Condition', 'id' => 'condition-1', 'code' => ['text' => 'Synthetic condition']]],
        ]]);
        // SQLite drops FOR UPDATE, so observe the guard's locked patient read: the
        // first transaction the import opens reads the patient row before the
        // record is written. (Access checks read it too, but outside it.)
        $events = [];
        Event::listen(TransactionBeginning::class, function () use (&$events): void {
            $events[] = 'begin';
        });
        DB::listen(function (QueryExecuted $query) use (&$events): void {
            $sql = strtolower($query->sql);
            if (str_starts_with($sql, 'select') && str_contains($sql, 'from "phr_patients"') && str_contains($sql, 'limit 1')) {
                $events[] = 'patient';
            } elseif (str_starts_with($sql, 'insert into "phr_conditions"')) {
                $events[] = 'insert';
            }
        });

        $this->send($owner, $patient, 'bundle.json', $bundle)->assertOk();

        $begin = array_search('begin', $events, true);
        $insert = array_search('insert', $events, true);
        self::assertNotFalse($begin);
        self::assertNotFalse($insert);
        self::assertContains('patient', array_slice($events, (int) $begin, (int) $insert - (int) $begin), 'the import\'s transaction locks the patient row before writing');
    }

    public function test_manage_access_is_required_and_archived_patients_are_refused(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $viewer = $this->createUser();
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $viewer->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);

        $this->send($viewer, $patient, 'summary.xml', PhrCcdaSyntheticDocument::xml())->assertForbidden();
        $this->send($this->createUser(), $patient, 'summary.xml', PhrCcdaSyntheticDocument::xml())->assertNotFound();

        $patient->update(['archived_at' => now()]);
        $this->send($owner, $patient, 'summary.xml', PhrCcdaSyntheticDocument::xml())->assertStatus(409)->assertExactJson(['error' => 'patient_archived']);
        self::assertSame(0, PhrImmunization::query()->count());
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic Patient', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);

        return [$owner, $patient];
    }

    /** @return TestResponse<Response> */
    private function send(User $actor, PhrPatient $patient, string $name, string $contents): TestResponse
    {
        return $this->actingAs($actor)->post("/api/phr/patients/{$patient->id}/imports/structured", [
            'file' => UploadedFile::fake()->createWithContent($name, $contents),
        ], ['Accept' => 'application/json']);
    }
}
