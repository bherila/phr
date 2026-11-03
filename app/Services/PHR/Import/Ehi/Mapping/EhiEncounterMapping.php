<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

use App\Models\PhrEhiEntity;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Models\PhrOfficeVisit;
use App\Services\PHR\Import\Ehi\Mapping\Document\EhiDocumentValues;
use App\Services\PHR\Import\Ehi\Mapping\Encounter\EhiEncounterApplyRefused;
use App\Services\PHR\Import\Ehi\Mapping\Encounter\EhiEncounterCategory;
use App\Services\PHR\Import\Ehi\Mapping\Encounter\EhiEncounterRecordIndex;
use App\Support\PHR\PhrRecordLifecycle;
use App\Support\PHR\PhrReviewStatus;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Epic EHI `PAT_ENC` rows to visit proposals (issue #213, M3).
 *
 * Source tables, verified against the public spec (open.epic.com/EHITables) on
 * 2026-11-02. Projection re-checks each key against the package's own dictionary.
 *
 * - PAT_ENC, PK [PAT_ENC_CSN_ID]. PAT_ID owns the encounter. The public page has no
 *   encounter-type column, so ENC_TYPE_C_NAME is read only if a package supplies it.
 * - PAT_ENC_DX, PK [PAT_ENC_CSN_ID, LINE]. A diagnosis recorded on an encounter, by
 *   name: the table has no ICD-10 column, and none is invented. These stay on the
 *   visit in encounter_diagnoses; they never create a longitudinal condition. A list
 *   is only as good as the rows behind it: if the table was withheld or absent, or a
 *   row could not be read or was quarantined, the package cannot say what an
 *   encounter's diagnoses are, so its proposal carries none and an update leaves the
 *   visit's alone. A complete table with no row for an encounter does say it has none.
 *
 * Only a clinical encounter (EhiEncounterCategory) maps to a visit. Telephone,
 * portal, cancelled, administrative, and unclassified ones are retained as evidence
 * with their category shown, so no visit is claimed that the source does not state.
 * A later mapping version can propose them from the same revisions.
 *
 * Column to field (a fixed list: billing columns such as CLAIM_ID, COPAY_DUE, and
 * HSP_ACCOUNT_ID are never read, and billing tables never reach this mapping):
 *   CONTACT_DATE (no zone), else EFFECTIVE_DATE_DT -> visit_date, visit_date_source
 *   HOSP_ADMSN_TIME, else EFFECTIVE_DATE_DTTM (Local) -> visit_started_at, visit_started_*
 *   HOSP_DISCHRG_TIME (Local) -> visit_ended_at, visit_ended_*
 *   ENC_TYPE_C_NAME -> visit_type
 *   APPT_STATUS_C_NAME -> encounter_status
 *   VISIT_PROV_ID_PROV_NAME -> provider_name
 *   DEPARTMENT_ID_EXTERNAL_NAME, else PRIMARY_LOC_ID_LOC_NAME -> facility_name
 *   SUMMARY_COLUMNS -> raw_text
 *
 * Times hold the source's wall clock as written, never shifted, and *_timezone says
 * what the spec documents it as. Exports state no offset for such a time.
 *
 * Reconciliation matches the lab mapping: a link or this source's own identity
 * corroborates or proposes an explicit update; a source-ID bridge rule links evidence,
 * or names a conflict for a person; a same-date visit from elsewhere is a possible
 * duplicate; nothing writes without a decision; a deleted, retracted, or rejected
 * record is never a target nor silently recreated.
 */
final class EhiEncounterMapping implements EhiMapping
{
    public const string NAME = 'encounter';

    public const string VERSION = 'ehi-enc-1';

    public const string TABLE_ENCOUNTERS = 'PAT_ENC';

    public const string TABLE_DIAGNOSES = 'PAT_ENC_DX';

    public const string RECORD_TABLE = 'phr_office_visits';

    public const string TIMEZONE_LOCAL = 'local';

    public const string TIMEZONE_UNSPECIFIED = 'unspecified';

    public const string FINDING_ORPHAN = 'orphan_encounter';

    public const string FINDING_FOREIGN_PATIENT = 'foreign_patient_encounter';

    public const string FINDING_AMBIGUOUS_REVISION = 'ambiguous_encounter_revision';

    public const string FINDING_AMBIGUOUS_LINK = 'ambiguous_encounter_link';

    public const string FINDING_IDENTITY_TOO_LONG = 'encounter_identity_too_long';

    public const string FINDING_VALUE_TOO_LONG = 'value_too_long';

    public const string FINDING_UNPARSEABLE_DATE = 'unparseable_date';

    public const string FINDING_MISSING_DATE = 'missing_encounter_date';

    public const string FINDING_UNORDERED_DIAGNOSES = 'unordered_encounter_diagnoses';

    public const string FINDING_DIAGNOSES_TOO_LARGE = 'encounter_diagnoses_too_large';

    /** The package cannot vouch for an encounter's diagnoses; what a visit holds is left alone. */
    public const string FINDING_DIAGNOSES_UNAVAILABLE = 'encounter_diagnoses_unavailable';

    public const string FINDING_UNCLASSIFIED = 'unclassified_encounter';

    public const string FINDING_LINKED_RECORD_WITHDRAWN = 'linked_record_withdrawn';

    public const string FINDING_IDENTITY_WITHDRAWN = 'encounter_identity_withdrawn';

    public const string FINDING_MATCHED_EXTERNAL_IDENTITY = 'matched_external_identity';

    public const string FINDING_LINKED_NOT_CLINICAL = 'linked_encounter_not_clinical';

    public const string FINDING_BRIDGE_CONFLICT = 'encounter_bridge_conflict';

    public const string FINDING_BRIDGE_AMBIGUOUS = 'encounter_bridge_ambiguous';

    public const string FINDING_BRIDGE_WITHDRAWN = 'encounter_bridge_target_withdrawn';

    public const string FINDING_WITHDRAWN_MATCH = 'encounter_withdrawn_match';

    public const string FINDING_UNMATCHED_DIAGNOSES = 'unmatched_encounter_diagnoses';

    /** What a proposal may write; update diffs and applies cover only these. */
    public const array MAPPED_FIELDS = [
        'visit_date',
        'visit_date_source',
        'visit_started_at',
        'visit_started_source',
        'visit_started_precision',
        'visit_started_timezone',
        'visit_ended_at',
        'visit_ended_source',
        'visit_ended_precision',
        'visit_ended_timezone',
        'visit_type',
        'encounter_category',
        'encounter_status',
        'provider_name',
        'facility_name',
        'raw_text',
    ];

    /** Clinical columns summarized in raw_text, in this order. Never a billing column. */
    public const array SUMMARY_COLUMNS = [
        'PAT_ENC_CSN_ID',
        'PAT_ENC_DATE_REAL',
        'CONTACT_DATE',
        'EFFECTIVE_DATE_DTTM',
        'ENC_TYPE_C_NAME',
        'APPT_STATUS_C_NAME',
        'VISIT_PROV_ID_PROV_NAME',
        'VISIT_PROV_TITLE_NAME',
        'DEPARTMENT_ID_EXTERNAL_NAME',
        'PRIMARY_LOC_ID_LOC_NAME',
        'HOSP_ADMSN_TIME',
        'HOSP_DISCHRG_TIME',
        'HOSP_ADMSN_TYPE_C_NAME',
        'ENC_CLOSED_YN',
        'CONTACT_COMMENT',
    ];

    /** phr_office_visits column limits. */
    private const array FIELD_LIMITS = [
        'visit_date_source' => 64,
        'visit_started_source' => 64,
        'visit_ended_source' => 64,
        'visit_type' => 100,
        'encounter_status' => 50,
        'provider_name' => 255,
        'facility_name' => 255,
    ];

    private const int MAX_EXTERNAL_ID_BYTES = 255;

    /** Encoded encounter_diagnoses, in bytes; a larger list is retained, never cut. */
    private const int MAX_DIAGNOSES_BYTES = 64 * 1024;

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
        return [
            self::TABLE_ENCOUNTERS => ['PAT_ENC_CSN_ID'],
            self::TABLE_DIAGNOSES => ['PAT_ENC_CSN_ID', 'LINE'],
        ];
    }

    public static function importSource(int $sourceId): string
    {
        return 'ehi:'.$sourceId;
    }

    public static function externalId(string $csn): string
    {
        return self::TABLE_ENCOUNTERS.':'.rawurlencode($csn);
    }

    /** @return Generator<int, EhiProposalDraft> */
    public function propose(EhiMappingContext $context): iterable
    {
        $diagnoses = $this->diagnoses($context);
        $gaps = $context->incompleteRows(self::TABLE_DIAGNOSES, 'PAT_ENC_CSN_ID');
        if ($gaps['all']) {
            $context->finding(self::FINDING_DIAGNOSES_UNAVAILABLE, null, ['scope' => 'table', 'severity' => 'info']);
        }
        $importSource = self::importSource($context->source->id);
        $records = new EhiEncounterRecordIndex($context, $importSource, self::TABLE_ENCOUNTERS, self::NAME);
        /** @var array<string, true> $used */
        $used = [];

        $group = [];
        foreach ($context->lazyRevisions(self::TABLE_ENCOUNTERS, withEntity: true) as $revision) {
            if ($group !== [] && $group[0]->entity_id !== $revision->entity_id) {
                yield $this->draft($context, $records, $group, $diagnoses, $gaps, $used, $importSource);
                $group = [];
            }
            $group[] = $revision;
        }
        if ($group !== []) {
            yield $this->draft($context, $records, $group, $diagnoses, $gaps, $used, $importSource);
        }

        $unmatched = (int) array_sum(array_map('count', array_diff_key($diagnoses, $used)));
        if ($unmatched > 0) {
            $context->finding(self::FINDING_UNMATCHED_DIAGNOSES, null, ['count' => $unmatched, 'severity' => 'info']);
        }
    }

    public function apply(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome
    {
        if ((int) $proposal->patient_id !== $context->patient->id
            || $proposal->mapping !== self::NAME
            || $proposal->mapping_version !== self::VERSION) {
            throw new EhiEncounterApplyRefused(EhiEncounterApplyRefused::WRONG_SCOPE, 'the proposal does not belong to this patient and mapping version');
        }
        $creatable = [PhrEhiProposal::KIND_CREATE, PhrEhiProposal::KIND_POSSIBLE_DUPLICATE];

        return match (true) {
            $context->decision === PhrEhiProposal::DECISION_ACCEPT && in_array($proposal->kind, $creatable, true) => $this->applyCreate($proposal, $context),
            $context->decision === PhrEhiProposal::DECISION_ACCEPT && $proposal->kind === PhrEhiProposal::KIND_CORROBORATE => new EhiApplyOutcome(
                self::RECORD_TABLE,
                $this->lockedLiveTarget($proposal, $proposal->target_table === self::RECORD_TABLE ? $proposal->target_id : null)->id,
                EhiSourceIdBridge::of($proposal, $context->decision) === null ? PhrEhiRecordLink::KIND_CORROBORATING : PhrEhiRecordLink::KIND_BRIDGED,
            ),
            $context->decision === PhrEhiProposal::DECISION_APPROVE_UPDATE && $proposal->kind === PhrEhiProposal::KIND_UPDATE => $this->applyUpdate($proposal),
            $context->decision === PhrEhiProposal::DECISION_LINK && in_array($proposal->kind, $creatable, true) => new EhiApplyOutcome(self::RECORD_TABLE, $this->lockedLiveTarget($proposal, $context->linkTargetId)->id, PhrEhiRecordLink::KIND_BRIDGED),
            default => throw new EhiEncounterApplyRefused(EhiEncounterApplyRefused::DECISION_NOT_APPLICABLE, 'the decision does not apply to this proposal kind'),
        };
    }

    /**
     * The package's diagnosis rows by exact CSN, holding only the columns read.
     *
     * @return array<string, list<array{id: int, ambiguous: bool, line: string, entry: array<string, string|bool|null>}>>
     */
    private function diagnoses(EhiMappingContext $context): array
    {
        $rows = [];
        $previous = null;
        foreach ($context->lazyRevisions(self::TABLE_DIAGNOSES) as $revision) {
            $repeated = $previous !== null && $previous === $revision->entity_id;
            $previous = $revision->entity_id;
            $csn = EhiDocumentValues::cell($revision, 'PAT_ENC_CSN_ID');
            if ($csn === null) {
                continue;
            }
            $line = (string) EhiDocumentValues::cell($revision, 'LINE');
            $rows[$csn][] = [
                'id' => $revision->id,
                'ambiguous' => $repeated,
                'line' => $line,
                'entry' => [
                    'line' => $line,
                    'name' => self::trimmed(EhiDocumentValues::cell($revision, 'DX_ID_DX_NAME')),
                    'primary' => $this->yesNo(EhiDocumentValues::cell($revision, 'PRIMARY_DX_YN')),
                    'chronic' => $this->yesNo(EhiDocumentValues::cell($revision, 'DX_CHRONIC_YN')),
                    'ed_impression' => $this->yesNo(EhiDocumentValues::cell($revision, 'DX_ED_YN')),
                    'qualifier' => self::trimmed(EhiDocumentValues::cell($revision, 'DX_QUALIFIER_C_NAME')),
                    'annotation' => self::trimmed(EhiDocumentValues::cell($revision, 'ANNOTATION')),
                    'comments' => self::trimmed(EhiDocumentValues::cell($revision, 'COMMENTS')),
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param  non-empty-list<PhrEhiRevision>  $revisions
     * @param  array<string, list<array{id: int, ambiguous: bool, line: string, entry: array<string, string|bool|null>}>>  $diagnoses
     * @param  array{all: bool, keys: array<string, true>}  $gaps
     * @param  array<string, true>  $used
     */
    private function draft(EhiMappingContext $context, EhiEncounterRecordIndex $records, array $revisions, array $diagnoses, array $gaps, array &$used, string $importSource): EhiProposalDraft
    {
        $context->budget->checkpoint();
        $revision = $revisions[0];
        /** @var PhrEhiEntity $entity */
        $entity = $revision->entity;
        $subject = $entity->key_digest;
        $key = (array) $entity->key_json;
        $csn = is_string($key['PAT_ENC_CSN_ID'] ?? null) ? $key['PAT_ENC_CSN_ID'] : '';
        $revisionIds = array_map(static fn (PhrEhiRevision $row): int => $row->id, $revisions);
        foreach ($diagnoses[$csn] ?? [] as $diagnosis) {
            $revisionIds[] = $diagnosis['id'];
        }
        $used[$csn] = true;

        if (count($revisions) > 1) {
            $context->finding(self::FINDING_AMBIGUOUS_REVISION, $revision, ['revisions' => count($revisions)]);

            return $this->retain($subject, $revisionIds, null, self::FINDING_AMBIGUOUS_REVISION);
        }
        $externalId = self::externalId($csn);
        if (strlen($externalId) > self::MAX_EXTERNAL_ID_BYTES) {
            $context->finding(self::FINDING_IDENTITY_TOO_LONG, $revision);

            return $this->retain($subject, $revisionIds, null, self::FINDING_IDENTITY_TOO_LONG);
        }

        $owner = EhiDocumentValues::cell($revision, 'PAT_ID');
        if ($owner === null) {
            $context->finding(self::FINDING_ORPHAN, $revision);

            return $this->retain($subject, $revisionIds, null, self::FINDING_ORPHAN);
        }
        if ($owner !== $context->source->source_patient_key) {
            $context->finding(self::FINDING_FOREIGN_PATIENT, $revision);

            return $this->retain($subject, $revisionIds, null, self::FINDING_FOREIGN_PATIENT);
        }

        $diagnosesAvailable = ! $gaps['all'] && ! isset($gaps['keys'][$csn]);
        if (! $gaps['all'] && ! $diagnosesAvailable) {
            $context->finding(self::FINDING_DIAGNOSES_UNAVAILABLE, $revision, ['scope' => 'encounter', 'severity' => 'info']);
        }
        $built = $this->build($revision, $csn, $diagnoses[$csn] ?? [], $diagnosesAvailable, $importSource, $externalId);
        if ($built['issue'] !== null) {
            $context->finding($built['issue']['code'], $revision, $built['issue']['detail']);

            return $this->retain($subject, $revisionIds, $built['proposed'], $built['issue']['code']);
        }
        $fields = $built['fields'];

        $target = $this->resolveTarget($context, $records, $entity, $revision, $externalId);
        if ($target['state'] === 'retain') {
            return $this->retain($subject, $revisionIds, $built['proposed'], $target['reason']);
        }

        $category = (string) $fields['encounter_category'];
        if ($category === EhiEncounterCategory::UNKNOWN) {
            $context->finding(self::FINDING_UNCLASSIFIED, $revision);
        }
        if ($category !== EhiEncounterCategory::CLINICAL) {
            if ($target['state'] === 'target') {
                // A source status change never replaces an accepted assertion.
                $context->finding(self::FINDING_LINKED_NOT_CLINICAL, $revision, ['category' => $category, 'record_id' => $target['record']->id]);

                return $this->retain($subject, $revisionIds, $built['proposed'], self::FINDING_LINKED_NOT_CLINICAL);
            }

            return $this->retain($subject, $revisionIds, $built['proposed'], 'category_'.$category);
        }
        if ($fields['visit_date'] === null) {
            $context->finding(self::FINDING_MISSING_DATE, $revision);

            return $this->retain($subject, $revisionIds, $built['proposed'], self::FINDING_MISSING_DATE);
        }

        if ($target['state'] === 'target') {
            return $this->reconcile($subject, $revisionIds, $built['proposed'], $fields, $diagnosesAvailable, $built['diagnoses'], $target['record'], $target['previous'], $target['same_revision']);
        }

        $bridged = $records->bridged($csn);
        if ($bridged !== []) {
            return $this->bridgeDraft($context, $revision, $subject, $revisionIds, $built['proposed'], (string) $fields['visit_date'], $bridged);
        }

        $matches = $records->candidates((string) $fields['visit_date'], $fields['provider_name']);
        if ($matches['live']->total > 0) {
            return new EhiProposalDraft(
                subjectKey: $subject,
                kind: PhrEhiProposal::KIND_POSSIBLE_DUPLICATE,
                revisionIds: $revisionIds,
                proposed: $built['proposed'],
                candidateIds: $matches['live']->ids,
                candidateSummary: $matches['live'],
            );
        }
        if ($matches['withdrawn'] > 0) {
            $context->finding(self::FINDING_WITHDRAWN_MATCH, $revision, ['records' => $matches['withdrawn'], 'severity' => 'info']);

            return new EhiProposalDraft(
                subjectKey: $subject,
                kind: PhrEhiProposal::KIND_POSSIBLE_DUPLICATE,
                revisionIds: $revisionIds,
                proposed: [...$built['proposed'], 'withdrawn_match' => true],
            );
        }

        return new EhiProposalDraft(
            subjectKey: $subject,
            kind: PhrEhiProposal::KIND_CREATE,
            revisionIds: $revisionIds,
            proposed: $built['proposed'],
        );
    }

    /**
     * @param  list<array{id: int, ambiguous: bool, line: string, entry: array<string, string|bool|null>}>  $diagnoses
     * @return array{fields: array<string, string|null>, diagnoses: list<array<string, string|bool|null>>|null, proposed: array<string, mixed>, issue: array{code: string, detail: array<string, int|string|bool|null>}|null}
     */
    private function build(PhrEhiRevision $revision, string $csn, array $diagnoses, bool $diagnosesAvailable, string $importSource, string $externalId): array
    {
        $issue = null;
        $raise = static function (string $code, array $detail = []) use (&$issue): void {
            $issue ??= ['code' => $code, 'detail' => $detail];
        };
        $cell = static fn (string $column): ?string => self::trimmed(EhiDocumentValues::cell($revision, $column));

        $dateText = $cell('CONTACT_DATE') ?? $cell('EFFECTIVE_DATE_DT');
        $visitDate = null;
        if ($dateText !== null) {
            $parsed = EhiDocumentValues::dateTime($dateText);
            if ($parsed === null) {
                $raise(self::FINDING_UNPARSEABLE_DATE, ['column' => 'CONTACT_DATE']);
            } else {
                $visitDate = $parsed['date'];
            }
        }
        $started = $this->timeField($raise, [['HOSP_ADMSN_TIME', $cell('HOSP_ADMSN_TIME')], ['EFFECTIVE_DATE_DTTM', $cell('EFFECTIVE_DATE_DTTM')]]);
        $ended = $this->timeField($raise, [['HOSP_DISCHRG_TIME', $cell('HOSP_DISCHRG_TIME')]]);
        $visitDate ??= $started['value'] === null ? null : substr($started['value'], 0, 10);

        $type = $cell('ENC_TYPE_C_NAME');
        $status = $cell('APPT_STATUS_C_NAME');
        $fields = [
            'visit_date' => $visitDate,
            'visit_date_source' => $dateText,
            'visit_started_at' => $started['value'],
            'visit_started_source' => $started['source'],
            'visit_started_precision' => $started['precision'],
            'visit_started_timezone' => $started['timezone'],
            'visit_ended_at' => $ended['value'],
            'visit_ended_source' => $ended['source'],
            'visit_ended_precision' => $ended['precision'],
            'visit_ended_timezone' => $ended['timezone'],
            'visit_type' => $type,
            'encounter_category' => EhiEncounterCategory::of($type, $status, $cell('CALCULATED_ENC_STAT_C_NAME'), $cell('HOSP_ADMSN_TIME') !== null),
            'encounter_status' => $status,
            'provider_name' => $cell('VISIT_PROV_ID_PROV_NAME'),
            'facility_name' => $cell('DEPARTMENT_ID_EXTERNAL_NAME') ?? $cell('PRIMARY_LOC_ID_LOC_NAME'),
            'raw_text' => $this->summary($cell),
        ];
        foreach (self::FIELD_LIMITS as $field => $limit) {
            if ($fields[$field] !== null && mb_strlen($fields[$field]) > $limit) {
                $raise(self::FINDING_VALUE_TOO_LONG, ['field' => $field, 'limit' => $limit]);
            }
        }

        $ordered = null;
        if (! $diagnosesAvailable) {
            $diagnoses = [];
        }
        foreach ($diagnoses as $diagnosis) {
            if ($diagnosis['ambiguous'] || ! EhiDocumentValues::isExactInteger($diagnosis['line'])) {
                $raise(self::FINDING_UNORDERED_DIAGNOSES, ['table' => self::TABLE_DIAGNOSES]);
                $diagnoses = [];
                break;
            }
        }
        if ($diagnoses !== []) {
            usort($diagnoses, static fn (array $a, array $b): int => EhiDocumentValues::compareIntegers($a['line'], $b['line']));
            $ordered = array_column($diagnoses, 'entry');
            if (strlen(json_encode($ordered, JSON_THROW_ON_ERROR)) > self::MAX_DIAGNOSES_BYTES) {
                $raise(self::FINDING_DIAGNOSES_TOO_LARGE, ['count' => count($ordered)]);
                $ordered = null;
            }
        }

        return [
            'fields' => $fields,
            'diagnoses' => $ordered,
            'proposed' => [
                'attributes' => [
                    'import_source' => $importSource,
                    'external_id' => $externalId,
                    'review_status' => PhrReviewStatus::CONFIRMED,
                    ...$fields,
                    // Named on the encounter only, and PAT_ENC_DX has no ICD-10 column.
                    ...($diagnosesAvailable ? ['encounter_diagnoses' => $ordered] : []),
                    'icd10_codes' => null,
                ],
                'ehi' => [
                    'table' => self::TABLE_ENCOUNTERS,
                    'csn' => $csn,
                    'contact_date_real' => $cell('PAT_ENC_DATE_REAL'),
                    'diagnosis_lines' => count($ordered ?? []),
                    'diagnoses_unavailable' => ! $diagnosesAvailable,
                ],
            ],
            'issue' => $issue,
        ];
    }

    /**
     * The first non-empty column in order, its text kept even when it cannot be read.
     *
     * @param  callable(string, array<string, int|string|bool|null>): void  $raise
     * @param  list<array{0: string, 1: string|null}>  $columns
     * @return array{value: string|null, source: string|null, precision: string|null, timezone: string|null}
     */
    private function timeField(callable $raise, array $columns): array
    {
        foreach ($columns as [$column, $text]) {
            if ($text === null) {
                continue;
            }
            $parsed = EhiDocumentValues::dateTime($text);
            if ($parsed === null) {
                $raise(self::FINDING_UNPARSEABLE_DATE, ['column' => $column]);

                return ['value' => null, 'source' => $text, 'precision' => null, 'timezone' => null];
            }

            return [
                'value' => $parsed['date'].' '.($parsed['time'] ?? '00:00:00'),
                'source' => $text,
                'precision' => $parsed['precision'],
                'timezone' => self::TIMEZONE_LOCAL,
            ];
        }

        return ['value' => null, 'source' => null, 'precision' => null, 'timezone' => null];
    }

    /** @param  callable(string): ?string  $cell */
    private function summary(callable $cell): string
    {
        $lines = ['Epic EHI '.self::TABLE_ENCOUNTERS];
        foreach (self::SUMMARY_COLUMNS as $column) {
            $value = $cell($column);
            if ($value !== null) {
                $lines[] = $column.': '.preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{state: 'none'}|array{state: 'retain', reason: string}|array{state: 'target', record: PhrOfficeVisit, previous: array<string, mixed>|null, same_revision: bool}
     */
    private function resolveTarget(EhiMappingContext $context, EhiEncounterRecordIndex $records, PhrEhiEntity $entity, PhrEhiRevision $revision, string $externalId): array
    {
        $links = collect($records->linksOf($entity->id));
        $linkedIds = $links->map(static fn (PhrEhiRecordLink $link): int => (int) $link->office_visit_id)->unique()->values();

        if ($linkedIds->count() > 1) {
            $context->finding(self::FINDING_AMBIGUOUS_LINK, $revision, ['records' => $linkedIds->count()]);

            return ['state' => 'retain', 'reason' => self::FINDING_AMBIGUOUS_LINK];
        }
        if ($linkedIds->count() === 1) {
            $record = $records->linkedRecord((int) $linkedIds->first());
            if ($record === null || ! PhrRecordLifecycle::stands($record)) {
                $context->finding(self::FINDING_LINKED_RECORD_WITHDRAWN, $revision, [
                    'lifecycle' => $record === null ? 'missing' : PhrRecordLifecycle::of($record),
                    'review_status' => $record?->review_status,
                ]);

                return ['state' => 'retain', 'reason' => self::FINDING_LINKED_RECORD_WITHDRAWN];
            }
            $sameRevision = $links->contains(static fn (PhrEhiRecordLink $link): bool => (int) $link->revision_id === $revision->id);

            return ['state' => 'target', 'record' => $record, 'previous' => $records->decidedAttributes($entity->key_digest), 'same_revision' => $sameRevision];
        }

        $record = $records->heldIdentity($externalId);
        if ($record === null) {
            return ['state' => 'none'];
        }
        if (! PhrRecordLifecycle::stands($record)) {
            $context->finding(self::FINDING_IDENTITY_WITHDRAWN, $revision, ['lifecycle' => PhrRecordLifecycle::of($record), 'review_status' => $record->review_status]);

            return ['state' => 'retain', 'reason' => self::FINDING_IDENTITY_WITHDRAWN];
        }
        $context->finding(self::FINDING_MATCHED_EXTERNAL_IDENTITY, $revision, ['severity' => 'info']);

        return ['state' => 'target', 'record' => $record, 'previous' => null, 'same_revision' => false];
    }

    /**
     * Corroborate when nothing would change; otherwise an update over the fields the
     * source changed that the record does not already hold, judged against what the
     * source said at the last accepted or linked decision: a diagnosis can change while
     * the encounter row stays identical. What a person edited and the source did not
     * change is left alone. Diagnoses are compared as a whole list.
     *
     * @param  list<int>  $revisionIds
     * @param  array<string, mixed>  $proposed
     * @param  array<string, string|null>  $fields
     * @param  list<array<string, string|bool|null>>|null  $diagnoses
     * @param  array<string, mixed>|null  $previous
     */
    private function reconcile(string $subject, array $revisionIds, array $proposed, array $fields, bool $diagnosesAvailable, ?array $diagnoses, PhrOfficeVisit $record, ?array $previous, bool $sameRevision): EhiProposalDraft
    {
        $changed = [];
        $before = [];
        $current = $this->currentFields($record);
        $next = $diagnosesAvailable
            ? [...$fields, 'encounter_diagnoses' => $diagnoses === null ? null : EhiCanonicalJson::encode($diagnoses)]
            : $fields;
        foreach ($next as $field => $value) {
            $earlier = $previous[$field] ?? null;
            $earlier = is_array($earlier) ? EhiCanonicalJson::encode($earlier) : (is_string($earlier) ? $earlier : null);
            // A decision that could not vouch for a field says nothing about whether it changed.
            $sourceChanged = $previous === null ? ! $sameRevision : (! array_key_exists($field, $previous) || $earlier !== $value);
            if ($sourceChanged && $current[$field] !== $value) {
                $changed[] = $field;
                $before[$field] = $field === 'encounter_diagnoses' ? $record->encounter_diagnoses : $current[$field];
            }
        }

        if ($changed === []) {
            return new EhiProposalDraft($subject, PhrEhiProposal::KIND_CORROBORATE, $revisionIds, $proposed, self::RECORD_TABLE, $record->id);
        }

        return new EhiProposalDraft($subject, PhrEhiProposal::KIND_UPDATE, $revisionIds, [...$proposed, 'changed' => $changed], self::RECORD_TABLE, $record->id, $before);
    }

    /**
     * @param  non-empty-list<array{id: int, source: string|null, provider: string|null, date: string|null, live: bool, rule: string|null}>  $bridged
     * @param  list<int>  $revisionIds
     * @param  array<string, mixed>  $proposed
     */
    private function bridgeDraft(EhiMappingContext $context, PhrEhiRevision $revision, string $subject, array $revisionIds, array $proposed, string $date, array $bridged): EhiProposalDraft
    {
        $live = array_values(array_filter($bridged, static fn (array $row): bool => $row['live']));
        if ($live === []) {
            $context->finding(self::FINDING_BRIDGE_WITHDRAWN, $revision, ['records' => count($bridged)]);

            return $this->retain($subject, $revisionIds, $proposed, self::FINDING_BRIDGE_WITHDRAWN);
        }
        $ids = array_map(static fn (array $row): int => $row['id'], $live);
        sort($ids);
        $bridge = EhiSourceIdBridge::matched((string) $live[0]['rule']);
        if (count($live) > 1) {
            $context->finding(self::FINDING_BRIDGE_AMBIGUOUS, $revision, ['records' => count($live)]);

            return new EhiProposalDraft($subject, PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $revisionIds, [...$proposed, 'bridge' => $bridge], candidateIds: $ids);
        }
        // A FHIR instant can be a day off the source's local date, which is not a conflict.
        $days = $live[0]['date'] === null ? 0 : abs(strtotime($live[0]['date']) - strtotime($date)) / 86400;
        if ($days > ($live[0]['source'] === 'fhir' ? 1 : 0)) {
            $context->finding(self::FINDING_BRIDGE_CONFLICT, $revision, ['conflicts' => 'date']);

            return new EhiProposalDraft($subject, PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $revisionIds, [...$proposed, 'bridge' => [...$bridge, 'conflicts' => ['date']]], candidateIds: $ids);
        }

        return new EhiProposalDraft(
            subjectKey: $subject,
            kind: PhrEhiProposal::KIND_CORROBORATE,
            revisionIds: $revisionIds,
            proposed: [...$proposed, 'bridge' => $bridge],
            targetTable: self::RECORD_TABLE,
            targetId: $live[0]['id'],
        );
    }

    /**
     * @param  list<int>  $revisionIds
     * @param  array<string, mixed>|null  $proposed
     */
    private function retain(string $subject, array $revisionIds, ?array $proposed, string $reason): EhiProposalDraft
    {
        return new EhiProposalDraft(
            subjectKey: $subject,
            kind: PhrEhiProposal::KIND_RETAIN,
            revisionIds: $revisionIds,
            proposed: $proposed === null ? ['retain_reason' => $reason] : [...$proposed, 'retain_reason' => $reason],
        );
    }

    private function applyCreate(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome
    {
        $patient = $context->patient;
        $attributes = $this->attributesOf($proposal);
        $package = PhrEhiPackage::query()->where('patient_id', $patient->id)->find($proposal->package_id);
        $importSource = $attributes['import_source'] ?? null;
        $externalId = $attributes['external_id'] ?? null;
        if (! $package instanceof PhrEhiPackage || $package->source_id === null
            || $importSource !== self::importSource((int) $package->source_id)
            || ! is_string($externalId) || ! str_starts_with($externalId, self::TABLE_ENCOUNTERS.':') || strlen($externalId) > self::MAX_EXTERNAL_ID_BYTES
            || ($attributes['encounter_category'] ?? null) !== EhiEncounterCategory::CLINICAL) {
            throw new EhiEncounterApplyRefused(EhiEncounterApplyRefused::MALFORMED_PROPOSAL, 'the proposal does not carry a usable import identity and clinical category');
        }

        $identity = ['patient_id' => $patient->id, 'import_source' => $importSource, 'external_id' => $externalId];
        $this->refuseHeldIdentity($identity);
        try {
            $record = PhrOfficeVisit::query()->create([
                ...$identity,
                'user_id' => $patient->owner_user_id,
                'review_status' => PhrReviewStatus::CONFIRMED,
                ...$this->writable($attributes),
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->refuseHeldIdentity($identity);

            throw new EhiEncounterApplyRefused(EhiEncounterApplyRefused::IDENTITY_EXISTS, 'a record already holds this import identity');
        }

        return new EhiApplyOutcome(self::RECORD_TABLE, $record->id, PhrEhiRecordLink::KIND_PROJECTED);
    }

    private function applyUpdate(PhrEhiProposal $proposal): EhiApplyOutcome
    {
        $record = $this->lockedLiveTarget($proposal, $proposal->target_table === self::RECORD_TABLE ? $proposal->target_id : null);
        $expected = $proposal->target_digest;
        if (! is_string($expected) || ! hash_equals($expected, EhiTargetDigest::of($record))) {
            throw new EhiEncounterApplyRefused(EhiEncounterApplyRefused::TARGET_CHANGED, 'the target changed since the proposal was previewed');
        }

        $changed = $proposal->proposed_json['changed'] ?? null;
        $writable = $this->writable($this->attributesOf($proposal));
        $changes = array_intersect_key($writable, array_flip(is_array($changed) ? array_filter($changed, 'is_string') : []));
        if ($changes === []) {
            throw new EhiEncounterApplyRefused(EhiEncounterApplyRefused::MALFORMED_PROPOSAL, 'the update proposes no applicable mapped field');
        }
        // A changed date brings what the source says about it, unchanged or not.
        foreach (PhrOfficeVisit::DATE_PROVENANCE as $column => $provenance) {
            if (array_key_exists($column, $changes)) {
                $changes = [...$changes, ...array_intersect_key($writable, array_flip($provenance))];
            }
        }
        $record->fill($changes);
        $record->save();

        return new EhiApplyOutcome(self::RECORD_TABLE, $record->id, PhrEhiRecordLink::KIND_PROJECTED);
    }

    private function lockedLiveTarget(PhrEhiProposal $proposal, ?int $recordId): PhrOfficeVisit
    {
        $record = $recordId === null ? null : $this->withdrawnInclusiveQuery()
            ->where('patient_id', (int) $proposal->patient_id)
            ->whereKey($recordId)
            ->lockForUpdate()
            ->first();
        if (! $record instanceof PhrOfficeVisit) {
            throw new EhiEncounterApplyRefused(EhiEncounterApplyRefused::TARGET_NOT_FOUND, "the target is not this patient's visit");
        }
        if (! PhrRecordLifecycle::stands($record)) {
            throw new EhiEncounterApplyRefused(EhiEncounterApplyRefused::TARGET_WITHDRAWN, 'the target was deleted, retracted, or rejected');
        }

        return $record;
    }

    /** @param  array{patient_id: int, import_source: string, external_id: string}  $identity */
    private function refuseHeldIdentity(array $identity): void
    {
        $existing = $this->withdrawnInclusiveQuery()->where($identity)->lockForUpdate()->first();
        if (! $existing instanceof PhrOfficeVisit) {
            return;
        }
        if (PhrRecordLifecycle::of($existing) !== PhrRecordLifecycle::ACTIVE) {
            throw new EhiEncounterApplyRefused(EhiEncounterApplyRefused::IDENTITY_WITHDRAWN, 'the import identity is no longer available for this patient');
        }

        throw new EhiEncounterApplyRefused(EhiEncounterApplyRefused::IDENTITY_EXISTS, 'a record already holds this import identity');
    }

    /** @return Builder<PhrOfficeVisit> */
    private function withdrawnInclusiveQuery(): Builder
    {
        return PhrOfficeVisit::query()->withoutGlobalScope(SoftDeletingScope::class);
    }

    /** @return array<string, mixed> */
    private function attributesOf(PhrEhiProposal $proposal): array
    {
        $attributes = $proposal->proposed_json['attributes'] ?? null;
        if (! is_array($attributes)) {
            throw new EhiEncounterApplyRefused(EhiEncounterApplyRefused::MALFORMED_PROPOSAL, 'the proposal carries no proposed attributes');
        }

        return $attributes;
    }

    /**
     * The mapped text fields and the diagnoses list.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function writable(array $attributes): array
    {
        $mapped = [];
        foreach (self::MAPPED_FIELDS as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }
            $value = $attributes[$field];
            if ($value !== null && ! is_string($value)) {
                throw new EhiEncounterApplyRefused(EhiEncounterApplyRefused::MALFORMED_PROPOSAL, 'a proposed field is not text');
            }
            $mapped[$field] = $value;
        }
        if (array_key_exists('encounter_diagnoses', $attributes)) {
            $diagnoses = $attributes['encounter_diagnoses'];
            if ($diagnoses !== null && ! is_array($diagnoses)) {
                throw new EhiEncounterApplyRefused(EhiEncounterApplyRefused::MALFORMED_PROPOSAL, 'the diagnoses are not a list');
            }
            $mapped['encounter_diagnoses'] = $diagnoses;
        }

        return $mapped;
    }

    /** @return array<string, string|null> */
    private function currentFields(PhrOfficeVisit $record): array
    {
        $current = [];
        foreach (self::MAPPED_FIELDS as $field) {
            $value = match ($field) {
                'visit_date' => $record->visit_date?->format('Y-m-d'),
                'visit_started_at' => $record->visit_started_at?->format('Y-m-d H:i:s'),
                'visit_ended_at' => $record->visit_ended_at?->format('Y-m-d H:i:s'),
                default => $record->getAttribute($field),
            };
            $current[$field] = $value === null ? null : (string) $value;
        }
        $current['encounter_diagnoses'] = $record->encounter_diagnoses === null ? null : EhiCanonicalJson::encode($record->encounter_diagnoses);

        return $current;
    }

    private function yesNo(?string $raw): ?bool
    {
        return match ($raw === null ? null : strtolower(trim($raw))) {
            'y', 'yes' => true,
            'n', 'no' => false,
            default => null,
        };
    }

    private static function trimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
