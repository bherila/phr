<?php

namespace Tests\Feature\PHR;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrDocument;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiSource;
use App\Models\PhrImmunization;
use App\Models\PhrMedication;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiDocumentMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiImmunizationMapping;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\TestCase;

/**
 * The patient's review queue (sidebar counts and the Review page): what waits for
 * review across clinical records, AI import proposals and EHI export proposals,
 * read under the patient's ordinary access rules, and decided only through the
 * routes that already own each decision.
 */
class PhrReviewQueueTest extends TestCase
{
    use EhiIntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Storage::fake(PhrDocument::STORAGE_DISK);
        Storage::fake('s3');
        Queue::fake();
        config(['phr.ehi_mappings' => [EhiImmunizationMapping::class, EhiDocumentMapping::class]]);
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    public function test_pending_records_are_counted_per_section_and_grouped_by_where_they_came_from(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $summary = $this->document($patient, $owner, 'visit-summary.xml');
        $metformin = $this->record(PhrMedication::class, $patient, ['name' => 'Synthetic metformin', 'dose' => '500', 'dose_unit' => 'mg', 'import_source' => 'ccda', 'source_document_id' => $summary->id]);
        $lisinopril = $this->record(PhrMedication::class, $patient, ['name' => 'Synthetic lisinopril', 'import_source' => 'ccda', 'source_document_id' => $summary->id]);
        // Its source changed it after it was first added, which reopened review.
        $lisinopril->forceFill(['updated_at' => now()->addMinute()])->save();
        $this->record(PhrCondition::class, $patient, ['name' => 'Synthetic rhinitis', 'icd10_code' => 'J30.2', 'import_source' => 'fhir']);
        $this->record(PhrAllergy::class, $patient, ['substance' => 'Synthetic pollen', 'import_source' => 'agent-client:0199f2b7-0000-7000-8000-000000000001']);
        // Not waiting: decided, withdrawn by its source, deleted, or another patient's.
        $this->record(PhrMedication::class, $patient, ['name' => 'Confirmed', 'review_status' => PhrReviewStatus::CONFIRMED]);
        $this->record(PhrMedication::class, $patient, ['name' => 'Rejected', 'review_status' => PhrReviewStatus::REJECTED]);
        $this->record(PhrMedication::class, $patient, ['name' => 'Retracted'])->forceFill(['retracted_at' => now()])->save();
        $this->record(PhrMedication::class, $patient, ['name' => 'Deleted'])->delete();
        [$otherOwner, $otherPatient] = $this->ownerAndPatient();
        $this->record(PhrMedication::class, $otherPatient, ['name' => 'Someone else']);

        $this->actingAs($owner)->getJson($this->url($patient).'/summary')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('summary.total', 4)
            ->assertJsonPath('summary.sections.medications', ['pending' => 2, 'records' => 3])
            ->assertJsonPath('summary.sections.conditions', ['pending' => 1, 'records' => 1])
            ->assertJsonPath('summary.sections.allergies', ['pending' => 1, 'records' => 1])
            ->assertJsonPath('summary.sections.documents', ['pending' => 0, 'records' => 1])
            ->assertJsonMissingPath('summary.sections.labs')
            ->assertJsonPath('can_manage', true);

        $groups = collect($this->actingAs($owner)->getJson($this->url($patient))->assertOk()->json('groups'))->keyBy('key');
        self::assertSame(["records:ccda:{$summary->id}", 'records:fhir:', 'records:agent-client:0199f2b7-0000-7000-8000-000000000001:'], $groups->keys()->all());

        $ccda = $groups["records:ccda:{$summary->id}"];
        self::assertSame(['C-CDA import', 'Synthetic visit-summary.xml', true, false, $summary->id], [$ccda['label'], $ccda['name'], $ccda['parsed_by_phr'], $ccda['ai_read'], $ccda['document_id']]);
        $items = collect($ccda['items'])->keyBy('id');
        self::assertSame(['medications', 'Synthetic metformin', '500 mg', 'new'], [$items[$metformin->id]['section'], $items[$metformin->id]['title'], $items[$metformin->id]['detail'], $items[$metformin->id]['change']]);
        self::assertSame('update', $items[$lisinopril->id]['change']);
        self::assertSame(64, strlen($items[$metformin->id]['version']));
        self::assertSame('ICD-10 J30.2', $groups['records:fhir:']['items'][0]['detail']);
        self::assertSame('From a connected app', $groups['records:agent-client:0199f2b7-0000-7000-8000-000000000001:']['label']);
    }

