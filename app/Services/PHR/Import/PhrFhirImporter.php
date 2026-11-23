<?php

namespace App\Services\PHR\Import;

use App\Models\PhrDocument;
use App\Models\PhrMedication;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Services\PHR\Documents\PhrDocumentRelations;
use App\Services\PHR\Encounters\PhrEncounterLinks;
use App\Support\PHR\PhrEncounterVocabulary;
use App\Support\PHR\PhrMedicationCategory;
use App\Support\PHR\PhrReviewStatus;
use App\Support\PHR\PhrVisitParticipants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

class PhrFhirImporter
{
    /** The record source for rows read from a stored document, a PhrDocument::SOURCES value. */
    private const string RECORD_SOURCE = 'fhir_import';

    /** phr_* external_id column width. */
    private const int MAX_EXTERNAL_ID_BYTES = 255;

    /** @var array<int, array<string, mixed>> the bundle being read, by entry position */
    private array $resources = [];

    /** @var array<string, int> fullUrl, as written and without a `_history` version => entry position */
    private array $fullUrls = [];

    /** @var array<string, int> `Type/id` => entry position, for relative references */
    private array $typeIds = [];

    /** @var array<int, string> entry position => its fullUrl */
    private array $entryUrls = [];

    /**
     * Home medications sent both as a MedicationStatement and a MedicationRequest.
     *
     * @var array{request_of: array<int, int>, statement_of: array<int, int>, flagged: array<int, int>}
     */
    private array $medicationPairs = ['request_of' => [], 'statement_of' => [], 'flagged' => []];

    /** FHIR MedicationStatement.status and MedicationRequest.status, as the app's medication statuses. */
    private const array MEDICATION_STATUSES = [
        'active' => 'active',
        'intended' => 'active',
        'completed' => 'completed',
        'stopped' => 'discontinued',
        'cancelled' => 'discontinued',
        'not-taken' => 'discontinued',
        'on-hold' => 'on_hold',
        'draft' => 'on_hold',
    ];

    private PhrFhirDocumentReferences $documentReferences;

    public function __construct(
        private PhrStructuredDataImporter $structuredImporter,
        private PhrEncounterLinks $encounterLinks,
        PhrDocumentRelations $relations,
    ) {
        // The same importer, so collectingWritten() sees the documents a DocumentReference
        // becomes and linkEncounters() can put them on their encounter (#411).
        $this->documentReferences = new PhrFhirDocumentReferences($structuredImporter, $relations);
    }

    public function importFile(string $path, PhrPatient $patient, int $actorUserId): PhrImportResult
    {
        if (! File::isReadable($path)) {
            throw new InvalidArgumentException("FHIR file is not readable: {$path}");
        }

        $contents = File::get($path);
        $bundle = json_decode($contents, true);
        if (! is_array($bundle)) {
            throw new InvalidArgumentException('FHIR file must be valid JSON.');
        }

        return $this->importBundle($bundle, $patient, $actorUserId);
    }

    /**
     * With `$document`, the stored document the bundle was read from, every row
     * written is linked to it (see PhrStructuredDataImporter::fromDocument()).
     *
     * @param  array<string, mixed>  $bundle
     */
    public function importBundle(array $bundle, PhrPatient $patient, int $actorUserId, ?PhrDocument $document = null): PhrImportResult
    {
        return $this->structuredImporter->fromDocument($document, self::RECORD_SOURCE, fn (): PhrImportResult => $this->readBundle($bundle, $patient, $actorUserId));
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function readBundle(array $bundle, PhrPatient $patient, int $actorUserId): PhrImportResult
    {
        if (($bundle['resourceType'] ?? null) !== 'Bundle') {
            throw new InvalidArgumentException('FHIR import expects an R4 Bundle resource.');
        }

        $result = new PhrImportResult;
        $bundleEntries = is_array($bundle['entry'] ?? null) ? $bundle['entry'] : [];
        $this->index($bundleEntries);
        $index = $this->referenceIndex($bundle);
        $folded = $this->foldedImpressions();
        $written = [];
        $teams = $this->foldedCareTeams();
        $this->medicationPairs = $this->medicationPairs();
        foreach ($bundleEntries as $position => $entry) {
            if (! is_array($entry) || ! is_array($entry['resource'] ?? null)) {
                $result->addSkipped();

                continue;
            }
            if (isset($folded['into'][$position]) || isset($teams['into'][$position])) {
                // Read as part of the encounter it assesses or staffs, not as a record of its own.
                continue;
            }
            if (isset($this->medicationPairs['statement_of'][$position])) {
                // A home medication's request, read as part of the statement it pairs with.
                continue;
            }

            $fullUrl = is_string($entry['fullUrl'] ?? null) ? $entry['fullUrl'] : null;
            [$imported, $written[$position]] = $this->structuredImporter->collectingWritten(
                fn (): PhrImportResult => $this->importResource($entry['resource'], $patient, $actorUserId, $fullUrl, $folded['of'][$position] ?? [], $position, $teams['of'][$position] ?? [], $index),
            );
            $result->merge($imported);
        }
        $this->linkEncounters((int) $patient->id, $written, $result);
        // Addenda name the document they append to, which may come later in the bundle.
        $this->documentReferences->linkRelations($patient, $index);

        return $result;
    }

    /**
     * Links each record to the visit its resource names as its encounter (#411):
     * Observation, Procedure and MedicationRequest `encounter`, MedicationStatement
     * `context`, DocumentReference `context.encounter`. Only an Encounter in this bundle,
     * imported as a visit, is linked to; a reference that does not resolve is not guessed
     * at. It stays in the source: the record's raw text where its type keeps one, and the
     * bundle itself, which an import of a stored document links as each record's source.
     * A record already on another encounter keeps it.
     *
     * @param  array<int, list<Model>>  $written  bundle position => the rows its resource became
     */
    private function linkEncounters(int $patientId, array $written, PhrImportResult $result): void
    {
        $unresolved = 0;
        $kept = 0;
        foreach ($written as $position => $models) {
            $references = $this->encounterReferences($this->resources[$position] ?? []);
            if ($references === [] || $models === []) {
                continue;
            }
            $visitIds = [];
            foreach ($references as $reference) {
                $target = $this->resolve($reference, $position);
                if ($target === null || ($this->resources[$target]['resourceType'] ?? null) !== 'Encounter') {
                    continue;
                }
                foreach ($written[$target] ?? [] as $model) {
                    if ($model instanceof PhrOfficeVisit) {
                        $visitIds[(int) $model->id] = true;
                    }
                }
            }
            if (count($visitIds) !== 1) {
                // Not in the bundle, not imported, or more than one encounter: not linked.
                $unresolved++;

                continue;
            }
            $visitId = (int) array_key_first($visitIds);
            foreach ($models as $model) {
                $type = PhrEncounterLinks::typeForModel($model);
                if ($type !== null && ! $this->encounterLinks->linkIfFree($patientId, $visitId, $type, (int) $model->getKey())) {
                    $kept++;
                }
            }
        }
        if ($unresolved > 0) {
            $result->warn(sprintf('%d resource(s) name an encounter that is not an Encounter imported from this bundle; they were not linked to a visit, and the reference stays in the source.', $unresolved));
        }
        if ($kept > 0) {
            $result->warn(sprintf('%d record(s) already belong to another encounter and kept it.', $kept));
        }
    }

    /**
     * The encounter references a resource carries, by its type.
     *
     * @param  array<string, mixed>  $resource
     * @return list<mixed>
     */
    private function encounterReferences(array $resource): array
    {
        $references = match ($resource['resourceType'] ?? null) {
            'Observation', 'Procedure', 'MedicationRequest' => [$resource['encounter'] ?? null],
            'MedicationStatement' => [$resource['context'] ?? $resource['encounter'] ?? null],
            'DocumentReference' => (static function (mixed $encounter): array {
                // R4 lists them; STU3 had a single reference.
                return is_array($encounter) && array_is_list($encounter) ? $encounter : [$encounter];
            })(data_get($resource, 'context.encounter')),
            default => [],
        };

        return array_values(array_filter($references, static fn (mixed $reference): bool => is_array($reference) && isset($reference['reference'])));
    }

    /**
     * The bundle's resources by every name a reference inside it may use: the entry's
     * fullUrl, and `Type/id`.
     *
     * @param  array<array-key, mixed>  $entries
     */
    private function index(array $entries): void
    {
        $this->resources = [];
        $this->fullUrls = [];
        $this->typeIds = [];
        $this->entryUrls = [];
        foreach ($entries as $position => $entry) {
            if (! is_array($entry) || ! is_array($entry['resource'] ?? null)) {
                continue;
            }
            $resource = $entry['resource'];
            $this->resources[$position] = $resource;
            $fullUrl = is_string($entry['fullUrl'] ?? null) ? $this->text($entry['fullUrl']) : null;
            if ($fullUrl !== null) {
                $this->entryUrls[$position] = $fullUrl;
                $this->fullUrls[$fullUrl] ??= $position;
                $this->fullUrls[self::withoutVersion($fullUrl)] ??= $position;
            }
            $id = $resource['id'] ?? null;
            if (is_string($id) && trim($id) !== '' && is_string($resource['resourceType'] ?? null)) {
                $this->typeIds[$resource['resourceType'].'/'.trim($id)] ??= $position;
            }
        }
    }

    /**
     * The bundle position a reference names, when the resource is in the bundle, the
     * way FHIR resolves a reference inside a bundle. A reference that does not resolve
     * is not guessed at.
     *
     * - An absolute reference (a URL or a URN) is only the entry with that fullUrl, at
     *   any `_history` version: the same server base and the same `Type/id`. The same
     *   `Type/id` on another server is another resource.
     * - A relative `Type/id` is the resource at the referring entry's server base when
     *   the bundle holds it, else the bundle's resource with that `Type/id`.
     *
     * @param  int|null  $from  the bundle position of the resource the reference is in
     */
    private function resolve(mixed $reference, ?int $from = null): ?int
    {
        $reference = is_array($reference) ? ($reference['reference'] ?? null) : null;
        if (! is_string($reference) || trim($reference) === '') {
            return null;
        }
        $reference = trim($reference);
        if (isset($this->fullUrls[$reference])) {
            return $this->fullUrls[$reference];
        }
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:#', $reference) === 1) {
            return $this->fullUrls[self::withoutVersion($reference)] ?? null;
        }
        if (preg_match('#(?:^|/)([A-Z][A-Za-z]+)/([A-Za-z0-9\-.]{1,64})(?:/_history/[^/]+)?$#', $reference, $m) !== 1) {
            return null;
        }
        $base = $from === null ? null : self::restfulBase($this->entryUrls[$from] ?? null);
        if ($base !== null && isset($this->fullUrls[$base.$m[1].'/'.$m[2]])) {
            return $this->fullUrls[$base.$m[1].'/'.$m[2]];
        }

        return $this->typeIds[$m[1].'/'.$m[2]] ?? null;
    }

