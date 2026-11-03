<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrDocument;
use App\Models\PhrEhiBlob;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiSource;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiDocumentMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use Closure;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

/**
 * A package that could not read a note's text says nothing about that text. Replacing
 * what is stored with what happened to be left would lose lines the source never
 * changed, so an update built from it leaves the text alone and says why. Through real
 * intake and projection, and the review route.
 */
class EhiDocumentTextGapsTest extends TestCase
{
    use EhiIntakeFixtures;

    private const array NOTE = ['NOTE_ID' => '88001'];

    private const array RETITLED = ['NOTE_DESC' => 'Synthetic retitled note'];

    private const string LINKED_RTF = 'notes/SYN-88001.rtf';

    private const string LINKED_IMAGE = 'media/SYN-88001.png';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Storage::fake('phr_exports');
        Storage::fake('phr_dicom');
        Storage::fake('phr_documents');
        Queue::fake();
        config(['phr.ehi_mappings' => [EhiDocumentMapping::class]]);
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function gaps(): array
    {
        return [
            'a line repeats a key and is quarantined' => ['quarantined', null],
            'a line cannot be parsed' => ['malformed', null],
            'the whole table is withheld' => ['withheld', null],
            'the table is missing' => ['missing', null],
            'a line number cannot be ordered' => ['unordered', null],
            'quarantined, with an image' => ['quarantined', self::LINKED_IMAGE],
            'withheld, with an image' => ['withheld', self::LINKED_IMAGE],
            'missing, with an image' => ['missing', self::LINKED_IMAGE],
            'unordered, with an image' => ['unordered', self::LINKED_IMAGE],
            'quarantined, with rich text' => ['quarantined', self::LINKED_RTF],
            'withheld, with rich text' => ['withheld', self::LINKED_RTF],
            'missing, with rich text' => ['missing', self::LINKED_RTF],
            'unordered, with rich text' => ['unordered', self::LINKED_RTF],
        ];
    }

    #[DataProvider('gaps')]
    public function test_text_a_package_could_not_read_is_not_replaced_when_the_note_also_changed(string $gap, ?string $file): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $first = $this->previewed($this->buildWith($this->withFile(EhiClinicalPackage::standard(), $file), $file), $owner, $patient);
        $this->review($owner, $patient, $this->subject($first, self::NOTE), 'accept')->assertOk();
        $document = PhrDocument::query()->where('external_id', 'NOTE:88001')->sole();
        $stored = (string) $document->extracted_text;
        self::assertStringContainsString('Synthetic final line 2', $stored);

        $changed = $this->withFile(EhiClinicalPackage::standard()->changing('HNO_INFO', self::NOTE, self::RETITLED), $file);
        $second = $this->previewed($this->damaged($changed, $gap, $file), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id);
        $update = $this->subject($second, self::NOTE);

