<?php

namespace App\Support\PHR;

use App\Models\PhrDocument;
use Illuminate\Support\Carbon;

/**
 * Clinical note vocabulary for documents (phr#409).
 *
 * A document keeps the source's LOINC code verbatim in `loinc_code`: a plan-of-care
 * note stays 18776-5 even when PHR files it as a nursing note. EXPORT_LOINC is only
 * what an export sends for a type when the document carries no code of its own.
 * Types not listed have no export default: a code PHR could not confirm against
 * LOINC is never sent.
 */
final class PhrClinicalNote
{
    public const string LOINC_SYSTEM = 'http://loinc.org';

    public const string LOINC_OID = '2.16.840.1.113883.6.1';

    /** LOINC's generic "Note" code, the C-CDA Note Activity's own code. */
    public const string LOINC_NOTE = '34109-9';

    /** A LOINC code as LOINC writes one: digits, a hyphen and a check digit. */
    public const string LOINC_PATTERN = '/\A\d{1,8}-\d\z/';

    /**
     * The export-default LOINC document code per type.
     *
     * @var array<string, string>
     */
    public const array EXPORT_LOINC = [
        'progress_note' => '11506-3',
        'consult_note' => '11488-4',
        'history_and_physical' => '34117-2',
        'procedure_note' => '28570-0',
        'nursing_note' => '34746-8',
        'discharge_summary' => '18842-5',
    ];

    /**
     * The types exported as clinical notes: US Core `clinical-note` category in FHIR,
     * Note Activities in the C-CDA Notes section.
     *
     * @var list<string>
     */
    public const array NOTE_TYPES = [
        'progress_note',
        'consult_note',
        'history_and_physical',
        'procedure_note',
        'nursing_note',
        'discharge_summary',
        'office_visit_note',
    ];

    /** FHIR DocumentReference.docStatus values. */
    public const array DOC_STATUSES = ['preliminary', 'final', 'amended', 'entered-in-error'];

    /**
     * Whether a consent or estimate awaits the patient's signature. Deliberately
     * separate from doc_status, which is the author's.
     */
    public const array PATIENT_SIGNATURE_STATUSES = ['to_sign', 'signed', 'not_applicable'];

    public const string APPENDS = 'appends';

    public const string REPLACES = 'replaces';

    /** FHIR relatesTo codes PHR keeps: an addendum appends, an amendment replaces. */
    public const array RELATIONS = [self::APPENDS, self::REPLACES];

    /**
     * Sources whose text and metadata an agent or an AI read wrote. A person's
     * approval admits such a document to exports, but never makes it
     * clinician-authenticated.
     */
    public const array AGENT_SOURCES = ['agent_upload', 'genai_import'];

    /** The metadata columns, all nullable, that a document update may set. */
    public const array COLUMNS = [
        'loinc_code',
        'source_type_label',
        'author_name',
        'author_role',
        'cosigner_name',
        'authored_at',
        'signed_at',
        'doc_status',
        'patient_signature_status',
        'patient_signed_at',
    ];

    /** The note columns that hold a moment. */
    public const array MOMENTS = ['authored_at', 'signed_at', 'patient_signed_at'];

    /**
     * Note metadata from a validated request, with each moment as the instant it
     * names in the application's time zone (a stated offset is honoured).
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function normalizedChanges(array $changes): array
    {
        foreach (self::MOMENTS as $column) {
            if (array_key_exists($column, $changes)) {
                $changes[$column] = self::moment($changes[$column]);
            }
        }

        return $changes;
    }

    public static function isLoinc(mixed $code): bool
    {
        return is_string($code) && preg_match(self::LOINC_PATTERN, $code) === 1;
    }

    /** A code read from outside, kept verbatim when it is LOINC-shaped. */
    public static function loinc(mixed $code): ?string
    {
        $code = is_string($code) ? trim($code) : null;

        return self::isLoinc($code) ? $code : null;
    }

    /** The type a LOINC code names, where it is one of the export defaults. */
    public static function typeForLoinc(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $type = array_search($code, self::EXPORT_LOINC, true);

        return is_string($type) ? $type : null;
    }

    /** The code an export sends: the document's own, else its type's default. */
    public static function exportLoinc(PhrDocument $document): ?string
    {
        return $document->loinc_code ?? self::EXPORT_LOINC[$document->document_type] ?? null;
    }

    public static function isNote(PhrDocument $document): bool
    {
        return in_array($document->document_type, self::NOTE_TYPES, true);
    }

    public static function isAgentAuthored(PhrDocument $document): bool
    {
        return in_array($document->source, self::AGENT_SOURCES, true);
    }

    /**
     * A moment read from outside, as the application stores datetimes: the
     * instant it names, in the application's time zone. A date alone is that
     * day's midnight.
     */
    public static function moment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse(trim($value))->setTimezone((string) config('app.timezone', 'UTC'))->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function docStatus(mixed $status): ?string
    {
        return is_string($status) && in_array($status, self::DOC_STATUSES, true) ? $status : null;
    }

    public static function relation(mixed $relation): ?string
    {
        return is_string($relation) && in_array($relation, self::RELATIONS, true) ? $relation : null;
    }
}
