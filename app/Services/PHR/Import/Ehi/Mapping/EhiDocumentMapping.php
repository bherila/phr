<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

use App\Models\PhrDocument;
use App\Models\PhrEhiEntity;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Services\PHR\Import\Ehi\EhiMemberKind;
use App\Services\PHR\Import\Ehi\EhiRtfText;
use App\Services\PHR\Import\Ehi\Mapping\Document\EhiDocumentApplyRefused;
use App\Services\PHR\Import\Ehi\Mapping\Document\EhiDocumentEvidence;
use App\Services\PHR\Import\Ehi\Mapping\Document\EhiDocumentFileStore;
use App\Services\PHR\Import\Ehi\Mapping\Document\EhiDocumentFinding;
use App\Services\PHR\Import\Ehi\Mapping\Document\EhiDocumentValues;
use App\Support\PHR\PhrRecordLifecycle;
use Generator;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * EHI Tables notes and scanned documents to PHR documents (issue #213, M2).
 *
 * Source tables, verified against the public spec (open.epic.com/EHITables) on
 * 2026-10-19. The key each package's own dictionary declares is re-checked by
 * projection; this list is never assumed universal.
 *
 * - HNO_INFO, PK [NOTE_ID]. One row per note. Links: PAT_ENC_CSN_ID (NUMERIC)
 *   -> PAT_ENC; PAT_LINK_ID (virtual: the first patient ID among linked HNO
 *   items). Dates: CREATE_INSTANT_DTTM, DATE_OF_SERVIC_DTTM, DELETE_INSTANT_DTTM,
 *   LST_FILED_INST_DTTM are DATETIME (UTC); CRT_INST_LOCAL_DTTM is DATETIME
 *   (Local). DELETE_INSTANT_DTTM set means deleted. UNSIGNED_YN (Yes/No).
 *   IP_NOTE_TYPE_C_NAME (clinical note type), NOTE_TYPE_NOADD_C_NAME, NOTE_DESC.
 *   No file or attachment column.
 * - NOTE_ENC_INFO, PK [CONTACT_SERIAL_NUM]. One row per note contact (a new
 *   contact per revision/addendum). NOTE_ID -> HNO_INFO. CONTACT_DATE_REAL
 *   (FLOAT in the spec): integer part is the date, the fraction sequences
 *   contacts on that day. It is compared here as an exact decimal string, never
 *   a float and never a date; CONTACT_SERIAL_NUM breaks ties. NOTE_STATUS_C_NAME,
 *   NOTE_TYPE_C_NAME, NOTE_FORMAT_C_NAME, AUTHOR_USER_ID_NAME, CONTACT_DATE.
 *   DOCUMENT_NAME is a multi-part document name, not a file reference.
 * - HNO_PLAIN_TEXT, PK [NOTE_CSN_ID, LINE]. Plain-text-only notes as ordered
 *   lines; NOTE_CSN_ID is the note contact (NOTE_ENC_INFO.CONTACT_SERIAL_NUM),
 *   NOTE_ID the note, NOTE_TEXT one line. Rich-text notes are not in it. The
 *   spec names HNO_NOTE_TEXT for reporting, but no public page documents it, so
 *   it is not mapped.
 * - V_EHI_HNO_LINKED_PATS, PK [NOTE_ID, LINE]. The documented direct
 *   note-to-patient link for EHI export: LINKED_PAT_ID, one row per patient.
 * - DOC_INFORMATION, PK [DOC_INFO_ID]. Scanned / e-signed documents.
 *   DOC_PT_ID (patient), DOC_CSN (encounter), DOC_HNO_ID (note), SCAN_FILE (file
 *   name of the scanned image, name only), RECORD_STATE_C_NAME (inactive /
 *   deleted / hidden). Dates are DATETIME (Local) except DT_ON_DOC (no zone).
 * - PAT_ENC, PK [PAT_ENC_CSN_ID]. PAT_ID owns the encounter.
 *
 * Ownership: a note is this patient's only when at least one documented link
 * (PAT_ENC.PAT_ID via PAT_ENC_CSN_ID, HNO_INFO.PAT_LINK_ID, or
 * V_EHI_HNO_LINKED_PATS.LINKED_PAT_ID) equals the bound source patient key and
 * none names another patient. Otherwise it is retained with a finding.
 *
 * Files: the only documented note/file join is DOC_INFORMATION.DOC_HNO_ID plus
 * SCAN_FILE, matched byte-for-byte against a note/media member's file name and
 * only when exactly one member matches. File-name tokens (e.g. a NOTE_ID inside
 * an RTF member's name) are undocumented and never used. ESIGN_HTML_FILE names
 * HTML, which is not rendered or linked. Members no documented reference reaches
 * stay unlinked evidence, counted in one finding per member kind.
 *
 * Text: derived, never evidence. HNO_PLAIN_TEXT lines of the note's latest
 * contact that has text, in numeric LINE order (canonical integer strings
 * only); else the linked RTF member through EhiRtfText. Both are capped.
 *
 * Apply: a document is created with source "ehi_tables", import_source
 * "ehi:{source_id}", external_id "NOTE:{NOTE_ID}" or "DOC:{DOC_INFO_ID}". Its
 * file is a verified copy of the linked member's bytes, or, with no linked
 * member, the derived text stored as a .txt object tagged `ehi-derived-text`.
 * observed_at comes from a UTC-documented column as the UTC instant; from a
 * Local column, or one with no documented zone (recorded as "unspecified"), only
 * its calendar date is kept (time not applied). The column, zone, and precision
 * are written into `summary`. An update changes only title,
 * document_type, observed_at, and extracted_text, never the stored file.
 *
 * Size: a proposal must restore from a native backup record. Besides the
 * capped text, source values copied into it are cut to MAX_VALUE_BYTES, the
 * file name to the column's 255 characters (extension kept), and a note's
 * contact summaries are fitted to maxMetadataBytes(); a key too long for
 * external_id is retained as `identity_too_long`.
 */
final readonly class EhiDocumentMapping implements EhiMapping
{
    public const string NAME = 'document';

    public const string VERSION = 'ehi-doc-1';

    public const string SOURCE = 'ehi_tables';

    public const string RECORD_TABLE = 'phr_documents';

    /** Derived text kept per document, in bytes, at most; see maxTextBytes(). */
    public const int MAX_TEXT_BYTES = 1024 * 1024;

    /** Everything else in a proposal, encoded, in bytes, at most; see maxMetadataBytes(). */
    public const int MAX_METADATA_BYTES = 64 * 1024;

    /** Each source value copied into proposal metadata, in bytes, at most. */
    public const int MAX_VALUE_BYTES = 255;

    /** phr_documents.external_id holds 255; a longer key is retained, never cut. */
    private const int MAX_EXTERNAL_ID_BYTES = 255;

    public const string TAG_IMPORTED = 'ehi-tables';

    public const string TAG_DERIVED_TEXT = 'ehi-derived-text';

    public const string TAG_UNSIGNED = 'ehi-unsigned';

    /**
     * Observed-time zone recorded for a column the spec documents without a zone
     * (DOC_INFORMATION.DT_ON_DOC): neither UTC nor local time is claimed.
     */
    public const string TIMEZONE_UNSPECIFIED = 'unspecified';

    private const string HNO_INFO = 'HNO_INFO';

    private const string NOTE_ENC_INFO = 'NOTE_ENC_INFO';

    private const string HNO_PLAIN_TEXT = 'HNO_PLAIN_TEXT';

    private const string LINKED_PATS = 'V_EHI_HNO_LINKED_PATS';

    private const string DOC_INFORMATION = 'DOC_INFORMATION';

    private const string PAT_ENC = 'PAT_ENC';

    private const array KEYS = [
        self::HNO_INFO => ['NOTE_ID'],
        self::NOTE_ENC_INFO => ['CONTACT_SERIAL_NUM'],
        self::HNO_PLAIN_TEXT => ['NOTE_CSN_ID', 'LINE'],
        self::LINKED_PATS => ['NOTE_ID', 'LINE'],
        self::DOC_INFORMATION => ['DOC_INFO_ID'],
        self::PAT_ENC => ['PAT_ENC_CSN_ID'],
    ];

    /**
     * How each joined table is indexed: the column dependants look it up by, and
     * for note text the contact its lines belong to. HNO_INFO and DOC_INFORMATION
     * are also streamed as proposal roots.
     */
    private const array JOINS = [
        self::PAT_ENC => ['PAT_ENC_CSN_ID'],
        self::LINKED_PATS => ['NOTE_ID'],
        self::NOTE_ENC_INFO => ['NOTE_ID'],
        self::HNO_PLAIN_TEXT => ['NOTE_ID', 'NOTE_CSN_ID'],
        self::DOC_INFORMATION => ['DOC_HNO_ID'],
    ];

    /** Fields an approved update may change; the stored file never changes. */
    private const array UPDATABLE = ['title', 'document_type', 'observed_at', 'extracted_text'];

    private const array MIME_TYPES = [
        'rtf' => 'application/rtf', 'pdf' => 'application/pdf',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
        'heic' => 'image/heic', 'heif' => 'image/heif', 'avif' => 'image/avif', 'svg' => 'image/svg+xml',
    ];

    public function __construct(
        private EhiRtfText $rtf,
        private EhiDocumentFileStore $files,
        private PhrPatientArtifactWriteGuard $artifactWriteGuard,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function expectedKeys(): array
    {
        return self::KEYS;
    }

    public static function importSource(int $sourceId): string
    {
        return 'ehi:'.$sourceId;
    }

    /**
     * Notes, then scanned documents, each streamed a page at a time in the
     * context's deterministic order. Joined tables are indexed compactly by
     * EhiDocumentEvidence and their rows read back per note.
     *
     * @return Generator<int, EhiProposalDraft>
     */
    public function propose(EhiMappingContext $context): iterable
    {
        $evidence = EhiDocumentEvidence::load($context, self::JOINS);
        /** @var array<int, true> $linkedMembers */
        $linkedMembers = [];

        // Only a DOC_INFORMATION row with a DOC_HNO_ID can be taken in by a note.
        $noteDocuments = $evidence->indexedIds(self::DOC_INFORMATION);
        /** @var array<int, true> $consumed document revisions some note proposal relies on */
        $consumed = [];
        foreach ($this->entityGroups($context->lazyRevisions(self::HNO_INFO, withEntity: true)) as $revisions) {
            $draft = count($revisions) > 1
                ? $this->ambiguousDraft($context, $revisions, self::HNO_INFO)
                : $this->noteDraft($context, $evidence, $revisions[0], $linkedMembers);
            foreach ($draft->revisionIds as $id) {
                if (isset($noteDocuments[$id])) {
                    $consumed[$id] = true;
                }
            }
            // Yielded as produced: the caller persists in chunks, so drafts are
            // never all held at once.
            yield $draft;
        }

        foreach ($this->entityGroups($context->lazyRevisions(self::DOC_INFORMATION, withEntity: true)) as $revisions) {
            // Skipped only when its note's proposal took it in. A note retained
            // before reading its documents (foreign, orphaned, deleted) leaves
            // them to stand on their own ownership.
            if (array_filter($revisions, static fn (PhrEhiRevision $revision): bool => ! isset($consumed[$revision->id])) === []) {
                continue;
            }
            yield count($revisions) > 1
                ? $this->ambiguousDraft($context, $revisions, self::DOC_INFORMATION)
                : $this->scannedDocumentDraft($context, $evidence, $revisions[0], $linkedMembers);
        }

        $unlinked = [];
        foreach ($evidence->members() as $member) {
            if (! isset($linkedMembers[$member->id])) {
                $unlinked[$member->kind] = ($unlinked[$member->kind] ?? 0) + 1;
            }
        }
        ksort($unlinked, SORT_STRING);
        foreach ($unlinked as $kind => $count) {
            $this->finding($context, EhiDocumentFinding::UNLINKED_NOTE_MEMBER, null, ['kind' => $kind, 'count' => $count]);
        }
    }

    public function apply(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome
    {
        if ((int) $proposal->patient_id !== $context->patient->id
            || $proposal->mapping !== self::NAME
            || $proposal->mapping_version !== self::VERSION) {
            throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::WRONG_SCOPE, 'the proposal does not belong to this patient and mapping version');
        }
        $creatable = [PhrEhiProposal::KIND_CREATE, PhrEhiProposal::KIND_POSSIBLE_DUPLICATE];

        return match (true) {
            $context->decision === PhrEhiProposal::DECISION_ACCEPT && in_array($proposal->kind, $creatable, true) => $this->applyCreate($proposal, $context),
            $context->decision === PhrEhiProposal::DECISION_ACCEPT && $proposal->kind === PhrEhiProposal::KIND_CORROBORATE => new EhiApplyOutcome(self::RECORD_TABLE, $this->lockedLiveDocument($proposal, $proposal->target_id)->id, PhrEhiRecordLink::KIND_CORROBORATING),
            $context->decision === PhrEhiProposal::DECISION_APPROVE_UPDATE && $proposal->kind === PhrEhiProposal::KIND_UPDATE => $this->applyUpdate($proposal),
            $context->decision === PhrEhiProposal::DECISION_LINK && in_array($proposal->kind, $creatable, true) => new EhiApplyOutcome(self::RECORD_TABLE, $this->lockedLiveDocument($proposal, $context->linkTargetId)->id, PhrEhiRecordLink::KIND_BRIDGED),
            default => throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::DECISION_NOT_APPLICABLE, 'the decision does not apply to this proposal kind'),
        };
    }

    // ---------------------------------------------------------------- notes

    /** @param  array<int, true>  $linkedMembers */
    private function noteDraft(EhiMappingContext $context, EhiDocumentEvidence $evidence, PhrEhiRevision $note, array &$linkedMembers): EhiProposalDraft
    {
        $entity = $note->entity;
        $noteId = (string) EhiDocumentValues::cell($note, 'NOTE_ID');
        $reference = ['table' => self::HNO_INFO, 'note_id' => $noteId];
        $revisionIds = [$note->id];
        if (strlen('NOTE:'.$noteId) > self::MAX_EXTERNAL_ID_BYTES) {
            // No document could hold the identity, and the key is not copied.
            return $this->retainDraft($entity, $revisionIds, 'identity_too_long', ['table' => self::HNO_INFO]);
        }

        $ownership = $this->noteOwnership($context, $evidence, $note, $noteId, $revisionIds);
        if ($ownership['verdict'] !== 'owned') {
            return $this->retainDraft($entity, $revisionIds, $ownership['verdict'], $reference);
        }
        if (EhiDocumentValues::cell($note, 'DELETE_INSTANT_DTTM') !== null) {
            return $this->retainDraft($entity, $revisionIds, 'deleted', $reference);
        }

        $contacts = $this->contacts($evidence, $noteId, $revisionIds);
        if ($contacts['ambiguous']) {
            $this->finding($context, EhiDocumentFinding::AMBIGUOUS_REVISION, $note, ['table' => self::NOTE_ENC_INFO]);

            return $this->retainDraft($entity, $revisionIds, 'ambiguous_revision', $reference);
        }
        $file = $this->referencedFile($context, $evidence, $note, self::DOC_INFORMATION, 'DOC_HNO_ID', $noteId, $revisionIds, $linkedMembers);
        $text = $this->noteText($context, $evidence, $note, $noteId, $contacts['rows'], $revisionIds);
        if ($text['text'] === null) {
            $text = $this->rtfText($context, $note, $file);
        }
        if ($text['text'] === null && $file === null) {
            return $this->retainDraft($entity, $revisionIds, 'no_content', $reference);
        }

        $observed = $this->observed($context, $note, [
            ['DATE_OF_SERVIC_DTTM', 'UTC'],
            ['CREATE_INSTANT_DTTM', 'UTC'],
            ['CRT_INST_LOCAL_DTTM', 'local'],
        ]);
        $unsignedRaw = EhiDocumentValues::cell($note, 'UNSIGNED_YN');
        $unsigned = $this->yesNo($unsignedRaw);
        $noteType = EhiDocumentValues::cell($note, 'IP_NOTE_TYPE_C_NAME');
        $title = EhiDocumentValues::cell($note, 'NOTE_DESC') ?? $noteType ?? EhiDocumentValues::cell($note, 'NOTE_TYPE_NOADD_C_NAME') ?? 'Clinical note';
        $documentType = $noteType !== null && stripos($noteType, 'discharge summary') !== false ? 'discharge_summary' : 'office_visit_note';

        $summary = ['Imported from an EHI Tables note (HNO_INFO).'];
        if ($unsigned === true) {
            $summary[] = 'The source marks this note unsigned.';
        }

        return $this->reconcile($context, $entity, $revisionIds, $this->boundedContacts($this->proposal(
            context: $context,
            externalId: 'NOTE:'.$noteId,
            title: $title,
            documentType: $documentType,
            observed: $observed,
            text: $text,
            file: $file,
            summary: $summary,
            unsigned: $unsigned === true,
            ehi: [
                ...$reference,
                'encounter_csn' => EhiDocumentValues::bounded(EhiDocumentValues::cell($note, 'PAT_ENC_CSN_ID'), self::MAX_VALUE_BYTES),
                'note_type' => EhiDocumentValues::bounded($noteType, self::MAX_VALUE_BYTES),
                'unsigned' => $unsigned,
                'unsigned_source' => EhiDocumentValues::bounded($unsignedRaw, self::MAX_VALUE_BYTES),
                'ownership' => $ownership['via'],
                'contacts' => array_map($this->contactSummary(...), $contacts['rows']),
            ],
        )));
    }

    /**
     * Fit the note's contact summaries into what maxMetadataBytes() leaves once
     * the rest of the proposal is counted (its derived text is capped on its
     * own): the oldest contact, then as many of the newest as fit, oldest first.
     * contacts_total and contacts_truncated record what was left out. Sizes are
     * measured, so the same evidence always keeps the same contacts.
     *
     * @param  array<string, mixed>  $proposed
     * @return array<string, mixed>
     */
    private function boundedContacts(array $proposed): array
    {
        /** @var list<array<string, string|null>> $contacts */
        $contacts = $proposed['ehi']['contacts'];
        $total = count($contacts);
        $proposed['ehi']['contacts'] = [];
        $proposed['ehi']['contacts_total'] = $total;
        // Counted at its longer value; the update `changed` list (four field
        // names at most) is not, and fits in the slack this leaves.
        $proposed['ehi']['contacts_truncated'] = false;
        $rest = $proposed;
        $rest['attributes']['extracted_text'] = null;
        $remaining = self::maxMetadataBytes() - self::encodedBytes($rest);

        $kept = [];
        $order = $total === 0 ? [] : [0, ...($total > 1 ? range($total - 1, 1) : [])];
        foreach ($order as $index) {
            $bytes = self::encodedBytes($contacts[$index]) + 1;
            if ($bytes > $remaining) {
                break;
            }
            $kept[$index] = $contacts[$index];
            $remaining -= $bytes;
        }
        ksort($kept, SORT_NUMERIC);
        $proposed['ehi']['contacts'] = array_values($kept);
        $proposed['ehi']['contacts_truncated'] = count($kept) < $total;

        return $proposed;
    }

    /**
     * @param  list<int>  $revisionIds
     * @return array{verdict: string, via: list<string>}
     */
    private function noteOwnership(EhiMappingContext $context, EhiDocumentEvidence $evidence, PhrEhiRevision $note, string $noteId, array &$revisionIds): array
    {
        $patientKey = $context->source->source_patient_key;
        $via = [];
        $foreign = false;
        $ambiguous = false;

        $csn = EhiDocumentValues::cell($note, 'PAT_ENC_CSN_ID');
        if ($csn !== null) {
            $encounters = $evidence->where(self::PAT_ENC, 'PAT_ENC_CSN_ID', $csn);
            if ($encounters === []) {
                $this->finding($context, EhiDocumentFinding::ENCOUNTER_MISSING, $note, ['table' => self::PAT_ENC]);
            }
            foreach ($encounters as $encounter) {
                $revisionIds[] = $encounter->id;
                $ambiguous = $ambiguous || $evidence->isAmbiguous($encounter);
                $owner = EhiDocumentValues::cell($encounter, 'PAT_ID');
                if ($owner === $patientKey) {
                    $via[] = self::PAT_ENC.'.PAT_ID';
                } elseif ($owner !== null) {
                    $foreign = true;
                }
            }
        }

        $patLink = EhiDocumentValues::cell($note, 'PAT_LINK_ID');
        if ($patLink === $patientKey) {
            $via[] = self::HNO_INFO.'.PAT_LINK_ID';
        } elseif ($patLink !== null) {
            $foreign = true;
        }

        foreach ($this->sortedBy($evidence->where(self::LINKED_PATS, 'NOTE_ID', $noteId), 'LINE') as $link) {
            $revisionIds[] = $link->id;
            $ambiguous = $ambiguous || $evidence->isAmbiguous($link);
            $owner = EhiDocumentValues::cell($link, 'LINKED_PAT_ID');
            if ($owner === $patientKey) {
                $via[] = self::LINKED_PATS.'.LINKED_PAT_ID';
            } elseif ($owner !== null) {
                $foreign = true;
            }
        }

        $via = array_values(array_unique($via));
        if ($ambiguous) {
            $this->finding($context, EhiDocumentFinding::AMBIGUOUS_REVISION, $note, ['table' => 'ownership']);

            return ['verdict' => 'ambiguous_revision', 'via' => $via];
        }
        if ($foreign) {
            $this->finding($context, EhiDocumentFinding::FOREIGN_PATIENT, $note, ['table' => self::HNO_INFO]);

            return ['verdict' => 'foreign_patient', 'via' => $via];
        }
        if ($via === []) {
            $this->finding($context, EhiDocumentFinding::ORPHAN, $note, ['table' => self::HNO_INFO]);

            return ['verdict' => 'orphan', 'via' => []];
        }

        return ['verdict' => 'owned', 'via' => $via];
    }

    /**
     * Every contact (revision/addendum) of the note, oldest first by exact
     * CONTACT_DATE_REAL, then CONTACT_SERIAL_NUM. The proposal relies on all.
     *
     * @param  list<int>  $revisionIds
     * @return array{rows: list<PhrEhiRevision>, ambiguous: bool}
     */
    private function contacts(EhiDocumentEvidence $evidence, string $noteId, array &$revisionIds): array
    {
        $rows = $evidence->where(self::NOTE_ENC_INFO, 'NOTE_ID', $noteId);
        $ambiguous = false;
        foreach ($rows as $row) {
            $revisionIds[] = $row->id;
            $ambiguous = $ambiguous || $evidence->isAmbiguous($row);
        }
        usort($rows, static fn (PhrEhiRevision $a, PhrEhiRevision $b): int => EhiDocumentValues::compareDecimals(EhiDocumentValues::cell($a, 'CONTACT_DATE_REAL') ?? '', EhiDocumentValues::cell($b, 'CONTACT_DATE_REAL') ?? '')
            ?: EhiDocumentValues::compareIntegers(EhiDocumentValues::cell($a, 'CONTACT_SERIAL_NUM') ?? '', EhiDocumentValues::cell($b, 'CONTACT_SERIAL_NUM') ?? '')
            ?: strcmp($a->content_digest, $b->content_digest));

        return ['rows' => $rows, 'ambiguous' => $ambiguous];
    }

    /** @return array<string, string|null> each value at most MAX_VALUE_BYTES */
    private function contactSummary(PhrEhiRevision $contact): array
    {
        $summary = [];
        foreach ([
            'contact_serial_num' => 'CONTACT_SERIAL_NUM',
            'contact_date_real' => 'CONTACT_DATE_REAL',
            'contact_date' => 'CONTACT_DATE',
            'note_status' => 'NOTE_STATUS_C_NAME',
            'note_type' => 'NOTE_TYPE_C_NAME',
            'note_format' => 'NOTE_FORMAT_C_NAME',
            'author' => 'AUTHOR_USER_ID_NAME',
        ] as $field => $column) {
            $summary[$field] = EhiDocumentValues::bounded(EhiDocumentValues::cell($contact, $column), self::MAX_VALUE_BYTES);
        }

        return $summary;
    }

    /**
     * Reconstruct HNO_PLAIN_TEXT for the latest contact that has text lines.
     *
     * @param  list<PhrEhiRevision>  $contacts  oldest first
     * @param  list<int>  $revisionIds
     * @return array{text: string|null, provenance: array<string, mixed>|null}
     */
    private function noteText(EhiMappingContext $context, EhiDocumentEvidence $evidence, PhrEhiRevision $note, string $noteId, array $contacts, array &$revisionIds): array
    {
        // Line ids by contact; only the chosen contact's lines are read back.
        $groups = $evidence->groups(self::HNO_PLAIN_TEXT, 'NOTE_ID', $noteId);
        if ($groups === []) {
            return ['text' => null, 'provenance' => null];
        }

        $chosen = null;
        foreach (array_reverse($contacts) as $contact) {
            $csn = EhiDocumentValues::cell($contact, 'CONTACT_SERIAL_NUM');
            if ($csn !== null && isset($groups[$csn])) {
                $chosen = $csn;
                break;
            }
        }
        if ($chosen === null && $contacts === [] && count($groups) === 1 && array_key_first($groups) !== '') {
            $chosen = (string) array_key_first($groups);
        }
        if ($chosen === null) {
            $this->finding($context, EhiDocumentFinding::TEXT_UNORDERED, $note, ['table' => self::HNO_PLAIN_TEXT, 'reason' => 'no_contact']);

            return ['text' => null, 'provenance' => null];
        }

        $lines = $evidence->fetch($groups[$chosen]);
        foreach ($lines as $line) {
            $revisionIds[] = $line->id;
            $number = EhiDocumentValues::cell($line, 'LINE');
            if ($evidence->isAmbiguous($line) || $number === null || ! EhiDocumentValues::isExactInteger($number)) {
                $this->finding($context, EhiDocumentFinding::TEXT_UNORDERED, $note, ['table' => self::HNO_PLAIN_TEXT, 'reason' => $evidence->isAmbiguous($line) ? 'ambiguous' : 'line']);

                return ['text' => null, 'provenance' => null];
            }
        }
        $lines = $this->sortedBy($lines, 'LINE');
        $parts = [];
        $gaps = false;
        foreach ($lines as $position => $line) {
            $gaps = $gaps || EhiDocumentValues::cell($line, 'LINE') !== (string) ($position + 1);
            $parts[] = EhiDocumentValues::cell($line, 'NOTE_TEXT') ?? '';
        }
        [$text, $truncated] = $this->boundedText(implode("\n", $parts));
        if (trim($text) === '') {
            // No content, as applyCreate() judges it: blank lines are not text.
            return ['text' => null, 'provenance' => null];
        }

        return ['text' => $text, 'provenance' => [
            'derived' => true,
            'source' => self::HNO_PLAIN_TEXT,
            'contact_serial_num' => $chosen,
            'line_count' => count($lines),
            'line_gaps' => $gaps,
            'truncated' => $truncated,
        ]];
    }

    /**
     * Derived text from a linked RTF member, when there is no table text.
     *
     * @param  array{member: PhrEhiPackageMember, via: string, doc_info_id: string}|null  $file
     * @return array{text: string|null, provenance: array<string, mixed>|null}
     */
    private function rtfText(EhiMappingContext $context, PhrEhiRevision $root, ?array $file): array
    {
        if ($file === null || $file['member']->kind !== EhiMemberKind::NOTE) {
            return ['text' => null, 'provenance' => null];
        }
        $bytes = $this->files->readVerified($file['member'], EhiRtfText::MAX_INPUT_BYTES);
        if ($bytes === null) {
            $this->finding($context, EhiDocumentFinding::MEMBER_UNVERIFIED, $root, ['reason' => 'rtf_unreadable']);

            return ['text' => null, 'provenance' => null];
        }
        $result = $this->rtf->toText($bytes, self::maxTextBytes());
        if ($result->unsupported) {
            $this->finding($context, EhiDocumentFinding::RTF_UNSUPPORTED, $root, ['truncated' => $result->truncated]);

            return ['text' => null, 'provenance' => ['derived' => true, 'source' => 'rtf', 'rtf' => $result->flags()]];
        }

        return [
            'text' => trim($result->text) === '' ? null : $result->text,
            'provenance' => ['derived' => true, 'source' => 'rtf', 'member_id' => $file['member']->id, 'truncated' => $result->truncated, 'rtf' => $result->flags()],
        ];
    }

    // ------------------------------------------------------ scanned documents

    /** @param  array<int, true>  $linkedMembers */
    private function scannedDocumentDraft(EhiMappingContext $context, EhiDocumentEvidence $evidence, PhrEhiRevision $document, array &$linkedMembers): EhiProposalDraft
    {
        $entity = $document->entity;
        $docInfoId = (string) EhiDocumentValues::cell($document, 'DOC_INFO_ID');
        $reference = ['table' => self::DOC_INFORMATION, 'doc_info_id' => $docInfoId];
        $revisionIds = [$document->id];
        if (strlen('DOC:'.$docInfoId) > self::MAX_EXTERNAL_ID_BYTES) {
            return $this->retainDraft($entity, $revisionIds, 'identity_too_long', ['table' => self::DOC_INFORMATION]);
        }
        $patientKey = $context->source->source_patient_key;

        $owner = EhiDocumentValues::cell($document, 'DOC_PT_ID');
        $via = [];
        $foreign = $owner !== null && $owner !== $patientKey;
        if ($owner === $patientKey) {
            $via[] = self::DOC_INFORMATION.'.DOC_PT_ID';
        }
        $csn = EhiDocumentValues::cell($document, 'DOC_CSN');
        if ($csn !== null) {
            foreach ($evidence->where(self::PAT_ENC, 'PAT_ENC_CSN_ID', $csn) as $encounter) {
                $revisionIds[] = $encounter->id;
                $encounterOwner = EhiDocumentValues::cell($encounter, 'PAT_ID');
                if ($evidence->isAmbiguous($encounter)) {
                    $foreign = true;
                } elseif ($encounterOwner === $patientKey) {
                    $via[] = self::PAT_ENC.'.PAT_ID';
                } elseif ($encounterOwner !== null) {
                    $foreign = true;
                }
            }
        }
        if ($foreign) {
            $this->finding($context, EhiDocumentFinding::FOREIGN_PATIENT, $document, ['table' => self::DOC_INFORMATION]);

            return $this->retainDraft($entity, $revisionIds, 'foreign_patient', $reference);
        }
        if ($via === []) {
            $this->finding($context, EhiDocumentFinding::ORPHAN, $document, ['table' => self::DOC_INFORMATION]);

            return $this->retainDraft($entity, $revisionIds, 'orphan', $reference);
        }
        // Every documented record state (inactive, deleted, hidden) withdraws it.
        if (EhiDocumentValues::cell($document, 'RECORD_STATE_C_NAME') !== null) {
            return $this->retainDraft($entity, $revisionIds, 'record_state', $reference);
        }

        $file = $this->resolveScanFile($context, $evidence, $document, $linkedMembers);
        if ($file === null) {
            return $this->retainDraft($entity, $revisionIds, 'no_content', $reference);
        }
        $text = $this->rtfText($context, $document, $file);
        $observed = $this->observed($context, $document, [
            ['DOC_SRVC_DTTM', 'local'],
            ['SCAN_INST_DTTM', 'local'],
            ['DT_ON_DOC', self::TIMEZONE_UNSPECIFIED],
            ['DOC_RECV_TIME', 'local'],
        ]);
        $title = EhiDocumentValues::cell($document, 'DOC_DESCR') ?? EhiDocumentValues::cell($document, 'DOC_INFO_TYPE_C_NAME') ?? 'Scanned document';

        return $this->reconcile($context, $entity, $revisionIds, $this->proposal(
            context: $context,
            externalId: 'DOC:'.$docInfoId,
            title: $title,
            documentType: 'other',
            observed: $observed,
            text: $text,
            file: $file,
            summary: ['Imported from an EHI Tables document record (DOC_INFORMATION).'],
            unsigned: false,
            ehi: [
                ...$reference,
                'encounter_csn' => EhiDocumentValues::bounded($csn, self::MAX_VALUE_BYTES),
                'document_type' => EhiDocumentValues::bounded(EhiDocumentValues::cell($document, 'DOC_INFO_TYPE_C_NAME'), self::MAX_VALUE_BYTES),
                'document_status' => EhiDocumentValues::bounded(EhiDocumentValues::cell($document, 'DOC_STAT_C_NAME'), self::MAX_VALUE_BYTES),
                'ownership' => array_values(array_unique($via)),
            ],
        ));
    }

    /**
     * The one note/media member a documented SCAN_FILE reference names, for every
     * DOC_INFORMATION row whose reference column equals $value.
     *
     * Only the rows that supply that file join $revisionIds. A row that is
     * ambiguous, names another patient, has no resolvable file, or names a
     * different file is not used, so it stays out of the note's proposal and
     * stands on its own ownership (and reports its own file reference).
     *
     * @param  list<int>  $revisionIds
     * @param  array<int, true>  $linkedMembers
     * @return array{member: PhrEhiPackageMember, via: string, doc_info_id: string}|null
     */
    private function referencedFile(EhiMappingContext $context, EhiDocumentEvidence $evidence, PhrEhiRevision $root, string $table, string $column, string $value, array &$revisionIds, array &$linkedMembers): ?array
    {
        $resolved = [];
        /** @var array<int, list<int>> $suppliers package file => the revisions that name it */
        $suppliers = [];
        $patientKey = $context->source->source_patient_key;
        foreach ($this->sortedBy($evidence->where($table, $column, $value), 'DOC_INFO_ID') as $document) {
            if ($evidence->isAmbiguous($document)) {
                $this->finding($context, EhiDocumentFinding::AMBIGUOUS_REVISION, $root, ['table' => $table]);

                continue;
            }
            $owner = EhiDocumentValues::cell($document, 'DOC_PT_ID');
            if ($owner !== null && $owner !== $patientKey) {
                $this->finding($context, EhiDocumentFinding::FOREIGN_PATIENT, $root, ['table' => $table]);

                continue;
            }
            $file = $this->resolveScanFile($context, $evidence, $document, $linkedMembers, report: false);
            if ($file !== null) {
                $resolved[$file['member']->id] ??= $file;
                $suppliers[$file['member']->id][] = $document->id;
            }
        }
        if (count($resolved) > 1) {
            $this->finding($context, EhiDocumentFinding::MULTIPLE_FILES, $root, ['table' => $table, 'count' => count($resolved)]);

            return null;
        }
        if ($resolved === []) {
            return null;
        }
        $memberId = array_key_first($resolved);
        array_push($revisionIds, ...$suppliers[$memberId]);

        return $resolved[$memberId];
    }

    /**
     * @param  array<int, true>  $linkedMembers
     * @param  bool  $report  whether an unresolved reference is a finding here (a
     *                        note's lookup leaves it to the row's own proposal)
     * @return array{member: PhrEhiPackageMember, via: string, doc_info_id: string}|null
     */
    private function resolveScanFile(EhiMappingContext $context, EhiDocumentEvidence $evidence, PhrEhiRevision $document, array &$linkedMembers, bool $report = true): ?array
    {
        $scanFile = EhiDocumentValues::cell($document, 'SCAN_FILE');
        if ($scanFile === null) {
            return null;
        }
        $matches = $evidence->membersNamed($scanFile);
        if (count($matches) !== 1) {
            if ($report) {
                $this->finding($context, EhiDocumentFinding::FILE_REFERENCE_UNRESOLVED, $document, ['table' => self::DOC_INFORMATION, 'column' => 'SCAN_FILE', 'matches' => count($matches)]);
            }

            return null;
        }
        $linkedMembers[$matches[0]->id] = true;

        return ['member' => $matches[0], 'via' => self::DOC_INFORMATION.'.SCAN_FILE', 'doc_info_id' => (string) EhiDocumentValues::cell($document, 'DOC_INFO_ID')];
    }

    // --------------------------------------------------------------- shared

    /**
     * @param  array{text: string|null, provenance: array<string, mixed>|null}  $text
     * @param  array{member: PhrEhiPackageMember, via: string, doc_info_id: string}|null  $file
     * @param  array{column: string, timezone: string, precision: string, source_text: string, time_applied: bool, value: string}|null  $observed
     * @param  list<string>  $summary
     * @param  array<string, mixed>  $ehi
     * @return array<string, mixed>
     */
    private function proposal(EhiMappingContext $context, string $externalId, string $title, string $documentType, ?array $observed, array $text, ?array $file, array $summary, bool $unsigned, array $ehi): array
    {
        if ($observed !== null) {
            $summary[] = $observed['time_applied']
                ? sprintf('Observed time from %s (UTC).', $observed['column'])
                : sprintf($observed['timezone'] === self::TIMEZONE_UNSPECIFIED
                    ? 'Observed date from %s (no documented time zone; time of day not applied).'
                    : 'Observed date from %s (%s; time of day not applied).', $observed['column'], $observed['timezone']);
        }
        if ($text['text'] !== null) {
            $summary[] = 'Text is derived from the source and is not the original.';
        }
        if ($file === null) {
            $summary[] = 'No original file is linked; the stored file is the derived text.';
        }
        $tags = [self::TAG_IMPORTED];
        if ($file === null) {
            $tags[] = self::TAG_DERIVED_TEXT;
        }
        if ($unsigned) {
            $tags[] = self::TAG_UNSIGNED;
        }

        $member = $file['member'] ?? null;
        $extension = $member === null ? 'txt' : strtolower(pathinfo($member->path, PATHINFO_EXTENSION));

        return [
            'attributes' => [
                'title' => mb_substr($title, 0, 255),
                'document_type' => $documentType,
                'observed_at' => $observed['value'] ?? null,
                'summary' => implode(' ', $summary),
                'extracted_text' => $text['text'],
                'source' => self::SOURCE,
                'import_source' => self::importSource($context->source->id),
                'external_id' => $externalId,
                'mime_type' => $member === null ? 'text/plain' : (self::MIME_TYPES[$extension] ?? 'application/octet-stream'),
                'original_filename' => EhiDocumentValues::boundedFileName($member === null ? strtolower(str_replace(':', '-', $externalId)).'.txt' : basename($member->path)),
                'tags' => $tags,
            ],
            'ehi' => [
                ...$ehi,
                'observed' => $observed,
                'text' => $text['provenance'],
                'text_sha256' => $text['text'] === null ? null : hash('sha256', $text['text']),
                'file' => $member === null ? null : [
                    'member_id' => $member->id,
                    'kind' => $member->kind,
                    'sha256' => $member->sha256,
                    'byte_size' => $member->byte_size,
                    'via' => $file['via'],
                    'doc_info_id' => $file['doc_info_id'],
                ],
            ],
        ];
    }

    /**
     * Corroborate, update, retain, flag a possible duplicate, or create.
     *
     * @param  list<int>  $revisionIds
     * @param  array<string, mixed>  $proposed
     */
    private function reconcile(EhiMappingContext $context, PhrEhiEntity $entity, array $revisionIds, array $proposed): EhiProposalDraft
    {
        $revisionIds = $this->ids($revisionIds);
        /** @var array<string, mixed> $attributes */
        $attributes = $proposed['attributes'];
        $patientId = $context->patient->id;

        $documentIds = $context->linksForEntity($entity)->pluck('document_id')->filter()->map(static fn ($id): int => (int) $id)->all();
        $identity = PhrDocument::withTrashed()
            ->where('patient_id', $patientId)
            ->where('import_source', $attributes['import_source'])
            ->where('external_id', $attributes['external_id'])
            ->value('id');
        if ($identity !== null) {
            $documentIds[] = (int) $identity;
        }
        $targets = $documentIds === [] ? collect() : PhrDocument::withTrashed()
            ->where('patient_id', $patientId)
            ->whereIn('id', array_values(array_unique($documentIds)))
            ->orderBy('id')
            ->get();
        $liveTargets = $targets->filter(static fn (PhrDocument $document): bool => PhrRecordLifecycle::of($document) === PhrRecordLifecycle::ACTIVE);
        if ($liveTargets->count() > 1) {
            // Links to different documents (e.g. two packages reviewed against
            // different targets): neither is chosen, so none is corroborated or updated.
            $this->finding($context, EhiDocumentFinding::AMBIGUOUS_LINK, null, ['table' => (string) $entity->table_name, 'records' => $liveTargets->count()]);

            return $this->retainDraft($entity, $revisionIds, 'ambiguous_link', $proposed['ehi'] ?? []);
        }
        $live = $liveTargets->first();

        if ($live instanceof PhrDocument) {
            $before = $this->comparable($live);
            $after = $this->comparableProposed($attributes);
            $linked = PhrEhiRecordLink::query()
                ->where('document_id', $live->id)
                ->whereIn('revision_id', $revisionIds)
                ->distinct()
                ->count('revision_id');
            if ($linked === count($revisionIds) || $before === $after) {
                return new EhiProposalDraft($entity->key_digest, PhrEhiProposal::KIND_CORROBORATE, $revisionIds, $proposed, self::RECORD_TABLE, $live->id);
            }
            $proposed['changed'] = array_keys(array_diff_assoc(
                array_map(static fn (?string $value): string => (string) $value, $after),
                array_map(static fn (?string $value): string => (string) $value, $before),
            ));
            $proposed['changed'] = array_map(static fn (string $field): string => $field === 'extracted_text_sha256' ? 'extracted_text' : $field, $proposed['changed']);

            return new EhiProposalDraft($entity->key_digest, PhrEhiProposal::KIND_UPDATE, $revisionIds, $proposed, self::RECORD_TABLE, $live->id, $before);
        }
        if ($targets->isNotEmpty()) {
            $this->finding($context, EhiDocumentFinding::TARGET_WITHDRAWN, null, ['table' => (string) $entity->table_name]);

            return $this->retainDraft($entity, $revisionIds, 'target_withdrawn', $proposed['ehi'] ?? []);
        }

        $candidates = $this->possibleDuplicates($patientId, $attributes);

        return $candidates === []
            ? new EhiProposalDraft($entity->key_digest, PhrEhiProposal::KIND_CREATE, $revisionIds, $proposed)
            : new EhiProposalDraft($entity->key_digest, PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $revisionIds, $proposed, candidateIds: $candidates);
    }

    /**
     * Live documents of the patient with the same observed date, the same type,
     * and identical derived text.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<int>
     */
    private function possibleDuplicates(int $patientId, array $attributes): array
    {
        $observed = $attributes['observed_at'] ?? null;
        $text = $attributes['extracted_text'] ?? null;
        if (! is_string($observed) || ! is_string($text) || $text === '') {
            return [];
        }
        $digest = hash('sha256', $text);

        return array_values(PhrDocument::query()
            ->where('patient_id', $patientId)
            ->whereNull('retracted_at')
            ->where('document_type', $attributes['document_type'])
            ->whereDate('observed_at', substr($observed, 0, 10))
            ->whereNotNull('extracted_text')
            ->orderBy('id')
            ->get(['id', 'extracted_text'])
            ->filter(static fn (PhrDocument $document): bool => hash_equals($digest, hash('sha256', (string) $document->extracted_text)))
            ->map(static fn (PhrDocument $document): int => $document->id)
            ->all());
    }

    /** @return array{title: string|null, document_type: string|null, observed_at: string|null, extracted_text_sha256: string|null} */
    private function comparable(PhrDocument $document): array
    {
        return [
            'title' => $document->title,
            'document_type' => $document->document_type,
            'observed_at' => $document->observed_at?->format('Y-m-d H:i:s'),
            'extracted_text_sha256' => $document->extracted_text === null ? null : hash('sha256', $document->extracted_text),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{title: string|null, document_type: string|null, observed_at: string|null, extracted_text_sha256: string|null}
     */
    private function comparableProposed(array $attributes): array
    {
        $text = $attributes['extracted_text'] ?? null;

        return [
            'title' => is_string($attributes['title'] ?? null) ? $attributes['title'] : null,
            'document_type' => is_string($attributes['document_type'] ?? null) ? $attributes['document_type'] : null,
            'observed_at' => is_string($attributes['observed_at'] ?? null) ? $attributes['observed_at'] : null,
            'extracted_text_sha256' => is_string($text) ? hash('sha256', $text) : null,
        ];
    }

    /**
     * First recognized date/time among the columns, in order.
     *
     * @param  list<array{0: string, 1: string}>  $columns  [column, 'UTC'|'local'|TIMEZONE_UNSPECIFIED]
     * @return array{column: string, timezone: string, precision: string, source_text: string, time_applied: bool, value: string}|null
     */
    private function observed(EhiMappingContext $context, PhrEhiRevision $revision, array $columns): ?array
    {
        foreach ($columns as [$column, $timezone]) {
            $raw = EhiDocumentValues::cell($revision, $column);
            if ($raw === null) {
                continue;
            }
            $parsed = EhiDocumentValues::dateTime($raw);
            if ($parsed === null) {
                $this->finding($context, EhiDocumentFinding::UNPARSED_DATETIME, $revision, ['column' => $column]);

                continue;
            }
            $applyTime = $timezone === 'UTC' && $parsed['time'] !== null;

            return [
                'column' => $column,
                'timezone' => $timezone,
                'precision' => $applyTime ? $parsed['precision'] : 'date',
                'source_text' => EhiDocumentValues::bounded($raw, self::MAX_VALUE_BYTES),
                'time_applied' => $applyTime,
                'value' => $parsed['date'].' '.($applyTime ? $parsed['time'] : '00:00:00'),
            ];
        }

        return null;
    }

    /**
     * Record a finding with its fixed severity (the proposal service strips
     * `severity` from the stored detail).
     *
     * @param  array<string, int|string|bool|null>  $detail  structural only, never source values
     */
    private function finding(EhiMappingContext $context, string $code, ?PhrEhiRevision $revision, array $detail): void
    {
        $context->finding($code, $revision, ['severity' => EhiDocumentFinding::SEVERITY[$code] ?? 'info', ...$detail]);
    }

    /**
     * MAX_TEXT_BYTES, or an eighth of the native restore record limit if that is
     * smaller: a proposal can carry the proposed and the previous text, and each
     * may nearly double when escaped, yet the record must still restore.
     */
    public static function maxTextBytes(): int
    {
        return min(self::MAX_TEXT_BYTES, intdiv((int) config('phr.native_restore_max_record_bytes'), 8));
    }

    /**
     * MAX_METADATA_BYTES, or an eighth of the native restore record limit if that
     * is smaller: the budget for a proposal without its derived text, measured as
     * escaped JSON. Source values in it are cut to MAX_VALUE_BYTES and note
     * contacts are fitted to what remains, so with the text it still restores.
     */
    public static function maxMetadataBytes(): int
    {
        return min(self::MAX_METADATA_BYTES, intdiv((int) config('phr.native_restore_max_record_bytes'), 8));
    }

    /** Bytes of the value as escaped JSON, the larger of its stored encodings. */
    private static function encodedBytes(mixed $value): int
    {
        return strlen(json_encode($value, JSON_THROW_ON_ERROR));
    }

    /** @return array{0: string, 1: bool} sanitized text within maxTextBytes(), and whether it was cut */
    private function boundedText(string $text): array
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text) ?? '';
        $max = self::maxTextBytes();
        if (strlen($text) <= $max) {
            return [$text, false];
        }

        return [mb_strcut($text, 0, $max, 'UTF-8'), true];
    }

    private function yesNo(?string $raw): ?bool
    {
        return match ($raw === null ? null : strtolower($raw)) {
            'y', 'yes', '1' => true,
            'n', 'no', '0' => false,
            default => null,
        };
    }

    /**
     * @param  list<int>  $revisionIds
     * @param  array<string, mixed>  $reference
     */
    private function retainDraft(PhrEhiEntity $entity, array $revisionIds, string $reason, array $reference): EhiProposalDraft
    {
        return new EhiProposalDraft($entity->key_digest, PhrEhiProposal::KIND_RETAIN, $this->ids($revisionIds), [
            'reason' => $reason,
            'ehi' => array_intersect_key($reference, array_flip(['table', 'note_id', 'doc_info_id'])),
        ]);
    }

    /** @param  list<PhrEhiRevision>  $revisions */
    private function ambiguousDraft(EhiMappingContext $context, array $revisions, string $table): EhiProposalDraft
    {
        $entity = $revisions[0]->entity;
        $this->finding($context, EhiDocumentFinding::AMBIGUOUS_REVISION, $revisions[0], ['table' => $table, 'count' => count($revisions)]);

        return new EhiProposalDraft($entity->key_digest, PhrEhiProposal::KIND_RETAIN, $this->ids(array_map(static fn (PhrEhiRevision $revision): int => $revision->id, $revisions)), [
            'reason' => 'ambiguous_revision',
            'ehi' => ['table' => $table],
        ]);
    }

    /**
     * Consecutive revisions of one entity, in the context's deterministic order
     * (which keeps every revision of an entity adjacent).
     *
     * @param  iterable<PhrEhiRevision>  $revisions
     * @return Generator<int, list<PhrEhiRevision>>
     */
    private function entityGroups(iterable $revisions): Generator
    {
        $group = [];
        foreach ($revisions as $revision) {
            if ($group !== [] && $group[0]->entity_id !== $revision->entity_id) {
                yield $group;
                $group = [];
            }
            $group[] = $revision;
        }
        if ($group !== []) {
            yield $group;
        }
    }

    /**
     * @param  list<PhrEhiRevision>  $rows
     * @return list<PhrEhiRevision>
     */
    private function sortedBy(array $rows, string $column): array
    {
        usort($rows, static fn (PhrEhiRevision $a, PhrEhiRevision $b): int => EhiDocumentValues::compareIntegers(EhiDocumentValues::cell($a, $column) ?? '', EhiDocumentValues::cell($b, $column) ?? '')
            ?: strcmp($a->content_digest, $b->content_digest));

        return $rows;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    // ---------------------------------------------------------------- apply

    private function applyCreate(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome
    {
        $patient = $context->patient;
        $proposed = is_array($proposal->proposed_json) ? $proposal->proposed_json : [];
        $attributes = is_array($proposed['attributes'] ?? null) ? $proposed['attributes'] : [];
        $file = $proposed['ehi']['file'] ?? null;
        $package = PhrEhiPackage::query()->where('patient_id', $patient->id)->find($proposal->package_id);
        $externalId = $attributes['external_id'] ?? null;
        $text = $attributes['extracted_text'] ?? null;
        if (! $package instanceof PhrEhiPackage || $package->source_id === null
            || ($attributes['import_source'] ?? null) !== self::importSource((int) $package->source_id)
            || ! is_string($externalId) || preg_match('/\A(?:NOTE|DOC):./', $externalId) !== 1 || strlen($externalId) > self::MAX_EXTERNAL_ID_BYTES
            || ! is_string($attributes['title'] ?? null)
            || ! in_array($attributes['document_type'] ?? null, PhrDocument::DOCUMENT_TYPES, true)
            || ($file === null && (! is_string($text) || trim($text) === ''))
            || ($file !== null && (! is_array($file) || ! is_int($file['member_id'] ?? null) || ! is_string($file['sha256'] ?? null)))) {
            throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::MALFORMED_PROPOSAL, 'the proposal does not carry a usable import identity, title, and content');
        }
        $member = null;
        $fileSha256 = is_array($file) && is_string($file['sha256'] ?? null) ? $file['sha256'] : null;
        if (is_array($file)) {
            $member = PhrEhiPackageMember::query()
                ->where('patient_id', $patient->id)
                ->where('package_id', $package->id)
                ->whereIn('kind', [EhiMemberKind::NOTE, EhiMemberKind::MEDIA])
                ->find($file['member_id']);
            if (! $member instanceof PhrEhiPackageMember) {
                throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::EVIDENCE_UNAVAILABLE, 'the linked member is not in this package');
            }
        }

        return $this->artifactWriteGuard->run($patient->id, function () use ($attributes, $context, $externalId, $fileSha256, $member, $patient, $text): EhiApplyOutcome {
            $identity = ['patient_id' => $patient->id, 'import_source' => $attributes['import_source'], 'external_id' => $externalId];
            $this->refuseHeldIdentity($identity);

            $stored = $member instanceof PhrEhiPackageMember && $fileSha256 !== null
                ? $this->files->copyMember($patient->id, $member, $fileSha256, basename($member->path))
                : $this->files->storeText($patient->id, (string) $text, (string) $attributes['original_filename']);
            // Published before the row that references it commits: if the review
            // transaction rolls back after this point, the review service removes it.
            $context->published->add(PhrDocument::STORAGE_DISK, $stored['path']);
            try {
                $document = PhrDocument::query()->create([
                    ...$identity,
                    'user_id' => $patient->owner_user_id,
                    'uploaded_by_user_id' => $context->actorUserId,
                    'title' => $attributes['title'],
                    'document_type' => $attributes['document_type'],
                    'observed_at' => $attributes['observed_at'] ?? null,
                    'original_filename' => $attributes['original_filename'] ?? null,
                    'storage_disk' => PhrDocument::STORAGE_DISK,
                    'storage_path' => $stored['path'],
                    'mime_type' => $attributes['mime_type'] ?? null,
                    'byte_size' => $stored['bytes'],
                    'file_hash' => $stored['sha256'],
                    'extracted_text' => is_string($text) ? $text : null,
                    'summary' => $attributes['summary'] ?? null,
                    'source' => self::SOURCE,
                    'tags' => is_array($attributes['tags'] ?? null) ? array_values($attributes['tags']) : [self::TAG_IMPORTED],
                    'imported_at' => $context->now,
                ]);
            } catch (UniqueConstraintViolationException) {
                $this->files->delete($stored['path']);
                $this->refuseHeldIdentity($identity);

                throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::IDENTITY_EXISTS, 'a document already holds this import identity');
            } catch (Throwable $exception) {
                $this->files->delete($stored['path']);

                throw $exception;
            }

            return new EhiApplyOutcome(self::RECORD_TABLE, $document->id, PhrEhiRecordLink::KIND_PROJECTED);
        });
    }

    private function applyUpdate(PhrEhiProposal $proposal): EhiApplyOutcome
    {
        $document = $this->lockedLiveDocument($proposal, $proposal->target_table === self::RECORD_TABLE ? $proposal->target_id : null);
        $expected = $proposal->target_digest;
        if (! is_string($expected) || ! hash_equals($expected, EhiTargetDigest::of($document))) {
            throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::TARGET_CHANGED, 'the target changed since the proposal was previewed');
        }

        $proposed = is_array($proposal->proposed_json) ? $proposal->proposed_json : [];
        $attributes = is_array($proposed['attributes'] ?? null) ? $proposed['attributes'] : [];
        $changed = is_array($proposed['changed'] ?? null) ? $proposed['changed'] : [];
        $changes = [];
        foreach (self::UPDATABLE as $field) {
            if (in_array($field, $changed, true) && array_key_exists($field, $attributes)) {
                $changes[$field] = $attributes[$field];
            }
        }
        if ($changes === []
            || (array_key_exists('title', $changes) && ! is_string($changes['title']))
            || (array_key_exists('document_type', $changes) && ! in_array($changes['document_type'], PhrDocument::DOCUMENT_TYPES, true))) {
            throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::MALFORMED_PROPOSAL, 'the update proposes no applicable field');
        }
        $document->fill($changes);
        $document->save();

        return new EhiApplyOutcome(self::RECORD_TABLE, $document->id, PhrEhiRecordLink::KIND_PROJECTED);
    }

    private function lockedLiveDocument(PhrEhiProposal $proposal, ?int $documentId): PhrDocument
    {
        $document = $documentId === null ? null : PhrDocument::withTrashed()
            ->where('patient_id', (int) $proposal->patient_id)
            ->whereKey($documentId)
            ->lockForUpdate()
            ->first();
        if (! $document instanceof PhrDocument) {
            throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::TARGET_NOT_FOUND, "the target is not this patient's document");
        }
        if (PhrRecordLifecycle::of($document) !== PhrRecordLifecycle::ACTIVE) {
            throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::TARGET_WITHDRAWN, 'the target was deleted or retracted');
        }

        return $document;
    }

    /** @param  array{patient_id: int, import_source: mixed, external_id: string}  $identity */
    private function refuseHeldIdentity(array $identity): void
    {
        $existing = PhrDocument::withTrashed()->where($identity)->lockForUpdate()->first();
        if (! $existing instanceof PhrDocument) {
            return;
        }
        if (PhrRecordLifecycle::of($existing) !== PhrRecordLifecycle::ACTIVE) {
            throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::IDENTITY_WITHDRAWN, 'the import identity is no longer available for this patient');
        }

        throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::IDENTITY_EXISTS, 'a document already holds this import identity');
    }
}