        if ($file === null && in_array($gap, ['withheld', 'missing', 'unordered'], true)) {
            self::assertSame(PhrEhiProposal::KIND_RETAIN, $update->kind, 'no content is left to update with');
            self::assertSame($stored, $document->refresh()->extracted_text);

            return;
        }
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);
        self::assertSame(['title'], $update->proposed_json['changed'], 'the title still changes; the text is not proposed');
        self::assertTrue($update->proposed_json['ehi']['text_unavailable'], 'and the proposal says why');

        $this->review($owner, $patient, $update, 'approve_update')->assertOk();
        $document->refresh();
        self::assertSame('Synthetic retitled note', $document->title);
        self::assertSame($stored, $document->extracted_text, 'every stored line is still there');
    }

    /** @return array<string, array{0: string}> */
    public static function unreadableRichText(): array
    {
        return ['unsupported' => ['unsupported'], 'its stored bytes are gone' => ['gone']];
    }

    #[DataProvider('unreadableRichText')]
    public function test_text_from_rich_text_that_can_no_longer_be_read_is_kept(string $how): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $key = ['NOTE_ID' => '88002'];
        $first = $this->previewed(EhiClinicalPackage::standard()->build(), $owner, $patient);
        $this->review($owner, $patient, $this->subject($first, $key), 'accept')->assertOk();
        $document = PhrDocument::query()->where('external_id', 'NOTE:88002')->sole();
        $stored = (string) $document->extracted_text;
        self::assertStringContainsString('Synthetic scanned note line one', $stored);

        $retitled = EhiClinicalPackage::standard()->changing('HNO_INFO', $key, self::RETITLED);
        $build = $retitled->build();
        $rtf = EhiClinicalPackage::RTF_MEMBER;
        $second = $how === 'unsupported'
            ? $this->previewed($build->with($rtf, '{\\rtf1 '.str_repeat('{', 5000).' Synthetic}'), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id)
            : $this->previewed($build->with($rtf, EhiClinicalPackage::RTF_BYTES.' '), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id, function (PhrEhiPackage $package) use ($rtf): void {
                $member = PhrEhiPackageMember::query()->where('package_id', $package->id)->where('path', 'like', '%'.basename($rtf))->sole();
                $blob = PhrEhiBlob::query()->where('patient_id', $package->patient_id)->where('sha256', $member->sha256)->sole();
                Storage::disk($blob->storage_disk)->delete($blob->storage_path);
            });
        $update = $this->subject($second, $key);

        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);
        self::assertSame(['title'], $update->proposed_json['changed']);
        $this->review($owner, $patient, $update, 'approve_update')->assertOk();
        self::assertSame($stored, $document->refresh()->extracted_text);
    }

    public function test_a_gap_changes_nothing_when_nothing_else_about_the_note_changed(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $first = $this->previewed(EhiClinicalPackage::standard()->build(), $owner, $patient);
        $this->review($owner, $patient, $this->subject($first, self::NOTE), 'accept')->assertOk();

        $second = $this->previewed($this->damaged(EhiClinicalPackage::standard(), 'malformed', null), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id);

        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $this->subject($second, self::NOTE)->kind);
    }

    public function test_a_gap_in_another_notes_lines_does_not_hold_back_a_real_correction(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $first = $this->previewed(EhiClinicalPackage::standard()->build(), $owner, $patient);
        $this->review($owner, $patient, $this->subject($first, self::NOTE), 'accept')->assertOk();

        $corrected = EhiClinicalPackage::standard()
            ->changing('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '9102', 'LINE' => '2'], ['NOTE_TEXT' => 'Synthetic corrected line 2'])
            ->adding('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '9301', 'LINE' => '1', 'NOTE_ID' => '88003', 'NOTE_TEXT' => 'Synthetic deleted line']);
        $second = $this->previewed($corrected->build(), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id);
        $update = $this->subject($second, self::NOTE);

        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);
        self::assertContains('extracted_text', $update->proposed_json['changed']);
        self::assertArrayNotHasKey('text_unavailable', $update->proposed_json['ehi']);
        $this->review($owner, $patient, $update, 'approve_update')->assertOk();
        self::assertStringContainsString('Synthetic corrected line 2', (string) PhrDocument::query()->where('external_id', 'NOTE:88001')->sole()->extracted_text);
    }

    public function test_a_source_that_really_shortened_a_note_can_still_be_reviewed(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $first = $this->previewed(EhiClinicalPackage::standard()->build(), $owner, $patient);
        $this->review($owner, $patient, $this->subject($first, self::NOTE), 'accept')->assertOk();

        $shorter = EhiClinicalPackage::standard()->changing('HNO_INFO', self::NOTE, self::RETITLED)->without('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '9102', 'LINE' => '2']);
        $second = $this->previewed($shorter->build(), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id);
        $update = $this->subject($second, self::NOTE);

        self::assertSame(['title', 'extracted_text'], $update->proposed_json['changed'], 'every row was read, so the source said this');
        self::assertArrayNotHasKey('text_unavailable', $update->proposed_json['ehi']);
    }

    public function test_a_first_import_with_a_gap_says_its_text_may_be_incomplete_and_a_whole_export_can_complete_it(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $first = $this->previewed($this->damaged(EhiClinicalPackage::standard(), 'quarantined', null), $owner, $patient);
        $create = $this->subject($first, self::NOTE);

        self::assertSame(PhrEhiProposal::KIND_CREATE, $create->kind);
        self::assertTrue($create->proposed_json['ehi']['text_incomplete']);
        $this->review($owner, $patient, $create, 'accept')->assertOk();
        $document = PhrDocument::query()->where('external_id', 'NOTE:88001')->sole();
        self::assertStringNotContainsString('Synthetic final line 2', (string) $document->extracted_text);
        self::assertStringContainsString('may be incomplete', (string) $document->summary);

        $second = $this->previewed(EhiClinicalPackage::standard()->build(), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id);
        $update = $this->subject($second, self::NOTE);
        self::assertSame(['extracted_text'], $update->proposed_json['changed']);
        $this->review($owner, $patient, $update, 'approve_update')->assertOk();
        self::assertStringContainsString('Synthetic final line 2', (string) $document->refresh()->extracted_text);
    }

    private function withFile(EhiClinicalPackage $package, ?string $file): EhiClinicalPackage
    {
        return $file === null ? $package : $package->adding('DOC_INFORMATION', ['DOC_INFO_ID' => '5002', 'DOC_PT_ID' => EhiClinicalPackage::PATIENT_KEY, 'DOC_CSN' => '7001', 'DOC_HNO_ID' => '88001', 'SCAN_FILE' => basename($file), 'DOC_DESCR' => 'Synthetic linked file']);
    }

    private function buildWith(EhiClinicalPackage $package, ?string $file): EhiSyntheticPackage
    {
        return $this->withBytes($package->build(), $file);
    }

    private function withBytes(EhiSyntheticPackage $build, ?string $file): EhiSyntheticPackage
    {
        return match ($file) {
            null => $build,
            self::LINKED_RTF => $build->with($file, '{\\rtf1\\ansi\\pard Synthetic different words in the file.\\par}'),
            default => $build->with($file, "\x89PNG\r\n\x1A\nsynthetic-linked-image"),
        };
    }

    private function damaged(EhiClinicalPackage $package, string $gap, ?string $file): EhiSyntheticPackage
    {
        $build = match ($gap) {
            'quarantined' => $package->adding('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '9102', 'LINE' => '2', 'NOTE_ID' => '88001', 'NOTE_TEXT' => 'Synthetic final line 2'])->build(),
            'malformed' => (static function () use ($package): EhiSyntheticPackage {
                // One of the note's real lines arrives damaged.
                $build = $package->without('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '9102', 'LINE' => '2'])->build();

                return $build->with('HNO_PLAIN_TEXT.tsv', $build->files['HNO_PLAIN_TEXT.tsv']."only\ttwo\n");
            })(),
            'withheld' => $package->buildWithDocumentedKey('HNO_PLAIN_TEXT', ['NOTE_ID']),
            'missing' => $package->build()->without('HNO_PLAIN_TEXT.tsv'),
            'unordered' => $package->changing('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '9102', 'LINE' => '2'], ['LINE' => 'two'])->build(),
        };

        return $this->withBytes($build, $file);
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);

        return [$owner, $patient];
    }

    /** @param  (Closure(PhrEhiPackage): void)|null  $beforePreview */
    private function previewed(EhiSyntheticPackage $build, User $owner, PhrPatient $patient, string $name = 'package', ?int $sourceId = null, ?Closure $beforePreview = null): PhrEhiPackage
    {
        $package = $this->ehiIntakeDirectory($build, $patient, (int) $owner->id, $name)->package;
        $package = app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, $sourceId, $sourceId === null ? 'Synthetic Clinic' : null);
        if ($beforePreview !== null) {
            $beforePreview($package);
        }
        app(EhiProposalService::class)->preview($package, $patient, (int) $owner->id);

        return $package->refresh();
    }

    /** @param  array<string, string>  $key */
    private function subject(PhrEhiPackage $package, array $key): PhrEhiProposal
    {
        return PhrEhiProposal::query()->where('package_id', $package->id)->where('subject_key', EhiMappingContext::keyDigest($key))->firstOrFail();
    }

    private function review(User $owner, PhrPatient $patient, PhrEhiProposal $proposal, string $decision): TestResponse
    {
        return $this->actingAs($owner)->postJson("/api/phr/patients/{$patient->id}/ehi/proposals/{$proposal->id}/review", ['decision' => $decision, 'preview_digest' => $proposal->preview_digest]);
    }
}
