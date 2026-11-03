<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrDocument;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiSource;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiDocumentMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

/**
 * Before replacing a document's text, a reviewer sees the text it holds now. It is read
 * when the proposal is shown, so a proposal never holds a second copy of it, and only
 * while it is still the text the proposal was built from.
 */
class EhiDocumentBeforeTextTest extends TestCase
{
    use EhiIntakeFixtures;

    private const array NOTE = ['NOTE_ID' => '88001'];

    private User $owner;

    private PhrPatient $patient;

    private PhrDocument $document;

    private PhrEhiPackage $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Storage::fake('phr_exports');
        Storage::fake('phr_dicom');
        Storage::fake('phr_documents');
        Queue::fake();
        config(['phr.ehi_mappings' => [EhiDocumentMapping::class]]);

        $this->owner = $this->createUser();
        $this->patient = $this->ehiPatient($this->owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $this->owner->id, 'granted_at' => now()]);
        $first = $this->previewed(EhiClinicalPackage::standard()->build(), 'package', null);
        foreach ([self::NOTE, ['NOTE_ID' => '88002']] as $key) {
            $this->review($this->subject($first, $key), 'accept')->assertOk();
        }
        $this->document = PhrDocument::query()->where('external_id', 'NOTE:88001')->sole();
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    public function test_a_text_update_shows_the_text_the_record_holds_now_and_the_review_queue_does_too(): void
    {
        $this->previewCorrection();
        $stored = (string) $this->document->refresh()->extracted_text;
        self::assertStringContainsString('Synthetic final line 2', $stored);

        $shown = $this->shownBefore();
        self::assertSame($stored, $shown['extracted_text']);
        self::assertArrayNotHasKey('extracted_text_truncated', $shown);
        self::assertArrayNotHasKey('extracted_text_unavailable', $shown);
        self::assertNull($this->update()->before_json['extracted_text'] ?? null, 'no second copy is stored in the proposal');

        $queue = collect($this->actingAs($this->owner)->getJson("/api/phr/patients/{$this->patient->id}/review")->assertOk()->json('groups'))->pluck('items')->flatten(1)->firstWhere('id', $this->update()->id);
        self::assertSame($stored, $queue['proposal']['before']['extracted_text']);
    }

    public function test_an_update_that_leaves_the_text_alone_reads_no_text(): void
    {
        $build = EhiClinicalPackage::standard()->changing('HNO_INFO', self::NOTE, ['NOTE_DESC' => 'Synthetic retitled note'])->build();
        $this->second = $this->previewed($build, 'package-2', PhrEhiSource::query()->sole()->id);

        $shown = $this->shownBefore();
        self::assertSame('Synthetic progress note', $shown['title']);
        self::assertArrayNotHasKey('extracted_text', $shown);
        self::assertArrayNotHasKey('extracted_text_unavailable', $shown);
    }

    public function test_a_record_with_no_text_says_nothing_is_there_rather_than_that_it_is_hidden(): void
    {
        $this->document->forceFill(['extracted_text' => null])->save();
        $this->previewCorrection();

        $shown = $this->shownBefore();
        self::assertNull($shown['extracted_text_sha256']);
        self::assertArrayNotHasKey('extracted_text', $shown);
        self::assertArrayNotHasKey('extracted_text_unavailable', $shown);
    }

    public function test_text_that_has_changed_since_the_preview_is_not_shown_as_what_the_proposal_was_built_from(): void
    {
        $this->previewCorrection();
        $this->document->forceFill(['extracted_text' => 'A person retyped this meanwhile'])->save();

        $shown = $this->shownBefore();
        self::assertArrayNotHasKey('extracted_text', $shown);
        self::assertTrue($shown['extracted_text_unavailable']);
    }

    public function test_once_accepted_the_replaced_text_is_no_longer_there_to_show(): void
    {
        $this->previewCorrection();
        $this->review($this->update(), 'approve_update')->assertOk()->assertJsonPath('proposal.before.extracted_text_unavailable', true);

        $shown = $this->shownBefore('accepted');
        self::assertArrayNotHasKey('extracted_text', $shown);
        self::assertTrue($shown['extracted_text_unavailable']);
    }

    public function test_the_text_of_a_deleted_or_retracted_document_is_not_shown(): void
    {
        $this->previewCorrection();
        $this->document->forceFill(['retracted_at' => now()])->save();
        self::assertArrayNotHasKey('extracted_text', $this->shownBefore());

        $this->document->forceFill(['retracted_at' => null])->save();
        $this->document->delete();
        $shown = $this->shownBefore();
        self::assertArrayNotHasKey('extracted_text', $shown);
        self::assertTrue($shown['extracted_text_unavailable']);
    }

    public function test_someone_who_cannot_see_the_patient_cannot_read_it(): void
    {
        $this->previewCorrection();

        $this->actingAs($this->createUser())->getJson("/api/phr/patients/{$this->patient->id}/ehi/packages/{$this->second->id}/proposals")->assertNotFound();
    }

    public function test_longer_text_is_cut_on_a_character_boundary_and_says_so(): void
    {
        config(['phr.native_restore_max_record_bytes' => 8 * 8192]);
        $this->document->forceFill(['extracted_text' => str_repeat('é', 5000)])->save();
        $this->previewCorrection();

        $shown = $this->shownBefore();
        self::assertTrue($shown['extracted_text_truncated']);
        self::assertSame(str_repeat('é', 4096), $shown['extracted_text']);
    }

    public function test_a_page_of_text_updates_reads_their_documents_once(): void
    {
        $build = EhiClinicalPackage::standard()
            ->changing('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '9102', 'LINE' => '2'], ['NOTE_TEXT' => 'Synthetic corrected line 2'])
            ->changing('HNO_INFO', ['NOTE_ID' => '88002'], ['NOTE_DESC' => 'Synthetic retitled scan'])
            ->build()->with(EhiClinicalPackage::RTF_MEMBER, '{\\rtf1\\ansi\\pard Synthetic corrected scan.\\par}');
        $this->second = $this->previewed($build, 'package-2', PhrEhiSource::query()->sole()->id);
        self::assertSame(2, PhrEhiProposal::query()->where('package_id', $this->second->id)->where('kind', PhrEhiProposal::KIND_UPDATE)->count());

        $reads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$reads): void {
            if (preg_match('/from\s+["`]?phr_documents["`]?/i', $query->sql) === 1) {
                $reads++;
            }
        });
        $listed = $this->actingAs($this->owner)->getJson("/api/phr/patients/{$this->patient->id}/ehi/packages/{$this->second->id}/proposals")->assertOk()->json('proposals');

        self::assertCount(2, array_filter($listed, static fn (array $proposal): bool => is_string($proposal['before']['extracted_text'] ?? null)));
        self::assertSame(1, $reads);
    }

    private function previewCorrection(): void
    {
        $corrected = EhiClinicalPackage::standard()->changing('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '9102', 'LINE' => '2'], ['NOTE_TEXT' => 'Synthetic corrected line 2']);
        $this->second = $this->previewed($corrected->build(), 'package-2', PhrEhiSource::query()->sole()->id);
    }

    private function update(): PhrEhiProposal
    {
        return $this->subject($this->second, self::NOTE);
    }

    /** @return array<string, mixed> what the route gives the review screen */
    private function shownBefore(string $status = 'pending'): array
    {
        $listed = $this->actingAs($this->owner)->getJson("/api/phr/patients/{$this->patient->id}/ehi/packages/{$this->second->id}/proposals?status={$status}")->assertOk()->json('proposals');

        return collect($listed)->firstWhere('id', $this->update()->id)['before'];
    }

    private function previewed(EhiSyntheticPackage $build, string $name, ?int $sourceId): PhrEhiPackage
    {
        $package = $this->ehiIntakeDirectory($build, $this->patient, (int) $this->owner->id, $name)->package;
        $package = app(EhiBindingService::class)->bind($package, $this->patient, (int) $this->owner->id, $sourceId, $sourceId === null ? 'Synthetic Clinic' : null);
        app(EhiProposalService::class)->preview($package, $this->patient, (int) $this->owner->id);

        return $package->refresh();
    }

    /** @param  array<string, string>  $key */
    private function subject(PhrEhiPackage $package, array $key): PhrEhiProposal
    {
        return PhrEhiProposal::query()->where('package_id', $package->id)->where('subject_key', EhiMappingContext::keyDigest($key))->firstOrFail();
    }

    private function review(PhrEhiProposal $proposal, string $decision): TestResponse
    {
        return $this->actingAs($this->owner)->postJson("/api/phr/patients/{$this->patient->id}/ehi/proposals/{$proposal->id}/review", ['decision' => $decision, 'preview_digest' => $proposal->preview_digest]);
    }
}
