<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrDocument;
use App\Models\PhrEhiBlob;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Models\PhrPatient;
use App\Services\PHR\Import\Ehi\EhiMemberKind;
use App\Services\PHR\Import\Ehi\Mapping\Document\EhiDocumentApplyRefused;
use App\Services\PHR\Import\Ehi\Mapping\Document\EhiDocumentFinding;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyRefused;
use App\Services\PHR\Import\Ehi\Mapping\EhiDocumentMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingRegistry;
use App\Services\PHR\Import\Ehi\Mapping\EhiProposalDraft;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EhiDocumentFixtures;
use Tests\TestCase;
use WeakMap;

class EhiDocumentMappingTest extends TestCase
{
    use EhiDocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhiDocuments();
    }

    public function test_derived_text_is_bounded_so_its_proposal_fits_a_backup_record(): void
    {
        // An eighth of this record limit: 1024 bytes of text.
        config(['phr.native_restore_max_record_bytes' => 8192]);
        $this->ehiDocOwnedNote('N100', '9001', ['NOTE_DESC' => 'Synthetic long note']);
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '300', 'NOTE_ID' => 'N100', 'CONTACT_DATE_REAL' => '65000.1', 'NOTE_STATUS_C_NAME' => 'Signed']);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '300', 'LINE' => '1', 'NOTE_ID' => 'N100', 'NOTE_TEXT' => str_repeat('Synthetic line text ', 100)]);

        $draft = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N100');

        self::assertSame(1024, EhiDocumentMapping::maxTextBytes());
        self::assertLessThanOrEqual(1024, strlen((string) $draft->proposed['attributes']['extracted_text']));
        self::assertTrue($draft->proposed['ehi']['text']['truncated']);
    }

    public function test_contact_metadata_is_bounded_so_the_whole_proposal_fits_a_backup_record(): void
    {
        // An eighth of this record limit: 4096 bytes for everything but the text.
        config(['phr.native_restore_max_record_bytes' => 32768]);
        $this->ehiDocOwnedNote('N150', '9050', ['IP_NOTE_TYPE_C_NAME' => str_repeat('Synthetic type ', 100)]);
        for ($serial = 1; $serial <= 40; $serial++) {
            $this->ehiDocRow('NOTE_ENC_INFO', [
                'CONTACT_SERIAL_NUM' => (string) $serial,
                'NOTE_ID' => 'N150',
                'CONTACT_DATE_REAL' => (string) (65000 + $serial),
                'NOTE_STATUS_C_NAME' => 'Addendum',
                'AUTHOR_USER_ID_NAME' => str_repeat('Synthetic Author ', 20),
            ]);
        }
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '40', 'LINE' => '1', 'NOTE_ID' => 'N150', 'NOTE_TEXT' => 'body']);

        $draft = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N150');
        $ehi = $draft->proposed['ehi'];
        $kept = array_column($ehi['contacts'], 'contact_serial_num');
        $withoutText = $draft->proposed;
        $withoutText['attributes']['extracted_text'] = null;

        self::assertLessThanOrEqual(4096, strlen(json_encode($withoutText, JSON_THROW_ON_ERROR)));
        self::assertSame(4096, EhiDocumentMapping::maxMetadataBytes());
        self::assertSame(40, $ehi['contacts_total']);
        self::assertTrue($ehi['contacts_truncated']);
        // The oldest contact, then the newest that fit, oldest first.
        self::assertGreaterThan(2, count($kept));
        self::assertSame('1', $kept[0]);
        self::assertSame(array_map(strval(...), range(42 - count($kept), 40)), array_slice($kept, 1));
        self::assertLessThanOrEqual(EhiDocumentMapping::MAX_VALUE_BYTES, strlen($ehi['note_type']));
        foreach ($ehi['contacts'] as $contact) {
            self::assertSame(EhiDocumentMapping::MAX_VALUE_BYTES, strlen($contact['author']));
        }
        self::assertEquals($draft, $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N150'));
    }

    public function test_a_key_too_long_for_the_document_identity_is_retained_without_copying_it(): void
    {
        $noteId = str_repeat('N', 300);
        $this->ehiDocOwnedNote($noteId, '9060');
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '1', 'LINE' => '1', 'NOTE_ID' => $noteId, 'NOTE_TEXT' => 'body']);

        $drafts = array_values(array_filter($this->ehiDocPropose()['drafts'], static fn (EhiProposalDraft $draft): bool => ($draft->proposed['ehi']['table'] ?? null) === 'HNO_INFO'));

        self::assertCount(1, $drafts);
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $drafts[0]->kind);
        self::assertSame('identity_too_long', $drafts[0]->proposed['reason']);
        self::assertStringNotContainsString($noteId, json_encode($drafts[0]->proposed, JSON_THROW_ON_ERROR));
    }

    public function test_a_long_member_file_name_is_cut_to_the_column_keeping_its_extension(): void
    {
        $name = str_repeat('é', 300).'.png';
        $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D750', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => $name]);
        $this->ehiDocMember('Media/'.$name, EhiMemberKind::MEDIA, "\x89PNG synthetic long name");

        $draft = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'doc_info_id', 'D750');
        $filename = $draft->proposed['attributes']['original_filename'];

        self::assertSame(str_repeat('é', 251).'.png', $filename);
        self::assertSame(255, mb_strlen($filename));
        $document = PhrDocument::query()->findOrFail($this->ehiDocApply($this->ehiDocPersist($draft))->recordId);
        self::assertSame($filename, $document->original_filename);
        self::assertSame('image/png', $document->mime_type);
    }

    public function test_a_document_row_is_skipped_only_when_its_note_proposal_took_it_in(): void
    {
        // Notes retained before their documents are read: foreign and deleted.
        $this->ehiDocOwnedNote('N-FOREIGN', '9150', [], self::DOC_OTHER_PATIENT_KEY);
        $foreign = $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D-FOREIGN', 'DOC_HNO_ID' => 'N-FOREIGN', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'scan-foreign.png']);
        $this->ehiDocMember('Media/scan-foreign.png', EhiMemberKind::MEDIA, "\x89PNG synthetic foreign-note scan");
        $this->ehiDocOwnedNote('N-DELETED', '9151', ['DELETE_INSTANT_DTTM' => '2024-04-01 00:00:00']);
        $deleted = $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D-DELETED', 'DOC_HNO_ID' => 'N-DELETED', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'scan-deleted.png']);
        $this->ehiDocMember('Media/scan-deleted.png', EhiMemberKind::MEDIA, "\x89PNG synthetic deleted-note scan");
        // A note whose proposal relies on its document.
        $this->ehiDocOwnedNote('N-OWNED', '9152');
        $owned = $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D-OWNED', 'DOC_HNO_ID' => 'N-OWNED', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'scan-owned.png']);
        $this->ehiDocMember('Media/scan-owned.png', EhiMemberKind::MEDIA, "\x89PNG synthetic owned-note scan");

        $drafts = $this->ehiDocPropose()['drafts'];

        self::assertSame(PhrEhiProposal::KIND_CREATE, $this->ehiDocDraftFor($drafts, 'doc_info_id', 'D-FOREIGN')->kind);
        self::assertSame(PhrEhiProposal::KIND_CREATE, $this->ehiDocDraftFor($drafts, 'doc_info_id', 'D-DELETED')->kind);
        self::assertSame('D-OWNED', $this->ehiDocDraftFor($drafts, 'note_id', 'N-OWNED')->proposed['ehi']['file']['doc_info_id']);
        self::assertSame([], array_filter($drafts, static fn (EhiProposalDraft $draft): bool => ($draft->proposed['ehi']['doc_info_id'] ?? null) === 'D-OWNED'));
        // Each document revision is in exactly one draft, so no occurrence stays pending.
        foreach ([$foreign, $deleted, $owned] as $revision) {
            self::assertCount(1, array_filter($drafts, static fn (EhiProposalDraft $draft): bool => in_array($revision->id, $draft->revisionIds, true)));
        }
    }

    public function test_a_document_row_the_note_does_not_use_is_left_out_of_the_note(): void
    {
        // A note with a valid scan: rows naming another patient, or ambiguous, supply nothing.
        $this->ehiDocOwnedNote('N-SCAN', '9160');
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '1', 'LINE' => '1', 'NOTE_ID' => 'N-SCAN', 'NOTE_TEXT' => 'scan note text']);
        $used = $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D-SCAN-USED', 'DOC_HNO_ID' => 'N-SCAN', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'scan-used.png']);
        $this->ehiDocMember('Media/scan-used.png', EhiMemberKind::MEDIA, "\x89PNG synthetic used scan");
        $foreign = $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D-SCAN-FOREIGN', 'DOC_HNO_ID' => 'N-SCAN', 'DOC_PT_ID' => self::DOC_OTHER_PATIENT_KEY, 'SCAN_FILE' => 'scan-foreign-row.png']);
        $this->ehiDocMember('Media/scan-foreign-row.png', EhiMemberKind::MEDIA, "\x89PNG synthetic foreign-row scan");
        $ambiguous = [
            $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D-SCAN-AMBIGUOUS', 'DOC_HNO_ID' => 'N-SCAN', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'scan-used.png']),
            $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D-SCAN-AMBIGUOUS', 'DOC_HNO_ID' => 'N-SCAN', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'scan-other.png']),
        ];
        // A note with usable text: a foreign row, and an owned row whose file is missing.
        $this->ehiDocOwnedNote('N-TEXT', '9161');
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '2', 'LINE' => '1', 'NOTE_ID' => 'N-TEXT', 'NOTE_TEXT' => 'text note body']);
        $textForeign = $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D-TEXT-FOREIGN', 'DOC_HNO_ID' => 'N-TEXT', 'DOC_PT_ID' => self::DOC_OTHER_PATIENT_KEY, 'SCAN_FILE' => 'scan-text-foreign.png']);
        $this->ehiDocMember('Media/scan-text-foreign.png', EhiMemberKind::MEDIA, "\x89PNG synthetic text-foreign scan");
        $missing = $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D-TEXT-MISSING', 'DOC_HNO_ID' => 'N-TEXT', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'not-in-package.png']);

        $result = $this->ehiDocPropose();
        $drafts = $result['drafts'];
        $scanNote = $this->ehiDocDraftFor($drafts, 'note_id', 'N-SCAN');
        $textNote = $this->ehiDocDraftFor($drafts, 'note_id', 'N-TEXT');

        self::assertSame(PhrEhiProposal::KIND_CREATE, $scanNote->kind);
        self::assertSame('D-SCAN-USED', $scanNote->proposed['ehi']['file']['doc_info_id']);
        self::assertContains($used->id, $scanNote->revisionIds);
        self::assertSame(PhrEhiProposal::KIND_CREATE, $textNote->kind);
        self::assertNull($textNote->proposed['ehi']['file']);
        self::assertSame('text note body', $textNote->proposed['attributes']['extracted_text']);
        foreach ([$foreign, ...$ambiguous] as $revision) {
            self::assertNotContains($revision->id, $scanNote->revisionIds);
        }
        foreach ([$textForeign, $missing] as $revision) {
            self::assertNotContains($revision->id, $textNote->revisionIds);
        }
        // Each rejected row stands on its own ownership instead.
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $this->ehiDocDraftFor($drafts, 'doc_info_id', 'D-SCAN-FOREIGN')->kind);
        self::assertSame('foreign_patient', $this->ehiDocDraftFor($drafts, 'doc_info_id', 'D-SCAN-FOREIGN')->proposed['reason']);
        self::assertSame('foreign_patient', $this->ehiDocDraftFor($drafts, 'doc_info_id', 'D-TEXT-FOREIGN')->proposed['reason']);
        self::assertSame('no_content', $this->ehiDocDraftFor($drafts, 'doc_info_id', 'D-TEXT-MISSING')->proposed['reason']);
        $ambiguousDrafts = array_values(array_filter($drafts, static fn (EhiProposalDraft $draft): bool => in_array($ambiguous[0]->id, $draft->revisionIds, true)));
        self::assertCount(1, $ambiguousDrafts);
        self::assertSame('ambiguous_revision', $ambiguousDrafts[0]->proposed['reason']);
        self::assertEqualsCanonicalizing([$ambiguous[0]->id, $ambiguous[1]->id], $ambiguousDrafts[0]->revisionIds);
        // Each document revision is in exactly one draft, so no occurrence stays pending.
        foreach ([$used, $foreign, ...$ambiguous, $textForeign, $missing] as $revision) {
            self::assertCount(1, array_filter($drafts, static fn (EhiProposalDraft $draft): bool => in_array($revision->id, $draft->revisionIds, true)));
        }
        // The missing file is reported once, by the row's own proposal.
        $unresolved = array_values(array_filter($result['findings'], static fn (array $finding): bool => $finding['code'] === EhiDocumentFinding::FILE_REFERENCE_UNRESOLVED));
        self::assertSame([$missing->id], array_column($unresolved, 'revision_id'));
    }

    public function test_dt_on_doc_has_no_documented_zone_and_is_recorded_as_unspecified(): void
    {
        // DOC_SRVC_DTTM and SCAN_INST_DTTM are absent or unparseable: DT_ON_DOC is used.
        $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D-DT', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'dt-on-doc.png', 'DT_ON_DOC' => '11/2/2023']);
        $this->ehiDocMember('Media/dt-on-doc.png', EhiMemberKind::MEDIA, "\x89PNG synthetic dt-on-doc");
        $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D-DT-FALLBACK', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'dt-fallback.png', 'DOC_SRVC_DTTM' => 'not a date', 'DT_ON_DOC' => '2023-11-03 09:15:00']);
        $this->ehiDocMember('Media/dt-fallback.png', EhiMemberKind::MEDIA, "\x89PNG synthetic dt-fallback");
        // A documented Local column keeps its zone.
        $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D-DT-LOCAL', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'dt-local.png', 'SCAN_INST_DTTM' => '2023-11-04 10:00:00', 'DT_ON_DOC' => '2023-11-01']);
        $this->ehiDocMember('Media/dt-local.png', EhiMemberKind::MEDIA, "\x89PNG synthetic dt-local");

        $result = $this->ehiDocPropose();
        $draft = $this->ehiDocDraftFor($result['drafts'], 'doc_info_id', 'D-DT');
        $fallback = $this->ehiDocDraftFor($result['drafts'], 'doc_info_id', 'D-DT-FALLBACK');
        $local = $this->ehiDocDraftFor($result['drafts'], 'doc_info_id', 'D-DT-LOCAL');

        self::assertSame('unspecified', EhiDocumentMapping::TIMEZONE_UNSPECIFIED);
        self::assertSame(['column' => 'DT_ON_DOC', 'timezone' => 'unspecified', 'precision' => 'date', 'source_text' => '11/2/2023', 'time_applied' => false, 'value' => '2023-11-02 00:00:00'], $draft->proposed['ehi']['observed']);
        self::assertSame(['column' => 'DT_ON_DOC', 'timezone' => 'unspecified', 'precision' => 'date', 'source_text' => '2023-11-03 09:15:00', 'time_applied' => false, 'value' => '2023-11-03 00:00:00'], $fallback->proposed['ehi']['observed']);
        self::assertSame('DOC_SRVC_DTTM', collect($result['findings'])->firstWhere('code', EhiDocumentFinding::UNPARSED_DATETIME)['detail']['column']);
        foreach ([$draft, $fallback] as $unspecified) {
            $summary = $unspecified->proposed['attributes']['summary'];
            self::assertStringContainsString('Observed date from DT_ON_DOC (no documented time zone; time of day not applied).', $summary);
            self::assertStringNotContainsString('local', $summary);
        }
        self::assertSame(['SCAN_INST_DTTM', 'local'], [$local->proposed['ehi']['observed']['column'], $local->proposed['ehi']['observed']['timezone']]);
        self::assertStringContainsString('SCAN_INST_DTTM (local; time of day not applied)', $local->proposed['attributes']['summary']);

        $document = PhrDocument::query()->findOrFail($this->ehiDocApply($this->ehiDocPersist($draft))->recordId);
        self::assertSame('2023-11-02 00:00:00', $document->observed_at?->format('Y-m-d H:i:s'));
        self::assertStringContainsString('DT_ON_DOC (no documented time zone; time of day not applied)', (string) $document->summary);
    }

    public function test_note_evidence_is_streamed_in_pages_and_text_is_read_back_per_note(): void
    {
        $chunk = 10;
        $lineIds = [];
        for ($note = 1; $note <= 8; $note++) {
            $noteId = 'N-STREAM-'.$note;
            $this->ehiDocOwnedNote($noteId, (string) (9700 + $note));
            $older = (string) (970000 + $note * 10);
            $newer = (string) (970000 + $note * 10 + 1);
            $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => $older, 'NOTE_ID' => $noteId, 'CONTACT_DATE_REAL' => '65000.1']);
            $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => $newer, 'NOTE_ID' => $noteId, 'CONTACT_DATE_REAL' => '65000.2']);
            for ($line = 1; $line <= 5; $line++) {
                $lineIds[$this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => $older, 'LINE' => (string) $line, 'NOTE_ID' => $noteId, 'NOTE_TEXT' => "old {$note}.{$line}"])->id] = true;
            }
            for ($line = 6; $line >= 1; $line--) {
                $lineIds[$this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => $newer, 'LINE' => (string) $line, 'NOTE_ID' => $noteId, 'NOTE_TEXT' => "new {$note}.{$line}"])->id] = true;
            }
        }
        self::assertGreaterThan(2 * $chunk, count($lineIds));

        // Count note text lines held as models at once: a page, never the table.
        $alive = new WeakMap;
        $peak = 0;
        PhrEhiRevision::retrieved(static function (PhrEhiRevision $revision) use ($lineIds, $alive, &$peak): void {
            if (isset($lineIds[$revision->id])) {
                $alive[$revision] = true;
                $peak = max($peak, count($alive));
            }
        });
        $context = new EhiMappingContext($this->docPackage, $this->docPatient, $this->docSource, $chunk);
        $drafts = [...app(EhiDocumentMapping::class)->propose($context)];

        self::assertGreaterThan(0, $peak);
        self::assertLessThanOrEqual(2 * $chunk, $peak);
        self::assertCount(8, $drafts);
        for ($note = 1; $note <= 8; $note++) {
            $draft = $this->ehiDocDraftFor($drafts, 'note_id', 'N-STREAM-'.$note);
            self::assertSame(PhrEhiProposal::KIND_CREATE, $draft->kind);
            self::assertSame(implode("\n", array_map(static fn (int $line): string => "new {$note}.{$line}", range(1, 6))), $draft->proposed['attributes']['extracted_text']);
            self::assertSame((string) (970000 + $note * 10 + 1), $draft->proposed['ehi']['text']['contact_serial_num']);
            self::assertSame(6, $draft->proposed['ehi']['text']['line_count']);
        }
        // Paging changes nothing: the same drafts and findings as one page.
        self::assertEquals(['drafts' => $drafts, 'findings' => $context->findings()], $this->ehiDocPropose());
        self::assertSame(
            $this->ehiDocContext()->revisions('HNO_PLAIN_TEXT')->pluck('id')->all(),
            $context->lazyRevisions('HNO_PLAIN_TEXT', 7)->pluck('id')->all(),
        );
    }

    public function test_contract_and_registration(): void
    {
        $mapping = app(EhiDocumentMapping::class);

        self::assertSame('document', $mapping->name());
        self::assertSame('ehi-doc-1', $mapping->version());
        self::assertSame([
            'HNO_INFO' => ['NOTE_ID'],
            'NOTE_ENC_INFO' => ['CONTACT_SERIAL_NUM'],
            'HNO_PLAIN_TEXT' => ['NOTE_CSN_ID', 'LINE'],
            'V_EHI_HNO_LINKED_PATS' => ['NOTE_ID', 'LINE'],
            'DOC_INFORMATION' => ['DOC_INFO_ID'],
            'PAT_ENC' => ['PAT_ENC_CSN_ID'],
        ], $mapping->expectedKeys());
        self::assertInstanceOf(EhiDocumentMapping::class, app(EhiMappingRegistry::class)->get('document'));
        self::assertContains(EhiDocumentMapping::SOURCE, PhrDocument::SOURCES);
    }

    public function test_owned_note_orders_contacts_exactly_and_reconstructs_the_latest_text(): void
    {
        $note = $this->ehiDocOwnedNote('N100', '9001', [
            'NOTE_DESC' => 'Synthetic follow-up',
            'DATE_OF_SERVIC_DTTM' => '2024-03-05 14:30:00',
            'UNSIGNED_YN' => 'Yes',
        ]);
        // Fractional contact keys compare as exact decimals: 64999.9 < 65000.02 < 65000.1.
        $late = $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '300', 'NOTE_ID' => 'N100', 'CONTACT_DATE_REAL' => '65000.1', 'NOTE_STATUS_C_NAME' => 'Addendum']);
        $early = $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '900', 'NOTE_ID' => 'N100', 'CONTACT_DATE_REAL' => '64999.9', 'NOTE_STATUS_C_NAME' => 'Signed']);
        $middle = $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '20', 'NOTE_ID' => 'N100', 'CONTACT_DATE_REAL' => '65000.02', 'NOTE_STATUS_C_NAME' => 'Revised']);
        $old = $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '900', 'LINE' => '1', 'NOTE_ID' => 'N100', 'NOTE_TEXT' => 'superseded text']);
        $lines = [
            $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '300', 'LINE' => '10', 'NOTE_ID' => 'N100', 'NOTE_TEXT' => 'tenth']),
            $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '300', 'LINE' => '2', 'NOTE_ID' => 'N100', 'NOTE_TEXT' => 'second']),
            $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '300', 'LINE' => '1', 'NOTE_ID' => 'N100', 'NOTE_TEXT' => "first\x07"]),
        ];

        $result = $this->ehiDocPropose();
        $draft = $this->ehiDocDraftFor($result['drafts'], 'note_id', 'N100');

        self::assertSame(PhrEhiProposal::KIND_CREATE, $draft->kind);
        self::assertSame($note->entity->key_digest, $draft->subjectKey);
        self::assertSame(['900', '20', '300'], array_column($draft->proposed['ehi']['contacts'], 'contact_serial_num'));
        self::assertSame(['Signed', 'Revised', 'Addendum'], array_column($draft->proposed['ehi']['contacts'], 'note_status'));
        $attributes = $draft->proposed['attributes'];
        self::assertSame("first\nsecond\ntenth", $attributes['extracted_text']);
        self::assertSame(['derived' => true, 'source' => 'HNO_PLAIN_TEXT', 'contact_serial_num' => '300', 'line_count' => 3, 'line_gaps' => true, 'truncated' => false], $draft->proposed['ehi']['text']);
        self::assertSame('2024-03-05 14:30:00', $attributes['observed_at']);
        self::assertSame(['column' => 'DATE_OF_SERVIC_DTTM', 'timezone' => 'UTC', 'precision' => 'second', 'source_text' => '2024-03-05 14:30:00', 'time_applied' => true, 'value' => '2024-03-05 14:30:00'], $draft->proposed['ehi']['observed']);
        self::assertTrue($draft->proposed['ehi']['unsigned']);
        self::assertContains(EhiDocumentMapping::TAG_UNSIGNED, $attributes['tags']);
        self::assertContains(EhiDocumentMapping::TAG_DERIVED_TEXT, $attributes['tags']);
        self::assertSame('office_visit_note', $attributes['document_type']);
        self::assertSame('Synthetic follow-up', $attributes['title']);
        self::assertSame('ehi:'.$this->docSource->id, $attributes['import_source']);
        self::assertSame('NOTE:N100', $attributes['external_id']);
        self::assertSame(['PAT_ENC.PAT_ID'], $draft->proposed['ehi']['ownership']);

        $expected = [$note->id, $late->id, $early->id, $middle->id, ...array_map(static fn ($line): int => $line->id, $lines)];
        foreach ($expected as $id) {
            self::assertContains($id, $draft->revisionIds);
        }
        self::assertNotContains($old->id, $draft->revisionIds, 'text of an earlier contact is not relied upon');
        self::assertSame($draft->revisionIds, array_values(array_unique($draft->revisionIds)));
    }

    public function test_ownership_requires_a_documented_link_to_the_bound_patient(): void
    {
        $this->ehiDocRow('HNO_INFO', ['NOTE_ID' => 'N-ORPHAN', 'NOTE_DESC' => self::DOC_MARKER]);
        $this->ehiDocOwnedNote('N-FOREIGN', '9100', [], self::DOC_OTHER_PATIENT_KEY);
        $this->ehiDocRow('HNO_INFO', ['NOTE_ID' => 'N-LINKED', 'NOTE_DESC' => 'linked']);
        $this->ehiDocRow('V_EHI_HNO_LINKED_PATS', ['NOTE_ID' => 'N-LINKED', 'LINE' => '1', 'LINKED_PAT_ID' => self::DOC_PATIENT_KEY]);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '1', 'LINE' => '1', 'NOTE_ID' => 'N-LINKED', 'NOTE_TEXT' => 'linked text']);
        $this->ehiDocRow('HNO_INFO', ['NOTE_ID' => 'N-SHARED', 'PAT_LINK_ID' => self::DOC_PATIENT_KEY]);
        $this->ehiDocRow('V_EHI_HNO_LINKED_PATS', ['NOTE_ID' => 'N-SHARED', 'LINE' => '1', 'LINKED_PAT_ID' => self::DOC_PATIENT_KEY]);
        $this->ehiDocRow('V_EHI_HNO_LINKED_PATS', ['NOTE_ID' => 'N-SHARED', 'LINE' => '2', 'LINKED_PAT_ID' => self::DOC_OTHER_PATIENT_KEY]);
        $this->ehiDocRow('HNO_INFO', ['NOTE_ID' => 'N-NOENC', 'PAT_ENC_CSN_ID' => '404']);

        $result = $this->ehiDocPropose();
        $drafts = $result['drafts'];

        self::assertSame(['kind' => 'retain', 'reason' => 'orphan'], $this->kindAndReason($drafts, 'N-ORPHAN'));
        self::assertSame(['kind' => 'retain', 'reason' => 'foreign_patient'], $this->kindAndReason($drafts, 'N-FOREIGN'));
        self::assertSame(['kind' => 'retain', 'reason' => 'foreign_patient'], $this->kindAndReason($drafts, 'N-SHARED'));
        self::assertSame(['kind' => 'retain', 'reason' => 'orphan'], $this->kindAndReason($drafts, 'N-NOENC'));
        $linked = $this->ehiDocDraftFor($drafts, 'note_id', 'N-LINKED');
        self::assertSame(PhrEhiProposal::KIND_CREATE, $linked->kind);
        self::assertSame(['V_EHI_HNO_LINKED_PATS.LINKED_PAT_ID'], $linked->proposed['ehi']['ownership']);
        self::assertSame('linked text', $linked->proposed['attributes']['extracted_text']);

        $codes = array_column($result['findings'], 'code');
        self::assertSame(2, count(array_keys($codes, EhiDocumentFinding::ORPHAN, true)));
        self::assertSame(2, count(array_keys($codes, EhiDocumentFinding::FOREIGN_PATIENT, true)));
        self::assertContains(EhiDocumentFinding::ENCOUNTER_MISSING, $codes);
        $this->assertFindingsCarryNoValues($result['findings']);
        foreach ($result['findings'] as $finding) {
            self::assertContains($finding['detail']['severity'] ?? null, ['info', 'warning', 'error']);
        }
    }

    public function test_deleted_ambiguous_and_contentless_notes_are_retained(): void
    {
        $this->ehiDocOwnedNote('N-DEL', '9200', ['DELETE_INSTANT_DTTM' => '2024-04-01 00:00:00']);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '1', 'LINE' => '1', 'NOTE_ID' => 'N-DEL', 'NOTE_TEXT' => 'deleted text']);
        $this->ehiDocOwnedNote('N-EMPTY', '9201');
        // Two revisions of one note in the same package.
        $this->ehiDocOwnedNote('N-AMB', '9202', ['NOTE_DESC' => 'one']);
        $this->ehiDocOwnedNote('N-AMB', '9202', ['NOTE_DESC' => 'two']);

        $result = $this->ehiDocPropose();

        self::assertSame(['kind' => 'retain', 'reason' => 'deleted'], $this->kindAndReason($result['drafts'], 'N-DEL'));
        self::assertSame(['kind' => 'retain', 'reason' => 'no_content'], $this->kindAndReason($result['drafts'], 'N-EMPTY'));
        $ambiguous = array_values(array_filter($result['drafts'], static fn ($draft): bool => ($draft->proposed['reason'] ?? null) === 'ambiguous_revision'));
        self::assertCount(1, $ambiguous);
        self::assertCount(2, $ambiguous[0]->revisionIds);
        self::assertContains(EhiDocumentFinding::AMBIGUOUS_REVISION, array_column($result['findings'], 'code'));
    }

    public function test_a_note_whose_only_text_line_is_empty_has_no_content(): void
    {
        $this->ehiDocOwnedNote('N-BLANK', '9210');
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '21', 'NOTE_ID' => 'N-BLANK', 'CONTACT_DATE_REAL' => '65000']);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '21', 'LINE' => '1', 'NOTE_ID' => 'N-BLANK', 'NOTE_TEXT' => '']);
        // Empty once control characters are removed.
        $this->ehiDocOwnedNote('N-CONTROL', '9211');
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '22', 'NOTE_ID' => 'N-CONTROL', 'CONTACT_DATE_REAL' => '65000']);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '22', 'LINE' => '1', 'NOTE_ID' => 'N-CONTROL', 'NOTE_TEXT' => "\x07"]);
        // Only blank lines: whitespace is not content either.
        $this->ehiDocOwnedNote('N-SPACES', '9213');
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '24', 'NOTE_ID' => 'N-SPACES', 'CONTACT_DATE_REAL' => '65000']);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '24', 'LINE' => '1', 'NOTE_ID' => 'N-SPACES', 'NOTE_TEXT' => '']);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '24', 'LINE' => '2', 'NOTE_ID' => 'N-SPACES', 'NOTE_TEXT' => '   ']);
        // An empty table text still falls back to the linked RTF member.
        $this->ehiDocOwnedNote('N-BLANK-RTF', '9212');
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '23', 'NOTE_ID' => 'N-BLANK-RTF', 'CONTACT_DATE_REAL' => '65000']);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '23', 'LINE' => '1', 'NOTE_ID' => 'N-BLANK-RTF', 'NOTE_TEXT' => '']);
        $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D-BLANK-RTF', 'DOC_HNO_ID' => 'N-BLANK-RTF', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'blank-rtf.rtf']);
        $this->ehiDocMember('Rich Text/blank-rtf.rtf', EhiMemberKind::NOTE, '{\\rtf1 rtf body}');

        $drafts = $this->ehiDocPropose()['drafts'];

        self::assertSame(['kind' => 'retain', 'reason' => 'no_content'], $this->kindAndReason($drafts, 'N-BLANK'));
        self::assertSame(['kind' => 'retain', 'reason' => 'no_content'], $this->kindAndReason($drafts, 'N-CONTROL'));
        self::assertSame(['kind' => 'retain', 'reason' => 'no_content'], $this->kindAndReason($drafts, 'N-SPACES'));
        $rtf = $this->ehiDocDraftFor($drafts, 'note_id', 'N-BLANK-RTF');
        self::assertSame(PhrEhiProposal::KIND_CREATE, $rtf->kind);
        self::assertSame('rtf body', $rtf->proposed['attributes']['extracted_text']);
        self::assertSame('rtf', $rtf->proposed['ehi']['text']['source']);
    }

    public function test_a_blank_latest_contact_falls_back_to_the_latest_contact_with_text(): void
    {
        $note = $this->ehiDocOwnedNote('N-FALLBACK', '9220');
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '31', 'NOTE_ID' => 'N-FALLBACK', 'CONTACT_DATE_REAL' => '65000']);
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '32', 'NOTE_ID' => 'N-FALLBACK', 'CONTACT_DATE_REAL' => '65001']);
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '33', 'NOTE_ID' => 'N-FALLBACK', 'CONTACT_DATE_REAL' => '65002']);
        $oldest = $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '31', 'LINE' => '1', 'NOTE_ID' => 'N-FALLBACK', 'NOTE_TEXT' => 'oldest text']);
        $text = [
            $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '32', 'LINE' => '2', 'NOTE_ID' => 'N-FALLBACK', 'NOTE_TEXT' => 'second']),
            $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '32', 'LINE' => '1', 'NOTE_ID' => 'N-FALLBACK', 'NOTE_TEXT' => 'older text']),
        ];
        // The latest contact has lines, but none survives sanitizing and trimming.
        $blank = [
            $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '33', 'LINE' => '1', 'NOTE_ID' => 'N-FALLBACK', 'NOTE_TEXT' => '']),
            $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '33', 'LINE' => '2', 'NOTE_ID' => 'N-FALLBACK', 'NOTE_TEXT' => " \x07 "]),
        ];

        // Streaming the index loads every row with all its columns; a read-back
        // by id (EhiDocumentEvidence::fetch) selects only what a draft needs.
        $readBack = 0;
        PhrEhiRevision::retrieved(static function (PhrEhiRevision $revision) use ($oldest, &$readBack): void {
            if ($revision->id === $oldest->id && ! array_key_exists('patient_id', $revision->getAttributes())) {
                $readBack++;
            }
        });
        $draft = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N-FALLBACK');

        self::assertSame(PhrEhiProposal::KIND_CREATE, $draft->kind);
        self::assertSame("older text\nsecond", $draft->proposed['attributes']['extracted_text']);
        self::assertSame(['derived' => true, 'source' => 'HNO_PLAIN_TEXT', 'contact_serial_num' => '32', 'line_count' => 2, 'line_gaps' => false, 'truncated' => false], $draft->proposed['ehi']['text']);
        // The blank lines decided the choice and are relied upon; an older
        // contact than the one chosen is never read back.
        foreach ([$note, ...$text, ...$blank] as $revision) {
            self::assertContains($revision->id, $draft->revisionIds);
        }
        self::assertNotContains($oldest->id, $draft->revisionIds);
        self::assertSame(0, $readBack);
    }

    public function test_text_lines_that_are_not_canonical_integers_are_not_ordered(): void
    {
        $this->ehiDocOwnedNote('N-LINE', '9300');
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '5', 'NOTE_ID' => 'N-LINE', 'CONTACT_DATE_REAL' => '65000']);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '5', 'LINE' => '01', 'NOTE_ID' => 'N-LINE', 'NOTE_TEXT' => 'a']);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '5', 'LINE' => '2', 'NOTE_ID' => 'N-LINE', 'NOTE_TEXT' => 'b']);

        $result = $this->ehiDocPropose();

        self::assertSame(['kind' => 'retain', 'reason' => 'no_content'], $this->kindAndReason($result['drafts'], 'N-LINE'));
        $finding = collect($result['findings'])->firstWhere('code', EhiDocumentFinding::TEXT_UNORDERED);
        self::assertSame('line', $finding['detail']['reason']);
    }

    public function test_local_only_timestamp_keeps_the_date_without_applying_a_timezone(): void
    {
        $this->ehiDocOwnedNote('N-LOCAL', '9400', ['CREATE_INSTANT_DTTM' => 'not a date', 'CRT_INST_LOCAL_DTTM' => '3/5/2024 11:45 PM']);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '1', 'LINE' => '1', 'NOTE_ID' => 'N-LOCAL', 'NOTE_TEXT' => 'local']);

        $result = $this->ehiDocPropose();
        $draft = $this->ehiDocDraftFor($result['drafts'], 'note_id', 'N-LOCAL');

        self::assertSame('2024-03-05 00:00:00', $draft->proposed['attributes']['observed_at']);
        self::assertSame(['column' => 'CRT_INST_LOCAL_DTTM', 'timezone' => 'local', 'precision' => 'date', 'source_text' => '3/5/2024 11:45 PM', 'time_applied' => false, 'value' => '2024-03-05 00:00:00'], $draft->proposed['ehi']['observed']);
        self::assertStringContainsString('CRT_INST_LOCAL_DTTM (local; time of day not applied)', $draft->proposed['attributes']['summary']);
        $finding = collect($result['findings'])->firstWhere('code', EhiDocumentFinding::UNPARSED_DATETIME);
        self::assertSame('CREATE_INSTANT_DTTM', $finding['detail']['column']);
    }

    public function test_an_undocumented_file_name_token_never_links_a_member(): void
    {
        $this->ehiDocOwnedNote('N500', '9500');
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '1', 'LINE' => '1', 'NOTE_ID' => 'N500', 'NOTE_TEXT' => 'table text']);
        $this->ehiDocMember('Rich Text/N500.rtf', EhiMemberKind::NOTE, '{\\rtf1 token text}');
        $this->ehiDocMember('Media/N500.png', EhiMemberKind::MEDIA, "\x89PNG synthetic");

        $result = $this->ehiDocPropose();
        $draft = $this->ehiDocDraftFor($result['drafts'], 'note_id', 'N500');

        self::assertNull($draft->proposed['ehi']['file']);
        self::assertSame('table text', $draft->proposed['attributes']['extracted_text']);
        $unlinked = array_values(array_filter($result['findings'], static fn (array $finding): bool => $finding['code'] === EhiDocumentFinding::UNLINKED_NOTE_MEMBER));
        self::assertSame([['media', 1], ['note', 1]], array_map(static fn (array $finding): array => [$finding['detail']['kind'], $finding['detail']['count']], $unlinked));
    }

    public function test_documented_reference_links_the_rtf_member_and_apply_copies_its_bytes(): void
    {
        $marker = self::DOC_MARKER;
        $rtf = "{\\rtf1\\ansi{\\fonttbl{\\f0 {$marker};}}Assessment\\par Stable{\\pict\\pngblip {$marker}}{\\object{\\*\\objdata {$marker}}}{\\field{\\*\\fldinst HYPERLINK {$marker}}{\\fldrslt  plan}}}";
        $note = $this->ehiDocOwnedNote('N600', '9600', ['IP_NOTE_TYPE_C_NAME' => 'Discharge Summary']);
        $docInfo = $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D600', 'DOC_HNO_ID' => 'N600', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'scan-600.rtf']);
        $member = $this->ehiDocMember('Rich Text/scan-600.rtf', EhiMemberKind::NOTE, $rtf);
        $blob = PhrEhiBlob::query()->findOrFail($member->blob_id);

        $result = $this->ehiDocPropose();
        $draft = $this->ehiDocDraftFor($result['drafts'], 'note_id', 'N600');

        self::assertSame(PhrEhiProposal::KIND_CREATE, $draft->kind);
        self::assertContains($docInfo->id, $draft->revisionIds);
        self::assertSame(['member_id' => $member->id, 'kind' => 'note', 'sha256' => hash('sha256', $rtf), 'byte_size' => strlen($rtf), 'via' => 'DOC_INFORMATION.SCAN_FILE', 'doc_info_id' => 'D600'], $draft->proposed['ehi']['file']);
        self::assertSame("Assessment\nStable plan", $draft->proposed['attributes']['extracted_text']);
        self::assertStringNotContainsString($marker, json_encode($draft->proposed, JSON_THROW_ON_ERROR));
        self::assertTrue($draft->proposed['ehi']['text']['rtf']['pictures']);
        self::assertTrue($draft->proposed['ehi']['text']['rtf']['embedded_objects']);
        self::assertSame('discharge_summary', $draft->proposed['attributes']['document_type']);
        self::assertNotContains(EhiDocumentFinding::UNLINKED_NOTE_MEMBER, array_column($result['findings'], 'code'));

        $outcome = $this->ehiDocApply($this->ehiDocPersist($draft));

        self::assertSame('phr_documents', $outcome->recordTable);
        self::assertSame(PhrEhiRecordLink::KIND_PROJECTED, $outcome->linkKind);
        $document = PhrDocument::query()->findOrFail($outcome->recordId);
        self::assertSame('ehi_tables', $document->source);
        self::assertSame('NOTE:N600', $document->external_id);
        self::assertSame('application/rtf', $document->mime_type);
        self::assertSame('scan-600.rtf', $document->original_filename);
        self::assertSame(hash('sha256', $rtf), $document->file_hash);
        self::assertSame(strlen($rtf), $document->byte_size);
        self::assertSame($this->docOwner->id, $document->uploaded_by_user_id);
        self::assertSame($rtf, Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $document->storage_path));
        self::assertStringStartsWith("patients/{$this->docPatient->id}/documents/", (string) $document->storage_path);
        self::assertNotSame($blob->storage_path, $document->storage_path);
        self::assertSame($rtf, Storage::disk($blob->storage_disk)->get($blob->storage_path), 'the EHI blob is untouched');
        self::assertSame('2024-03-05 14:30:00', $document->observed_at?->format('Y-m-d H:i:s'));
        self::assertSame(1, PhrEhiBlob::query()->whereKey($blob->id)->count());
        self::assertSame(1, $note->entity()->count());
    }

    public function test_scanned_document_without_a_note_becomes_a_media_document(): void
    {
        $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D700', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'img-700.png', 'DOC_DESCR' => 'Synthetic card', 'DOC_SRVC_DTTM' => '2023-11-02 08:15:00']);
        $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D701', 'DOC_PT_ID' => self::DOC_OTHER_PATIENT_KEY, 'SCAN_FILE' => 'img-701.png']);
        $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D702', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'img-702.png', 'RECORD_STATE_C_NAME' => 'Deleted']);
        $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D703', 'DOC_PT_ID' => self::DOC_PATIENT_KEY, 'SCAN_FILE' => 'missing.png']);
        $png = "\x89PNG\r\n\x1A\nsynthetic-image-bytes";
        $this->ehiDocMember('Media/img-700.png', EhiMemberKind::MEDIA, $png);

        $result = $this->ehiDocPropose();
        $draft = $this->ehiDocDraftFor($result['drafts'], 'doc_info_id', 'D700');

        self::assertSame(PhrEhiProposal::KIND_CREATE, $draft->kind);
        self::assertSame('other', $draft->proposed['attributes']['document_type']);
        self::assertSame('image/png', $draft->proposed['attributes']['mime_type']);
        self::assertNull($draft->proposed['attributes']['extracted_text']);
        self::assertSame('2023-11-02 00:00:00', $draft->proposed['attributes']['observed_at']);
        self::assertSame('retain', $this->ehiDocDraftFor($result['drafts'], 'doc_info_id', 'D701')->kind);
        self::assertSame('record_state', $this->ehiDocDraftFor($result['drafts'], 'doc_info_id', 'D702')->proposed['reason']);
        self::assertSame('no_content', $this->ehiDocDraftFor($result['drafts'], 'doc_info_id', 'D703')->proposed['reason']);
        self::assertContains(EhiDocumentFinding::FILE_REFERENCE_UNRESOLVED, array_column($result['findings'], 'code'));

        $document = PhrDocument::query()->findOrFail($this->ehiDocApply($this->ehiDocPersist($draft))->recordId);
        self::assertSame('DOC:D700', $document->external_id);
        self::assertSame($png, Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $document->storage_path));
        self::assertNull($document->extracted_text);
    }

    public function test_a_note_without_a_file_stores_derived_text_marked_derived(): void
    {
        $this->ehiDocOwnedNote('N800', '9800');
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '1', 'LINE' => '1', 'NOTE_ID' => 'N800', 'NOTE_TEXT' => 'derived body']);

        $draft = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N800');
        $document = PhrDocument::query()->findOrFail($this->ehiDocApply($this->ehiDocPersist($draft))->recordId);

        self::assertSame('text/plain', $document->mime_type);
        self::assertSame('note-n800.txt', $document->original_filename);
        self::assertSame('derived body', Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $document->storage_path));
        self::assertSame(hash('sha256', 'derived body'), $document->file_hash);
        self::assertContains(EhiDocumentMapping::TAG_DERIVED_TEXT, $document->tags ?? []);
        self::assertStringContainsString('derived text', (string) $document->summary);
    }

    public function test_reimport_corroborates_then_changed_text_proposes_an_explicit_update(): void
    {
        $this->ehiDocOwnedNote('N900', '9900');
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '11', 'NOTE_ID' => 'N900', 'CONTACT_DATE_REAL' => '65000']);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '11', 'LINE' => '1', 'NOTE_ID' => 'N900', 'NOTE_TEXT' => 'original']);
        $proposal = $this->ehiDocPersist($this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N900'));
        $outcome = $this->ehiDocApply($proposal);
        $this->ehiDocLink($proposal, $outcome);
        $document = PhrDocument::query()->findOrFail($outcome->recordId);
        $storagePath = $document->storage_path;

        // Same evidence again, even after a person edited the title: corroborate.
        $document->forceFill(['title' => 'Edited by a person'])->save();
        $again = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N900');
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $again->kind);
        self::assertSame($document->id, $again->targetId);
        $corroborated = $this->ehiDocApply($this->ehiDocPersist($again, $this->ehiDocPackage()));
        self::assertSame(PhrEhiRecordLink::KIND_CORROBORATING, $corroborated->linkKind);
        self::assertSame($document->id, $corroborated->recordId);

        // A later package adds an addendum contact with new text.
        $second = $this->ehiDocPackage();
        $this->ehiDocOwnedNote('N900', '9900', [], self::DOC_PATIENT_KEY, $second);
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '11', 'NOTE_ID' => 'N900', 'CONTACT_DATE_REAL' => '65000'], $second);
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '12', 'NOTE_ID' => 'N900', 'CONTACT_DATE_REAL' => '65000.01'], $second);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '11', 'LINE' => '1', 'NOTE_ID' => 'N900', 'NOTE_TEXT' => 'original'], $second);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '12', 'LINE' => '1', 'NOTE_ID' => 'N900', 'NOTE_TEXT' => 'amended'], $second);

        $update = $this->ehiDocDraftFor($this->ehiDocPropose($second)['drafts'], 'note_id', 'N900');
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);
        self::assertSame($document->id, $update->targetId);
        self::assertSame(['title', 'extracted_text'], $update->proposed['changed']);
        self::assertSame(hash('sha256', 'original'), $update->before['extracted_text_sha256']);
        self::assertSame('Edited by a person', $update->before['title']);

        // Refused when the target changed after the preview.
        $stale = $this->ehiDocPersist($update, $second);
        PhrDocument::query()->whereKey($document->id)->update(['summary' => 'changed meanwhile']);
        $this->assertRefused(EhiDocumentApplyRefused::TARGET_CHANGED, fn () => $this->ehiDocApply($stale, PhrEhiProposal::DECISION_APPROVE_UPDATE));

        $fresh = $this->ehiDocPersist($this->ehiDocDraftFor($this->ehiDocPropose($second)['drafts'], 'note_id', 'N900'), $this->ehiDocPackage());
        $applied = $this->ehiDocApply($fresh, PhrEhiProposal::DECISION_APPROVE_UPDATE);
        self::assertSame(PhrEhiRecordLink::KIND_PROJECTED, $applied->linkKind);
        $document->refresh();
        self::assertSame('amended', $document->extracted_text);
        self::assertSame($storagePath, $document->storage_path, 'the stored file is never replaced');
        self::assertSame('original', Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $storagePath));

        $this->assertRefused(EhiDocumentApplyRefused::DECISION_NOT_APPLICABLE, fn () => $this->ehiDocApply($fresh));
    }

    public function test_a_withdrawn_document_is_never_resurrected(): void
    {
        $this->ehiDocOwnedNote('N1000', '10000');
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '1', 'LINE' => '1', 'NOTE_ID' => 'N1000', 'NOTE_TEXT' => 'body']);
        $draft = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N1000');
        $pending = $this->ehiDocPersist($draft);
        $replay = $this->ehiDocPersist($draft, $this->ehiDocPackage());
        $outcome = $this->ehiDocApply($pending);
        $this->ehiDocLink($pending, $outcome);

        $this->assertRefused(EhiDocumentApplyRefused::IDENTITY_EXISTS, fn () => $this->ehiDocApply($replay));

        $document = PhrDocument::query()->findOrFail($outcome->recordId);
        $document->delete();
        $result = $this->ehiDocPropose();
        self::assertSame(['kind' => 'retain', 'reason' => 'target_withdrawn'], $this->kindAndReason($result['drafts'], 'N1000'));
        self::assertContains(EhiDocumentFinding::TARGET_WITHDRAWN, array_column($result['findings'], 'code'));
        $objects = $this->ehiDocDocumentObjects();
        $this->assertRefused(EhiDocumentApplyRefused::IDENTITY_WITHDRAWN, fn () => $this->ehiDocApply($replay));
        self::assertSame($objects, $this->ehiDocDocumentObjects(), 'a refused create leaves no new object');

        $document->restore();
        $document->forceFill(['retracted_at' => now()])->save();
        self::assertSame(['kind' => 'retain', 'reason' => 'target_withdrawn'], $this->kindAndReason($this->ehiDocPropose()['drafts'], 'N1000'));
        self::assertSame(1, PhrDocument::withTrashed()->count());
    }

    public function test_a_note_linked_to_more_than_one_live_document_is_retained(): void
    {
        $first = $this->manualDocument(['title' => 'First manual']);
        $second = $this->manualDocument(['title' => 'Second manual']);
        $this->ehiDocOwnedNote('N1050', '10500');
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '1', 'LINE' => '1', 'NOTE_ID' => 'N1050', 'NOTE_TEXT' => 'linked twice']);
        // Two packages previewed concurrently, each reviewed against a different document.
        $draft = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N1050');
        $pending = $this->ehiDocPersist($draft);
        $concurrent = $this->ehiDocPersist($draft, $this->ehiDocPackage());
        $this->ehiDocLink($pending, $this->ehiDocApply($pending, PhrEhiProposal::DECISION_LINK, $first->id));
        $this->ehiDocLink($concurrent, $this->ehiDocApply($concurrent, PhrEhiProposal::DECISION_LINK, $second->id));

        // Neither the same evidence nor a later revision picks one of them.
        $later = $this->ehiDocPackage();
        $this->ehiDocOwnedNote('N1050', '10500', [], self::DOC_PATIENT_KEY, $later);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '1', 'LINE' => '1', 'NOTE_ID' => 'N1050', 'NOTE_TEXT' => 'amended'], $later);
        foreach ([$this->ehiDocPropose(), $this->ehiDocPropose($later)] as $result) {
            $retained = $this->ehiDocDraftFor($result['drafts'], 'note_id', 'N1050');
            self::assertSame(['kind' => 'retain', 'reason' => 'ambiguous_link'], ['kind' => $retained->kind, 'reason' => $retained->proposed['reason'] ?? null]);
            self::assertNull($retained->targetId);
            $finding = collect($result['findings'])->firstWhere('code', EhiDocumentFinding::AMBIGUOUS_LINK);
            self::assertSame(['severity' => 'warning', 'table' => 'HNO_INFO', 'records' => 2], $finding['detail']);
        }

        // Once one is withdrawn, the remaining live document is the target again.
        $second->delete();
        $again = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N1050');
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $again->kind);
        self::assertSame($first->id, $again->targetId);
    }

    public function test_identical_text_on_the_same_day_is_a_possible_duplicate_that_can_be_linked(): void
    {
        $existing = $this->manualDocument(['title' => 'From another import', 'extracted_text' => 'same words', 'observed_at' => '2024-03-05 09:00:00']);
        $trashed = $this->manualDocument(['extracted_text' => 'same words', 'observed_at' => '2024-03-05 09:00:00']);
        $trashed->delete();
        $this->ehiDocOwnedNote('N1100', '11000');
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '1', 'LINE' => '1', 'NOTE_ID' => 'N1100', 'NOTE_TEXT' => 'same words']);

        $draft = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N1100');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $draft->kind);
        self::assertSame([$existing->id], $draft->candidateIds);
        $proposal = $this->ehiDocPersist($draft);

        $this->assertRefused(EhiDocumentApplyRefused::TARGET_WITHDRAWN, fn () => $this->ehiDocApply($proposal, PhrEhiProposal::DECISION_LINK, $trashed->id));
        $otherOwner = $this->createUser();
        $otherPatient = PhrPatient::query()->create(['owner_user_id' => $otherOwner->id, 'display_name' => 'Other Synthetic', 'relationship' => 'self']);
        $foreign = PhrDocument::query()->create(['patient_id' => $otherPatient->id, 'user_id' => $otherOwner->id, 'document_type' => 'other', 'source' => 'manual_upload', 'storage_disk' => PhrDocument::STORAGE_DISK, 'byte_size' => 0]);
        $this->assertRefused(EhiDocumentApplyRefused::TARGET_NOT_FOUND, fn () => $this->ehiDocApply($proposal, PhrEhiProposal::DECISION_LINK, $foreign->id));
        $this->assertRefused(EhiDocumentApplyRefused::TARGET_NOT_FOUND, fn () => $this->ehiDocApply($proposal, PhrEhiProposal::DECISION_LINK, null));

        $linked = $this->ehiDocApply($proposal, PhrEhiProposal::DECISION_LINK, $existing->id);
        self::assertSame(PhrEhiRecordLink::KIND_BRIDGED, $linked->linkKind);
        self::assertSame($existing->id, $linked->recordId);
        self::assertSame(3, PhrDocument::withTrashed()->count(), 'linking creates nothing');
    }

    public function test_apply_refuses_wrong_scope_and_tampered_evidence(): void
    {
        $this->ehiDocOwnedNote('N1200', '12000');
        $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D1200', 'DOC_HNO_ID' => 'N1200', 'SCAN_FILE' => 'n1200.rtf']);
        $member = $this->ehiDocMember('Rich Text/n1200.rtf', EhiMemberKind::NOTE, '{\\rtf1 tamper target}');
        $proposal = $this->ehiDocPersist($this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N1200'));

        $wrongVersion = $proposal->replicate()->fill(['mapping_version' => 'ehi-doc-0']);
        $this->assertRefused(EhiDocumentApplyRefused::WRONG_SCOPE, fn () => $this->ehiDocApply($wrongVersion));
        $this->assertRefused(EhiDocumentApplyRefused::DECISION_NOT_APPLICABLE, fn () => $this->ehiDocApply($proposal, PhrEhiProposal::DECISION_RETAIN));

        $blob = PhrEhiBlob::query()->findOrFail($member->blob_id);
        Storage::disk($blob->storage_disk)->put($blob->storage_path, '{\\rtf1 tampered!!!}');
        $this->assertRefused(EhiDocumentApplyRefused::EVIDENCE_UNAVAILABLE, fn () => $this->ehiDocApply($proposal));
        self::assertSame(0, PhrDocument::withTrashed()->count());
        self::assertSame([], $this->ehiDocDocumentObjects());
    }

    public function test_hostile_rtf_is_retained_when_it_cannot_be_converted(): void
    {
        $this->ehiDocOwnedNote('N1300', '13000');
        $this->ehiDocRow('DOC_INFORMATION', ['DOC_INFO_ID' => 'D1300', 'DOC_HNO_ID' => 'N1300', 'SCAN_FILE' => 'bomb.rtf']);
        $this->ehiDocMember('Rich Text/bomb.rtf', EhiMemberKind::NOTE, '{\\rtf1 '.str_repeat('{', 5000).self::DOC_MARKER);

        $result = $this->ehiDocPropose();
        $draft = $this->ehiDocDraftFor($result['drafts'], 'note_id', 'N1300');

        // The original is still linked and preserved; no text is derived from it.
        self::assertSame(PhrEhiProposal::KIND_CREATE, $draft->kind);
        self::assertNull($draft->proposed['attributes']['extracted_text']);
        self::assertTrue($draft->proposed['ehi']['text']['rtf']['unsupported']);
        self::assertContains(EhiDocumentFinding::RTF_UNSUPPORTED, array_column($result['findings'], 'code'));
        self::assertStringNotContainsString(self::DOC_MARKER, json_encode($draft->proposed, JSON_THROW_ON_ERROR));
    }

    public function test_proposals_are_deterministic(): void
    {
        $this->ehiDocOwnedNote('N1400', '14000');
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '2', 'NOTE_ID' => 'N1400', 'CONTACT_DATE_REAL' => '65000.1']);
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '1', 'NOTE_ID' => 'N1400', 'CONTACT_DATE_REAL' => '65000.10']);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '2', 'LINE' => '1', 'NOTE_ID' => 'N1400', 'NOTE_TEXT' => 'x']);
        $this->ehiDocRow('HNO_INFO', ['NOTE_ID' => 'N1401']);
        $this->ehiDocMember('Media/loose.pdf', EhiMemberKind::MEDIA, '%PDF-synthetic');

        $first = $this->ehiDocPropose();
        $second = $this->ehiDocPropose();

        self::assertEquals($first, $second);
        $draft = $this->ehiDocDraftFor($first['drafts'], 'note_id', 'N1400');
        // Equal decimal values (65000.1 = 65000.10) fall back to the contact serial number.
        self::assertSame(['1', '2'], array_column($draft->proposed['ehi']['contacts'], 'contact_serial_num'));
    }

    /**
     * @param  list<EhiProposalDraft>  $drafts
     * @return array{kind: string, reason: string|null}
     */
    private function kindAndReason(array $drafts, string $noteId): array
    {
        $draft = $this->ehiDocDraftFor($drafts, 'note_id', $noteId);

        return ['kind' => $draft->kind, 'reason' => $draft->proposed['reason'] ?? null];
    }

    /** @param  list<array{code: string, revision_id: int|null, detail: array<string, int|string|bool|null>}>  $findings */
    private function assertFindingsCarryNoValues(array $findings): void
    {
        $encoded = json_encode($findings, JSON_THROW_ON_ERROR);
        foreach ([self::DOC_MARKER, self::DOC_PATIENT_KEY, self::DOC_OTHER_PATIENT_KEY, 'N-ORPHAN', 'N-FOREIGN', 'N-SHARED', '9100', '404'] as $value) {
            self::assertStringNotContainsString($value, $encoded);
        }
    }

    private function assertRefused(string $reason, callable $apply): void
    {
        try {
            $apply();
            self::fail("expected refusal {$reason}");
        } catch (EhiApplyRefused $refused) {
            self::assertInstanceOf(EhiDocumentApplyRefused::class, $refused);
            self::assertSame($reason, $refused->reason);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function manualDocument(array $attributes): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => $this->docPatient->id,
            'user_id' => $this->docOwner->id,
            'title' => 'Manual',
            'document_type' => 'office_visit_note',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'byte_size' => 0,
            'source' => 'manual_upload',
            ...$attributes,
        ]);
    }
}
