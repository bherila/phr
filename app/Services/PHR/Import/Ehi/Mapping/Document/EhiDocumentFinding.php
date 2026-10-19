<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Document;

use App\Models\PhrEhiFinding;

/**
 * Finding codes the document mapping raises through EhiMappingContext::finding().
 * Details are structural (table, column, reason, counts), never source values.
 */
final class EhiDocumentFinding
{
    /** More than one revision for one entity in this package; the entity is retained. */
    public const string AMBIGUOUS_REVISION = 'document_ambiguous_revision';

    /** No documented patient link resolved to the bound source patient. */
    public const string ORPHAN = 'document_orphan';

    /** A documented patient link names a different source patient. */
    public const string FOREIGN_PATIENT = 'document_foreign_patient';

    /** The note's encounter (PAT_ENC_CSN_ID) is not in this package. */
    public const string ENCOUNTER_MISSING = 'document_encounter_missing';

    /** Text rows exist but cannot be ordered (no matching contact, or a non-integer LINE). */
    public const string TEXT_UNORDERED = 'document_text_unordered';

    /** A documented file reference matched no member, or more than one. */
    public const string FILE_REFERENCE_UNRESOLVED = 'document_file_reference_unresolved';

    /** More than one documented file resolved for one note; none is linked. */
    public const string MULTIPLE_FILES = 'document_multiple_files';

    /** A linked member's stored bytes are missing or differ from the manifest. */
    public const string MEMBER_UNVERIFIED = 'document_member_unverified';

    /** A linked RTF member could not be converted (not RTF, or over a limit). */
    public const string RTF_UNSUPPORTED = 'document_rtf_unsupported';

    /** A date/time column was present but not in a recognized format. */
    public const string UNPARSED_DATETIME = 'document_unparsed_datetime';

    /** The record previously linked (or holding this identity) was deleted or retracted. */
    public const string TARGET_WITHDRAWN = 'document_target_withdrawn';

    /** Note/media members that no documented reference links to; detail.kind and detail.count. */
    public const string UNLINKED_NOTE_MEMBER = 'unlinked_note_member';

    public const array SEVERITY = [
        self::AMBIGUOUS_REVISION => PhrEhiFinding::SEVERITY_WARNING,
        self::ORPHAN => PhrEhiFinding::SEVERITY_WARNING,
        self::FOREIGN_PATIENT => PhrEhiFinding::SEVERITY_WARNING,
        self::ENCOUNTER_MISSING => PhrEhiFinding::SEVERITY_INFO,
        self::TEXT_UNORDERED => PhrEhiFinding::SEVERITY_WARNING,
        self::FILE_REFERENCE_UNRESOLVED => PhrEhiFinding::SEVERITY_INFO,
        self::MULTIPLE_FILES => PhrEhiFinding::SEVERITY_WARNING,
        self::MEMBER_UNVERIFIED => PhrEhiFinding::SEVERITY_ERROR,
        self::RTF_UNSUPPORTED => PhrEhiFinding::SEVERITY_WARNING,
        self::UNPARSED_DATETIME => PhrEhiFinding::SEVERITY_INFO,
        self::TARGET_WITHDRAWN => PhrEhiFinding::SEVERITY_INFO,
        self::UNLINKED_NOTE_MEMBER => PhrEhiFinding::SEVERITY_INFO,
    ];
}
