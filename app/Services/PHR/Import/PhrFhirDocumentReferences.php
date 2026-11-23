<?php

namespace App\Services\PHR\Import;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Services\PHR\Documents\PhrDocumentRelations;
use App\Support\PHR\PhrClinicalNote;
use App\Support\PHR\PhrDocumentType;
use App\Support\PHR\PhrDocumentUploadLimits;
use App\Support\PHR\PhrReviewStatus;
use finfo;

/**
 * FHIR R4 DocumentReference to a PHR document (phr#409).
 *
 * - type: the LOINC coding is kept verbatim in `loinc_code`; the document type is
 *   PHR's own coding when the reference carries one (a PHR export), else the type
 *   the LOINC code is the export default of, else the type text as before. The
 *   source's label is `type.text`, or a non-LOINC coding's display.
 * - author (the first) gives author_name and, through a PractitionerRole,
 *   author_role; authenticator (0..1) is the cosigner. Either may be a display,
 *   a contained resource or a bundle entry.
 * - attachment.creation is when the note was written and `date` when it was
 *   signed; with no creation, `date` is the signing time of a final or amended
 *   note, or one with an authenticator, and the writing time otherwise.
 * - docStatus as stated; relatesTo (appends, replaces) links the document to the
 *   one it names, resolved by its import identity once both are read. A target
 *   PHR does not hold is kept as the reference, never guessed.
 * - attachment.data, the first content that carries any, is stored as the file
 *   through Storage when it is an accepted document type, at most
 *   MAX_INLINE_BYTES, and matches its stated hash. Otherwise the document keeps
 *   its metadata only, as before.
 */
final class PhrFhirDocumentReferences
{
    public const int MAX_INLINE_BYTES = 10 * 1024 * 1024;

    public const string PHR_TYPE_SYSTEM = 'urn:phr:document-type';

    public const string US_CORE_CATEGORY_SYSTEM = 'http://hl7.org/fhir/us/core/CodeSystem/us-core-documentreference-category';

    private const string IMPORT_SOURCE = 'fhir';

    /** @var list<array{document: string, relation: string, reference: string, target: string|null}> */
    private array $pendingRelations = [];

    public function __construct(
        private PhrStructuredDataImporter $structuredImporter,
        private PhrDocumentRelations $relations,
    ) {}

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, array<string, mixed>>  $index  the bundle's resources, by `Type/id` and fullUrl
     */
    public function import(array $resource, array $index, PhrPatient $patient, int $actorUserId, ?string $externalId): PhrImportResult
    {
        $type = is_array($resource['type'] ?? null) ? $resource['type'] : [];
        $loinc = PhrClinicalNote::loinc($this->codeForSystem($type, PhrClinicalNote::LOINC_SYSTEM));
        $attachment = is_array(data_get($resource, 'content.0.attachment')) ? data_get($resource, 'content.0.attachment') : [];
        [$authorName, $authorRole] = $this->person($resource['author'][0] ?? null, $resource, $index);
        [$cosigner] = $this->person($resource['authenticator'] ?? null, $resource, $index);
        $docStatus = PhrClinicalNote::docStatus($resource['docStatus'] ?? null);
        $creation = $this->string(data_get($resource, 'content.0.attachment.creation'));
        $date = $this->string($resource['date'] ?? null);
        $signedByDate = $creation !== null || in_array($docStatus, ['final', 'amended'], true) || $cosigner !== null;
        $authoredAt = $creation ?? ($signedByDate ? null : $date);
        $signedAt = $signedByDate ? $date : null;

        $payload = [
            'external_id' => $externalId,
            'title' => $resource['description'] ?? $attachment['title'] ?? null,
            'document_type' => $this->documentType($type, $loinc),
            'observed_at' => data_get($resource, 'context.period.start') ?? $authoredAt ?? $date,
            'original_filename' => $attachment['title'] ?? null,
            'mime_type' => $attachment['contentType'] ?? null,
            'summary' => $resource['description'] ?? null,
            'source' => 'fhir_import',
            'loinc_code' => $loinc,
            'source_type_label' => $this->label($type),
            'author_name' => $authorName,
            'author_role' => $authorRole,
            'cosigner_name' => $cosigner,
            'authored_at' => $authoredAt,
            'signed_at' => $signedAt,
            'doc_status' => $docStatus,
        ];
        $options = ['import_source' => self::IMPORT_SOURCE];
        $content = $this->inlineContent($resource, $externalId);
        if ($content !== null) {
            $options['content'] = $content;
        }

        $result = $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_document', $payload, $options);

        $relatesTo = is_array($resource['relatesTo'] ?? null) ? $resource['relatesTo'] : [];
        foreach ($relatesTo as $relation) {
            $code = is_array($relation) ? PhrClinicalNote::relation($relation['code'] ?? null) : null;
            $reference = is_array($relation) ? $this->string(data_get($relation, 'target.reference')) : null;
            if ($externalId !== null && $code !== null && $reference !== null) {
                // One relation per document: the first PHR keeps.
                $this->pendingRelations[] = ['document' => $externalId, 'relation' => $code, 'reference' => $reference, 'target' => $this->referencedExternalId($reference, $index)];

                break;
            }
        }

        return $result;
    }

