<?php

namespace App\Support\PHR\Validation;

use App\Support\PHR\PhrClinicalNote;
use Illuminate\Validation\Rule;

/**
 * Validation for a document's clinical note metadata (phr#409), shared by the
 * browser's metadata edit, documents.update and documents.create_text, and the
 * JSON Schema MCP shows for the same fields.
 */
final class DocumentNoteRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'loinc_code' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:'.PhrClinicalNote::LOINC_PATTERN],
            'source_type_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'author_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'author_role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cosigner_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'authored_at' => ['sometimes', 'nullable', 'date'],
            'signed_at' => ['sometimes', 'nullable', 'date'],
            'doc_status' => ['sometimes', 'nullable', 'string', Rule::in(PhrClinicalNote::DOC_STATUSES)],
            'patient_signature_status' => ['sometimes', 'nullable', 'string', Rule::in(PhrClinicalNote::PATIENT_SIGNATURE_STATUSES)],
            'patient_signed_at' => ['sometimes', 'nullable', 'date'],
            // null removes the link; anything else is a whole link. `{}` and `[]` are
            // empty, which required_with does not see as given (phr#430).
            'relates_to' => ['sometimes', 'nullable', 'array:document_id,relation', 'required_array_keys:document_id,relation'],
            'relates_to.document_id' => ['required_with:relates_to', 'integer', 'min:1'],
            'relates_to.relation' => ['required_with:relates_to', 'string', Rule::in(PhrClinicalNote::RELATIONS)],
        ];
    }

    /**
     * The same fields as JSON Schema properties.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function jsonSchema(): array
    {
        $text = static fn (int $max, string $description): array => ['type' => ['string', 'null'], 'maxLength' => $max, 'description' => $description];
        $moment = static fn (string $description): array => ['type' => ['string', 'null'], 'description' => $description];

        return [
            'loinc_code' => ['type' => ['string', 'null'], 'pattern' => '^\d{1,8}-\d$', 'description' => 'The LOINC document code the source gives, kept verbatim (a plan-of-care note stays 18776-5). Null lets an export use the type\'s default code.'],
            'source_type_label' => $text(255, 'The source\'s own name for the note type, e.g. "Daily Progress Note".'),
            'author_name' => $text(255, 'Who wrote the note, as the source names them, e.g. "Jordan Fellow, MD".'),
            'author_role' => $text(255, 'The author\'s role or credentials as the source states them.'),
            'cosigner_name' => $text(255, 'Who cosigned (authenticated) the note, as the source names them.'),
            'authored_at' => $moment('When the note was written: an ISO 8601 date or datetime.'),
            'signed_at' => $moment('When the note was signed: an ISO 8601 date or datetime.'),
            'doc_status' => ['type' => ['string', 'null'], 'enum' => [...PhrClinicalNote::DOC_STATUSES, null], 'description' => 'FHIR docStatus of the note as the source states it.'],
            'patient_signature_status' => ['type' => ['string', 'null'], 'enum' => [...PhrClinicalNote::PATIENT_SIGNATURE_STATUSES, null], 'description' => 'For a consent or estimate: whether it awaits the patient\'s signature. Separate from doc_status.'],
            'patient_signed_at' => $moment('When the patient signed: an ISO 8601 date or datetime.'),
            'relates_to' => [
                'type' => ['object', 'null'],
                'additionalProperties' => false,
                'required' => ['document_id', 'relation'],
                'properties' => [
                    'document_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Another document of this patient.'],
                    'relation' => ['type' => 'string', 'enum' => PhrClinicalNote::RELATIONS, 'description' => 'appends for an addendum, replaces for an amendment.'],
                ],
                'description' => 'The document this one is an addendum to (appends) or replaces. Null removes the link.',
            ],
        ];
    }
}
