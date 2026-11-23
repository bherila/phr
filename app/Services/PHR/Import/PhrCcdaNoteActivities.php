<?php

namespace App\Services\PHR\Import;

use App\Support\PHR\PhrClinicalNote;
use App\Support\PHR\PhrDocumentType;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * C-CDA clinical notes to PHR documents (phr#409).
 *
 * - The Notes section (2.16.840.1.113883.10.20.22.2.65): each Note Activity
 *   (2.16.840.1.113883.10.20.22.4.202) is one document. Its code is LOINC 34109-9
 *   "Note" with the note type as a translation; the specific code is kept
 *   verbatim as loinc_code. The author is the author (name, role, time written);
 *   the cosigner is the legal authenticator participant (typeCode="LA"), with
 *   the time it signed, unless it is the author signing their own note.
 *   statusCode is the docStatus (completed final, active preliminary, nullified
 *   entered-in-error). The text is the narrative the act's text references.
 * - Hospital Course (LOINC 8648-8): the section's narrative is one note.
 *
 * An addendum or amendment names the note it appends to or replaces in an
 * entryRelationship (REFR) act coded with the ActRelationshipType (APND, RPLC)
 * and carrying that note's id, as PHR's own export writes it.
 *
 * The importer reads a notes section through notes(), not the shared entry and
 * narrative-table paths: a note is a document, not a row.
 */
final class PhrCcdaNoteActivities extends PhrCcdaSectionReader
{
    public const string NOTES_SECTION = '2.16.840.1.113883.10.20.22.2.65';

    public const string NOTE_ACTIVITY = '2.16.840.1.113883.10.20.22.4.202';

    /** IHE Hospital Course section. */
    public const string HOSPITAL_COURSE_SECTION = '1.3.6.1.4.1.19376.1.5.3.1.3.5';

    public const string HOSPITAL_COURSE = '8648-8';

    /** HL7 v3 ActRelationshipType. */
    public const string ACT_RELATIONSHIP_TYPE = '2.16.840.1.113883.5.1002';

    /**
     * PHR's document types as a code system (a UUID-derived OID): an export names
     * a note's PHR type beside its LOINC code, so a re-import keeps it.
     */
    public const string PHR_DOCUMENT_TYPE_OID = '2.25.148288929269828447787136808478095270397';

    /** @var array<string, string> */
    private const array RELATIONS = ['APND' => PhrClinicalNote::APPENDS, 'RPLC' => PhrClinicalNote::REPLACES];

    private const array BLOCKS = ['paragraph', 'list', 'item', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'caption', 'br'];

    public function sectionTemplates(): array
    {
        return [self::NOTES_SECTION, self::HOSPITAL_COURSE_SECTION];
    }

    /** The LOINC code a section routed by code alone carries (phr#406). */
    public function sectionCode(): string
    {
        return self::HOSPITAL_COURSE;
    }

    public function matchesTitle(string $title): bool
    {
        return in_array(trim(preg_replace('/\s+/', ' ', $title) ?? $title), ['notes', 'clinical notes', 'hospital course'], true);
    }

    public function jobType(): string
    {
        return 'phr_document';
    }

    public function idPrefix(): string
    {
        return 'ccda-note-';
    }

    public function legacyKind(): string
    {
        return 'note';
    }

    /** Notes are read by notes(); no entry is a record row. */
    public function entries(DOMElement $section): array
    {
        return [];
    }

    public function narrativeRecord(array $cells): array
    {
        return [];
    }

    /**
     * Every note in the section, with the bytes stored as its file.
     *
     * @return list<array{payload: array<string, mixed>, text: string, relation: array{relation: string, reference: string, target: string}|null}>
     */
    public function notes(DOMElement $section): array
    {
        $acts = array_values(array_filter(
            $this->xml->entryElements($section, 'act', [self::NOTE_ACTIVITY]),
            fn (DOMElement $act): bool => $this->xml->hasTemplate($act, [self::NOTE_ACTIVITY]),
        ));
        if ($acts !== []) {
            return array_values(array_filter(array_map($this->noteActivity(...), $acts)));
        }
        if ($this->isHospitalCourse($section)) {
            $note = $this->hospitalCourse($section);

            return $note === null ? [] : [$note];
        }

        return [];
    }

    /**
     * @return array{payload: array<string, mixed>, text: string, relation: array{relation: string, reference: string, target: string}|null}|null
     */
    private function noteActivity(DOMElement $act): ?array
    {
        $text = $this->noteText($this->xml->child($act, 'text'));
        if ($text === null) {
            return null;
        }
        $code = $this->xml->child($act, 'code');
        $loinc = $this->loinc($code);
        $label = $this->xml->edText($this->xml->child($code, 'originalText')) ?? $this->vendorDisplay($code);
        $author = $this->xml->child($act, 'author');
        $authorName = $this->xml->nameText($this->xml->path($author, 'assignedAuthor', 'assignedPerson', 'name'));
        $authorRoleCode = $this->xml->path($author, 'assignedAuthor', 'code');
        $authorRole = $this->xml->edText($this->xml->child($authorRoleCode, 'originalText')) ?? $this->xml->attribute($authorRoleCode, 'displayName');
        $authenticator = null;
        foreach ($this->xml->children($act, 'participant') as $participant) {
            if ($this->xml->attribute($participant, 'typeCode') === 'LA') {
                $authenticator = $participant;

                break;
            }
        }
        $signer = $this->xml->nameText($this->xml->path($authenticator, 'participantRole', 'playingEntity', 'name'));
        $authoredAt = $this->xml->timestamp($this->xml->attribute($this->xml->child($author, 'time'), 'value'));
        [$effective] = $this->xml->period($this->xml->child($act, 'effectiveTime'));
        $status = $this->xml->attribute($this->xml->child($act, 'statusCode'), 'code');

        return [
            'payload' => [
                'external_id' => $this->externalId($act, [$loinc, $authoredAt, $authorName, sha1($text)]),
                'title' => $label ?? $this->xml->codeName($this->translation($code, PhrClinicalNote::LOINC_OID)) ?? 'Clinical note',
                'document_type' => $this->documentType($code, $loinc),
                'observed_at' => $effective ?? $authoredAt,
                'source' => 'ccda_import',
                'loinc_code' => $loinc,
                'source_type_label' => $label,
                'author_name' => $authorName,
                'author_role' => $authorRole,
                // A note its author signs has no cosigner.
                'cosigner_name' => $signer !== null && $signer !== $authorName ? $signer : null,
                'authored_at' => $authoredAt,
                'signed_at' => $this->xml->timestamp($this->xml->attribute($this->xml->child($authenticator, 'time'), 'value')),
                'doc_status' => match ($status) {
                    'completed' => 'final',
                    'active' => 'preliminary',
                    'nullified' => 'entered-in-error',
                    default => null,
                },
            ],
            'text' => $text,
            'relation' => $this->relation($act),
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, text: string, relation: null}|null
     */
    private function hospitalCourse(DOMElement $section): ?array
    {
        $text = $this->noteText($this->xml->child($section, 'text'));
        if ($text === null) {
            return null;
        }
        $title = ValueCoercion::string($this->xml->child($section, 'title')?->textContent) ?? 'Hospital Course';
        // One per clinical document: the document's id, else what the section says.
        $document = $this->xml->first('/*[local-name()="ClinicalDocument"]/*[local-name()="id"]');
        $documentId = $document === null ? null : ($this->xml->attribute($document, 'root') ?? '').'|'.($this->xml->attribute($document, 'extension') ?? '');
        $key = $documentId === null || $documentId === '|' ? ['content', sha1($text)] : ['document', $documentId];
        [$effective] = $this->xml->period($this->xml->first('/*[local-name()="ClinicalDocument"]/*[local-name()="effectiveTime"]'));

        return [
            'payload' => [
                'external_id' => $this->idPrefix().sha1(implode('|', ['hospital-course', ...$key])),
                'title' => $title,
                'document_type' => 'discharge_summary',
                'observed_at' => $effective,
                'source' => 'ccda_import',
                'loinc_code' => self::HOSPITAL_COURSE,
                'source_type_label' => $title,
            ],
            'text' => $text,
            'relation' => null,
        ];
    }

    private function isHospitalCourse(DOMElement $section): bool
    {
        if ($this->xml->hasTemplate($section, [self::HOSPITAL_COURSE_SECTION])) {
            return true;
        }
        $code = $this->xml->child($section, 'code');
        if ($this->xml->attribute($code, 'code') === self::HOSPITAL_COURSE) {
            return true;
        }

        return strtolower(trim((string) $this->xml->child($section, 'title')?->textContent)) === 'hospital course';
    }

    /**
     * The note's specific LOINC code: a LOINC translation of the generic "Note"
     * code, else the code itself when it is LOINC.
     */
    private function loinc(?DOMElement $code): ?string
    {
        $own = $this->xml->attribute($code, 'codeSystem') === PhrClinicalNote::LOINC_OID ? $this->xml->attribute($code, 'code') : null;
        $translated = $this->xml->attribute($this->translation($code, PhrClinicalNote::LOINC_OID), 'code');
        if ($own === PhrClinicalNote::LOINC_NOTE && $translated !== null) {
            return PhrClinicalNote::loinc($translated);
        }

        return PhrClinicalNote::loinc($own ?? $translated);
    }

    private function documentType(?DOMElement $code, ?string $loinc): string
    {
        $own = $this->xml->attribute($this->translation($code, self::PHR_DOCUMENT_TYPE_OID), 'code');
        if (PhrDocumentType::isValid($own)) {
            return (string) $own;
        }

        return PhrClinicalNote::typeForLoinc($loinc) ?? 'other';
    }

    private function translation(?DOMElement $code, string $system): ?DOMElement
    {
        foreach ($this->xml->children($code, 'translation') as $translation) {
            if ($this->xml->attribute($translation, 'codeSystem') === $system) {
                return $translation;
            }
        }

        return null;
    }

    /** A display name from a vendor's own code system: the source's label for the type. */
    private function vendorDisplay(?DOMElement $code): ?string
    {
        foreach ([$code, ...$this->xml->children($code, 'translation')] as $candidate) {
            $system = $this->xml->attribute($candidate, 'codeSystem');
            if ($candidate !== null && $system !== PhrClinicalNote::LOINC_OID && $system !== self::PHR_DOCUMENT_TYPE_OID) {
                $display = $this->xml->attribute($candidate, 'displayName');
                if ($display !== null) {
                    return $display;
                }
            }
        }

        return null;
    }

    /**
     * @return array{relation: string, reference: string, target: string}|null
     */
    private function relation(DOMElement $act): ?array
    {
        foreach ($this->xml->children($act, 'entryRelationship') as $relationship) {
            $target = $this->xml->child($relationship, 'act');
            $code = $this->xml->child($target, 'code');
            $relation = $this->xml->attribute($code, 'codeSystem') === self::ACT_RELATIONSHIP_TYPE
                ? (self::RELATIONS[(string) $this->xml->attribute($code, 'code')] ?? null)
                : null;
            $id = $this->xml->child($target, 'id');
            $root = $this->xml->attribute($id, 'root');
            if ($target === null || $relation === null || $root === null) {
                continue;
            }
            $extension = $this->xml->attribute($id, 'extension') ?? '';

            return [
                'relation' => $relation,
                'reference' => $extension === '' ? $root : $root.'^'.$extension,
                'target' => $this->idPrefix().sha1(implode('|', ['id', $root, $extension])),
            ];
        }

        return null;
    }

    /**
     * The note's text with its lines kept: the narrative its `text` references,
     * else its own content. Paragraphs, line breaks, list items and table rows
     * each start a line; table cells are separated by " | ".
     */
    private function noteText(?DOMElement $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $reference = $this->xml->attribute($this->xml->child($text, 'reference'), 'value');
        $source = $reference !== null && str_starts_with($reference, '#') ? $this->xml->narrativeElement(substr($reference, 1)) : null;
        $lines = [''];
        $this->collect($source ?? $text, $lines, $source === null);
        $clean = [];
        foreach ($lines as $line) {
            $clean[] = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line);
        }
        $joined = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $clean)) ?? '');

        return $joined === '' ? null : $joined;
    }

    /** @param non-empty-array<int, string> $lines  the lines so far; text joins the last */
    private function collect(DOMNode $node, array &$lines, bool $skipReference): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $lines[count($lines) - 1] .= str_replace(["\r", "\n"], ' ', $child->data);

                continue;
            }
            if (! $child instanceof DOMElement || ($skipReference && $child->localName === 'reference')) {
                continue;
            }
            if ($child->localName === 'br') {
                $lines[] = '';

                continue;
            }
            $block = in_array($child->localName, self::BLOCKS, true);
            if ($block && $lines[count($lines) - 1] !== '') {
                $lines[] = '';
            }
            if ($child->localName === 'item') {
                $lines[count($lines) - 1] .= '- ';
            }
            if (in_array($child->localName, ['td', 'th'], true) && trim($lines[count($lines) - 1]) !== '') {
                $lines[count($lines) - 1] .= ' | ';
            }
            $this->collect($child, $lines, $skipReference);
            if ($block) {
                $lines[] = '';
            }
        }
    }
}
