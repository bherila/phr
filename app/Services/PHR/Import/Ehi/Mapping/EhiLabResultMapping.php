<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

use App\Models\PhrEhiEntity;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Models\PhrLabResult;
use App\Services\PHR\Import\Ehi\Mapping\Document\EhiDocumentValues;
use App\Services\PHR\Import\Ehi\Mapping\Lab\EhiLabApplyRefused;
use App\Services\PHR\Import\Ehi\Mapping\Lab\EhiLabEvidence;
use App\Services\PHR\Import\Ehi\Mapping\Lab\EhiLabRecordIndex;
use App\Services\PHR\Import\Ehi\Mapping\Lab\EhiLabValue;
use App\Support\PHR\PhrRecordLifecycle;
use App\Support\PHR\PhrReviewStatus;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Epic EHI `ORDER_RESULTS` rows to lab result proposals (issue #213, M3).
 *
 * Source tables, verified against the public spec (open.epic.com/EHITables) on
 * 2026-11-02. Projection re-checks each key against the package's own dictionary,
 * in this column order, and withholds a table that disagrees, so nothing below is
 * assumed universal.
 *
 * - ORDER_RESULTS, PK [ORDER_PROC_ID, ORD_DATE_REAL, LINE]. One component of one
 *   order contact. ORD_DATE_REAL is a FLOAT in the spec whose integer part is a
 *   date and whose fraction separates same-day contacts: it is an exact string
 *   here, never a number and never a time. Two contacts of one order and line are
 *   two results.
 * - ORDER_PROC, PK [ORDER_PROC_ID]. PAT_ID owns the order; the order's name, type,
 *   status, and authorizing provider come from it.
 * - ORDER_RES_COMMENT, PK [ORDER_ID, CONTACT_DATE_REAL, LINE, LINE_COMMENT]. Comment
 *   overflow, one row per line, ordered by numeric LINE_COMMENT.
 * - ORDER_NARRATIVE, PK [ORDER_PROC_ID, ORD_DATE_REAL, LINE]. Narrative of an order
 *   contact, ordered by numeric LINE. Recorded once, on that contact's lowest LINE
 *   component, since it describes the order rather than one component.
 * - PAT_ENC, PK [PAT_ENC_CSN_ID]. A second, optional ownership check.
 *
 * Ownership: a result is this patient's only when its order or its encounter names
 * the bound source patient key and neither names another. Otherwise it is retained
 * with a finding.
 *
 * Column to field:
 *   DISPLAY_NAME, else DESCRIPTION, else PROC_ID_PROC_NAME (ORDER_PROC) -> test_name
 *   COMPONENT_ID_NAME -> analyte; COMPONENT_ID -> analyte_code (ANALYTE_CODE_SYSTEM)
 *   ORD_VALUE -> value (verbatim); ORD_RAW_VALUE -> value_raw (verbatim)
 *   ORD_VALUE + VALUE_NORMALIZED -> value_comparator, value_numeric (EhiLabValue)
 *   REFERENCE_UNIT -> unit
 *   REFERENCE_LOW, REFERENCE_HIGH -> range_min, range_max when exact decimals
 *   REF_NORMAL_VALS, else non-decimal low/high text -> reference_range_text
 *   RESULT_FLAG_C_NAME -> abnormal_flag
 *   RESULT_STATUS_C_NAME, else LAB_STATUS_C_NAME -> result_status
 *   COMPONENT_COMMENT then ORDER_RES_COMMENT lines -> result_comment
 *   ORDER_NARRATIVE lines -> notes
 *   AUTHRZING_PROV_ID_PROV_NAME -> ordering_provider
 *   RESULTING_LAB_ID_LLB_NAME -> resulting_lab
 *   COMP_OBS_INST_TM (Local) -> collection_datetime, collected_at_*
 *   COMP_ANL_INST_TM (Local), else RESULT_DATE (no zone), else RESULT_TIME (Local,
 *     ORDER_PROC) -> result_datetime, resulted_at_*
 *
 * Dates: the datetime columns hold the source's wall clock as written, never
 * shifted through any timezone, and *_timezone says what the spec documents it as.
 * A date with no time reads as midnight with precision "date". Exports state no
 * offset for such a time (PhrLabResult::timeZoneIsUnrecorded).
 *
 * A row with no value and no comment, or of a canceled order, or whose status is
 * Incomplete, In process, or unrecognized, is retained rather than mapped: the
 * status is still in its proposal, and its bytes in the package. Microbiology
 * sensitivities and organism columns have no mapping yet. A value or date outside
 * what the target columns hold exactly is retained, never truncated or rounded.
 *
 * Reconciliation, strongest evidence first:
 *  1. An evidence link, or this source's own import identity: unchanged content
 *     corroborates, changed content is an explicit before/after update.
 *  2. A rule of EhiSourceIdBridge: corroborates by linking evidence. If the
 *     clinical values disagree it is a possible duplicate naming the conflict.
 *  3. A probable duplicate (EhiLabRecordIndex): a person decides.
 *  4. Otherwise new.
 * No outcome writes without a decision. A deleted, retracted, or rejected record
 * is never a target; one that holds the identity or a matching bridge retains the
 * row, and one that only looks the same makes the row a possible duplicate, so
 * bulk acceptance cannot quietly undo a person's removal.
 */
final class EhiLabResultMapping implements EhiMapping
{
    public const string NAME = 'lab_result';

    public const string VERSION = 'ehi-lab-1';

    public const string TABLE_RESULTS = 'ORDER_RESULTS';

    public const string TABLE_ORDERS = 'ORDER_PROC';

    public const string TABLE_COMMENTS = 'ORDER_RES_COMMENT';

    public const string TABLE_NARRATIVE = 'ORDER_NARRATIVE';

    public const string TABLE_ENCOUNTERS = 'PAT_ENC';

    public const string RECORD_TABLE = 'phr_lab_results';

    public const string SOURCE = 'ehi_tables';

    public const string ANALYTE_CODE_SYSTEM = 'epic-component';

    public const string TIMEZONE_LOCAL = 'local';

    public const string TIMEZONE_UNSPECIFIED = 'unspecified';

    public const string FINDING_ORPHAN = 'orphan_lab_result';

    public const string FINDING_FOREIGN_PATIENT = 'foreign_patient_lab_result';

    public const string FINDING_AMBIGUOUS_REVISION = 'ambiguous_lab_revision';

    public const string FINDING_AMBIGUOUS_LINK = 'ambiguous_lab_link';

    public const string FINDING_IDENTITY_TOO_LONG = 'lab_identity_too_long';

    public const string FINDING_MISSING_ANALYTE = 'missing_lab_analyte';

    public const string FINDING_VALUE_TOO_LONG = 'value_too_long';

    public const string FINDING_UNPARSEABLE_DATE = 'unparseable_date';

    public const string FINDING_UNORDERED_LINES = 'unordered_lab_lines';

    public const string FINDING_UNKNOWN_STATUS = 'unknown_lab_status';

    public const string FINDING_UNKNOWN_ORDER_TYPE = 'unknown_lab_order_type';

    public const string FINDING_COMPARATOR_CONFLICT = 'lab_comparator_conflict';

    public const string FINDING_NORMALIZED_UNRECOGNIZED = 'lab_normalized_value_unrecognized';

    public const string FINDING_LINKED_RECORD_WITHDRAWN = 'linked_record_withdrawn';

    public const string FINDING_IDENTITY_WITHDRAWN = 'lab_identity_withdrawn';

    public const string FINDING_MATCHED_EXTERNAL_IDENTITY = 'matched_external_identity';

    public const string FINDING_LINKED_NOT_CLINICAL = 'linked_lab_status_not_clinical';

    public const string FINDING_BRIDGE_CONFLICT = 'lab_bridge_conflict';

    public const string FINDING_BRIDGE_AMBIGUOUS = 'lab_bridge_ambiguous';

    public const string FINDING_BRIDGE_WITHDRAWN = 'lab_bridge_target_withdrawn';

    public const string FINDING_WITHDRAWN_MATCH = 'lab_withdrawn_match';

    public const string FINDING_ORDERS_WITHOUT_RESULTS = 'orders_without_results';

    public const string FINDING_UNMATCHED_COMMENTS = 'unmatched_component_comments';

    public const string FINDING_UNMATCHED_NARRATIVE = 'unmatched_order_narrative';

    /** What a proposal may write; update diffs and applies cover only these. */
    public const array MAPPED_FIELDS = [
        'test_name',
        'analyte',
        'analyte_code',
        'analyte_code_system',
        'value',
        'value_raw',
        'value_comparator',
        'value_numeric',
        'unit',
        'range_min',
        'range_max',
        'reference_range_text',
        'abnormal_flag',
        'result_status',
        'result_comment',
        'notes',
        'ordering_provider',
        'resulting_lab',
        'collection_datetime',
        'collected_at_source',
        'collected_at_precision',
        'collected_at_timezone',
        'result_datetime',
        'resulted_at_source',
        'resulted_at_precision',
        'resulted_at_timezone',
    ];

    /** The decimal(18, 10) columns, which compare by value rather than spelling. */
    private const array DECIMAL_FIELDS = ['value_numeric', 'range_min', 'range_max'];

    /** Clinical values a bridged record must agree on, or the match is a conflict. */
    private const array BRIDGE_COMPARED = ['value', 'unit', 'date'];

    /** phr_lab_results column limits. */
    private const array FIELD_LIMITS = [
        'test_name' => 255,
        'analyte' => 100,
        'analyte_code' => 64,
        'value' => 255,
        'value_raw' => 255,
        'unit' => 50,
        'reference_range_text' => 255,
        'abnormal_flag' => 50,
        'result_status' => 50,
        'ordering_provider' => 100,
        'resulting_lab' => 100,
        'collected_at_source' => 64,
        'resulted_at_source' => 64,
    ];

    /** phr_lab_results.external_id */
    private const int MAX_EXTERNAL_ID_BYTES = 255;

    /** phr_lab_results.notes is `text`, 65,535 bytes on MariaDB. */
    private const int MAX_NOTES_BYTES = 60000;

    /** Documented statuses that assert a result. */
    private const array CLINICAL_STATUSES = [
        'preliminary', 'final', 'corrected',
        'preliminary result', 'final result', 'edited', 'edited result - final',
    ];

    /** Documented statuses that do not yet assert one. */
    private const array NON_CLINICAL_STATUSES = ['incomplete', 'in process'];

    /** Documented order statuses under which no result stands. */
    private const array WITHDRAWN_ORDER_STATUSES = ['canceled', 'denied approval'];

    /**
     * Order types whose results are laboratory values. ORDER_TYPE_C_NAME may hold
     * organization-specific values, so an unlisted one is retained, not guessed at.
     * An order with no type is mapped: ORDER_RESULTS is documented as result rows.
     */
    private const array LAB_ORDER_TYPES = ['lab', 'laboratory', 'microbiology', 'pathology', 'blood bank', 'point of care testing'];

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
            self::TABLE_RESULTS => ['ORDER_PROC_ID', 'ORD_DATE_REAL', 'LINE'],
            self::TABLE_ORDERS => ['ORDER_PROC_ID'],
            self::TABLE_COMMENTS => ['ORDER_ID', 'CONTACT_DATE_REAL', 'LINE', 'LINE_COMMENT'],
            self::TABLE_NARRATIVE => ['ORDER_PROC_ID', 'ORD_DATE_REAL', 'LINE'],
            self::TABLE_ENCOUNTERS => ['PAT_ENC_CSN_ID'],
        ];
    }

    public static function importSource(int $sourceId): string
    {
        return 'ehi:'.$sourceId;
    }

    /**
     * The full key, each part percent-encoded so a part holding ":" cannot collide
     * with another key. Digits and "." are unchanged, so the contact key stays as
     * the source wrote it.
     */
    public static function externalId(string $orderProcId, string $contact, string $line): string
    {
        return self::TABLE_RESULTS.':'.rawurlencode($orderProcId).':'.rawurlencode($contact).':'.rawurlencode($line);
    }

    /** @return Generator<int, EhiProposalDraft> */
    public function propose(EhiMappingContext $context): iterable
    {
        $evidence = EhiLabEvidence::load($context, self::TABLE_ORDERS, self::TABLE_ENCOUNTERS, self::TABLE_COMMENTS, self::TABLE_NARRATIVE);
        $importSource = self::importSource($context->source->id);
        $records = new EhiLabRecordIndex($context, $importSource, self::TABLE_RESULTS);
        $narrativeOwners = $this->narrativeOwners($context);

        $group = [];
        foreach ($context->lazyRevisions(self::TABLE_RESULTS, withEntity: true) as $revision) {
            if ($group !== [] && $group[0]->entity_id !== $revision->entity_id) {
                yield $this->draft($context, $evidence, $records, $group, $importSource, $narrativeOwners);
                $group = [];
            }
            $group[] = $revision;
        }
        if ($group !== []) {
            yield $this->draft($context, $evidence, $records, $group, $importSource, $narrativeOwners);
        }

        foreach ([
            self::FINDING_ORDERS_WITHOUT_RESULTS => $evidence->unusedOrderCount(),
            self::FINDING_UNMATCHED_COMMENTS => $evidence->unmatchedCommentCount(),
            self::FINDING_UNMATCHED_NARRATIVE => $evidence->unmatchedNarrativeCount(),
        ] as $code => $count) {
            if ($count > 0) {
                $context->finding($code, null, ['count' => $count, 'severity' => 'info']);
            }
        }
    }

    public function apply(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome
    {
        if ((int) $proposal->patient_id !== $context->patient->id
            || $proposal->mapping !== self::NAME
            || $proposal->mapping_version !== self::VERSION) {
            throw new EhiLabApplyRefused(EhiLabApplyRefused::WRONG_SCOPE, 'the proposal does not belong to this patient and mapping version');
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
            default => throw new EhiLabApplyRefused(EhiLabApplyRefused::DECISION_NOT_APPLICABLE, 'the decision does not apply to this proposal kind'),
        };
    }

    /**
     * For each order contact, the LINE its narrative is recorded on: the lowest
     * numeric LINE of this package's components. Non-numeric lines sort last.
     *
     * @return array<string, string> "order\0contact" => line
     */
    private function narrativeOwners(EhiMappingContext $context): array
    {
        $owners = [];
        foreach ($context->lazyRevisions(self::TABLE_RESULTS) as $revision) {
            $contact = (string) EhiDocumentValues::cell($revision, 'ORDER_PROC_ID')."\0".(string) EhiDocumentValues::cell($revision, 'ORD_DATE_REAL');
            $line = (string) EhiDocumentValues::cell($revision, 'LINE');
            if (! isset($owners[$contact]) || EhiDocumentValues::compareIntegers($line, $owners[$contact]) < 0) {
                $owners[$contact] = $line;
            }
        }

        return $owners;
    }

    /**
     * @param  non-empty-list<PhrEhiRevision>  $revisions  every revision of one entity this package supplied
     * @param  array<string, string>  $narrativeOwners
     */
    private function draft(EhiMappingContext $context, EhiLabEvidence $evidence, EhiLabRecordIndex $records, array $revisions, string $importSource, array $narrativeOwners): EhiProposalDraft
    {
        $context->budget->checkpoint();
        $revision = $revisions[0];
        /** @var PhrEhiEntity $entity */
        $entity = $revision->entity;
        $subject = $entity->key_digest;
        $key = (array) $entity->key_json;
        $orderProcId = is_string($key['ORDER_PROC_ID'] ?? null) ? $key['ORDER_PROC_ID'] : '';
        $contact = is_string($key['ORD_DATE_REAL'] ?? null) ? $key['ORD_DATE_REAL'] : '';
        $line = is_string($key['LINE'] ?? null) ? $key['LINE'] : '';
        $revisionIds = array_map(static fn (PhrEhiRevision $row): int => $row->id, $revisions);

        if (count($revisions) > 1) {
            $context->finding(self::FINDING_AMBIGUOUS_REVISION, $revision, ['revisions' => count($revisions)]);

            return $this->retain($subject, $revisionIds, null, self::FINDING_AMBIGUOUS_REVISION);
        }
        $externalId = self::externalId($orderProcId, $contact, $line);
        if (strlen($externalId) > self::MAX_EXTERNAL_ID_BYTES) {
            $context->finding(self::FINDING_IDENTITY_TOO_LONG, $revision);

            return $this->retain($subject, $revisionIds, null, self::FINDING_IDENTITY_TOO_LONG);
        }

        $order = $evidence->order($orderProcId);
        $ownership = $this->ownership($context, $evidence, $revision, $order, $revisionIds);
        if ($ownership['verdict'] !== 'owned') {
            return $this->retain($subject, $revisionIds, null, $ownership['verdict']);
        }

        $built = $this->build($context, $evidence, $revision, $order, $orderProcId, $contact, $line, $narrativeOwners, $importSource, $externalId, $ownership['via'], $revisionIds);
        if ($built['issue'] !== null) {
            $context->finding($built['issue']['code'], $revision, $built['issue']['detail']);

            return $this->retain($subject, $revisionIds, $built['proposed'], $built['issue']['code']);
        }
        $fields = $built['fields'];

        $target = $this->resolveTarget($context, $records, $entity, $revision, $externalId);
        if ($target['state'] === 'retain') {
            return $this->retain($subject, $revisionIds, $built['proposed'], $target['reason']);
        }

        // Guarded before any target is reconciled: a withdrawn order or an
        // unfinished status never updates a record, which would stay active
        // while its source no longer asserts it.
        $notClinical = $this->nonClinicalReason($context, $revision, $order, $fields);
        if ($notClinical !== null) {
            if ($target['state'] === 'target') {
                $context->finding(self::FINDING_LINKED_NOT_CLINICAL, $revision, ['reason' => $notClinical, 'record_id' => $target['record']->id]);

                return $this->retain($subject, $revisionIds, $built['proposed'], self::FINDING_LINKED_NOT_CLINICAL);
            }

            return $this->retain($subject, $revisionIds, $built['proposed'], $notClinical);
        }

        if ($target['state'] === 'target') {
            return $this->reconcile($subject, $revisionIds, $built['proposed'], $fields, $target['record'], $target['previous'], $target['same_revision']);
        }

        $analytes = $built['analytes'];
        $bridged = $records->bridged($orderProcId, $analytes);
        if ($bridged !== []) {
            return $this->bridgeDraft($context, $revision, $subject, $revisionIds, $built['proposed'], $fields, $bridged);
        }

        $dates = $this->dates($fields);
        $matches = $records->candidates($analytes, $dates, $fields['value'], $built['number']);
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
            // Nothing to link, but a person removed what looks like this result:
            // accepting it stays their explicit choice, never part of a bulk accept.
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
     * @param  array{id: int, ambiguous: bool, values: array<string, string|null>}|null  $order
     * @param  list<int>  $revisionIds
     * @return array{verdict: string, via: list<string>}
     */
    private function ownership(EhiMappingContext $context, EhiLabEvidence $evidence, PhrEhiRevision $revision, ?array $order, array &$revisionIds): array
    {
        $patientKey = $context->source->source_patient_key;
        $via = [];
        $foreign = false;
        $ambiguous = false;

        if ($order !== null) {
            $revisionIds[] = $order['id'];
            $ambiguous = $order['ambiguous'];
            $owner = $order['values']['PAT_ID'];
            if ($owner === $patientKey) {
                $via[] = self::TABLE_ORDERS.'.PAT_ID';
            } elseif ($owner !== null) {
                $foreign = true;
            }
        }
        $encounterKeys = array_unique(array_filter([
            EhiDocumentValues::cell($revision, 'PAT_ENC_CSN_ID'),
            $order['values']['PAT_ENC_CSN_ID'] ?? null,
        ]));
        foreach ($encounterKeys as $csn) {
            $encounter = $evidence->encounter($csn);
            if ($encounter === null) {
                continue;
            }
            $revisionIds[] = $encounter['id'];
            $ambiguous = $ambiguous || $encounter['ambiguous'];
            if ($encounter['owner'] === $patientKey) {
                $via[] = self::TABLE_ENCOUNTERS.'.PAT_ID';
            } elseif ($encounter['owner'] !== null) {
                $foreign = true;
            }
        }

        if ($ambiguous) {
            $context->finding(self::FINDING_AMBIGUOUS_REVISION, $revision, ['table' => 'ownership']);

            return ['verdict' => self::FINDING_AMBIGUOUS_REVISION, 'via' => $via];
        }
        if ($foreign) {
            $context->finding(self::FINDING_FOREIGN_PATIENT, $revision);

            return ['verdict' => self::FINDING_FOREIGN_PATIENT, 'via' => $via];
        }
        if ($via === []) {
            $context->finding(self::FINDING_ORPHAN, $revision, ['order_supplied' => $order !== null]);

            return ['verdict' => self::FINDING_ORPHAN, 'via' => []];
        }

        return ['verdict' => 'owned', 'via' => array_values(array_unique($via))];
    }

    /**
     * Map one result to target fields. `issue` is the first thing that prevents a
     * clinical mapping; `proposed` always carries the fields so a retained proposal
     * still shows them.
     *
     * @param  array{id: int, ambiguous: bool, values: array<string, string|null>}|null  $order
     * @param  array<string, string>  $narrativeOwners
     * @param  list<string>  $via
     * @param  list<int>  $revisionIds
     * @return array{fields: array<string, string|null>, proposed: array<string, mixed>, issue: array{code: string, detail: array<string, int|string|bool|null>}|null, analytes: list<string>, number: string|null}
     */
    private function build(EhiMappingContext $context, EhiLabEvidence $evidence, PhrEhiRevision $revision, ?array $order, string $orderProcId, string $contact, string $line, array $narrativeOwners, string $importSource, string $externalId, array $via, array &$revisionIds): array
    {
        $issue = null;
        $raise = static function (string $code, array $detail = []) use (&$issue): void {
            $issue ??= ['code' => $code, 'detail' => $detail];
        };
        $cell = static fn (string $column): ?string => self::trimmed(EhiDocumentValues::cell($revision, $column));
        $orderCell = static fn (string $column): ?string => self::trimmed($order['values'][$column] ?? null);

        $value = $cell('ORD_VALUE');
        $parsed = EhiLabValue::parse($value, EhiDocumentValues::cell($revision, 'VALUE_NORMALIZED'));
        if ($parsed['conflicting']) {
            $context->finding(self::FINDING_COMPARATOR_CONFLICT, $revision, ['severity' => 'warning']);
        }
        if ($parsed['unrecognized']) {
            $context->finding(self::FINDING_NORMALIZED_UNRECOGNIZED, $revision, ['severity' => 'info']);
        }

        $analyte = $cell('COMPONENT_ID_NAME');
        if ($analyte === null) {
            $raise(self::FINDING_MISSING_ANALYTE, ['column' => 'COMPONENT_ID_NAME']);
        }
        $loincName = $cell('COMPON_LNC_ID_LNC_LONG_NAME');

        $collected = $this->dateField($raise, [['COMP_OBS_INST_TM', $cell('COMP_OBS_INST_TM'), self::TIMEZONE_LOCAL]]);
        $resulted = $this->dateField($raise, [
            ['COMP_ANL_INST_TM', $cell('COMP_ANL_INST_TM'), self::TIMEZONE_LOCAL],
            ['RESULT_DATE', $cell('RESULT_DATE'), self::TIMEZONE_UNSPECIFIED],
            ['RESULT_TIME', $orderCell('RESULT_TIME'), self::TIMEZONE_LOCAL],
        ]);

        $commentLines = $this->orderedLines($evidence->commentsOf($orderProcId, $contact, $line), $revisionIds);
        if ($commentLines === null) {
            $raise(self::FINDING_UNORDERED_LINES, ['table' => self::TABLE_COMMENTS]);
        }
        $comment = $this->joined([$cell('COMPONENT_COMMENT'), ...($commentLines['lines'] ?? [])], $this->maxCommentBytes());

        $narrativeLines = null;
        if (($narrativeOwners[$orderProcId."\0".$contact] ?? null) === $line) {
            $narrativeLines = $this->orderedLines($evidence->narrativeOf($orderProcId, $contact), $revisionIds);
            if ($narrativeLines === null) {
                $raise(self::FINDING_UNORDERED_LINES, ['table' => self::TABLE_NARRATIVE]);
            }
        }
        $notes = $this->joined($narrativeLines['lines'] ?? [], min(self::MAX_NOTES_BYTES, $this->maxCommentBytes()));

        $low = $cell('REFERENCE_LOW');
        $high = $cell('REFERENCE_HIGH');
        $rangeMin = EhiLabValue::exactBound($low);
        $rangeMax = EhiLabValue::exactBound($high);
        $rangeText = $cell('REF_NORMAL_VALS');
        if ($rangeText === null && (($low !== null && $rangeMin === null) || ($high !== null && $rangeMax === null))) {
            // A bound the decimal columns cannot hold exactly stays as the source wrote it.
            $rangeText = implode(' - ', array_filter([$low, $high], static fn (?string $bound): bool => $bound !== null));
        }

        $fields = [
            'test_name' => $orderCell('DISPLAY_NAME') ?? $orderCell('DESCRIPTION') ?? $orderCell('PROC_ID_PROC_NAME'),
            'analyte' => $analyte,
            'analyte_code' => $cell('COMPONENT_ID'),
            'analyte_code_system' => $cell('COMPONENT_ID') === null ? null : self::ANALYTE_CODE_SYSTEM,
            'value' => $value,
            // Verbatim, not trimmed: this column is what the source held.
            'value_raw' => EhiDocumentValues::cell($revision, 'ORD_RAW_VALUE'),
            'value_comparator' => $parsed['comparator'],
            'value_numeric' => $parsed['numeric'],
            'unit' => $cell('REFERENCE_UNIT'),
            'range_min' => $rangeMin,
            'range_max' => $rangeMax,
            'reference_range_text' => $rangeText,
            'abnormal_flag' => $cell('RESULT_FLAG_C_NAME'),
            'result_status' => $cell('RESULT_STATUS_C_NAME') ?? $cell('LAB_STATUS_C_NAME') ?? $orderCell('LAB_STATUS_C_NAME'),
            'result_comment' => $comment['text'],
            'notes' => $notes['text'],
            'ordering_provider' => $orderCell('AUTHRZING_PROV_ID_PROV_NAME'),
            'resulting_lab' => $cell('RESULTING_LAB_ID_LLB_NAME'),
            'collection_datetime' => $collected['value'],
            'collected_at_source' => $collected['source'],
            'collected_at_precision' => $collected['precision'],
            'collected_at_timezone' => $collected['timezone'],
            'result_datetime' => $resulted['value'],
            'resulted_at_source' => $resulted['source'],
            'resulted_at_precision' => $resulted['precision'],
            'resulted_at_timezone' => $resulted['timezone'],
        ];

        foreach (self::FIELD_LIMITS as $field => $limit) {
            if ($fields[$field] !== null && mb_strlen($fields[$field]) > $limit) {
                $raise(self::FINDING_VALUE_TOO_LONG, ['field' => $field, 'limit' => $limit]);
            }
        }
        if ($issue === null && $value === null && $comment['text'] === null && $notes['text'] === null) {
            $issue = ['code' => 'no_result_value', 'detail' => ['severity' => 'info']];
        }

        return [
            'fields' => $fields,
            'proposed' => [
                'attributes' => [
                    'import_source' => $importSource,
                    'external_id' => $externalId,
                    'review_status' => PhrReviewStatus::CONFIRMED,
                    'source' => self::SOURCE,
                    ...$fields,
                ],
                'ehi' => [
                    'table' => self::TABLE_RESULTS,
                    'order_proc_id' => $orderProcId,
                    'ord_date_real' => $contact,
                    'line' => $line,
                    'encounter_csn' => $cell('PAT_ENC_CSN_ID') ?? $orderCell('PAT_ENC_CSN_ID'),
                    'ownership' => $via,
                    'loinc_long_name' => $loincName,
                    'loinc_source' => $cell('COMPON_LNC_SRC_C_NAME'),
                    'in_range' => $cell('RESULT_IN_RANGE_YN'),
                    'comment_lines' => count($commentLines['lines'] ?? []),
                    'comment_line_gaps' => $commentLines['gaps'] ?? false,
                    'comment_truncated' => $comment['truncated'],
                    'narrative_lines' => count($narrativeLines['lines'] ?? []),
                    'narrative_truncated' => $notes['truncated'],
                ],
            ],
            'issue' => $issue,
            'analytes' => array_values(array_unique(array_filter([EhiLabRecordIndex::fold($analyte), EhiLabRecordIndex::fold($loincName)]))),
            'number' => $parsed['number'],
        ];
    }

    /**
     * Why a mapped result is not asserted as clinical, as a fixed code, or null.
     *
     * @param  array{id: int, ambiguous: bool, values: array<string, string|null>}|null  $order
     * @param  array<string, string|null>  $fields
     */
    private function nonClinicalReason(EhiMappingContext $context, PhrEhiRevision $revision, ?array $order, array $fields): ?string
    {
        $orderStatus = EhiLabRecordIndex::fold($order['values']['ORDER_STATUS_C_NAME'] ?? null);
        if ($orderStatus !== null && in_array($orderStatus, self::WITHDRAWN_ORDER_STATUSES, true)) {
            return 'order_withdrawn';
        }
        $orderType = EhiLabRecordIndex::fold($order['values']['ORDER_TYPE_C_NAME'] ?? null);
        if ($orderType !== null && ! in_array($orderType, self::LAB_ORDER_TYPES, true)) {
            $context->finding(self::FINDING_UNKNOWN_ORDER_TYPE, $revision, ['severity' => 'info']);

            return 'order_type_not_laboratory';
        }
        $status = EhiLabRecordIndex::fold($fields['result_status']);
        if ($status === null || in_array($status, self::CLINICAL_STATUSES, true)) {
            return null;
        }
        if (in_array($status, self::NON_CLINICAL_STATUSES, true)) {
            return 'status_'.str_replace(' ', '_', $status);
        }
        $context->finding(self::FINDING_UNKNOWN_STATUS, $revision);

        return 'status_unknown';
    }

    /**
     * The first non-empty column in order. Its text is kept verbatim even when it
     * cannot be parsed, which raises an issue instead of trying the next column.
     *
     * @param  callable(string, array<string, int|string|bool|null>): void  $raise
     * @param  list<array{0: string, 1: string|null, 2: string}>  $columns  [column, raw text, documented zone]
     * @return array{value: string|null, source: string|null, precision: string|null, timezone: string|null}
     */
    private function dateField(callable $raise, array $columns): array
    {
        foreach ($columns as [$column, $text, $timezone]) {
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
                'timezone' => $timezone,
            ];
        }

        return ['value' => null, 'source' => null, 'precision' => null, 'timezone' => null];
    }

    /**
     * Lines in numeric order, or null when they cannot be ordered: a line number
     * that is not a canonical integer, or a row with more than one revision here.
     * The lines relied on join $revisionIds.
     *
     * @param  list<array{id: int, ambiguous: bool, line: string, text: string}>  $rows
     * @param  list<int>  $revisionIds
     * @return array{lines: list<string>, gaps: bool}|null
     */
    private function orderedLines(array $rows, array &$revisionIds): ?array
    {
        foreach ($rows as $row) {
            $revisionIds[] = $row['id'];
            if ($row['ambiguous'] || ! EhiDocumentValues::isExactInteger($row['line'])) {
                return null;
            }
        }
        usort($rows, static fn (array $a, array $b): int => EhiDocumentValues::compareIntegers($a['line'], $b['line']));
        $gaps = false;
        foreach ($rows as $position => $row) {
            $gaps = $gaps || $row['line'] !== (string) ($position + 1);
        }

        return ['lines' => array_column($rows, 'text'), 'gaps' => $gaps];
    }

    /**
     * @param  array<int, string|null>  $parts
     * @return array{text: string|null, truncated: bool}
     */
    private function joined(array $parts, int $limit): array
    {
        $parts = array_filter($parts, static fn (?string $part): bool => $part !== null);
        if ($parts === []) {
            return ['text' => null, 'truncated' => false];
        }
        $text = mb_scrub(implode("\n", $parts), 'UTF-8');
        if (trim($text) === '') {
            return ['text' => null, 'truncated' => false];
        }
        if (strlen($text) <= $limit) {
            return ['text' => $text, 'truncated' => false];
        }

        return ['text' => mb_strcut($text, 0, $limit, 'UTF-8'), 'truncated' => true];
    }

    /** An eighth of the restore record limit, so a proposal with both texts still restores. */
    private function maxCommentBytes(): int
    {
        return min(1024 * 1024, intdiv((int) config('phr.native_restore_max_record_bytes'), 8));
    }

    /**
     * The record this entity already maps to: an evidence link, or an unlinked
     * record holding the same import identity.
     *
     * @return array{state: 'none'}|array{state: 'retain', reason: string}|array{state: 'target', record: PhrLabResult, previous: array<string, string|null>|null, same_revision: bool}
     */
    private function resolveTarget(EhiMappingContext $context, EhiLabRecordIndex $records, PhrEhiEntity $entity, PhrEhiRevision $revision, string $externalId): array
    {
        $links = collect($records->linksOf($entity->id));
        $linkedIds = $links->map(static fn (PhrEhiRecordLink $link): int => (int) $link->lab_result_id)->unique()->values();

        if ($linkedIds->count() > 1) {
            $context->finding(self::FINDING_AMBIGUOUS_LINK, $revision, ['records' => $linkedIds->count()]);

            return ['state' => 'retain', 'reason' => self::FINDING_AMBIGUOUS_LINK];
        }
        if ($linkedIds->count() === 1) {
            $record = $records->linkedRecord((int) $linkedIds->first());
            if ($record === null || PhrRecordLifecycle::of($record) !== PhrRecordLifecycle::ACTIVE) {
                $context->finding(self::FINDING_LINKED_RECORD_WITHDRAWN, $revision, [
                    'lifecycle' => $record === null ? 'missing' : PhrRecordLifecycle::of($record),
                ]);

                return ['state' => 'retain', 'reason' => self::FINDING_LINKED_RECORD_WITHDRAWN];
            }

            $sameRevision = $links->contains(static fn (PhrEhiRecordLink $link): bool => (int) $link->revision_id === $revision->id);
            $previous = $sameRevision ? null : $this->previousFields($context, $links);

            return ['state' => 'target', 'record' => $record, 'previous' => $previous, 'same_revision' => $sameRevision];
        }

        $record = $records->heldIdentity($externalId);
        if ($record === null) {
            return ['state' => 'none'];
        }
        if (PhrRecordLifecycle::of($record) !== PhrRecordLifecycle::ACTIVE) {
            $context->finding(self::FINDING_IDENTITY_WITHDRAWN, $revision, ['lifecycle' => PhrRecordLifecycle::of($record)]);

            return ['state' => 'retain', 'reason' => self::FINDING_IDENTITY_WITHDRAWN];
        }
        $context->finding(self::FINDING_MATCHED_EXTERNAL_IDENTITY, $revision, ['severity' => 'info']);

        return ['state' => 'target', 'record' => $record, 'previous' => null, 'same_revision' => false];
    }

    /**
     * The fields the latest linked proposal wrote or showed, so an update can tell
     * what the source changed from what a person edited on the record.
     *
     * @param  Collection<int, PhrEhiRecordLink>  $links
     * @return array<string, string|null>|null
     */
    private function previousFields(EhiMappingContext $context, Collection $links): ?array
    {
        /** @var PhrEhiRecordLink $latest */
        $latest = $links->sortByDesc('id')->first();
        $proposal = $latest->proposal_id === null ? null : PhrEhiProposal::query()->where('patient_id', $context->patient->id)->find($latest->proposal_id);
        $attributes = is_array($proposal?->proposed_json) ? ($proposal->proposed_json['attributes'] ?? null) : null;
        if (! is_array($attributes)) {
            return null;
        }
        $fields = [];
        foreach (self::MAPPED_FIELDS as $field) {
            $value = $attributes[$field] ?? null;
            $fields[$field] = is_string($value) ? $value : null;
        }

        return $fields;
    }

    /**
     * Corroborate when nothing would change; otherwise an update over the mapped
     * fields this revision changed that the record does not already hold. A field a
     * person edited and the source did not change is left alone.
     *
     * @param  list<int>  $revisionIds
     * @param  array<string, mixed>  $proposed
     * @param  array<string, string|null>  $fields
     * @param  array<string, string|null>|null  $previous
     */
    private function reconcile(string $subject, array $revisionIds, array $proposed, array $fields, PhrLabResult $record, ?array $previous, bool $sameRevision): EhiProposalDraft
    {
        $current = $this->currentFields($record);
        $changed = [];
        $before = [];
        if (! $sameRevision) {
            foreach (self::MAPPED_FIELDS as $field) {
                $next = $this->comparable($field, $fields[$field]);
                $sourceChanged = $previous === null || $this->comparable($field, $previous[$field] ?? null) !== $next;
                if ($sourceChanged && $this->comparable($field, $current[$field]) !== $next) {
                    $changed[] = $field;
                    $before[$field] = $current[$field];
                }
            }
        }

        if ($changed === []) {
            return new EhiProposalDraft(
                subjectKey: $subject,
                kind: PhrEhiProposal::KIND_CORROBORATE,
                revisionIds: $revisionIds,
                proposed: $proposed,
                targetTable: self::RECORD_TABLE,
                targetId: $record->id,
            );
        }

        return new EhiProposalDraft(
            subjectKey: $subject,
            kind: PhrEhiProposal::KIND_UPDATE,
            revisionIds: $revisionIds,
            proposed: [...$proposed, 'changed' => $changed],
            targetTable: self::RECORD_TABLE,
            targetId: $record->id,
            before: $before,
        );
    }

    /**
     * What a matched source-ID bridge rule proposes. Only a single live record with
     * agreeing clinical values corroborates; anything else is a person's decision.
     *
     * @param  non-empty-list<array{id: int, source: string|null, analyte: string|null, value: string|null, number: string|null, unit: string|null, dates: list<string>, live: bool}>  $bridged
     * @param  list<int>  $revisionIds
     * @param  array<string, mixed>  $proposed
     * @param  array<string, string|null>  $fields
     */
    private function bridgeDraft(EhiMappingContext $context, PhrEhiRevision $revision, string $subject, array $revisionIds, array $proposed, array $fields, array $bridged): EhiProposalDraft
    {
        $bridge = EhiSourceIdBridge::matched(EhiSourceIdBridge::RULE_CCDA_ORDER_EXTENSION);
        $live = array_values(array_filter($bridged, static fn (array $row): bool => $row['live']));
        if ($live === []) {
            $context->finding(self::FINDING_BRIDGE_WITHDRAWN, $revision, ['records' => count($bridged)]);

            return $this->retain($subject, $revisionIds, $proposed, self::FINDING_BRIDGE_WITHDRAWN);
        }
        $ids = array_map(static fn (array $row): int => $row['id'], $live);
        sort($ids);
        if (count($live) > 1) {
            $context->finding(self::FINDING_BRIDGE_AMBIGUOUS, $revision, ['records' => count($live)]);

            return new EhiProposalDraft($subject, PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $revisionIds, [...$proposed, 'bridge' => $bridge], candidateIds: $ids);
        }
        $conflicts = $this->bridgeConflicts($live[0], $fields);
        if ($conflicts !== []) {
            $context->finding(self::FINDING_BRIDGE_CONFLICT, $revision, ['conflicts' => implode(',', $conflicts)]);

            return new EhiProposalDraft($subject, PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $revisionIds, [...$proposed, 'bridge' => [...$bridge, 'conflicts' => $conflicts]], candidateIds: $ids);
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
     * @param  array{value: string|null, number: string|null, unit: string|null, dates: list<string>}  $record
     * @param  array<string, string|null>  $fields
     * @return list<string> from BRIDGE_COMPARED
     */
    private function bridgeConflicts(array $record, array $fields): array
    {
        $conflicts = [];
        $number = EhiLabValue::canonical($fields['value_numeric']);
        $value = EhiLabRecordIndex::fold($fields['value']);
        $unit = EhiLabRecordIndex::fold($fields['unit']);
        $valueAgrees = $value === null
            || ($record['value'] === null && $record['number'] === null)
            || $record['value'] === $value
            || ($record['unit'] !== null && $record['value'] === $value.' '.$record['unit'])
            || ($number !== null && $record['number'] === $number);
        if (! $valueAgrees) {
            $conflicts[] = 'value';
        }
        if ($unit !== null && $record['unit'] !== null && $unit !== $record['unit']) {
            $conflicts[] = 'unit';
        }
        $dates = $this->dates($fields);
        if ($dates !== [] && $record['dates'] !== [] && array_intersect($dates, $record['dates']) === []) {
            $conflicts[] = 'date';
        }

        return array_values(array_intersect(self::BRIDGE_COMPARED, $conflicts));
    }

    /**
     * @param  array<string, string|null>  $fields
     * @return list<string> the calendar dates this result states
     */
    private function dates(array $fields): array
    {
        $dates = [];
        foreach (['collection_datetime', 'result_datetime'] as $field) {
            if ($fields[$field] !== null) {
                $dates[] = substr($fields[$field], 0, 10);
            }
        }

        return array_values(array_unique($dates));
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
        $analyte = $attributes['analyte'] ?? null;
        if (! $package instanceof PhrEhiPackage || $package->source_id === null
            || $importSource !== self::importSource((int) $package->source_id)
            || ! is_string($externalId) || ! str_starts_with($externalId, self::TABLE_RESULTS.':') || strlen($externalId) > self::MAX_EXTERNAL_ID_BYTES
            || ! is_string($analyte) || $analyte === '') {
            throw new EhiLabApplyRefused(EhiLabApplyRefused::MALFORMED_PROPOSAL, 'the proposal does not carry a usable import identity and analyte');
        }

        $identity = ['patient_id' => $patient->id, 'import_source' => $importSource, 'external_id' => $externalId];
        // Identity survives deletion and retraction; a withdrawn row is never revived.
        $this->refuseHeldIdentity($identity);
        try {
            $record = PhrLabResult::query()->create([
                ...$identity,
                'user_id' => $patient->owner_user_id,
                'review_status' => PhrReviewStatus::CONFIRMED,
                'source' => self::SOURCE,
                ...$this->mappedOf($attributes),
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->refuseHeldIdentity($identity);

            throw new EhiLabApplyRefused(EhiLabApplyRefused::IDENTITY_EXISTS, 'a record already holds this import identity');
        }

        return new EhiApplyOutcome(self::RECORD_TABLE, $record->id, PhrEhiRecordLink::KIND_PROJECTED);
    }

    private function applyUpdate(PhrEhiProposal $proposal): EhiApplyOutcome
    {
        $record = $this->lockedLiveTarget($proposal, $proposal->target_table === self::RECORD_TABLE ? $proposal->target_id : null);
        $expected = $proposal->target_digest;
        if (! is_string($expected) || ! hash_equals($expected, EhiTargetDigest::of($record))) {
            throw new EhiLabApplyRefused(EhiLabApplyRefused::TARGET_CHANGED, 'the target changed since the proposal was previewed');
        }

        $changed = $proposal->proposed_json['changed'] ?? null;
        $mapped = $this->mappedOf($this->attributesOf($proposal));
        $changes = array_intersect_key($mapped, array_flip(is_array($changed) ? array_filter($changed, 'is_string') : []));
        if ($changes === []) {
            throw new EhiLabApplyRefused(EhiLabApplyRefused::MALFORMED_PROPOSAL, 'the update proposes no applicable mapped field');
        }
        // A changed value or date brings what the source says about it, unchanged or not.
        foreach (PhrLabResult::VALUE_PROVENANCE as $column => $provenance) {
            if (array_key_exists($column, $changes)) {
                $changes = [...$changes, ...array_intersect_key($mapped, array_flip($provenance))];
            }
        }
        // review_status is left as it is: the reviewer approved these fields, not
        // whatever else the record holds from its original source.
        $record->fill($changes);
        $record->save();

        return new EhiApplyOutcome(self::RECORD_TABLE, $record->id, PhrEhiRecordLink::KIND_PROJECTED);
    }

    private function lockedLiveTarget(PhrEhiProposal $proposal, ?int $recordId): PhrLabResult
    {
        $record = $recordId === null ? null : $this->withdrawnInclusiveQuery()
            ->where('patient_id', (int) $proposal->patient_id)
            ->whereKey($recordId)
            ->lockForUpdate()
            ->first();
        if (! $record instanceof PhrLabResult) {
            throw new EhiLabApplyRefused(EhiLabApplyRefused::TARGET_NOT_FOUND, "the target is not this patient's lab result");
        }
        if (PhrRecordLifecycle::of($record) !== PhrRecordLifecycle::ACTIVE) {
            throw new EhiLabApplyRefused(EhiLabApplyRefused::TARGET_WITHDRAWN, 'the target was deleted or retracted');
        }

        return $record;
    }

    /** @param  array{patient_id: int, import_source: string, external_id: string}  $identity */
    private function refuseHeldIdentity(array $identity): void
    {
        $existing = $this->withdrawnInclusiveQuery()->where($identity)->lockForUpdate()->first();
        if (! $existing instanceof PhrLabResult) {
            return;
        }
        if (PhrRecordLifecycle::of($existing) !== PhrRecordLifecycle::ACTIVE) {
            throw new EhiLabApplyRefused(EhiLabApplyRefused::IDENTITY_WITHDRAWN, 'the import identity is no longer available for this patient');
        }

        throw new EhiLabApplyRefused(EhiLabApplyRefused::IDENTITY_EXISTS, 'a record already holds this import identity');
    }

    /** @return Builder<PhrLabResult> */
    private function withdrawnInclusiveQuery(): Builder
    {
        return PhrLabResult::query()->withoutGlobalScope(SoftDeletingScope::class);
    }

    /** @return array<string, mixed> */
    private function attributesOf(PhrEhiProposal $proposal): array
    {
        $attributes = $proposal->proposed_json['attributes'] ?? null;
        if (! is_array($attributes)) {
            throw new EhiLabApplyRefused(EhiLabApplyRefused::MALFORMED_PROPOSAL, 'the proposal carries no proposed attributes');
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    private function mappedOf(array $attributes): array
    {
        $mapped = [];
        foreach (self::MAPPED_FIELDS as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }
            $value = $attributes[$field];
            if ($value !== null && ! is_string($value)) {
                throw new EhiLabApplyRefused(EhiLabApplyRefused::MALFORMED_PROPOSAL, 'a proposed field is not text');
            }
            $mapped[$field] = $value;
        }

        return $mapped;
    }

    /** @return array<string, string|null> */
    private function currentFields(PhrLabResult $record): array
    {
        $current = [];
        foreach (self::MAPPED_FIELDS as $field) {
            $value = match ($field) {
                'collection_datetime' => $record->collection_datetime?->format('Y-m-d H:i:s'),
                'result_datetime' => $record->result_datetime?->format('Y-m-d H:i:s'),
                default => $record->getAttribute($field),
            };
            $current[$field] = $value === null ? null : (string) $value;
        }

        return $current;
    }

    private function comparable(string $field, ?string $value): ?string
    {
        return in_array($field, self::DECIMAL_FIELDS, true) ? EhiLabValue::canonical($value) : $value;
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
