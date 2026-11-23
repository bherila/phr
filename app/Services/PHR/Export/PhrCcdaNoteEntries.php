<?php

namespace App\Services\PHR\Export;

use App\Models\PhrDocument;
use App\Services\PHR\Documents\PhrDocumentTextReader;
use App\Services\PHR\Import\PhrCcdaNoteActivities;
use App\Support\PHR\PhrClinicalNote;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Throwable;
use XMLWriter;

/**
 * The C-CDA Notes section (phr#409): each exported clinical note as a Note
 * Activity (2.16.840.1.113883.10.20.22.4.202) whose text references its lines in
 * the section narrative.
 *
 * - code: LOINC 34109-9 "Note", with the note's own LOINC code (else its type's
 *   export default) and PHR's type as translations, and the source's label as
 *   original text.
 * - author: the recorded author, role and time written.
 * - participant typeCode="LA": the cosigner and time signed, or the author for
 *   a note they signed themselves. A note an agent wrote or an AI read has none:
 *   a person's approval is not a clinician's signature.
 * - statusCode from docStatus: preliminary active, entered-in-error nullified,
 *   anything else completed.
 * - entryRelationship REFR: the note this one appends to (APND) or replaces
 *   (RPLC), only when the export carries it, by that note's id.
 */
final class PhrCcdaNoteEntries
{
    private const string ID_NAMESPACE = 'b5b5f2b2-2c75-5d0c-9c34-0f0a6c2f3e41';

    private readonly string $idNamespace;

    public function __construct(private readonly PhrDocumentTextReader $texts, string $deploymentKey = '')
    {
        $this->idNamespace = $deploymentKey === ''
            ? self::ID_NAMESPACE
            : Uuid::uuid5(self::ID_NAMESPACE, hash_hmac('sha256', 'phr-ccda-note-ids', $deploymentKey))->toString();
    }

    /**
     * @param  iterable<PhrDocument>  $documents  the export's documents
     */
    public function write(XMLWriter $xml, iterable $documents): void
    {
        $notes = [];
        $exported = [];
        foreach ($documents as $document) {
            if (PhrClinicalNote::isNote($document)) {
                $notes[] = $document;
                $exported[(int) $document->id] = true;
            }
        }
        if ($notes === []) {
            return;
        }

        $xml->startElement('component');
        $xml->startElement('section');
        $this->template($xml, PhrCcdaNoteActivities::NOTES_SECTION);
        $this->loincCode($xml, 'code', PhrClinicalNote::LOINC_NOTE, 'Note');
        $xml->endElement();
        $xml->writeElement('title', 'Notes');
        $xml->startElement('text');
        $texts = [];
        foreach ($notes as $index => $note) {
            $texts[$index] = $this->text($note);
            $xml->startElement('paragraph');
            $xml->writeElement('caption', $this->heading($note));
            $xml->endElement();
            $xml->startElement('paragraph');
            $xml->writeAttribute('ID', 'note-'.($index + 1));
            foreach (preg_split('/\r\n|\r|\n/', $texts[$index]) ?: [] as $position => $line) {
                if ($position > 0) {
                    $xml->writeElement('br');
                }
                $xml->text($line);
            }
            $xml->endElement();
        }
        $xml->endElement();
        foreach ($notes as $index => $note) {
            $this->entry($xml, $note, $index, $exported);
        }
        $xml->endElement();
        $xml->endElement();
    }

