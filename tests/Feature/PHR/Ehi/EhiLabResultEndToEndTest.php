<?php

namespace Tests\Feature\PHR\Ehi;

use App\Jobs\PHR\IntakeEhiUploadJob;
use App\Jobs\PHR\PreviewEhiPackageJob;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiUpload;
use App\Models\PhrLabResult;
use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\DataHub\PhrPatientDeletionCleanupService;
use App\Services\PHR\DataHub\PhrPatientDeletionService;
use App\Services\PHR\Export\PhrCcdaExporter;
use App\Services\PHR\Export\PhrExportDataService;
use App\Services\PHR\Export\PhrFhirExporter;
use App\Services\PHR\Export\PhrPdfSummaryRenderer;
use App\Services\PHR\Import\Ehi\EhiPreviewQueue;
use App\Services\PHR\Import\Ehi\EhiUploadService;
use App\Services\PHR\Import\Ehi\Mapping\EhiLabResultMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use App\Services\PHR\Review\PhrReviewQueueService;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\TestCase;

/**
 * Labs from the browser routes to the record: upload, intake, bind, preview, the
 * review surfaces, an accept that lands on the Labs page's own route, then backup,
 * deletion, and restore. The production registry, not a test double. All synthetic.
 */
class EhiLabResultEndToEndTest extends TestCase
{
    use EhiIntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Storage::fake('phr_exports');
        Storage::fake('phr_dicom');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    public function test_an_accepted_lab_proposal_reaches_the_record_and_survives_backup_and_restore(): void
    {
        self::assertContains(EhiLabResultMapping::class, config('phr.ehi_mappings'), 'the mapping is registered in production config');
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $manual = PhrLabResult::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'review_status' => PhrReviewStatus::CONFIRMED, 'analyte' => 'SYNTHETIC GLUCOSE', 'value' => '95', 'value_numeric' => '95', 'collection_datetime' => '2024-03-05 08:00:00']);

        $package = $this->previewedFromBrowser($owner, $patient);

        // Both review surfaces offer it, under the labs section.
        $base = "/api/phr/patients/{$patient->id}";
        $this->actingAs($owner)->getJson("{$base}/ehi/pending")->assertOk()->assertJsonPath('pending.lab_result.count', 8);
        $queue = $this->actingAs($owner)->getJson("{$base}/review")->assertOk();
        $group = collect($queue->json('groups'))->firstWhere('key', "ehi:{$package->id}");
        $items = collect($group['items'])->where('section', 'labs');
        self::assertCount(8, $items);
        self::assertSame(8, app(PhrReviewQueueService::class)->summary($patient)['sections']['labs']['pending']);

        $listed = collect($this->actingAs($owner)->getJson("{$base}/ehi/packages/{$package->id}/proposals?status=pending")->assertOk()->json('proposals'))->where('mapping', EhiLabResultMapping::NAME);
        self::assertCount(8, $listed);

        // Accept: an inequality lands on the page's own route as written.
        $ldl = $this->proposal($package, '8101', '66000.01', '1');
        $review = $this->actingAs($owner)->postJson("{$base}/ehi/proposals/{$ldl->id}/review", ['decision' => 'accept', 'preview_digest' => $ldl->preview_digest])->assertOk();
        $review->assertJsonPath('proposal.status', PhrEhiProposal::STATUS_ACCEPTED);
        $recordId = $review->json('record.id');
        self::assertSame('phr_lab_results', $review->json('record.table'));

        $shown = $this->actingAs($owner)->getJson("{$base}/lab-results/{$recordId}")->assertOk()->json('lab_result');
        self::assertSame('<50', $shown['value']);
        self::assertSame('<', $shown['value_comparator']);
        self::assertNull($shown['value_numeric']);
        self::assertSame('2024-03-05 09:15:00', $shown['collection_datetime']);
        self::assertSame('2024-03-05 09:15', $shown['collected_at_source']);
        self::assertSame('minute', $shown['collected_at_precision']);
        self::assertSame('local', $shown['collected_at_timezone']);
        self::assertSame('41', $shown['analyte_code']);
        self::assertSame('confirmed', $shown['review_status']);
        self::assertStringContainsString('Synthetic comment line 10', (string) $shown['result_comment']);
        self::assertSame(1, collect($this->actingAs($owner)->getJson("{$base}/lab-results")->assertOk()->json('lab_results'))->where('id', $recordId)->count());

        // The link is the evidence: every row the result relied on points at it.
        self::assertGreaterThanOrEqual(4, PhrEhiRecordLink::query()->where('lab_result_id', $recordId)->where('link_kind', PhrEhiRecordLink::KIND_PROJECTED)->count());

        // A probable duplicate of a manual record: a person links it, the record stays.
        $glucose = $this->proposal($package, '8101', '66000.01', '2');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $glucose->kind);
        $this->actingAs($owner)->postJson("{$base}/ehi/proposals/{$glucose->id}/review", ['decision' => 'link_existing', 'preview_digest' => $glucose->preview_digest, 'link_id' => $manual->id])
            ->assertOk()->assertJsonPath('record.id', $manual->id);
        self::assertSame(PhrEhiRecordLink::KIND_BRIDGED, PhrEhiRecordLink::query()->where('lab_result_id', $manual->id)->value('link_kind'));
        self::assertNull($manual->refresh()->import_source);

        // An unzoned time is not exported as UTC, and the inequality still travels.
        $bundle = json_decode(app(PhrFhirExporter::class)->bundleJson(app(PhrExportDataService::class)->load($patient->refresh())), true, flags: JSON_THROW_ON_ERROR);
        $observation = collect($bundle['entry'])->pluck('resource')->first(fn (array $resource): bool => $resource['resourceType'] === 'Observation' && ($resource['code']['text'] ?? null) === 'SYNTHETIC LDL');
        self::assertSame('2024-03-05', $observation['effectiveDateTime']);
        self::assertSame('<50', $observation['valueString']);
        self::assertArrayNotHasKey('valueQuantity', array_filter($observation));
        $ccda = app(PhrCcdaExporter::class)->documentXml(app(PhrExportDataService::class)->load($patient->refresh()));
        self::assertStringNotContainsString('20240305091500', $ccda);
        self::assertStringNotContainsString('20240305114030', $ccda, 'a C-CDA time carries an offset, which the source did not state');

        // ---- native backup, patient deletion, restore.
        $counts = $this->labEvidenceCounts((int) $patient->id);
        self::assertGreaterThan(0, $counts['phr_ehi_record_links']);
        $backups = app(PhrNativeBackupService::class);
        $backup = $backups->generate($backups->createQueuedBackup($patient, $owner->id));
        $archive = tempnam(sys_get_temp_dir(), 'ehi-lab-e2e-');
        self::assertIsString($archive);
        self::assertTrue(copy(Storage::disk('phr_exports')->path((string) $backup->storage_path), $archive));

        $deletions = app(PhrPatientDeletionService::class);
        $plan = $deletions->preview($patient);
        self::assertSame([], $plan->blockers);
        $deletion = $deletions->delete($patient, $owner->id, $plan->digest, true);
        app(PhrPatientDeletionCleanupService::class)->cleanup($deletion->id);
        self::assertSame(0, DB::table('phr_ehi_record_links')->count(), 'links go with the patient');
        self::assertSame(0, DB::table('phr_lab_results')->where('patient_id', $patient->id)->count());

        $restore = app(PhrNativeRestoreService::class);
        $attempt = $restore->preview($restore->createPreview(new UploadedFile($archive, 'synthetic-native.zip', 'application/zip', null, true), $owner->id, false));
        self::assertSame(PhrNativeRestoreAttempt::STATUS_PREVIEW_READY, $attempt->status, (string) $attempt->failure_category);
        $restore->apply($attempt->refresh());
        self::assertSame(PhrNativeRestoreAttempt::STATUS_COMPLETED, $attempt->refresh()->status, (string) $attempt->failure_category);
        @unlink($archive);

        $restoredId = (int) $attempt->target_patient_root_id;
        self::assertSame($counts, $this->labEvidenceCounts($restoredId));
        $restored = PhrLabResult::query()->where('patient_id', $restoredId)->where('analyte', 'SYNTHETIC LDL')->sole();
        self::assertSame('<50', $restored->value);
        self::assertSame('<', $restored->value_comparator);
        self::assertSame('2024-03-05 09:15', $restored->collected_at_source);
        self::assertSame(1, DB::table('phr_ehi_proposal_decisions')->where('patient_id', $restoredId)->where('linked_lab_result_id', $restored->id)->count());
        self::assertGreaterThanOrEqual(4, DB::table('phr_ehi_record_links')->where('patient_id', $restoredId)->where('lab_result_id', $restored->id)->count());
        $bridged = DB::table('phr_ehi_record_links')->where('patient_id', $restoredId)->where('link_kind', PhrEhiRecordLink::KIND_BRIDGED)->first();
        self::assertNotNull($bridged);
        self::assertSame('ehi-bridge-1', $bridged->bridge_version);
        self::assertSame('person_confirmed', $bridged->bridge_rule);
    }

    public function test_editing_an_imported_result_on_its_page_drops_what_the_source_said_of_the_old_value(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $package = $this->previewedFromBrowser($owner, $patient);
        $ldl = $this->proposal($package, '8101', '66000.01', '1');
        $base = "/api/phr/patients/{$patient->id}";
        $recordId = $this->actingAs($owner)->postJson("{$base}/ehi/proposals/{$ldl->id}/review", ['decision' => 'accept', 'preview_digest' => $ldl->preview_digest])->assertOk()->json('record.id');

        $edited = $this->actingAs($owner)->patchJson("{$base}/lab-results/{$recordId}", ['value' => '6'])->assertOk()->json('lab_result');

        self::assertSame('6', $edited['value']);
        self::assertNull($edited['value_comparator'], 'a person\'s 6 is not "<6"');
        self::assertNull($edited['value_raw']);
        self::assertSame('2024-03-05 09:15', $edited['collected_at_source'], 'what was not edited keeps its provenance');
        self::assertSame('6', PhrLabResult::query()->findOrFail($recordId)->valueWithComparator());
    }

    public function test_an_inequality_stated_only_beside_the_value_shows_everywhere_the_value_is_read(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $build = EhiClinicalPackage::withLabs()->changing('ORDER_RESULTS', ['ORDER_PROC_ID' => '8101', 'ORD_DATE_REAL' => '66000.01', 'LINE' => '2'], ['ORD_VALUE' => '95', 'ORD_RAW_VALUE' => '', 'VALUE_NORMALIZED' => "<\x1095"]);
        $package = $this->previewedFromBrowser($owner, $patient, $build);
        $proposal = $this->proposal($package, '8101', '66000.01', '2');
        $base = "/api/phr/patients/{$patient->id}";
        $recordId = $this->actingAs($owner)->postJson("{$base}/ehi/proposals/{$proposal->id}/review", ['decision' => 'accept', 'preview_digest' => $proposal->preview_digest])->assertOk()->json('record.id');
        $record = PhrLabResult::query()->findOrFail($recordId);
        self::assertSame('95', $record->value, 'the stored value stays as the source wrote it');
        self::assertSame('<', $record->value_comparator);
        $data = app(PhrExportDataService::class)->load($patient->refresh());

        // The C-CDA narrative, as well as its structured entry.
        $ccda = app(PhrCcdaExporter::class)->documentXml($data);
        self::assertStringContainsString('<td>&lt;95</td>', $ccda, 'the Results narrative');

        // The PDF summary, read back as text.
        $pdf = (new Parser)->parseContent(app(PhrPdfSummaryRenderer::class)->render($data))->getText();
        self::assertStringContainsString('<95 mg/dL', $pdf);

        // FHIR, which never claims an exact quantity for it.
        $bundle = json_decode(app(PhrFhirExporter::class)->bundleJson($data), true, flags: JSON_THROW_ON_ERROR);
        $observation = collect($bundle['entry'])->pluck('resource')->first(fn (array $resource): bool => $resource['resourceType'] === 'Observation' && ($resource['code']['text'] ?? null) === 'SYNTHETIC GLUCOSE');
        self::assertSame('<95', $observation['valueString']);
        self::assertNull($observation['valueQuantity'] ?? null);

        // Patient search, and the Review page's line for a record back under review.
        $hit = collect($this->actingAs($owner)->getJson("{$base}/search?q=SYNTHETIC+GLUCOSE")->assertOk()->json('results'))->firstWhere('id', $recordId);
        self::assertIsArray($hit);
        self::assertStringStartsWith('<95', (string) $hit['description']);
        $record->forceFill(['review_status' => PhrReviewStatus::PENDING])->save();
        $item = collect($this->actingAs($owner)->getJson("{$base}/review")->assertOk()->json('groups'))->pluck('items')->flatten(1)->firstWhere('id', $recordId);
        self::assertIsArray($item);
        self::assertStringStartsWith('<95 mg/dL', (string) $item['detail']);

        // The panel route carries the comparator the Labs page shows it with.
        $row = collect($this->actingAs($owner)->getJson("{$base}/labs/{$recordId}")->assertOk()->json('panel.rows'))->firstWhere('id', $recordId);
        self::assertSame('<', $row['value_comparator']);
    }

    public function test_a_duplicate_is_still_found_when_only_one_side_writes_the_inequality_in_its_value(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $earlier = PhrLabResult::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'review_status' => PhrReviewStatus::CONFIRMED, 'import_source' => 'ccda', 'external_id' => 'ccda-lab-'.sha1('synthetic'), 'analyte' => 'SYNTHETIC GLUCOSE', 'value' => '<95', 'result_datetime' => '2024-03-05 10:00:00']);
        $build = EhiClinicalPackage::withLabs()->changing('ORDER_RESULTS', ['ORDER_PROC_ID' => '8101', 'ORD_DATE_REAL' => '66000.01', 'LINE' => '2'], ['ORD_VALUE' => '95', 'VALUE_NORMALIZED' => "<\x1095"]);
        $package = $this->previewedFromBrowser($owner, $patient, $build);

        $proposal = $this->proposal($package, '8101', '66000.01', '2');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $proposal->kind);
        self::assertSame([$earlier->id], $proposal->candidate_ids_json);
    }

    public function test_review_access_is_rechecked_for_a_lab_proposal(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $viewer = $this->createUser();
        $grant = PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $viewer->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $package = $this->previewedFromBrowser($owner, $patient);
        $proposal = $this->proposal($package, '8102', '66001', '1');
        $url = "/api/phr/patients/{$patient->id}/ehi/proposals/{$proposal->id}/review";

        $this->actingAs($viewer)->postJson($url, ['decision' => 'accept', 'preview_digest' => $proposal->preview_digest])->assertForbidden();
        self::assertSame(0, PhrLabResult::query()->count());

        $this->actingAs($owner)->postJson($url, ['decision' => 'accept', 'preview_digest' => 'stale'])->assertStatus(409)->assertExactJson(['error' => 'stale_preview']);
        self::assertSame(0, PhrLabResult::query()->count());

        $grant->delete();
        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patient->id}/lab-results")->assertNotFound();
    }

    public function test_the_cli_reviews_a_lab_proposal_and_names_the_record(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $package = $this->previewedFromBrowser($owner, $patient);
        $proposal = $this->proposal($package, '8102', '66001', '1');

        $exit = Artisan::call('phr:ehi:review', ['--patient' => $patient->id, '--actor' => $owner->id, '--proposal' => $proposal->id, '--decision' => 'accept', '--preview-digest' => $proposal->preview_digest]);
        $output = Artisan::output();
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('record: phr_lab_results '.PhrLabResult::query()->sole()->id, $output);
    }

    private function previewedFromBrowser(User $owner, PhrPatient $patient, ?EhiClinicalPackage $build = null, ?int $sourceId = null): PhrEhiPackage
    {
        $zip = ($build ?? EhiClinicalPackage::withLabs())->build()->writeZip($this->ehiRoot.'/'.bin2hex(random_bytes(4)).'.zip');
        $bytes = (string) file_get_contents($zip);
        $base = "/api/phr/patients/{$patient->id}/ehi";
        $uploadId = $this->actingAs($owner)->postJson("{$base}/uploads", ['file_size_bytes' => strlen($bytes)])->assertCreated()->json('upload.id');
        $this->actingAs($owner)->post("{$base}/uploads/{$uploadId}/chunks", ['chunk' => UploadedFile::fake()->createWithContent('chunk.bin', $bytes), 'offset' => 0], ['Accept' => 'application/json'])->assertOk();
        $this->actingAs($owner)->postJson("{$base}/uploads/{$uploadId}/intake")->assertStatus(202);
        (new IntakeEhiUploadJob($uploadId))->handle(app(EhiUploadService::class));
        $packageId = PhrEhiUpload::query()->findOrFail($uploadId)->package_id;
        $this->actingAs($owner)->postJson("{$base}/packages/{$packageId}/binding", $sourceId === null ? ['new_source_label' => 'Synthetic Clinic'] : ['source_id' => $sourceId])->assertOk();
        $runId = $this->actingAs($owner)->postJson("{$base}/packages/{$packageId}/preview")->assertStatus(202)->json('preview_run.id');
        (new PreviewEhiPackageJob($runId))->handle(app(EhiPreviewQueue::class));

        return PhrEhiPackage::query()->findOrFail($packageId);
    }

    private function proposal(PhrEhiPackage $package, string $order, string $contact, string $line): PhrEhiProposal
    {
        return PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', EhiLabResultMapping::NAME)
            ->where('subject_key', EhiMappingContext::keyDigest(['ORDER_PROC_ID' => $order, 'ORD_DATE_REAL' => $contact, 'LINE' => $line]))
            ->firstOrFail();
    }

    /** @return array<string, int> */
    private function labEvidenceCounts(int $patientId): array
    {
        $counts = [];
        foreach (['phr_ehi_entities', 'phr_ehi_revisions', 'phr_ehi_occurrences', 'phr_ehi_proposals', 'phr_ehi_proposal_revisions', 'phr_ehi_proposal_decisions', 'phr_ehi_record_links', 'phr_lab_results'] as $table) {
            $counts[$table] = DB::table($table)->where('patient_id', $patientId)->count();
        }

        return $counts;
    }
}