    public function test_ai_proposals_and_ehi_proposals_wait_in_the_same_queue(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $letter = $this->document($patient, $owner, 'letter.pdf');
        $job = $this->aiJob($letter, $owner);
        $this->aiResult($job, 0, ['name' => 'Synthetic amoxicillin']);
        $this->aiResult($job, 1, ['name' => 'Synthetic ibuprofen']);
        $this->aiResult($job, 2, ['name' => 'Already accepted'], 'imported');
        $package = $this->previewedEhiPackage($owner, $patient);

        $response = $this->actingAs($owner)->getJson($this->url($patient))->assertOk()
            // 2 AI proposals, 6 immunization and 3 document EHI proposals.
            ->assertJsonPath('summary.total', 11)
            ->assertJsonPath('summary.ai_results', 2)
            ->assertJsonPath('summary.sections.immunizations.pending', 6)
            ->assertJsonPath('summary.sections.documents.pending', 3);
        $groups = collect($response->json('groups'))->keyBy('key');

        $ai = $groups["ai:{$job->id}"];
        self::assertTrue($ai['ai_read']);
        self::assertSame(['Synthetic letter.pdf', GenAiImportJob::EXECUTION_EXTERNAL, $letter->id, 2], [$ai['name'], $ai['execution_mode'], $ai['document_id'], $ai['total']]);
        self::assertSame(['Synthetic amoxicillin', 'Synthetic ibuprofen'], array_column(array_column(array_column($ai['items'], 'result'), 'data'), 'name'));
        self::assertSame($job->id, $ai['items'][0]['import_id']);

        $ehi = $groups["ehi:{$package->id}"];
        self::assertSame(['EHI export', 'Synthetic Clinic', 9], [$ehi['label'], $ehi['name'], $ehi['total']]);
        self::assertCount(9, $ehi['items']);
        self::assertTrue(collect($ehi['items'])->every(static fn (array $item): bool => $item['proposal']['reviewable'] && $item['proposal']['preview_digest'] !== ''));
        self::assertEqualsCanonicalizing(['immunizations', 'documents'], collect($ehi['items'])->pluck('section')->unique()->values()->all());
    }

    public function test_every_reader_sees_the_queue_but_only_writers_may_decide(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $manager = $this->grantee($patient, PhrPatientUserAccess::LEVEL_MANAGER);
        $viewer = $this->grantee($patient, PhrPatientUserAccess::LEVEL_VIEWER);
        $record = $this->record(PhrMedication::class, $patient, ['name' => 'Synthetic metformin', 'import_source' => 'ccda']);

        foreach ([[$owner, true], [$manager, true], [$viewer, false]] as [$user, $canManage]) {
            $this->actingAs($user)->getJson($this->url($patient))->assertOk()
                ->assertJsonPath('summary.total', 1)
                ->assertJsonPath('groups.0.items.0.id', $record->id)
                ->assertJsonPath('can_manage', $canManage);
            $this->actingAs($user)->getJson($this->url($patient).'/summary')->assertOk()->assertJsonPath('can_manage', $canManage);
        }

        // A view-only grant reads the queue and still cannot decide through the record's route.
        $version = $this->actingAs($viewer)->getJson($this->url($patient))->json('groups.0.items.0.version');
        $this->actingAs($viewer)->patchJson("/api/phr/patients/{$patient->id}/medications/{$record->id}/review", ['review_status' => PhrReviewStatus::CONFIRMED, 'version' => $version])->assertForbidden();

        // No grant: the patient's existence is not disclosed.
        $this->actingAs($this->createUser())->getJson($this->url($patient))->assertNotFound();
        $this->actingAs($this->createUser())->getJson($this->url($patient).'/summary')->assertNotFound();
        // Revoked grant.
        PhrPatientUserAccess::query()->where('patient_id', $patient->id)->where('user_id', $viewer->id)->delete();
        $this->actingAs($viewer)->getJson($this->url($patient))->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->getJson($this->url($patient))->assertUnauthorized();
    }