    /**
     * Link the documents read since the last call to what their relatesTo names,
     * then resolve kept references a document read now answers.
     *
     * @param  array<string, array<string, mixed>>  $index
     */
    public function linkRelations(PhrPatient $patient, array $index): void
    {
        $pending = $this->pendingRelations;
        $this->pendingRelations = [];
        foreach ($pending as $relation) {
            $document = $this->document($patient, $relation['document']);
            if (! $document instanceof PhrDocument) {
                continue;
            }
            $target = $relation['target'] === null ? null : $this->document($patient, $relation['target']);
            $changed = $this->relations->setFromSource($document, $relation['relation'], $relation['reference'], $target?->id);
            if ($changed) {
                // What the source says the document amends is part of it: a change is
                // reviewed, and moves the document for a client syncing on updated_at.
                if ($document->review_status === PhrReviewStatus::PENDING) {
                    $document->touch();
                } else {
                    $document->update(['review_status' => PhrReviewStatus::PENDING]);
                }
            }
        }
        $this->relations->resolvePending((int) $patient->id, self::IMPORT_SOURCE, fn (string $reference): ?string => $this->referencedExternalId($reference, $index));
    }

    private function document(PhrPatient $patient, string $externalId): ?PhrDocument
    {
        return PhrDocument::query()->where('patient_id', $patient->id)->where('import_source', self::IMPORT_SOURCE)->where('external_id', $externalId)->first();
    }