    /** @param array<int, true> $exported */
    private function entry(XMLWriter $xml, PhrDocument $note, int $index, array $exported): void
    {
        $xml->startElement('entry');
        $xml->startElement('act');
        $xml->writeAttribute('classCode', 'ACT');
        $xml->writeAttribute('moodCode', 'EVN');
        $this->template($xml, PhrCcdaNoteActivities::NOTE_ACTIVITY);
        $this->id($xml, (int) $note->patient_id, (int) $note->id);

        $this->loincCode($xml, 'code', PhrClinicalNote::LOINC_NOTE, 'Note');
        if ($note->source_type_label !== null) {
            $xml->writeElement('originalText', $note->source_type_label);
        }
        $loinc = PhrClinicalNote::exportLoinc($note);
        if ($loinc !== null) {
            $xml->startElement('translation');
            $xml->writeAttribute('code', $loinc);
            $xml->writeAttribute('codeSystem', PhrClinicalNote::LOINC_OID);
            $xml->writeAttribute('codeSystemName', 'LOINC');
            $xml->endElement();
        }
        $xml->startElement('translation');
        $xml->writeAttribute('code', $note->document_type);
        $xml->writeAttribute('codeSystem', PhrCcdaNoteActivities::PHR_DOCUMENT_TYPE_OID);
        $xml->writeAttribute('codeSystemName', 'PHR document type');
        $xml->endElement();
        $xml->endElement();

        $xml->startElement('text');
        $xml->startElement('reference');
        $xml->writeAttribute('value', '#note-'.($index + 1));
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('statusCode');
        $xml->writeAttribute('code', match ($note->doc_status) {
            'preliminary' => 'active',
            'entered-in-error' => 'nullified',
            default => 'completed',
        });
        $xml->endElement();
        $this->time($xml, 'effectiveTime', $note->observed_at ?? $note->authored_at);

        if ($note->author_name !== null || $note->authored_at !== null) {
            $xml->startElement('author');
            $this->time($xml, 'time', $note->authored_at);
            $xml->startElement('assignedAuthor');
            $this->nullFlavor($xml, 'id', 'NI');
            if ($note->author_role !== null) {
                $xml->startElement('code');
                $xml->writeAttribute('nullFlavor', 'OTH');
                $xml->writeElement('originalText', $note->author_role);
                $xml->endElement();
            }
            if ($note->author_name !== null) {
                $xml->startElement('assignedPerson');
                $xml->writeElement('name', $note->author_name);
                $xml->endElement();
            }
            $xml->endElement();
            $xml->endElement();
        }

        $signer = $note->cosigner_name ?? ($note->signed_at !== null ? $note->author_name : null);
        if ($signer !== null && ! PhrClinicalNote::isAgentAuthored($note)) {
            $xml->startElement('participant');
            $xml->writeAttribute('typeCode', 'LA');
            $this->time($xml, 'time', $note->signed_at);
            $xml->startElement('participantRole');
            $xml->startElement('playingEntity');
            $xml->writeElement('name', $signer);
            $xml->endElement();
            $xml->endElement();
            $xml->endElement();
        }

        $relation = $note->relatesTo;
        if ($relation !== null && $relation->related_document_id !== null && isset($exported[$relation->related_document_id])) {
            $xml->startElement('entryRelationship');
            $xml->writeAttribute('typeCode', 'REFR');
            $xml->startElement('act');
            $xml->writeAttribute('classCode', 'ACT');
            $xml->writeAttribute('moodCode', 'EVN');
            $this->id($xml, (int) $note->patient_id, $relation->related_document_id);
            $xml->startElement('code');
            $xml->writeAttribute('code', $relation->relation === PhrClinicalNote::REPLACES ? 'RPLC' : 'APND');
            $xml->writeAttribute('codeSystem', PhrCcdaNoteActivities::ACT_RELATIONSHIP_TYPE);
            $xml->writeAttribute('codeSystemName', 'HL7ActRelationshipType');
            $xml->writeAttribute('displayName', $relation->relation);
            $xml->endElement();
            $xml->endElement();
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endElement();
    }

    /** The note's text, or its title when it has none PHR can read. */
    private function text(PhrDocument $note): string
    {
        try {
            $text = $this->texts->read($note)['text'] ?? null;
        } catch (Throwable) {
            $text = null;
        }

        return is_string($text) && trim($text) !== '' ? $text : ($note->title ?? $note->original_filename ?? 'Clinical note');
    }

    private function heading(PhrDocument $note): string
    {
        $parts = array_filter([
            $note->title ?? $note->source_type_label ?? 'Clinical note',
            $note->author_name,
            $note->signed_at?->toDateTimeString() ?? $note->authored_at?->toDateTimeString(),
        ]);

        return implode(' - ', $parts);
    }

    /** A stable id, derived from the note's identity and never its database key alone. */
    private function id(XMLWriter $xml, int $patientId, int $documentId): void
    {
        $xml->startElement('id');
        $xml->writeAttribute('root', Uuid::uuid5($this->idNamespace, "note:{$patientId}:{$documentId}")->toString());
        $xml->endElement();
    }

    /** Opens the code element; the caller ends it. */
    private function loincCode(XMLWriter $xml, string $element, string $code, string $display): void
    {
        $xml->startElement($element);
        $xml->writeAttribute('code', $code);
        $xml->writeAttribute('codeSystem', PhrClinicalNote::LOINC_OID);
        $xml->writeAttribute('codeSystemName', 'LOINC');
        $xml->writeAttribute('displayName', $display);
    }

    private function time(XMLWriter $xml, string $element, ?DateTimeInterface $moment): void
    {
        $xml->startElement($element);
        if ($moment === null) {
            $xml->writeAttribute('nullFlavor', 'UNK');
        } else {
            $xml->writeAttribute('value', Carbon::instance($moment)->utc()->format('YmdHisO'));
        }
        $xml->endElement();
    }

    private function template(XMLWriter $xml, string $root): void
    {
        $xml->startElement('templateId');
        $xml->writeAttribute('root', $root);
        $xml->writeAttribute('extension', '2016-11-01');
        $xml->endElement();
    }

    private function nullFlavor(XMLWriter $xml, string $element, string $nullFlavor): void
    {
        $xml->startElement($element);
        $xml->writeAttribute('nullFlavor', $nullFlavor);
        $xml->endElement();
    }
}