    public function test_decisions_go_through_the_routes_that_own_them_and_the_counts_follow(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $record = $this->record(PhrMedication::class, $patient, ['name' => 'Synthetic metformin', 'import_source' => 'ccda']);
        $stale = $this->record(PhrCondition::class, $patient, ['name' => 'Synthetic rhinitis', 'import_source' => 'ccda']);
        $job = $this->aiJob($this->document($patient, $owner, 'letter.pdf'), $owner);
        $result = $this->aiResult($job, 0, ['name' => 'Synthetic amoxicillin']);
        $package = $this->previewedEhiPackage($owner, $patient);
        $items = collect($this->actingAs($owner)->getJson($this->url($patient))->json('groups'))->flatMap(static fn (array $group): array => $group['items'])->keyBy('key');
        self::assertSame(12, $items->count());

        // A record: its section's review route, conditional on the version listed.
        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patient->id}/medications/{$record->id}/review", ['review_status' => PhrReviewStatus::CONFIRMED, 'version' => $items["record:medications:{$record->id}"]['version']])->assertOk();
        self::assertSame(PhrReviewStatus::CONFIRMED, $record->fresh()?->review_status);
        // Changed by its source after the page loaded: refused, still pending.
        $stale->forceFill(['notes' => 'changed'])->save();
        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patient->id}/conditions/{$stale->id}/review", ['review_status' => PhrReviewStatus::CONFIRMED, 'version' => $items["record:conditions:{$stale->id}"]['version']])->assertStatus(409);
        self::assertSame(PhrReviewStatus::PENDING, $stale->fresh()?->review_status);

        // An AI proposal: the Imports page's accept route, PhrImportReviewService.
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patient->id}/ai-imports/{$job->id}/results/{$result->id}/accept")->assertOk()->assertJsonPath('outcome', 'accepted');
        self::assertSame('Synthetic amoxicillin', PhrMedication::query()->where('patient_id', $patient->id)->where('import_source', 'genai')->sole()->name);

        // An EHI proposal: its review route, EhiProposalService, with the listed digest.
        $create = collect($items)->first(static fn (array $item): bool => $item['type'] === 'ehi_proposal' && $item['proposal']['kind'] === PhrEhiProposal::KIND_CREATE && $item['section'] === 'immunizations');
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patient->id}/ehi/proposals/{$create['id']}/review", ['decision' => PhrEhiProposal::DECISION_ACCEPT, 'preview_digest' => $create['proposal']['preview_digest']])->assertOk();
        self::assertSame(1, PhrImmunization::query()->where('patient_id', $patient->id)->count());

        $this->actingAs($owner)->getJson($this->url($patient).'/summary')->assertOk()
            ->assertJsonPath('summary.total', 9)
            ->assertJsonPath('summary.sections.medications.pending', 0)
            ->assertJsonPath('summary.sections.conditions.pending', 1)
            ->assertJsonPath('summary.sections.immunizations.pending', 5)
            ->assertJsonPath('summary.ai_results', 0);
        self::assertSame(PhrEhiPackage::query()->sole()->id, $package->id);
    }

    public function test_the_number_of_queries_does_not_grow_with_what_is_pending(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $seed = function (int $n) use ($owner, $patient): void {
            $document = $this->document($patient, $owner, "summary-{$n}.xml");
            foreach (range(1, $n) as $i) {
                $this->record(PhrMedication::class, $patient, ['name' => "Synthetic {$n}-{$i}", 'import_source' => 'ccda', 'source_document_id' => $document->id]);
                $this->record(PhrAllergy::class, $patient, ['substance' => "Synthetic {$n}-{$i}", 'import_source' => "agent-client:0199f2b7-0000-7000-8000-00000000000{$n}"]);
                $this->aiResult($this->aiJob($this->document($patient, $owner, "letter-{$n}-{$i}.pdf"), $owner), 0, ['name' => "Synthetic {$n}-{$i}"]);
            }
        };

        $seed(1);
        $few = $this->queriesFor(fn () => $this->actingAs($owner)->getJson($this->url($patient))->assertOk());
        $seed(6);
        $many = $this->queriesFor(fn () => $this->actingAs($owner)->getJson($this->url($patient))->assertOk()->assertJsonPath('summary.total', 21));

        self::assertSame($few, $many, 'listing more pending items must not issue more queries');
        self::assertLessThanOrEqual(25, $many);
        self::assertLessThanOrEqual(8, $this->queriesFor(fn () => $this->actingAs($owner)->getJson($this->url($patient).'/summary')->assertOk()));
    }

    public function test_the_patient_overview_counts_every_readable_patient_and_lists_what_needs_attention(): void
    {
        [$owner, $own] = $this->ownerAndPatient();
        $this->record(PhrMedication::class, $own, ['name' => 'Synthetic metformin', 'import_source' => 'ccda']);
        $this->record(PhrCondition::class, $own, ['name' => 'Synthetic rhinitis', 'review_status' => PhrReviewStatus::CONFIRMED]);
        $job = $this->aiJob($this->document($own, $owner, 'letter.pdf'), $owner);
        $this->aiResult($job, 0, ['name' => 'Synthetic amoxicillin']);
        $failed = $this->aiJob($this->document($own, $owner, 'scan.pdf'), $owner);
        $failed->forceFill(['status' => 'failed'])->save();
        [$sharer, $shared] = $this->ownerAndPatient();
        PhrPatientUserAccess::query()->create(['patient_id' => $shared->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER, 'granted_by_user_id' => $sharer->id, 'granted_at' => now()]);
        $this->record(PhrAllergy::class, $shared, ['substance' => 'Synthetic pollen']);
        [$stranger, $unrelated] = $this->ownerAndPatient();
        $this->record(PhrAllergy::class, $unrelated, ['substance' => 'Not yours']);

        $response = $this->actingAs($owner)->getJson('/api/phr/patients/overview')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $patients = collect($response->json('patients'))->keyBy('id');
        self::assertSame([$own->id, $shared->id], $patients->keys()->all());
        // Two clinical records and two documents; one medication and one AI proposal wait.
        self::assertSame([4, 2], [$patients[$own->id]['records'], $patients[$own->id]['pending']]);
        self::assertSame([1, 1], [$patients[$shared->id]['records'], $patients[$shared->id]['pending']]);
        self::assertNotNull($patients[$own->id]['last_updated']);

        $attention = collect($response->json('attention'));
        self::assertEqualsCanonicalizing(
            [['ready', 'records', $own->id, 1], ['ready', 'ai', $own->id, 1], ['ready', 'records', $shared->id, 1], ['failed', 'ai', $own->id, 0]],
            $attention->map(static fn (array $item): array => [$item['kind'], $item['origin'], $item['patient_id'], $item['count']])->all(),
        );
        self::assertSame('failed', $attention->last()['kind'], 'failures come after what is ready');
        self::assertSame('Synthetic letter.pdf', $attention->firstWhere('origin', 'ai')['name']);
        self::assertNotContains($unrelated->id, $attention->pluck('patient_id')->all());

        $this->actingAs($stranger)->getJson('/api/phr/patients/overview')->assertOk()
            ->assertJsonPath('patients.0.id', $unrelated->id)->assertJsonCount(1, 'patients');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/phr/patients/overview')->assertUnauthorized();
    }

    public function test_a_patient_card_counts_every_pending_ai_proposal_however_many_newer_jobs_there_are(): void
    {
        [$owner, $busy] = $this->ownerAndPatient();
        $quiet = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $quiet->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        // An older AI import of the quiet patient with a proposal still waiting...
        $older = $this->aiJob($this->document($quiet, $owner, 'old-letter.pdf'), $owner);
        $this->aiResult($older, 0, ['name' => 'Synthetic amoxicillin']);
        $this->record(PhrAllergy::class, $quiet, ['substance' => 'Synthetic pollen']);
        // ...then more than a page of newer AI imports of the other patient, failed or done.
        foreach (range(1, 105) as $i) {
            $job = $this->aiJob($this->document($busy, $owner, "scan-{$i}.pdf"), $owner);
            $job->forceFill(['status' => $i <= 101 ? 'failed' : 'imported'])->save();
            $this->aiResult($job, 0, ['name' => "Synthetic {$i}"], 'imported');
        }

        $patients = collect($this->actingAs($owner)->getJson('/api/phr/patients/overview')->assertOk()->json('patients'))->keyBy('id');

        self::assertSame(2, $patients[$quiet->id]['pending']);
        // One number, two places: a card says what that patient's Review page says.
        foreach ([$quiet, $busy] as $patient) {
            self::assertSame(
                $this->actingAs($owner)->getJson($this->url($patient).'/summary')->assertOk()->json('summary.total'),
                $patients[$patient->id]['pending'],
            );
        }
    }

    public function test_the_overview_query_count_does_not_grow_with_patients(): void
    {
        $owner = $this->createUser();
        $add = function () use ($owner): void {
            $patient = $this->ehiPatient($owner);
            PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
            $this->record(PhrMedication::class, $patient, ['name' => 'Synthetic', 'import_source' => 'ccda']);
            $this->aiResult($this->aiJob($this->document($patient, $owner, 'letter.pdf'), $owner), 0, ['name' => 'Synthetic']);
        };

        $add();
        $few = $this->queriesFor(fn () => $this->actingAs($owner)->getJson('/api/phr/patients/overview')->assertOk());
        $add();
        $add();
        $add();
        $many = $this->queriesFor(fn () => $this->actingAs($owner)->getJson('/api/phr/patients/overview')->assertOk()->assertJsonCount(4, 'patients'));

        self::assertSame($few, $many);
    }

    private function queriesFor(callable $request): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $count = 0;
        $request();
        $total = $count;
        DB::flushQueryLog();

        return $total;
    }

    private function url(PhrPatient $patient): string
    {
        return "/api/phr/patients/{$patient->id}/review";
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function record(string $model, PhrPatient $patient, array $attributes): mixed
    {
        return $model::query()->create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'review_status' => PhrReviewStatus::PENDING, ...$attributes]);
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);

        return [$owner, $patient];
    }

    private function grantee(PhrPatient $patient, string $level): User
    {
        $user = $this->createUser();
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $user->id, 'access_level' => $level, 'granted_by_user_id' => $patient->owner_user_id, 'granted_at' => now()]);

        return $user;
    }

    private function document(PhrPatient $patient, User $owner, string $filename): PhrDocument
    {
        $path = "patients/{$patient->id}/documents/synthetic/{$filename}";
        $contents = '%PDF-1.4 synthetic '.$filename;
        Storage::disk(PhrDocument::STORAGE_DISK)->put($path, $contents);

        return PhrDocument::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'uploaded_by_user_id' => $owner->id,
            'title' => 'Synthetic '.$filename, 'document_type' => 'other', 'original_filename' => $filename,
            'storage_disk' => PhrDocument::STORAGE_DISK, 'storage_path' => $path, 'mime_type' => 'application/pdf',
            'byte_size' => strlen($contents), 'file_hash' => hash('sha256', $contents), 'source' => 'manual_upload',
        ]);
    }

    private function aiJob(PhrDocument $document, User $actor): GenAiImportJob
    {
        $job = GenAiImportJob::query()->create([
            'user_id' => $actor->id, 'job_type' => 'phr_medication', 'file_hash' => $document->file_hash,
            'original_filename' => $document->original_filename, 's3_path' => 'genai-import/synthetic/'.$document->id,
            'mime_type' => $document->mime_type, 'file_size_bytes' => $document->byte_size,
            'execution_mode' => GenAiImportJob::EXECUTION_EXTERNAL, 'status' => 'parsed', 'parsed_at' => now(),
            'context_json' => json_encode(['patient_id' => $document->patient_id, 'document_id' => $document->id], JSON_THROW_ON_ERROR),
        ]);
        $document->update(['genai_job_id' => $job->id]);

        return $job;
    }

    /** @param array<string, mixed> $payload */
    private function aiResult(GenAiImportJob $job, int $index, array $payload, string $status = 'pending_review'): GenAiImportResult
    {
        return GenAiImportResult::query()->create([
            'job_id' => $job->id, 'result_index' => $index, 'status' => $status, 'produced_by' => 'synthetic',
            'result_json' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    private function previewedEhiPackage(User $owner, PhrPatient $patient): PhrEhiPackage
    {
        $package = $this->ehiIntakeDirectory(EhiClinicalPackage::standard()->build(), $patient, (int) $owner->id)->package;
        $source = PhrEhiSource::query()->where('patient_id', $patient->id)->first();
        $package = app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, $source?->id, $source === null ? 'Synthetic Clinic' : null);
        app(EhiProposalService::class)->preview($package, $patient, (int) $owner->id);

        return $package->refresh();
    }
}