    /**
     * The import identity a reference to a DocumentReference names, derived as
     * PhrFhirImporter::externalId() derived it when it imported the target: its
     * `DocumentReference/{id}`, else, for a target with no id, the identity its
     * entry's fullUrl gave it (phr#430).
     *
     * - A bundle entry the reference names, by `Type/id` or by fullUrl. Found by
     *   fullUrl, the reference is that fullUrl.
     * - Otherwise a relative or RESTful `DocumentReference/{id}`, or any other
     *   absolute reference (a URN), which is the fullUrl of the entry it names:
     *   a document read earlier without an id is found by it.
     *
     * @param  array<string, array<string, mixed>>  $index
     */
    private function referencedExternalId(string $reference, array $index): ?string
    {
        $resource = $index[$reference] ?? null;
        if (is_array($resource)) {
            return ($resource['resourceType'] ?? null) === 'DocumentReference' ? PhrFhirImporter::externalId($resource, $reference) : null;
        }
        if (preg_match('~(?:^|/)DocumentReference/([A-Za-z0-9\-.]{1,64})(?:/_history/[^/]+)?$~', $reference, $matches) === 1) {
            return PhrFhirImporter::externalId(['resourceType' => 'DocumentReference', 'id' => $matches[1]]);
        }
        if (preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*:~', $reference) === 1) {
            return PhrFhirImporter::externalId(['resourceType' => 'DocumentReference'], $reference);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $type
     */
    private function documentType(array $type, ?string $loinc): string
    {
        $own = $this->codeForSystem($type, self::PHR_TYPE_SYSTEM);
        if (PhrDocumentType::isValid($own)) {
            return (string) $own;
        }
        $fromLoinc = PhrClinicalNote::typeForLoinc($loinc);
        if ($fromLoinc !== null) {
            return $fromLoinc;
        }

        return PhrDocumentType::normalize($this->codeableText($type) ?? PhrDocumentType::FALLBACK);
    }

    /**
     * The source's own name for the type: its text, unless that is a PHR type
     * value (an earlier PHR export's), else the display of a coding that is
     * neither LOINC's nor PHR's.
     *
     * @param  array<string, mixed>  $type
     */
    private function label(array $type): ?string
    {
        $text = $this->string($type['text'] ?? null);
        if ($text !== null && ! PhrDocumentType::isValid($text)) {
            return mb_substr($text, 0, 255);
        }
        foreach (is_array($type['coding'] ?? null) ? $type['coding'] : [] as $coding) {
            $system = is_array($coding) ? (string) ($coding['system'] ?? '') : '';
            $display = is_array($coding) ? $this->string($coding['display'] ?? null) : null;
            if ($display !== null && $system !== PhrClinicalNote::LOINC_SYSTEM && $system !== self::PHR_TYPE_SYSTEM) {
                return mb_substr($display, 0, 255);
            }
        }

        return null;
    }

    /**
     * A person a Reference names, and their role when it is a PractitionerRole:
     * a contained resource, a bundle entry, or the reference's display.
     *
     * @param  array<string, mixed>  $resource
     * @param  array<string, array<string, mixed>>  $index
     * @return array{0: string|null, 1: string|null}
     */
    private function person(mixed $reference, array $resource, array $index): array
    {
        if (! is_array($reference)) {
            return [null, null];
        }
        $display = $this->string($reference['display'] ?? null);
        $target = $this->resolve($this->string($reference['reference'] ?? null), $resource, $index);
        if ($target === null) {
            return [$this->bounded($display), null];
        }
        $role = null;
        if (($target['resourceType'] ?? null) === 'PractitionerRole') {
            $role = $this->codeableText($target['code'][0] ?? null) ?? $this->codeableText($target['specialty'][0] ?? null);
            $practitioner = is_array($target['practitioner'] ?? null) ? $target['practitioner'] : [];
            $resolved = $this->resolve($this->string($practitioner['reference'] ?? null), $resource, $index);
            $name = ($resolved === null ? null : $this->humanName($resolved)) ?? $this->string($practitioner['display'] ?? null) ?? $display;

            return [$this->bounded($name), $this->bounded($role)];
        }

        return [$this->bounded($this->humanName($target) ?? $display), null];
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, array<string, mixed>>  $index
     * @return array<string, mixed>|null
     */
    private function resolve(?string $reference, array $resource, array $index): ?array
    {
        if ($reference === null) {
            return null;
        }
        if (str_starts_with($reference, '#')) {
            foreach (is_array($resource['contained'] ?? null) ? $resource['contained'] : [] as $contained) {
                if (is_array($contained) && ($contained['id'] ?? null) === substr($reference, 1)) {
                    return $contained;
                }
            }

            return null;
        }

        return is_array($index[$reference] ?? null) ? $index[$reference] : null;
    }

    /** @param array<string, mixed> $resource */
    private function humanName(array $resource): ?string
    {
        $name = is_array($resource['name'][0] ?? null) ? $resource['name'][0] : (is_array($resource['name'] ?? null) && isset($resource['name']['text']) ? $resource['name'] : null);
        if ($name === null) {
            return $this->string($resource['name'] ?? null);
        }
        $text = $this->string($name['text'] ?? null);
        if ($text !== null) {
            return $text;
        }
        $parts = [];
        foreach (['prefix', 'given'] as $field) {
            foreach (is_array($name[$field] ?? null) ? $name[$field] : [] as $part) {
                $parts[] = $this->string($part);
            }
        }
        $parts[] = $this->string($name['family'] ?? null);
        $joined = trim(implode(' ', array_filter($parts)));
        $suffixes = array_filter(array_map($this->string(...), is_array($name['suffix'] ?? null) ? $name['suffix'] : []));
        if ($suffixes !== [] && $joined !== '') {
            $joined .= ', '.implode(', ', $suffixes);
        }

        return $joined === '' ? null : $joined;
    }

    /**
     * The first attachment's inline bytes, when they are a document PHR accepts.
     *
     * @param  array<string, mixed>  $resource
     * @return array{bytes: string, filename: string, mime_type: string, text: string|null}|null
     */
    private function inlineContent(array $resource, ?string $externalId): ?array
    {
        foreach (is_array($resource['content'] ?? null) ? $resource['content'] : [] as $content) {
            $attachment = is_array($content) && is_array($content['attachment'] ?? null) ? $content['attachment'] : null;
            $data = $attachment === null ? null : ($attachment['data'] ?? null);
            if (! is_string($data) || $data === '') {
                continue;
            }
            // Base64 is 4 characters for every 3 bytes: refuse before decoding.
            if (strlen($data) > intdiv(self::MAX_INLINE_BYTES, 3) * 4 + 4) {
                return null;
            }
            $bytes = base64_decode($data, true);
            if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_INLINE_BYTES) {
                return null;
            }
            $hash = $this->string($attachment['hash'] ?? null);
            if ($hash !== null && ! hash_equals($hash, base64_encode(sha1($bytes, true)))) {
                // What arrived is not what the source says it sent.
                return null;
            }
            $declared = strtolower(trim(explode(';', (string) ($attachment['contentType'] ?? ''), 2)[0]));
            $sniffed = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $filename = $this->filename($attachment, $declared, $externalId);
            $mime = PhrDocumentUploadLimits::storedMimeType(is_string($sniffed) ? $sniffed : null, $filename);
            if ($mime === null || ! array_key_exists($mime, PhrDocumentUploadLimits::CONTENT_TYPES)) {
                return null;
            }
            $text = in_array($mime, ['text/plain', PhrDocumentUploadLimits::MARKDOWN], true) && mb_check_encoding($bytes, 'UTF-8') ? $bytes : null;

            return ['bytes' => $bytes, 'filename' => $filename, 'mime_type' => $mime, 'text' => $text];
        }

        return null;
    }

    /**
     * A file name for the bytes: the attachment's title when it names a file,
     * with an extension for the declared type when it has none.
     *
     * @param  array<string, mixed>  $attachment
     */
    private function filename(array $attachment, string $declared, ?string $externalId): string
    {
        $title = $this->string($attachment['title'] ?? null);
        $base = $title ?? str_replace('/', '-', $externalId ?? 'fhir-document');
        $extensions = PhrDocumentUploadLimits::CONTENT_TYPES[$declared] ?? null;
        $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if ($extensions !== null && ! in_array($extension, $extensions, true)) {
            $base .= '.'.$extensions[0];
        }

        return mb_substr(basename($base), 0, 255);
    }

    private function codeableText(mixed $concept): ?string
    {
        if (! is_array($concept)) {
            return is_scalar($concept) ? $this->string((string) $concept) : null;
        }

        return $this->string($concept['text'] ?? $concept['display'] ?? data_get($concept, 'coding.0.display') ?? data_get($concept, 'coding.0.code'));
    }

    /** @param array<string, mixed> $concept */
    private function codeForSystem(array $concept, string $system): ?string
    {
        foreach (is_array($concept['coding'] ?? null) ? $concept['coding'] : [] as $coding) {
            if (is_array($coding) && ($coding['system'] ?? null) === $system && is_scalar($coding['code'] ?? null)) {
                return trim((string) $coding['code']);
            }
        }

        return null;
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function bounded(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, 255);
    }
}