    /** A RESTful URL without its `_history` version. */
    private static function withoutVersion(string $url): string
    {
        return preg_replace('#/_history/[^/]+$#', '', $url) ?? $url;
    }

    /** The server base of a RESTful fullUrl (`<base>/Type/id`), with its trailing slash. */
    private static function restfulBase(?string $fullUrl): ?string
    {
        if ($fullUrl === null || preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*://.+/)[A-Z][A-Za-z]+/[A-Za-z0-9\-.]{1,64}(?:/_history/[^/]+)?$#', $fullUrl, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * ClinicalImpressions that assess an Encounter in the same bundle. PHR exports
     * each visit as both, so importing both as visits would double every visit; the
     * impressions' descriptions and summaries are read onto their encounter instead
     * (encounterRecord()), every one of them. One whose encounter is not in the bundle
     * is still a visit of its own.
     *
     * @return array{into: array<int, int>, of: array<int, list<array<string, mixed>>>}
     */
    private function foldedImpressions(): array
    {
        $into = [];
        $of = [];
        foreach ($this->resources as $position => $resource) {
            if (($resource['resourceType'] ?? null) !== 'ClinicalImpression') {
                continue;
            }
            $target = $this->resolve($resource['encounter'] ?? $resource['context'] ?? null, $position);
            if ($target === null || ($this->resources[$target]['resourceType'] ?? null) !== 'Encounter') {
                continue;
            }
            $into[$position] = $target;
            $of[$target][] = $resource;
        }

        return ['into' => $into, 'of' => $of];
    }

    /**
     * CareTeams that staff an Encounter in the same bundle (#412): one whose `encounter`
     * names it, else one whose period falls within the stay of exactly one encounter.
     * Their members are read as the encounter's care team. A CareTeam whose encounter is
     * not in the bundle, or that could belong to more than one stay, is not guessed into
     * one, and stays unread as before.
     *
     * @return array{into: array<int, int>, of: array<int, list<array<string, mixed>>>}
     */
    private function foldedCareTeams(): array
    {
        $encounters = [];
        foreach ($this->resources as $position => $resource) {
            if (($resource['resourceType'] ?? null) === 'Encounter') {
                $encounters[$position] = $resource;
            }
        }

        $into = [];
        $of = [];
        foreach ($this->resources as $position => $resource) {
            if (($resource['resourceType'] ?? null) !== 'CareTeam' || ($resource['status'] ?? null) === 'entered-in-error') {
                continue;
            }
            if (is_array($resource['encounter'] ?? null)) {
                $named = $this->resolve($resource['encounter']);
                $target = $named !== null && isset($encounters[$named]) ? $named : null;
            } else {
                $within = array_keys(array_filter($encounters, fn (array $encounter): bool => $this->periodWithin($resource['period'] ?? null, $encounter['period'] ?? null)));
                $target = count($within) === 1 ? $within[0] : null;
            }
            if ($target === null) {
                continue;
            }
            $into[$position] = $target;
            $of[$target][] = $resource;
        }

        return ['into' => $into, 'of' => $of];
    }

    /**
     * Whether a care team's period lies within a stay's: it starts no earlier than the
     * stay, and ends no later than a stay that ended. A team with no start says nothing.
     * A bound stated as a date is compared as that day.
     */
    private function periodWithin(mixed $inner, mixed $outer): bool
    {
        $innerStart = $this->moment(is_array($inner) ? ($inner['start'] ?? null) : null);
        $outerStart = $this->moment(is_array($outer) ? ($outer['start'] ?? null) : null);
        if ($innerStart === null || $outerStart === null || $this->compareMoments($innerStart, $outerStart) < 0) {
            return false;
        }
        $outerEnd = $this->moment(is_array($outer) ? ($outer['end'] ?? null) : null);
        if ($outerEnd === null) {
            return true;
        }
        $innerEnd = $this->moment(is_array($inner) ? ($inner['end'] ?? null) : null);

        return $innerEnd !== null && $this->compareMoments($innerEnd, $outerEnd) <= 0;
    }

    /** @return array{0: Carbon, 1: bool}|null the moment, and whether it was a date alone */
    private function moment(mixed $value): ?array
    {
        $text = $this->text($value);
        if ($text === null) {
            return null;
        }
        try {
            return [Carbon::parse($text), ! str_contains($text, 'T')];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array{0: Carbon, 1: bool}  $a
     * @param  array{0: Carbon, 1: bool}  $b
     */
    private function compareMoments(array $a, array $b): int
    {
        if ($a[1] || $b[1]) {
            return $a[0]->toDateString() <=> $b[0]->toDateString();
        }

        return $a[0]->getTimestamp() <=> $b[0]->getTimestamp();
    }

    /**
     * The bundle's resources by `Type/id` and by fullUrl, as PhrFhirDocumentReferences
     * resolves a document's author and relatesTo (phr#409).
     *
     * @param  array<string, mixed>  $bundle
     * @return array<string, array<string, mixed>>
     */
    private function referenceIndex(array $bundle): array
    {
        $index = [];
        foreach (is_array($bundle['entry'] ?? null) ? $bundle['entry'] : [] as $entry) {
            $resource = is_array($entry) && is_array($entry['resource'] ?? null) ? $entry['resource'] : null;
            if ($resource === null) {
                continue;
            }
            $id = $resource['id'] ?? null;
            if (is_string($id) && is_string($resource['resourceType'] ?? null)) {
                $index[$resource['resourceType'].'/'.$id] = $resource;
            }
            if (is_string($entry['fullUrl'] ?? null) && $entry['fullUrl'] !== '') {
                $index[$entry['fullUrl']] = $resource;
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  list<array<string, mixed>>  $impressions  ClinicalImpressions folded into this Encounter
     * @param  list<array<string, mixed>>  $careTeams  CareTeams folded into this Encounter
     * @param  array<string, array<string, mixed>>  $index  referenceIndex()
     */
    private function importResource(array $resource, PhrPatient $patient, int $actorUserId, ?string $fullUrl = null, array $impressions = [], ?int $position = null, array $careTeams = [], array $index = []): PhrImportResult
    {
        $resourceType = (string) ($resource['resourceType'] ?? '');
        $externalId = self::externalId($resource, $fullUrl);

        return match ($resourceType) {
            'Observation' => $this->importObservation($resource, $patient, $actorUserId, $externalId),
            'Condition' => $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_problem_list', [[
                'external_id' => $externalId,
                'name' => $this->codeableText($resource['code'] ?? null),
                'icd10_code' => $this->codeForSystem($resource['code'] ?? null, 'icd-10'),
                'snomed_code' => $this->codeForSystem($resource['code'] ?? null, 'snomed'),
                'onset_date' => $resource['onsetDateTime'] ?? $resource['onsetDate'] ?? null,
                'abated_date' => $resource['abatementDateTime'] ?? $resource['abatementDate'] ?? null,
                'clinical_status' => $this->codeableText($resource['clinicalStatus'] ?? null),
                'verification_status' => $this->codeableText($resource['verificationStatus'] ?? null),
                'raw_text' => json_encode($resource),
            ]], ['import_source' => 'fhir']),
            'MedicationStatement', 'MedicationRequest' => $this->importMedication($resource, $patient, $actorUserId, $externalId, $position),
            'Procedure' => $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_procedure', [[
                'external_id' => $externalId,
                'name' => $this->codeableText($resource['code'] ?? null),
                'cpt_code' => $this->codeForSystem($resource['code'] ?? null, 'cpt'),
                'snomed_code' => $this->codeForSystem($resource['code'] ?? null, 'snomed'),
                'performed_at' => $resource['performedDateTime'] ?? null,
                'performed_on' => $resource['performedDateTime'] ?? $resource['performedDate'] ?? null,
                'status' => $resource['status'] ?? 'completed',
                'raw_text' => json_encode($resource),
            ]], ['import_source' => 'fhir']),
            'Immunization' => $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_immunization', [[
                'external_id' => $externalId,
                'vaccine_name' => $this->codeableText($resource['vaccineCode'] ?? null),
                'cvx_code' => $this->codeForSystem($resource['vaccineCode'] ?? null, 'cvx'),
                'administered_on' => $resource['occurrenceDateTime'] ?? $resource['occurrenceString'] ?? null,
                'manufacturer' => data_get($resource, 'manufacturer.display'),
                'lot_number' => $resource['lotNumber'] ?? null,
                'raw_text' => json_encode($resource),
            ]], ['import_source' => 'fhir']),
            'AllergyIntolerance' => $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_allergy', [[
                'external_id' => $externalId,
                'substance' => $this->codeableText($resource['code'] ?? null),
                'category' => is_array($resource['category'] ?? null) ? implode(',', $resource['category']) : ($resource['category'] ?? null),
                'criticality' => $resource['criticality'] ?? null,
                'clinical_status' => $this->codeableText($resource['clinicalStatus'] ?? null),
                'verification_status' => $this->codeableText($resource['verificationStatus'] ?? null),
                'reaction' => $this->codeableText(data_get($resource, 'reaction.0.manifestation.0')),
                'raw_text' => json_encode($resource),
            ]], ['import_source' => 'fhir']),
            'Encounter' => $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_office_visit', [
                $this->encounterRecord($resource, $externalId, $impressions, $position, $careTeams),
            ], ['import_source' => 'fhir']),
            'ClinicalImpression' => $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_office_visit', [[
                'external_id' => $externalId,
                'visit_date' => data_get($resource, 'period.start') ?? $resource['date'] ?? null,
                'visit_started_at' => data_get($resource, 'period.start'),
                'visit_ended_at' => data_get($resource, 'period.end'),
                'visit_type' => $this->codeableText($resource['code'] ?? null),
                'provider_name' => data_get($resource, 'assessor.display'),
                'chief_complaint' => $this->text($resource['description'] ?? null),
                'assessment' => $this->summary($resource),
                'raw_text' => json_encode($resource),
            ]], ['import_source' => 'fhir']),
            'DocumentReference' => $this->documentReferences->import($resource, $index, $patient, $actorUserId, $externalId),
            default => new PhrImportResult(skipped: 1),
        };
    }

    /**
     * A MedicationStatement or MedicationRequest (#413).
     *
     * A request is an order: its category says whether it is for a hospital stay
     * (inpatient), a clinic (outpatient), the home list (community) or the list the
     * patient went home with (discharge). A statement's category is its own system's
     * (community, inpatient, outpatient). Any category but community scopes the
     * stored id (PhrMedicationCategory::scopedExternalId). A row an import before
     * #413 stored under the unscoped id, without a category, is moved onto it.
     *
     * US Core sends a home medication as both resources. A statement and a community
     * request that are certainly the same medication (the statement is basedOn or
     * derivedFrom the request alone, or they alone share an RxNorm code; see
     * medicationPairs()) are one row: the statement's, with what the request adds
     * where the statement says nothing. A
     * request that only may be one (the same name without a shared code, or one of
     * several) is written as its own row flagged possible_duplicate for a person, and
     * so is either resource when a stored row of the other kind has its name. The
     * request's encounter stays in raw_text, which keeps the resource as sent.
     *
     * @param  array<string, mixed>  $resource
     */
    private function importMedication(array $resource, PhrPatient $patient, int $actorUserId, string $externalId, ?int $position): PhrImportResult
    {
        $status = $resource['status'] ?? null;
        if ($status === 'entered-in-error') {
            return new PhrImportResult(skipped: 1);
        }

        $record = $this->medicationRecord($resource, $externalId);
        // A statement's paired request (requests that pair are not read on their own).
        $paired = $position === null ? null : ($this->medicationPairs['request_of'][$position] ?? null);
        if ($paired !== null) {
            $request = $this->resources[$paired];
            foreach ($this->medicationRecord($request, $this->externalId($request, $this->entryUrls[$paired] ?? null)) as $field => $value) {
                if (in_array($field, ['external_id', 'raw_text', 'review_flags'], true) || ($record[$field] ?? null) !== null) {
                    continue;
                }
                // The statement's own dosage text (its dose) already says it.
                if ($field === 'frequency' && $value !== null && $value === ($record['dose'] ?? null)) {
                    continue;
                }
                $record[$field] = $value;
            }
            $record['raw_text'] = json_encode([$resource, $request]);
        }

        $flagged = $position === null ? null : ($this->medicationPairs['flagged'][$position] ?? null);
        $counterpart = $flagged !== null
            ? $this->externalId($this->resources[$flagged], $this->entryUrls[$flagged] ?? null)
            : ($paired === null ? $this->storedCounterpart($patient, $resource, $record) : null);
        $record['review_flags'] = $counterpart === null ? null : [[
            'code' => 'possible_duplicate',
            'message' => 'May be the same home medication as another imported medication ('.$counterpart.'). Keep one and reject the other.',
            'external_id' => $counterpart,
        ]];

        $this->adoptUnscoped($patient, $externalId, (string) $record['external_id']);

        return $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_medication', [$record], ['import_source' => 'fhir']);
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    private function medicationRecord(array $resource, string $externalId): array
    {
        $isRequest = ($resource['resourceType'] ?? null) === 'MedicationRequest';
        $category = PhrMedicationCategory::fromFhir($resource['category'] ?? null);
        $dosage = data_get($resource, $isRequest ? 'dosageInstruction.0' : 'dosage.0');
        $dosage = is_array($dosage) ? $dosage : [];
        $concept = $this->medicationConcept($resource);
        $status = $resource['status'] ?? null;

        if ($isRequest) {
            $bounds = data_get($dosage, 'timing.repeat.boundsPeriod');
            $started = $this->text(data_get($bounds, 'start')) ?? $this->text($resource['authoredOn'] ?? null);
            $ended = $this->text(data_get($bounds, 'end')) ?? $this->endAfter($started, data_get($dosage, 'timing.repeat.boundsDuration'));
            [$dose, $doseUnit] = $this->doseQuantity(data_get($dosage, 'doseAndRate.0'));
        } else {
            $started = $this->text(data_get($resource, 'effectivePeriod.start') ?? $resource['effectiveDateTime'] ?? null);
            $ended = $this->text(data_get($resource, 'effectivePeriod.end'));
            // A statement's dose is its dosage as the source wrote it.
            [$dose, $doseUnit] = [data_get($resource, 'dosage.0.text'), null];
        }
        [$asNeeded, $asNeededReason] = $this->asNeeded($dosage);
        $route = $this->codeableText($dosage['route'] ?? null);
        $frequency = $this->frequency($dosage);
        $rate = $this->rate(data_get($dosage, 'doseAndRate.0'));
        if ($isRequest && $dose === null && $route === null && $frequency === null && $rate === null) {
            // An instruction only as text ("Take two tablets every evening") is how the
            // medication is taken: kept as its frequency, which the export's dosage text
            // reads back (#432). The column holds 100 characters; raw_text keeps it all.
            $frequency = ($instruction = $this->text($dosage['text'] ?? null)) === null ? null : mb_substr($instruction, 0, 100);
        }

        return [
            'external_id' => PhrMedicationCategory::scopedExternalId($externalId, $category),
            'name' => $this->codeableText($concept) ?? $this->text(data_get($resource, 'medicationReference.display')),
            'status' => is_string($status) ? (self::MEDICATION_STATUSES[$status] ?? null) : null,
            'started_on' => $started,
            'ended_on' => $ended,
            'started_at' => $started !== null && str_contains($started, 'T') ? $started : null,
            'ended_at' => $ended !== null && str_contains($ended, 'T') ? $ended : null,
            'dose' => $dose,
            ...($isRequest ? [
                'dose_unit' => $doseUnit,
                'rxnorm_code' => $this->codeForSystem($concept, 'rxnorm'),
                'route' => $route,
                'frequency' => $frequency,
                'prescriber_name' => $this->text(data_get($resource, 'requester.display')),
                'reason_for_use' => $this->codeableText(data_get($resource, 'reasonCode.0')),
            ] : []),
            'category' => $category,
            'as_needed' => $asNeeded,
            'as_needed_reason' => $asNeededReason,
            'rate' => $rate,
            'raw_text' => json_encode($resource),
        ];
    }

    /**
     * The medication a statement or request names: its CodeableConcept, or the code
     * of the Medication its reference names (contained, or in the bundle).
     *
     * @param  array<string, mixed>  $resource
     */
    private function medicationConcept(array $resource): mixed
    {
        if (is_array($resource['medicationCodeableConcept'] ?? null)) {
            return $resource['medicationCodeableConcept'];
        }
        $reference = data_get($resource, 'medicationReference.reference');
        if (is_string($reference) && str_starts_with($reference, '#')) {
            foreach (is_array($resource['contained'] ?? null) ? $resource['contained'] : [] as $inner) {
                if (is_array($inner) && ($inner['id'] ?? null) === substr($reference, 1)) {
                    return $inner['code'] ?? null;
                }
            }
        }
        $resolved = $this->resolve($resource['medicationReference'] ?? null);

        return $resolved === null ? null : ($this->resources[$resolved]['code'] ?? null);
    }

    /**
     * Pairs each home medication's MedicationRequest with the MedicationStatement it
     * is (see importMedication()): `request_of` statement position => request
     * position, `statement_of` the reverse, and `flagged` request position =>
     * the statement position it only may be.
     *
     * Explicit links come first, across the whole bundle (#432): a statement that is
     * basedOn or derivedFrom one request, which no other statement names, is that
     * request, wherever either sits in the bundle. A statement naming several
     * requests, or a request several statements name, pairs with none of them, and
     * each such request is flagged. What names or is named is kept for its links;
     * only the rest is then matched by RxNorm code, certainly when exactly one
     * statement and one request share it. A request that is not certainly a
     * statement is flagged with one it may be: one sharing its code, else its name.
     *
     * @return array{request_of: array<int, int>, statement_of: array<int, int>, flagged: array<int, int>}
     */
    private function medicationPairs(): array
    {
        $statements = [];
        $requests = [];
        foreach ($this->resources as $position => $resource) {
            $type = $resource['resourceType'] ?? null;
            $home = PhrMedicationCategory::isHomeList(PhrMedicationCategory::fromFhir($resource['category'] ?? null));
            if (! $home || ($resource['status'] ?? null) === 'entered-in-error') {
                continue;
            }
            if ($type === 'MedicationStatement') {
                $statements[] = $position;
            } elseif ($type === 'MedicationRequest') {
                $requests[] = $position;
            }
        }

        $pairs = ['request_of' => [], 'statement_of' => [], 'flagged' => []];

        // request position => the statements naming it; statement position => the requests it names
        $namedBy = [];
        $names = [];
        foreach ($requests as $request) {
            foreach ($statements as $statement) {
                if ($this->statementNames($statement, $request)) {
                    $namedBy[$request][] = $statement;
                    $names[$statement][] = $request;
                }
            }
        }
        foreach ($namedBy as $request => $naming) {
            if (count($naming) === 1 && count($names[$naming[0]]) === 1) {
                $pairs['request_of'][$naming[0]] = $request;
                $pairs['statement_of'][$request] = $naming[0];
            } else {
                $pairs['flagged'][$request] = $naming[0];
            }
        }

        $codes = [];
        foreach ([...$statements, ...$requests] as $position) {
            $codes[$position] = $this->codeForSystem($this->medicationConcept($this->resources[$position]), 'rxnorm');
        }
        // Of these positions, those whose RxNorm code is this one.
        $sharing = static fn (array $positions, ?string $code): array => $code === null ? [] : array_values(array_filter($positions, static fn (int $position): bool => $codes[$position] === $code));
        $openStatements = array_values(array_filter($statements, static fn (int $statement): bool => ! isset($names[$statement])));
        $openRequests = array_values(array_filter($requests, static fn (int $request): bool => ! isset($namedBy[$request])));
        foreach ($openRequests as $request) {
            $coded = $sharing($openStatements, $codes[$request]);
            if (count($coded) === 1 && count($sharing($openRequests, $codes[$request])) === 1) {
                $pairs['request_of'][$coded[0]] = $request;
                $pairs['statement_of'][$request] = $coded[0];

                continue;
            }
            $name = $this->comparableName($this->resources[$request]);
            $named = $name === null ? [] : array_values(array_filter($statements, fn (int $statement): bool => $this->comparableName($this->resources[$statement]) === $name));
            if (($maybe = $coded[0] ?? $sharing($statements, $codes[$request])[0] ?? $named[0] ?? null) !== null) {
                $pairs['flagged'][$request] = $maybe;
            }
        }

        return $pairs;
    }

    /** Whether the statement at `$statement` says it is based on, or derived from, the request at `$request`. */
    private function statementNames(int $statement, int $request): bool
    {
        $resource = $this->resources[$statement];
        foreach (['basedOn', 'derivedFrom'] as $field) {
            foreach (is_array($resource[$field] ?? null) ? $resource[$field] : [] as $reference) {
                if ($this->resolve($reference) === $request) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function comparableName(array $resource): ?string
    {
        $name = $this->codeableText($this->medicationConcept($resource)) ?? $this->text(data_get($resource, 'medicationReference.display'));

        return $name === null ? null : mb_strtolower((string) preg_replace('/\s+/', ' ', $name));
    }

    /**
     * A stored home medication of the other kind (a statement for a request, a request
     * for a statement) with this one's name, imported from another bundle: the two may
     * be one medication. Its id, or null.
     *
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $record
     */
    private function storedCounterpart(PhrPatient $patient, array $resource, array $record): ?string
    {
        $name = $this->comparableName($resource);
        if ($name === null || ! PhrMedicationCategory::isHomeList($record['category'] ?? null)) {
            return null;
        }
        $other = ($resource['resourceType'] ?? null) === 'MedicationRequest' ? 'MedicationStatement' : 'MedicationRequest';
        // This bundle's own medications are paired by medicationPairs(), not here: a
        // row an earlier entry of the same import just wrote is not "another bundle's".
        $own = [];
        foreach ($this->resources as $position => $inBundle) {
            if (($inBundle['resourceType'] ?? null) === $other) {
                $own[] = $this->externalId($inBundle, $this->entryUrls[$position] ?? null);
            }
        }
        $query = PhrMedication::query()->where('patient_id', $patient->id)->where('import_source', 'fhir')
            ->whereNull('retracted_at')->where('review_status', '!=', PhrReviewStatus::REJECTED)
            ->where('external_id', 'like', $other.'/%')
            ->when($own !== [], static fn ($rows) => $rows->whereNotIn('external_id', $own))
            ->whereRaw('LOWER(name) = ?', [$name])
            ->orderBy('id');
        PhrMedicationCategory::scopeHomeList($query);
        $external = $query->value('external_id');

        return is_string($external) ? $external : null;
    }

    /**
     * A row an import before #413 stored under the resource's own id, with no category,
     * is the row now stored under the scoped id.
     */
    private function adoptUnscoped(PhrPatient $patient, string $unscoped, string $scoped): void
    {
        if ($unscoped === $scoped) {
            return;
        }
        $rows = PhrMedication::withTrashed()->where('patient_id', $patient->id)->where('import_source', 'fhir');
        if ((clone $rows)->where('external_id', $scoped)->exists()) {
            return;
        }
        (clone $rows)->where('external_id', $unscoped)->whereNull('category')->limit(1)->get()
            ->each(static fn (PhrMedication $row): bool => $row->forceFill(['external_id' => $scoped])->saveQuietly());
    }

    /**
     * A dose's value and unit: a quantity, or a range's bounds.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function doseQuantity(mixed $doseAndRate): array
    {
        $quantity = data_get($doseAndRate, 'doseQuantity');
        if (is_array($quantity) && isset($quantity['value'])) {
            return [(string) $quantity['value'], $this->text($quantity['unit'] ?? $quantity['code'] ?? null)];
        }
        $low = data_get($doseAndRate, 'doseRange.low');
        $high = data_get($doseAndRate, 'doseRange.high');
        if (isset($low['value'], $high['value'])) {
            return [$low['value'].'-'.$high['value'], $this->text($low['unit'] ?? $high['unit'] ?? null)];
        }

        return [null, null];
    }

    /** A rate as it reads: "75 mL/h", from a rateQuantity, rateRatio or rateRange. */
    private function rate(mixed $doseAndRate): ?string
    {
        $quantity = static fn (mixed $q): ?string => is_array($q) && isset($q['value']) ? trim($q['value'].' '.($q['unit'] ?? $q['code'] ?? '')) : null;
        if (($rate = $quantity(data_get($doseAndRate, 'rateQuantity'))) !== null) {
            return $rate;
        }
        $numerator = $quantity(data_get($doseAndRate, 'rateRatio.numerator'));
        $denominator = data_get($doseAndRate, 'rateRatio.denominator');
        if ($numerator !== null && is_array($denominator)) {
            $per = (string) ($denominator['unit'] ?? $denominator['code'] ?? '');
            $value = $denominator['value'] ?? 1;

            return $numerator.'/'.((float) $value === 1.0 ? $per : trim($value.' '.$per));
        }
        $low = $quantity(data_get($doseAndRate, 'rateRange.low'));
        $high = $quantity(data_get($doseAndRate, 'rateRange.high'));

        return $low !== null && $high !== null ? $low.' to '.$high : null;
    }

    /**
     * Whether a dosage is as needed, and for what.
     *
     * @param  array<string, mixed>  $dosage
     * @return array{0: bool|null, 1: string|null}
     */
    private function asNeeded(array $dosage): array
    {
        if (is_bool($dosage['asNeededBoolean'] ?? null)) {
            return [$dosage['asNeededBoolean'], null];
        }
        if (is_array($dosage['asNeededCodeableConcept'] ?? null)) {
            return [true, $this->codeableText($dosage['asNeededCodeableConcept'])];
        }

        return [null, null];
    }

    /**
     * How often: the timing's code, else "every 6 h" from its repeat.
     *
     * @param  array<string, mixed>  $dosage
     */
    private function frequency(array $dosage): ?string
    {
        $code = $this->codeableText(data_get($dosage, 'timing.code'));
        if ($code !== null) {
            return $code;
        }
        $repeat = data_get($dosage, 'timing.repeat');
        if (! is_array($repeat) || ! isset($repeat['period'], $repeat['periodUnit'])) {
            return null;
        }
        $times = (int) ($repeat['frequency'] ?? 1);

        return ($times > 1 ? "{$times} times " : '').'every '.$repeat['period'].' '.$repeat['periodUnit'];
    }

    /** The end of a course that runs for a duration from its start ("for 5 days"). */
    private function endAfter(?string $start, mixed $duration): ?string
    {
        $value = is_array($duration) ? ($duration['value'] ?? null) : null;
        $unit = is_array($duration) ? ($duration['code'] ?? $duration['unit'] ?? null) : null;
        if ($start === null || ! is_numeric($value) || ! in_array($unit, ['d', 'day', 'days'], true)) {
            return null;
        }
        try {
            return Carbon::parse($start)->addDays((int) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function importObservation(array $resource, PhrPatient $patient, int $actorUserId, ?string $externalId): PhrImportResult
    {
        $quantity = is_array($resource['valueQuantity'] ?? null) ? $resource['valueQuantity'] : [];
        $value = $quantity !== []
            ? trim((string) ($quantity['value'] ?? '').' '.(string) ($quantity['unit'] ?? $quantity['code'] ?? ''))
            : ($resource['valueString'] ?? $resource['valueCodeableConcept']['text'] ?? null);

        if ($this->hasCategory($resource, 'vital-signs')) {
            return $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_vital', [[
                'external_id' => $externalId,
                'vital_name' => $this->codeableText($resource['code'] ?? null),
                'observed_at' => $resource['effectiveDateTime'] ?? null,
                'vital_date' => $resource['effectiveDateTime'] ?? null,
                'vital_value' => $value,
                'value_numeric' => $quantity['value'] ?? null,
                'unit' => $quantity['unit'] ?? $quantity['code'] ?? null,
                'raw_text' => json_encode($resource),
            ]], ['import_source' => 'fhir']);
        }

        return $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_lab_result', [[
            'external_id' => $externalId,
            'test_name' => data_get($resource, 'code.text'),
            'analyte' => $this->codeableText($resource['code'] ?? null),
            'observed_at' => $resource['effectiveDateTime'] ?? null,
            'result_datetime' => $resource['issued'] ?? $resource['effectiveDateTime'] ?? null,
            'value' => $value,
            'value_numeric' => $quantity['value'] ?? null,
            'unit' => $quantity['unit'] ?? $quantity['code'] ?? null,
            'range_min' => data_get($resource, 'referenceRange.0.low.value'),
            'range_max' => data_get($resource, 'referenceRange.0.high.value'),
            'range_min_inclusive' => $this->boundInclusive(data_get($resource, 'referenceRange.0.low')),
            'range_max_inclusive' => $this->boundInclusive(data_get($resource, 'referenceRange.0.high')),
            'reference_range_text' => data_get($resource, 'referenceRange.0.text'),
            'abnormal_flag' => $this->codeableText(data_get($resource, 'interpretation.0')),
            'raw_text' => json_encode($resource),
        ]], ['import_source' => 'fhir']);
    }

    /**
     * Whether a reference bound includes its value, by the comparator its
     * Quantity states: "<=" or ">=" is inclusive, "<" or ">" exclusive. A bound
     * with no comparator is unknown. FHIR defines a reference range's bounds as
     * inclusive, but sources commonly write a range their lab prints as "<200"
     * as a bare high of 200, so a bare bound is not taken as the comparator.
     */
    private function boundInclusive(mixed $bound): ?bool
    {
        if (! is_array($bound) || ! isset($bound['value'])) {
            return null;
        }

        return match ($bound['comparator'] ?? null) {
            '<=', '>=' => true,
            '<', '>' => false,
            default => null,
        };
    }

    /**
     * An Encounter, with what the ClinicalImpressions assessing it and the CareTeams
     * staffing it say.
     *
     * @param  array<string, mixed>  $resource
     * @param  list<array<string, mixed>>  $impressions
     * @param  list<array<string, mixed>>  $careTeams
     * @return array<string, mixed>
     */
    private function encounterRecord(array $resource, string $externalId, array $impressions, ?int $position = null, array $careTeams = []): array
    {
        $hospitalization = is_array($resource['hospitalization'] ?? null) ? $resource['hospitalization'] : [];
        $descriptions = array_values(array_filter(array_map(fn (array $impression): ?string => $this->text($impression['description'] ?? null), $impressions)));
        $complaint = $this->codeableText(data_get($resource, 'reasonCode.0'))
            ?? ($descriptions[0] ?? null)
            ?? $this->text($resource['description'] ?? null);
        $participants = $this->encounterParticipants($resource);
        foreach ($careTeams as $careTeam) {
            $participants = PhrVisitParticipants::merge($participants, $this->careTeamMembers($careTeam));
        }

        return [
            'external_id' => $externalId,
            'visit_date' => data_get($resource, 'period.start'),
            'visit_started_at' => data_get($resource, 'period.start'),
            'visit_ended_at' => data_get($resource, 'period.end'),
            'visit_type' => $this->codeableText(data_get($resource, 'type.0')),
            // The primary attending, else who the source listed first, as before the team was read.
            'provider_name' => PhrVisitParticipants::providerName($participants)
                ?? $this->text(data_get($resource, 'participant.0.individual.display'))
                ?? ($participants === [] ? null : PhrVisitParticipants::display($participants[0])),
            'participants' => $participants,
            'facility_name' => $this->text(data_get($resource, 'serviceProvider.display')),
            'chief_complaint' => $complaint,
            'assessment' => $this->assessment($impressions, $complaint),
            'encounter_class' => $this->encounterClass($resource['class'] ?? null),
            'encounter_state' => PhrEncounterVocabulary::stateCode($resource['status'] ?? null),
            'location' => $this->location($resource['location'] ?? null, $position),
            'admit_source' => PhrEncounterVocabulary::admitSourceCode($this->codeForSystemExactly($hospitalization['admitSource'] ?? null, PhrEncounterVocabulary::ADMIT_SOURCE_SYSTEM)),
            'admit_source_text' => $this->conceptText($hospitalization['admitSource'] ?? null),
            'discharge_disposition' => PhrEncounterVocabulary::dischargeDispositionCode($this->codeForSystemExactly($hospitalization['dischargeDisposition'] ?? null, PhrEncounterVocabulary::DISCHARGE_DISPOSITION_SYSTEM)),
            'discharge_disposition_text' => $this->conceptText($hospitalization['dischargeDisposition'] ?? null),
            'encounter_diagnoses' => $this->diagnoses($resource, $position),
            'raw_text' => json_encode($resource),
        ];
    }

    /**
     * What the ClinicalImpressions folded into an encounter assess, in bundle order. One
     * impression is its summary. With several, each one's summary is a paragraph, and
     * an impression that describes something other than the visit's chief complaint
     * starts its paragraph with that description (or is only it, with no summary), so
     * no impression's assessment is lost and each still reads as what it assessed.
     *
     * @param  list<array<string, mixed>>  $impressions
     */
    private function assessment(array $impressions, ?string $complaint): ?string
    {
        if (count($impressions) <= 1) {
            return $impressions === [] ? null : $this->summary($impressions[0]);
        }

        $parts = [];
        foreach ($impressions as $impression) {
            $summary = $this->summary($impression);
            $description = $this->text($impression['description'] ?? null);
            if ($description === $complaint) {
                $description = null;
            }
            $part = match (true) {
                $description === null => $summary,
                $summary === null => $description,
                default => $description.': '.$summary,
            };
            if ($part !== null && ! in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * Encounter.participant[]: each person with their type (a v3 ParticipationType code,
     * PPRF beside another for a primary member, the concept's text as the label) and the
     * period they took part.
     *
     * @param  array<string, mixed>  $resource
     * @return list<array<string, mixed>>
     */
    private function encounterParticipants(array $resource): array
    {
        $members = [];
        foreach (is_array($resource['participant'] ?? null) ? $resource['participant'] : [] as $participant) {
            if (! is_array($participant)) {
                continue;
            }
            $member = $this->member($participant['type'] ?? null, $participant['individual'] ?? null, $participant['period'] ?? null, $resource);
            if ($member !== null) {
                $members[] = $member;
            }
        }

        return PhrVisitParticipants::normalize($members);
    }

    /**
     * A CareTeam's members, each with their role from participant[].role. A member who
     * is the patient, an organization or another team is not a person on the team.
     *
     * @param  array<string, mixed>  $careTeam
     * @return list<array<string, mixed>>
     */
    private function careTeamMembers(array $careTeam): array
    {
        $members = [];
        foreach (is_array($careTeam['participant'] ?? null) ? $careTeam['participant'] : [] as $participant) {
            if (! is_array($participant)) {
                continue;
            }
            $member = $this->member($participant['role'] ?? null, $participant['member'] ?? null, $participant['period'] ?? null, $careTeam);
            if ($member !== null) {
                $members[] = $member;
            }
        }

        return PhrVisitParticipants::normalize($members);
    }

    /**
     * One person from their role concepts, the reference naming them (a contained or
     * bundled Practitioner, PractitionerRole or RelatedPerson, else the reference's
     * display) and their period.
     *
     * @param  array<string, mixed>  $container  the resource the reference is inside
     * @return array<string, mixed>|null
     */
    private function member(mixed $concepts, mixed $reference, mixed $period, array $container): ?array
    {
        $person = $this->person($reference, $container);
        if ($person === null) {
            return null;
        }
        $codes = [];
        $label = null;
        foreach (is_array($concepts) && array_is_list($concepts) ? $concepts : [] as $concept) {
            if (! is_array($concept)) {
                continue;
            }
            $label ??= $this->text($concept['text'] ?? null);
            foreach (is_array($concept['coding'] ?? null) ? $concept['coding'] : [] as $coding) {
                if (! is_array($coding) || ! is_string($coding['code'] ?? null)) {
                    continue;
                }
                $system = $coding['system'] ?? null;
                if (($system === null || $system === PhrVisitParticipants::PARTICIPATION_TYPE_SYSTEM) && isset(PhrVisitParticipants::ROLES[strtoupper($coding['code'])])) {
                    $codes[] = $coding['code'];
                } else {
                    // A code from another system (a SNOMED role, a local one) is named by its words.
                    $label ??= $this->text($coding['display'] ?? null);
                }
            }
        }

        return [
            ...$person,
            ...PhrVisitParticipants::role($codes, $label),
            'role_label' => $label,
            'period_start' => is_array($period) ? $this->text($period['start'] ?? null) : null,
            'period_end' => is_array($period) ? $this->text($period['end'] ?? null) : null,
        ];
    }

    /**
     * Who a reference names: their name and credentials (HumanName suffixes), their
     * specialty when it names a PractitionerRole, and the reference itself as the
     * member's identifier when it is to a resource outside its container. A reference
     * to the patient, an organization, a group or a device names no team member.
     *
     * @param  array<string, mixed>  $container
     * @return array{external_id: string|null, name: string, credentials: string|null, specialty: string|null}|null
     */
    private function person(mixed $reference, array $container, int $depth = 0): ?array
    {
        if (! is_array($reference) || $depth > 1) {
            return null;
        }
        $target = $this->referenced($reference, $container);
        $type = is_array($target) ? ($target['resourceType'] ?? null) : null;
        $named = is_string($reference['reference'] ?? null) ? trim($reference['reference']) : '';
        $notPeople = ['Patient', 'Organization', 'CareTeam', 'Group', 'Device', 'HealthcareService'];
        if (in_array($type, $notPeople, true)
            || ($type === null && preg_match('#(?:^|/)('.implode('|', $notPeople).')/#', $named) === 1)) {
            return null;
        }
        $externalId = $named !== '' && ! str_starts_with($named, '#') ? mb_substr($named, 0, 255) : null;
        $display = $this->text($reference['display'] ?? null);

        if ($type === 'PractitionerRole') {
            // A `#` reference resolves in the resource that holds it. A contained role
            // shares its container's contained list (contained resources hold none of
            // their own); a bundled role is its own container.
            $roleContainer = str_starts_with($named, '#') ? $container : $target;
            $practitioner = $this->person(is_array($target['practitioner'] ?? null) ? $target['practitioner'] : null, $roleContainer, $depth + 1);
            $name = $practitioner['name'] ?? $display;

            return $name === null ? null : [
                'external_id' => $externalId ?? $practitioner['external_id'] ?? null,
                'name' => $name,
                'credentials' => $practitioner['credentials'] ?? null,
                'specialty' => $this->conceptText(data_get($target, 'specialty.0')),
            ];
        }

        [$name, $credentials] = is_array($target) ? $this->humanName($target['name'] ?? null) : [null, null];
        $name ??= $display;

        return $name === null ? null : ['external_id' => $externalId, 'name' => $name, 'credentials' => $credentials, 'specialty' => null];
    }

    /**
     * The resource a reference names, contained in its container or in the bundle.
     *
     * @param  array<string, mixed>  $reference
     * @param  array<string, mixed>  $container
     * @return array<string, mixed>|null
     */
    private function referenced(array $reference, array $container): ?array
    {
        $named = is_string($reference['reference'] ?? null) ? trim($reference['reference']) : '';
        if (str_starts_with($named, '#')) {
            foreach (is_array($container['contained'] ?? null) ? $container['contained'] : [] as $inner) {
                if (is_array($inner) && ($inner['id'] ?? null) === substr($named, 1)) {
                    return $inner;
                }
            }

            return null;
        }
        $position = $this->resolve($reference);

        return $position === null ? null : $this->resources[$position];
    }

    /**
     * A HumanName list's first usable name, as the name a person goes by and the
     * credentials its suffixes state ("MD", "RN").
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function humanName(mixed $names): array
    {
        foreach (is_array($names) && array_is_list($names) ? $names : [] as $name) {
            if (! is_array($name) || in_array($name['use'] ?? null, ['old', 'maiden'], true)) {
                continue;
            }
            $suffixes = array_values(array_filter(array_map($this->text(...), is_array($name['suffix'] ?? null) ? $name['suffix'] : [])));
            $text = $this->text($name['text'] ?? null);
            if ($text === null) {
                $given = array_filter(array_map($this->text(...), is_array($name['given'] ?? null) ? $name['given'] : []));
                $text = $this->text(implode(' ', array_filter([...$given, $this->text($name['family'] ?? null)])));
            }
            if ($text !== null) {
                return [$text, $suffixes === [] ? null : implode(', ', $suffixes)];
            }
        }

        return [null, null];
    }

    /**
     * R4's class is a Coding; R5's a list of CodeableConcepts. Only an ActCode (or a
     * code with no system) is a class; a null flavor is none.
     */
    private function encounterClass(mixed $class): ?string
    {
        $codings = [];
        if (is_array($class) && array_is_list($class)) {
            foreach ($class as $concept) {
                foreach ((is_array($concept) && is_array($concept['coding'] ?? null) ? $concept['coding'] : []) as $coding) {
                    $codings[] = $coding;
                }
            }
        } elseif (is_array($class)) {
            $codings[] = $class;
        }
        foreach ($codings as $coding) {
            if (! is_array($coding)) {
                continue;
            }
            $system = $coding['system'] ?? null;
            if ($system === null || $system === PhrEncounterVocabulary::ACT_CODE_SYSTEM) {
                $code = PhrEncounterVocabulary::classCode($coding['code'] ?? null);
                if ($code !== null) {
                    return $code;
                }
            }
        }

        return null;
    }

    /** The first location the encounter was at that names itself, here or in the bundle. */
    private function location(mixed $locations, ?int $from = null): ?string
    {
        foreach (is_array($locations) ? $locations : [] as $location) {
            if (! is_array($location) || ($location['status'] ?? null) === 'planned') {
                continue;
            }
            $named = $this->text(data_get($location, 'location.display'));
            $resolved = $this->resolve($location['location'] ?? null, $from);
            if ($named === null && $resolved !== null && ($this->resources[$resolved]['resourceType'] ?? null) === 'Location') {
                $named = $this->text($this->resources[$resolved]['name'] ?? null);
            }
            if ($named !== null) {
                return $named;
            }
        }

        return null;
    }

    /**
     * The encounter's diagnoses in rank order, each named by the Condition it refers
     * to: a contained one, one in the bundle, or the reference's display alone.
     *
     * @param  array<string, mixed>  $resource
     * @return list<array<string, string>>|null
     */
    private function diagnoses(array $resource, ?int $from = null): ?array
    {
        $contained = [];
        foreach (is_array($resource['contained'] ?? null) ? $resource['contained'] : [] as $inner) {
            if (is_array($inner) && is_string($inner['id'] ?? null)) {
                $contained['#'.$inner['id']] = $inner;
            }
        }

        $ranked = [];
        foreach (is_array($resource['diagnosis'] ?? null) ? $resource['diagnosis'] : [] as $position => $diagnosis) {
            if (! is_array($diagnosis) || ! is_array($diagnosis['condition'] ?? null)) {
                continue;
            }
            $reference = $diagnosis['condition']['reference'] ?? null;
            $condition = is_string($reference) ? ($contained[$reference] ?? null) : null;
            if ($condition === null) {
                $resolved = $this->resolve($diagnosis['condition'], $from);
                $condition = $resolved === null ? null : $this->resources[$resolved];
            }
            $coding = is_array($condition) ? data_get($condition, 'code.coding.0') : null;
            $name = (is_array($condition) ? $this->codeableText($condition['code'] ?? null) : null)
                ?? $this->text($diagnosis['condition']['display'] ?? null);
            if ($name === null) {
                continue;
            }
            $code = is_array($coding) ? $this->text($coding['code'] ?? null) : null;
            $rank = $diagnosis['rank'] ?? null;
            $ranked[] = [is_int($rank) && $rank > 0 ? $rank : PHP_INT_MAX, (int) $position, array_filter([
                'name' => $name,
                'code' => $code,
                'code_system' => $code !== null ? $this->text($coding['system'] ?? null) : null,
                'use' => $this->text(data_get($diagnosis, 'use.coding.0.code')),
            ], static fn (?string $value): bool => $value !== null)];
        }
        usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $ranked === [] ? null : array_column($ranked, 2);
    }

    /**
     * A ClinicalImpression's summary: a string in R4, and some sources write it as
     * an object with text.
     *
     * @param  array<string, mixed>  $impression
     */
    private function summary(array $impression): ?string
    {
        $summary = $impression['summary'] ?? null;

        return $this->text(is_array($summary) ? ($summary['text'] ?? null) : $summary);
    }

    /** A concept's words: its text, else its first coding's display. */
    private function conceptText(mixed $concept): ?string
    {
        return is_array($concept) ? $this->text($concept['text'] ?? data_get($concept, 'coding.0.display')) : null;
    }

    /** A coding's code from exactly this system. */
    private function codeForSystemExactly(mixed $concept, string $system): ?string
    {
        foreach ((is_array($concept) && is_array($concept['coding'] ?? null) ? $concept['coding'] : []) as $coding) {
            if (is_array($coding) && ($coding['system'] ?? null) === $system && is_string($coding['code'] ?? null)) {
                return $coding['code'];
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * The resource's own id when it has one. A resource without one is named by its
     * entry's fullUrl, else by a digest of its content, so importing the same bundle
     * again updates it rather than adding it a second time.
     *
     * PhrFhirDocumentReferences names the target of a relatesTo with this too, so an
     * addendum finds the document its target became (phr#430).
     *
     * @param  array<string, mixed>  $resource
     */
    public static function externalId(array $resource, ?string $fullUrl = null): string
    {
        $type = is_string($resource['resourceType'] ?? null) ? $resource['resourceType'] : 'Resource';
        $id = $resource['id'] ?? null;
        if (is_string($id) && trim($id) !== '') {
            return $type.'/'.trim($id);
        }
        $fullUrl = $fullUrl === null ? '' : trim($fullUrl);
        if ($fullUrl !== '') {
            $named = $type.'/fullUrl:'.$fullUrl;

            return strlen($named) <= self::MAX_EXTERNAL_ID_BYTES ? $named : $type.'/fullUrl-sha256:'.hash('sha256', $fullUrl);
        }

        return $type.'/sha256:'.hash('sha256', json_encode(self::canonical($resource), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Object keys sorted at every level, so key order never changes a digest. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $canonical = array_map(self::canonical(...), $value);
        if (! array_is_list($canonical)) {
            ksort($canonical, SORT_STRING);
        }

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function hasCategory(array $resource, string $needle): bool
    {
        foreach (($resource['category'] ?? []) as $category) {
            foreach (($category['coding'] ?? []) as $coding) {
                $code = strtolower((string) ($coding['code'] ?? ''));
                $system = strtolower((string) ($coding['system'] ?? ''));
                if (str_contains($code, $needle) || str_contains($system, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function codeableText(mixed $concept): ?string
    {
        if (! is_array($concept)) {
            return is_scalar($concept) ? (string) $concept : null;
        }

        $text = $concept['text'] ?? $concept['display'] ?? data_get($concept, 'coding.0.display') ?? data_get($concept, 'coding.0.code');

        return is_scalar($text) && trim((string) $text) !== '' ? trim((string) $text) : null;
    }

    private function codeForSystem(mixed $concept, string $systemNeedle): ?string
    {
        if (! is_array($concept)) {
            return null;
        }

        foreach (($concept['coding'] ?? []) as $coding) {
            if (! is_array($coding)) {
                continue;
            }

            $system = strtolower((string) ($coding['system'] ?? ''));
            if (str_contains($system, strtolower($systemNeedle)) && isset($coding['code'])) {
                return (string) $coding['code'];
            }
        }

        return null;
    }
}
