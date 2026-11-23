<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrDocument;
use App\Models\PhrEhiProposal;
use App\Services\PHR\Import\Ehi\Mapping\EhiDocumentMapping;
use Tests\Support\EhiDocumentFixtures;
use Tests\TestCase;

/** EHI notes keep their author, source label and addenda (phr#409). Synthetic data only. */
final class EhiDocumentNoteMetadataTest extends TestCase
{
    use EhiDocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhiDocuments();
    }

    public function test_a_note_proposes_its_author_label_and_addenda_and_never_a_loinc_code(): void
    {
        $this->note();

        $draft = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N409');
        $attributes = $draft->proposed['attributes'];
        self::assertSame(PhrEhiProposal::KIND_CREATE, $draft->kind);
        self::assertSame('Jordan Fellow, MD', $attributes['author_name'], 'the first contact wrote the note');
        self::assertSame('Daily Progress Note', $attributes['source_type_label']);
        self::assertSame('amended', $attributes['doc_status']);
        self::assertArrayNotHasKey('loinc_code', $attributes, 'a LOINC code from a type name would be a guess');
        self::assertStringContainsString('1 addendum, the latest by Casey Consultant, MD on 2/11/2026', $attributes['summary']);

        $outcome = $this->ehiDocApply($this->ehiDocPersist($draft));
        $document = PhrDocument::query()->findOrFail($outcome->recordId);
        self::assertSame('Jordan Fellow, MD', $document->author_name);
        self::assertSame('Daily Progress Note', $document->source_type_label);
        self::assertSame('amended', $document->doc_status);
        self::assertNull($document->loinc_code);
        self::assertSame('office_visit_note', $document->document_type, 'the type folding is unchanged');
    }

    public function test_an_existing_note_gets_one_update_that_sets_only_its_missing_note_metadata(): void
    {
        $this->note();
        $draft = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N409');
        $proposal = $this->ehiDocPersist($draft);
        $outcome = $this->ehiDocApply($proposal);
        $this->ehiDocLink($proposal, $outcome);
        // As a note applied by ehi-doc-1 is: no note metadata, a title a person edited.
        PhrDocument::query()->whereKey($outcome->recordId)->update(['author_name' => null, 'source_type_label' => null, 'doc_status' => null, 'title' => 'Edited by a person']);

        $update = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N409');
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);
        self::assertEqualsCanonicalizing(['author_name', 'source_type_label', 'doc_status'], $update->proposed['changed']);
        $this->ehiDocApply($this->ehiDocPersist($update, $this->ehiDocPackage()), PhrEhiProposal::DECISION_APPROVE_UPDATE);
        $document = PhrDocument::query()->findOrFail($outcome->recordId);
        self::assertSame('Edited by a person', $document->title, 'a person\'s edit stands');
        self::assertSame('Jordan Fellow, MD', $document->author_name);

        // Once set, the same rows only corroborate it, and a person's own value is never replaced.
        $document->update(['author_name' => 'Jordan Fellow, MD (corrected)']);
        self::assertSame(PhrEhiProposal::KIND_CORROBORATE, $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N409')->kind);
        self::assertSame('ehi-doc-2', EhiDocumentMapping::VERSION);
    }

    private function note(): void
    {
        $this->ehiDocOwnedNote('N409', '9409', ['IP_NOTE_TYPE_C_NAME' => 'Daily Progress Note', 'NOTE_DESC' => 'Nephrology Follow-Up']);
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '4091', 'NOTE_ID' => 'N409', 'CONTACT_DATE_REAL' => '67000.1', 'CONTACT_DATE' => '2/11/2026', 'NOTE_STATUS_C_NAME' => 'Signed', 'AUTHOR_USER_ID_NAME' => 'Jordan Fellow, MD']);
        $this->ehiDocRow('NOTE_ENC_INFO', ['CONTACT_SERIAL_NUM' => '4092', 'NOTE_ID' => 'N409', 'CONTACT_DATE_REAL' => '67000.2', 'CONTACT_DATE' => '2/11/2026', 'NOTE_STATUS_C_NAME' => 'Addendum', 'AUTHOR_USER_ID_NAME' => 'Casey Consultant, MD']);
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '4092', 'LINE' => '1', 'NOTE_ID' => 'N409', 'NOTE_TEXT' => 'Assessment: acute kidney injury, improving.']);
    }
}
