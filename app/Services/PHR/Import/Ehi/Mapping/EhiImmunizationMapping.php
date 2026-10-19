<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

use App\Models\PhrEhiEntity;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Models\PhrImmunization;
use App\Services\PHR\Import\Ehi\Mapping\Immunization\EhiImmunizationApplyRefused;
use App\Services\PHR\Import\Ehi\Mapping\Immunization\EhiImmunizationDateText;
use App\Support\PHR\PhrRecordLifecycle;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Epic EHI `IMMUNE` rows, owned through `PAT_IMMUNIZATIONS`, to immunization proposals.
 *
 * `IMMUNE` has no patient column, so an `IMMUNE` revision belongs to the bound
 * patient only when a `PAT_IMMUNIZATIONS` revision supplied by the same package
 * carries `PAT_ID` equal to the source's declared patient key and `IMMUNE_ID`
 * equal to it (both exact strings). Orphans and rows also linked to another
 * `PAT_ID` are retained as evidence with a finding, never mapped and never
 * dropped.
 *
 * Only Given, historical Given, and Partially Administered rows map clinically.
 * Deleted, Deferred, Refused, Incomplete, Invalid, and unrecognized statuses are
 * retained as evidence (there is no negative-assertion mapping yet); their
 * status is still carried in the proposal so the distinction is never lost.
 *
 * Column to field (nothing else is mapped, nothing is invented):
 *   IMMUNZATN_ID_NAME          -> vaccine_name
 *   IMMUNE_DATE                -> administered_on (calendar date part)
 *   IMMUNE_DATE[|IMMUNIZATION_TIME] -> administered_at_source (verbatim text),
 *                                 administered_at_precision, administered_at_timezone
 *   IMMNZTN_STATUS_C_NAME + IMM_HISTORIC_ADM_YN -> administration_status
 *   IMMNZTN_DOSE_AMOUNT        -> dose_amount (exact decimal text)
 *   IMMNZTN_DOSE_UNIT_C_NAME   -> dose_unit
 *   LOT                        -> lot_number
 *   MFG_C_NAME                 -> manufacturer
 *   ROUTE_C_NAME               -> route
 *   SITE_C_NAME                -> site
 *   SUMMARY_COLUMNS            -> raw_text (compact "COLUMN: value" lines)
 * `DOSE` is free text (e.g. "0.5 mL") and appears only in raw_text: it is never a
 * dose number. `IMMUNE` has no CVX column, so cvx_code stays null; the NDC code
 * is kept in raw_text only.
 */
final class EhiImmunizationMapping implements EhiMapping
{
    public const string NAME = 'immunization';

    public const string VERSION = 'ehi-imm-1';

    public const string TABLE_IMMUNE = 'IMMUNE';

    public const string TABLE_PAT_IMMUNIZATIONS = 'PAT_IMMUNIZATIONS';

    public const string RECORD_TABLE = 'phr_immunizations';

    public const string TIMEZONE_LOCAL = 'local';

    public const string TIMEZONE_UTC = 'utc';

    public const string TIMEZONE_UNSPECIFIED = 'unspecified';

    /**
     * open.epic.com/EHITables IMMUNE: IMMUNE_DATE is typed plain `DATETIME`, with
     * neither the "(Local)" nor the "(UTC)" annotation other columns carry, so its
     * zone is unspecified. The server timezone is never applied to it.
     */
    public const string IMMUNE_DATE_TIMEZONE = self::TIMEZONE_UNSPECIFIED;

    /** open.epic.com/EHITables IMMUNE: IMMUNIZATION_TIME is typed `DATETIME (Local)`. */
    public const string IMMUNIZATION_TIME_TIMEZONE = self::TIMEZONE_LOCAL;

    /**
     * administered_at_source is IMMUNE_DATE verbatim, or, when IMMUNIZATION_TIME is
     * present, IMMUNE_DATE verbatim + this separator + IMMUNIZATION_TIME verbatim.
     */
    public const string SOURCE_TEXT_SEPARATOR = '|';

    public const string STATUS_GIVEN = 'given';

    public const string STATUS_HISTORICAL = 'historical';

    public const string STATUS_PARTIAL = 'partial';

    public const string STATUS_DELETED = 'deleted';

    public const string STATUS_DEFERRED = 'deferred';

    public const string STATUS_REFUSED = 'refused';

    public const string STATUS_INCOMPLETE = 'incomplete';

    public const string STATUS_INVALID = 'invalid';

    public const string STATUS_UNKNOWN = 'unknown';

    /** Statuses that map to a clinical immunization record. */
    public const array CLINICAL_STATUSES = [self::STATUS_GIVEN, self::STATUS_HISTORICAL, self::STATUS_PARTIAL];

    /** No `PAT_IMMUNIZATIONS` row in the package links the `IMMUNE` row to any patient. */
    public const string FINDING_ORPHAN = 'orphan_immunization';

    /** A `PAT_IMMUNIZATIONS` row links the `IMMUNE` row to a different `PAT_ID`. */
    public const string FINDING_FOREIGN_PATIENT_LINK = 'foreign_patient_link';

    /** A patient's `PAT_IMMUNIZATIONS` row names no `IMMUNE` row the package supplied. */
    public const string FINDING_DANGLING_PATIENT_LINK = 'dangling_patient_immunization';

    /** The package supplied more than one revision for one `IMMUNE_ID`. */
    public const string FINDING_AMBIGUOUS_REVISION = 'ambiguous_immunization_revision';

    /** A date/time column is outside the accepted shapes; detail.column names it. */
    public const string FINDING_UNPARSEABLE_DATE = 'unparseable_date';

    /** IMMUNIZATION_TIME carries a calendar date different from IMMUNE_DATE. */
    public const string FINDING_CONFLICTING_TIME = 'conflicting_administration_time';

    /** IMMNZTN_DOSE_AMOUNT is not a plain non-negative decimal. */
    public const string FINDING_INVALID_DOSE_AMOUNT = 'invalid_dose_amount';

    /** IMM_HISTORIC_ADM_YN is neither Y/Yes nor N/No. */
    public const string FINDING_UNRECOGNIZED_FLAG = 'unrecognized_historical_flag';

    /** IMMNZTN_STATUS_C_NAME is not one of the documented categories. */
    public const string FINDING_UNKNOWN_STATUS = 'unknown_immunization_status';

    /** IMMUNZATN_ID_NAME is empty, so there is no vaccine name to record. */
    public const string FINDING_MISSING_VACCINE_NAME = 'missing_vaccine_name';

    /** A value exceeds its target column; detail.field names it. Never truncated. */
    public const string FINDING_VALUE_TOO_LONG = 'value_too_long';

    /** The record this entity is linked to was deleted or retracted; never resurrected. */
    public const string FINDING_LINKED_RECORD_WITHDRAWN = 'linked_record_withdrawn';

    /** The entity is linked to more than one immunization record. */
    public const string FINDING_AMBIGUOUS_LINK = 'ambiguous_immunization_link';

    /** An unlinked record already holds this import identity and is used as the target. */
    public const string FINDING_MATCHED_EXTERNAL_IDENTITY = 'matched_external_identity';

    /** The record holding this import identity was deleted or retracted. */
    public const string FINDING_IDENTITY_WITHDRAWN = 'immunization_identity_withdrawn';

    /** The fields a proposal may write; update diffs and applies cover only these. */
    public const array MAPPED_FIELDS = [
        'vaccine_name',
        'administered_on',
        'administered_at_source',
        'administered_at_precision',
        'administered_at_timezone',
        'administration_status',
        'dose_amount',
        'dose_unit',
        'lot_number',
        'manufacturer',
        'route',
        'site',
        'raw_text',
    ];

    /** Source columns summarized in raw_text, in this order, when non-empty. */
    public const array SUMMARY_COLUMNS = [
        'IMMUNE_ID',
        'IMMUNZATN_ID',
        'IMMUNZATN_ID_NAME',
        'IMMUNE_DATE',
        'IMMUNIZATION_TIME',
        'IMMNZTN_STATUS_C_NAME',
        'IMM_HISTORIC_ADM_YN',
        'DOSE',
        'IMMNZTN_DOSE_AMOUNT',
        'IMMNZTN_DOSE_UNIT_C_NAME',
        'LOT',
        'MFG_C_NAME',
        'ROUTE_C_NAME',
        'SITE_C_NAME',
        'IMM_CSN',
        'NDC_NUM_ID_NDC_CODE',
    ];

    /** Documented IMMNZTN_STATUS_C_NAME categories (no organization-specific values). */
    private const array SOURCE_STATUSES = [
        'Given' => self::STATUS_GIVEN,
        'Partially Administered' => self::STATUS_PARTIAL,
        'Deleted' => self::STATUS_DELETED,
        'Incomplete' => self::STATUS_INCOMPLETE,
        'Deferred' => self::STATUS_DEFERRED,
        'Refused' => self::STATUS_REFUSED,
        'Invalid' => self::STATUS_INVALID,
    ];

    private const array HISTORICAL_YES = ['Y', 'Yes'];

    private const array HISTORICAL_NO = ['N', 'No'];

    /** Target column limits from the phr_immunizations schema. */
    private const array FIELD_LIMITS = [
        'vaccine_name' => 255,
        'administered_at_source' => 64,
        'dose_amount' => 32,
        'dose_unit' => 32,
        'lot_number' => 100,
        'manufacturer' => 100,
        'route' => 100,
        'site' => 100,
    ];

    /** A plain non-negative decimal, digits kept exactly (leading/trailing zeros preserved). */
    private const string DOSE_AMOUNT_PATTERN = '/^(?:\d+(?:\.\d+)?|\.\d+)$/';

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
            self::TABLE_IMMUNE => ['IMMUNE_ID'],
            self::TABLE_PAT_IMMUNIZATIONS => ['PAT_ID', 'LINE'],
        ];
    }

    public static function importSource(int $sourceId): string
    {
        return 'ehi:'.$sourceId;
    }

    public static function externalId(string $immuneId): string
    {
        return 'IMMUNE:'.$immuneId;
    }

    public function propose(EhiMappingContext $context): iterable
    {
        $patientLinks = $this->patientLinks($context);
        $importSource = self::importSource($context->source->id);

        $drafts = [];
        $seenImmuneIds = [];
        foreach ($context->revisions(self::TABLE_IMMUNE)->groupBy('entity_id', true) as $revisions) {
            /** @var Collection<int, PhrEhiRevision> $revisions */
            $revisions = $revisions->sortBy('id')->values();
            /** @var PhrEhiRevision $first */
            $first = $revisions->first();
            /** @var PhrEhiEntity $entity */
            $entity = $first->entity;
            $immuneId = $this->keyValue($entity, 'IMMUNE_ID');
            $seenImmuneIds[$immuneId] = true;

            $drafts[] = $this->draftForEntity(
                $context,
                $entity,
                $revisions,
                $immuneId,
                $importSource,
                $patientLinks[$immuneId] ?? ['own' => [], 'foreign' => []],
            );
        }

        foreach ($patientLinks as $immuneId => $links) {
            if (isset($seenImmuneIds[$immuneId])) {
                continue;
            }
            foreach ($links['own'] as $revision) {
                $context->finding(self::FINDING_DANGLING_PATIENT_LINK, $revision, ['reason' => 'immune_not_supplied']);
            }
        }

        return $drafts;
    }

    public function apply(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome
    {
        $patient = $context->patient;
        if ((int) $proposal->patient_id !== $patient->id
            || $proposal->mapping !== self::NAME
            || $proposal->mapping_version !== self::VERSION) {
            throw new EhiImmunizationApplyRefused(EhiImmunizationApplyRefused::WRONG_SCOPE, 'the proposal does not belong to this patient and mapping version');
        }

        return match (true) {
            $context->decision === PhrEhiProposal::DECISION_ACCEPT
                && in_array($proposal->kind, [PhrEhiProposal::KIND_CREATE, PhrEhiProposal::KIND_POSSIBLE_DUPLICATE], true) => $this->applyCreate($proposal, $context),
            $context->decision === PhrEhiProposal::DECISION_ACCEPT
                && $proposal->kind === PhrEhiProposal::KIND_CORROBORATE => $this->applyCorroborate($proposal),
            $context->decision === PhrEhiProposal::DECISION_APPROVE_UPDATE
                && $proposal->kind === PhrEhiProposal::KIND_UPDATE => $this->applyUpdate($proposal),
            $context->decision === PhrEhiProposal::DECISION_LINK
                && in_array($proposal->kind, [PhrEhiProposal::KIND_CREATE, PhrEhiProposal::KIND_POSSIBLE_DUPLICATE], true) => $this->applyLink($proposal, $context),
            default => throw new EhiImmunizationApplyRefused(EhiImmunizationApplyRefused::DECISION_NOT_APPLICABLE, 'the decision does not apply to this proposal kind'),
        };
    }

    /**
     * The package's PAT_IMMUNIZATIONS revisions, grouped by IMMUNE_ID (exact
     * string) into this patient's links and links to any other PAT_ID.
     *
     * @return array<string, array{own: list<PhrEhiRevision>, foreign: list<PhrEhiRevision>}>
     */
    private function patientLinks(EhiMappingContext $context): array
    {
        $patientKey = $context->source->source_patient_key;
        $links = [];
        foreach ($context->revisions(self::TABLE_PAT_IMMUNIZATIONS) as $revision) {
            $values = $this->values($revision);
            $own = ($values['PAT_ID'] ?? null) === $patientKey;
            $immuneId = $values['IMMUNE_ID'] ?? null;
            if ($immuneId === null || trim($immuneId) === '') {
                if ($own) {
                    $context->finding(self::FINDING_DANGLING_PATIENT_LINK, $revision, ['reason' => 'immune_id_missing']);
                }

                continue;
            }
            $links[$immuneId] ??= ['own' => [], 'foreign' => []];
            $links[$immuneId][$own ? 'own' : 'foreign'][] = $revision;
        }

        return $links;
    }

    /**
     * @param  Collection<int, PhrEhiRevision>  $revisions
     * @param  array{own: list<PhrEhiRevision>, foreign: list<PhrEhiRevision>}  $links
     */
    private function draftForEntity(
        EhiMappingContext $context,
        PhrEhiEntity $entity,
        Collection $revisions,
        string $immuneId,
        string $importSource,
        array $links,
    ): EhiProposalDraft {
        $joinedIds = array_map(static fn (PhrEhiRevision $revision): int => $revision->id, [...$links['own'], ...$links['foreign']]);
        sort($joinedIds);
        $revisionIds = array_values(array_unique([
            ...$revisions->map(static fn (PhrEhiRevision $revision): int => $revision->id)->all(),
            ...$joinedIds,
        ]));
        $subject = $entity->key_digest;

        if ($revisions->count() > 1) {
            $context->finding(self::FINDING_AMBIGUOUS_REVISION, $revisions->first(), ['revisions' => $revisions->count()]);

            return $this->retain($subject, $revisionIds, null, self::FINDING_AMBIGUOUS_REVISION);
        }

        /** @var PhrEhiRevision $revision */
        $revision = $revisions->first();
        $built = $this->build($this->values($revision), $immuneId, $importSource);

        if ($links['foreign'] !== []) {
            $context->finding(self::FINDING_FOREIGN_PATIENT_LINK, $revision, [
                'foreign_links' => count($links['foreign']),
                'own_links' => count($links['own']),
            ]);

            return $this->retain($subject, $revisionIds, $built['proposed'], self::FINDING_FOREIGN_PATIENT_LINK);
        }
        if ($links['own'] === []) {
            $context->finding(self::FINDING_ORPHAN, $revision);

            return $this->retain($subject, $revisionIds, $built['proposed'], self::FINDING_ORPHAN);
        }
        if ($built['issue'] !== null) {
            $context->finding($built['issue']['code'], $revision, $built['issue']['detail']);

            return $this->retain($subject, $revisionIds, $built['proposed'], $built['issue']['code']);
        }

        $fields = $built['fields'];
        $target = $this->resolveTarget($context, $entity, $revision, $importSource, self::externalId($immuneId));
        if ($target['state'] === 'retain') {
            return $this->retain($subject, $revisionIds, $built['proposed'], $target['reason']);
        }
        if ($target['state'] === 'target') {
            return $this->reconcile($subject, $revisionIds, $built['proposed'], $fields, $target['record'], $target['previous'], $target['same_revision']);
        }

        if (! in_array($fields['administration_status'], self::CLINICAL_STATUSES, true)) {
            if ($fields['administration_status'] === self::STATUS_UNKNOWN) {
                $context->finding(self::FINDING_UNKNOWN_STATUS, $revision, ['present' => $built['status_present']]);
            }

            return $this->retain($subject, $revisionIds, $built['proposed'], 'status_'.$fields['administration_status']);
        }

        $candidates = $this->duplicateCandidates($context, $fields);
        if ($candidates !== []) {
            return new EhiProposalDraft(
                subjectKey: $subject,
                kind: PhrEhiProposal::KIND_POSSIBLE_DUPLICATE,
                revisionIds: $revisionIds,
                proposed: $built['proposed'],
                candidateIds: $candidates,
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
     * The record this entity already maps to: an explicit evidence link, or else
     * an unlinked record holding the same import identity.
     *
     * @return array{state: 'none'}|array{state: 'retain', reason: string}|array{state: 'target', record: PhrImmunization, previous: array<string, string|null>|null, same_revision: bool}
     */
    private function resolveTarget(
        EhiMappingContext $context,
        PhrEhiEntity $entity,
        PhrEhiRevision $revision,
        string $importSource,
        string $externalId,
    ): array {
        $links = $context->linksForEntity($entity)->filter(static fn (PhrEhiRecordLink $link): bool => $link->getAttribute('immunization_id') !== null);
        $linkedIds = $links->map(static fn (PhrEhiRecordLink $link): int => (int) $link->getAttribute('immunization_id'))->unique()->values();

        if ($linkedIds->count() > 1) {
            $context->finding(self::FINDING_AMBIGUOUS_LINK, $revision, ['records' => $linkedIds->count()]);

            return ['state' => 'retain', 'reason' => self::FINDING_AMBIGUOUS_LINK];
        }

        if ($linkedIds->count() === 1) {
            $record = $this->withdrawnInclusiveQuery()
                ->where('patient_id', $context->patient->id)
                ->whereKey($linkedIds->first())
                ->first();
            if (! $record instanceof PhrImmunization || PhrRecordLifecycle::of($record) !== PhrRecordLifecycle::ACTIVE) {
                $context->finding(self::FINDING_LINKED_RECORD_WITHDRAWN, $revision, [
                    'lifecycle' => $record instanceof PhrImmunization ? PhrRecordLifecycle::of($record) : 'missing',
                ]);

                return ['state' => 'retain', 'reason' => self::FINDING_LINKED_RECORD_WITHDRAWN];
            }

            $sameRevision = $links->contains(static fn (PhrEhiRecordLink $link): bool => (int) $link->getAttribute('revision_id') === $revision->id);
            $previous = null;
            if (! $sameRevision) {
                /** @var PhrEhiRecordLink $latest */
                $latest = $links->sortByDesc('id')->first();
                $previousRevision = PhrEhiRevision::query()->find((int) $latest->getAttribute('revision_id'));
                if ($previousRevision instanceof PhrEhiRevision) {
                    $previous = $this->build($this->values($previousRevision), $this->keyValue($entity, 'IMMUNE_ID'), $importSource)['fields'];
                }
            }

            return ['state' => 'target', 'record' => $record, 'previous' => $previous, 'same_revision' => $sameRevision];
        }

        $record = $this->withdrawnInclusiveQuery()
            ->where('patient_id', $context->patient->id)
            ->where('import_source', $importSource)
            ->where('external_id', $externalId)
            ->first();
        if (! $record instanceof PhrImmunization) {
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
     * Corroborate when nothing would change; otherwise an update over the mapped
     * fields this revision changed (relative to the previously linked revision,
     * when there is one) that the record does not already hold. Fields a person
     * edited on the record and the source did not change are left alone.
     *
     * @param  list<int>  $revisionIds
     * @param  array<string, mixed>  $proposed
     * @param  array<string, string|null>  $fields
     * @param  array<string, string|null>|null  $previous
     */
    private function reconcile(
        string $subject,
        array $revisionIds,
        array $proposed,
        array $fields,
        PhrImmunization $record,
        ?array $previous,
        bool $sameRevision,
    ): EhiProposalDraft {
        $current = $this->currentFields($record);
        $after = [];
        $before = [];
        if (! $sameRevision) {
            foreach (self::MAPPED_FIELDS as $field) {
                $sourceChanged = $previous === null || ($previous[$field] ?? null) !== $fields[$field];
                if ($sourceChanged && $current[$field] !== $fields[$field]) {
                    $before[$field] = $current[$field];
                    $after[$field] = $fields[$field];
                }
            }
        }

        if ($after === []) {
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
            proposed: $after,
            targetTable: self::RECORD_TABLE,
            targetId: $record->id,
            before: $before,
        );
    }

    /**
     * Live immunizations of the patient on the same date with a case-insensitively
     * equal vaccine name or lot number. Never merged automatically.
     *
     * @param  array<string, string|null>  $fields
     * @return list<int>
     */
    private function duplicateCandidates(EhiMappingContext $context, array $fields): array
    {
        if ($fields['administered_on'] === null) {
            return [];
        }
        $name = $this->fold($fields['vaccine_name']);
        $lot = $this->fold($fields['lot_number']);

        $query = PhrImmunization::query()
            ->where('patient_id', $context->patient->id)
            ->whereDate('administered_on', $fields['administered_on']);
        PhrRecordLifecycle::scopeLive($query);

        $ids = $query->get(['id', 'vaccine_name', 'lot_number'])
            ->filter(fn (PhrImmunization $record): bool => ($name !== null && $this->fold($record->vaccine_name) === $name)
                || ($lot !== null && $this->fold($record->lot_number) === $lot))
            ->map(static fn (PhrImmunization $record): int => $record->id)
            ->values()
            ->all();
        sort($ids);

        return $ids;
    }

    /**
     * Map one IMMUNE revision's values to target fields. `issue` is the first
     * structural problem that prevents a clinical mapping; `proposed` always
     * carries the best-effort fields so a retained proposal still shows them.
     *
     * @param  array<string, string|null>  $values
     * @return array{fields: array<string, string|null>, proposed: array<string, mixed>, issue: array{code: string, detail: array<string, int|string|bool|null>}|null, status_present: bool}
     */
    private function build(array $values, string $immuneId, string $importSource): array
    {
        $issue = null;
        $raise = static function (string $code, array $detail = []) use (&$issue): void {
            $issue ??= ['code' => $code, 'detail' => $detail];
        };
        $text = static function (string $column) use ($values): ?string {
            $value = $values[$column] ?? null;
            if ($value === null) {
                return null;
            }
            $value = trim($value);

            return $value === '' ? null : $value;
        };

        $statusText = $text('IMMNZTN_STATUS_C_NAME');
        $status = $statusText === null ? self::STATUS_UNKNOWN : (self::SOURCE_STATUSES[$statusText] ?? self::STATUS_UNKNOWN);
        $historicalText = $text('IMM_HISTORIC_ADM_YN');
        $historical = false;
        if ($historicalText !== null && in_array($historicalText, self::HISTORICAL_YES, true)) {
            $historical = true;
        } elseif ($historicalText !== null && ! in_array($historicalText, self::HISTORICAL_NO, true)) {
            $raise(self::FINDING_UNRECOGNIZED_FLAG, ['column' => 'IMM_HISTORIC_ADM_YN']);
        }
        if ($status === self::STATUS_GIVEN && $historical) {
            $status = self::STATUS_HISTORICAL;
        }

        $vaccineName = $text('IMMUNZATN_ID_NAME');
        if ($vaccineName === null) {
            $raise(self::FINDING_MISSING_VACCINE_NAME, ['column' => 'IMMUNZATN_ID_NAME']);
        }

        [$administeredOn, $sourceText, $precision, $timezone] = $this->administeredAt($text('IMMUNE_DATE'), $text('IMMUNIZATION_TIME'), $raise);

        $doseAmount = $text('IMMNZTN_DOSE_AMOUNT');
        if ($doseAmount !== null && preg_match(self::DOSE_AMOUNT_PATTERN, $doseAmount) !== 1) {
            $raise(self::FINDING_INVALID_DOSE_AMOUNT, ['column' => 'IMMNZTN_DOSE_AMOUNT']);
        }

        $fields = [
            'vaccine_name' => $vaccineName,
            'administered_on' => $administeredOn,
            'administered_at_source' => $sourceText,
            'administered_at_precision' => $precision,
            'administered_at_timezone' => $timezone,
            'administration_status' => $status,
            'dose_amount' => $doseAmount,
            'dose_unit' => $text('IMMNZTN_DOSE_UNIT_C_NAME'),
            'lot_number' => $text('LOT'),
            'manufacturer' => $text('MFG_C_NAME'),
            'route' => $text('ROUTE_C_NAME'),
            'site' => $text('SITE_C_NAME'),
            'raw_text' => $this->summary($text),
        ];

        foreach (self::FIELD_LIMITS as $field => $limit) {
            if ($fields[$field] !== null && mb_strlen($fields[$field]) > $limit) {
                $raise(self::FINDING_VALUE_TOO_LONG, ['field' => $field, 'limit' => $limit]);
            }
        }

        return [
            'fields' => $fields,
            'proposed' => [
                'import_source' => $importSource,
                'external_id' => self::externalId($immuneId),
                'review_status' => PhrReviewStatus::CONFIRMED,
                ...$fields,
                // IMMUNE has no CVX column, and DOSE / IMMNZTN_DOSE_AMOUNT are volumes, not series positions.
                'cvx_code' => null,
                'dose_number' => null,
            ],
            'issue' => $issue,
            'status_present' => $statusText !== null,
        ];
    }

    /**
     * administered_on, the verbatim source text, its precision, and its timezone.
     *
     * @param  callable(string, array<string, int|string|bool|null>): void  $raise
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string|null}
     */
    private function administeredAt(?string $dateText, ?string $timeText, callable $raise): array
    {
        if ($dateText === null) {
            if ($timeText !== null) {
                $raise(self::FINDING_UNPARSEABLE_DATE, ['column' => 'IMMUNIZATION_TIME', 'reason' => 'time_without_date']);
            }

            return [null, null, null, null];
        }

        $date = EhiImmunizationDateText::parseDateTime($dateText);
        if ($date === null) {
            $raise(self::FINDING_UNPARSEABLE_DATE, ['column' => 'IMMUNE_DATE']);

            return [null, $dateText, null, null];
        }
        if ($timeText === null) {
            return [$date['date'], $dateText, $date['precision'], self::IMMUNE_DATE_TIMEZONE];
        }

        $sourceText = $dateText.self::SOURCE_TEXT_SEPARATOR.$timeText;
        $time = EhiImmunizationDateText::parseTime($timeText);
        if ($time === null) {
            $dateTime = EhiImmunizationDateText::parseDateTime($timeText);
            if ($dateTime === null || $dateTime['time'] === null) {
                $raise(self::FINDING_UNPARSEABLE_DATE, ['column' => 'IMMUNIZATION_TIME']);

                return [$date['date'], $sourceText, null, null];
            }
            if ($dateTime['date'] !== $date['date']) {
                $raise(self::FINDING_CONFLICTING_TIME, ['column' => 'IMMUNIZATION_TIME']);

                return [$date['date'], $sourceText, null, null];
            }
            $time = ['time' => $dateTime['time'], 'precision' => $dateTime['precision']];
        }

        return [$date['date'], $sourceText, $time['precision'], self::IMMUNIZATION_TIME_TIMEZONE];
    }

    /** @param  callable(string): ?string  $text */
    private function summary(callable $text): string
    {
        $lines = ['Epic EHI '.self::TABLE_IMMUNE];
        foreach (self::SUMMARY_COLUMNS as $column) {
            $value = $text($column);
            if ($value !== null) {
                // Control characters would break the line structure; the revision keeps the exact text.
                $lines[] = $column.': '.preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
            }
        }

        return implode("\n", $lines);
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
        $proposed = $this->proposedOf($proposal);
        $package = PhrEhiPackage::query()->where('patient_id', $patient->id)->find($proposal->package_id);
        $importSource = $proposed['import_source'] ?? null;
        $externalId = $proposed['external_id'] ?? null;
        $vaccineName = $proposed['vaccine_name'] ?? null;
        if (! $package instanceof PhrEhiPackage || $package->source_id === null
            || $importSource !== self::importSource((int) $package->source_id)
            || ! is_string($externalId) || ! str_starts_with($externalId, 'IMMUNE:')
            || ! is_string($vaccineName) || $vaccineName === '') {
            throw new EhiImmunizationApplyRefused(EhiImmunizationApplyRefused::MALFORMED_PROPOSAL, 'the proposal does not carry a usable import identity and vaccine name');
        }

        $identity = ['patient_id' => $patient->id, 'import_source' => $importSource, 'external_id' => $externalId];
        // Identity survives deletion and retraction; a withdrawn row is never revived.
        $this->refuseHeldIdentity($identity);

        $attributes = [
            ...$identity,
            'user_id' => $patient->owner_user_id,
            'review_status' => PhrReviewStatus::CONFIRMED,
            ...$this->mappedOf($proposed),
            'cvx_code' => null,
            'dose_number' => null,
        ];
        try {
            $record = PhrImmunization::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            $this->refuseHeldIdentity($identity);

            throw new EhiImmunizationApplyRefused(EhiImmunizationApplyRefused::IDENTITY_EXISTS, 'a record already holds this import identity');
        }

        return new EhiApplyOutcome(self::RECORD_TABLE, $record->id, PhrEhiRecordLink::KIND_PROJECTED);
    }

    private function applyUpdate(PhrEhiProposal $proposal): EhiApplyOutcome
    {
        $record = $this->lockedLiveTarget($proposal, $proposal->target_table === self::RECORD_TABLE ? $proposal->target_id : null);
        $expected = $proposal->target_digest;
        if (! is_string($expected) || ! hash_equals($expected, EhiTargetDigest::of($record))) {
            throw new EhiImmunizationApplyRefused(EhiImmunizationApplyRefused::TARGET_CHANGED, 'the target changed since the proposal was previewed');
        }

        $changes = $this->mappedOf($this->proposedOf($proposal));
        if ($changes === [] || (array_key_exists('vaccine_name', $changes) && ! is_string($changes['vaccine_name']))) {
            throw new EhiImmunizationApplyRefused(EhiImmunizationApplyRefused::MALFORMED_PROPOSAL, 'the update proposes no applicable mapped field');
        }
        // review_status is left as it is: the reviewer approved these fields, not
        // whatever else the record holds from its original source.
        $record->fill($changes);
        $record->save();

        return new EhiApplyOutcome(self::RECORD_TABLE, $record->id, PhrEhiRecordLink::KIND_PROJECTED);
    }

    private function applyCorroborate(PhrEhiProposal $proposal): EhiApplyOutcome
    {
        $record = $this->lockedLiveTarget($proposal, $proposal->target_table === self::RECORD_TABLE ? $proposal->target_id : null);

        return new EhiApplyOutcome(self::RECORD_TABLE, $record->id, PhrEhiRecordLink::KIND_CORROBORATING);
    }

    private function applyLink(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome
    {
        $record = $this->lockedLiveTarget($proposal, $context->linkTargetId);

        return new EhiApplyOutcome(self::RECORD_TABLE, $record->id, PhrEhiRecordLink::KIND_BRIDGED);
    }

    /** Lock a target immunization of the proposal's patient, refusing a missing or withdrawn one. */
    private function lockedLiveTarget(PhrEhiProposal $proposal, ?int $recordId): PhrImmunization
    {
        $record = $recordId === null ? null : $this->withdrawnInclusiveQuery()
            ->where('patient_id', (int) $proposal->patient_id)
            ->whereKey($recordId)
            ->lockForUpdate()
            ->first();
        if (! $record instanceof PhrImmunization) {
            throw new EhiImmunizationApplyRefused(EhiImmunizationApplyRefused::TARGET_NOT_FOUND, "the target is not this patient's immunization");
        }
        if (PhrRecordLifecycle::of($record) !== PhrRecordLifecycle::ACTIVE) {
            throw new EhiImmunizationApplyRefused(EhiImmunizationApplyRefused::TARGET_WITHDRAWN, 'the target was deleted or retracted');
        }

        return $record;
    }

    /** @param  array{patient_id: int, import_source: string, external_id: string}  $identity */
    private function refuseHeldIdentity(array $identity): void
    {
        $existing = $this->withdrawnInclusiveQuery()->where($identity)->lockForUpdate()->first();
        if (! $existing instanceof PhrImmunization) {
            return;
        }
        if (PhrRecordLifecycle::of($existing) !== PhrRecordLifecycle::ACTIVE) {
            throw new EhiImmunizationApplyRefused(EhiImmunizationApplyRefused::IDENTITY_WITHDRAWN, 'the import identity is no longer available for this patient');
        }

        throw new EhiImmunizationApplyRefused(EhiImmunizationApplyRefused::IDENTITY_EXISTS, 'a record already holds this import identity');
    }

    /** @return Builder<PhrImmunization> */
    private function withdrawnInclusiveQuery(): Builder
    {
        return PhrImmunization::query()->withoutGlobalScope(SoftDeletingScope::class);
    }

    /** @return array<string, mixed> */
    private function proposedOf(PhrEhiProposal $proposal): array
    {
        $proposed = $proposal->proposed_json;
        if (! is_array($proposed)) {
            throw new EhiImmunizationApplyRefused(EhiImmunizationApplyRefused::MALFORMED_PROPOSAL, 'the proposal carries no proposed attributes');
        }

        return $proposed;
    }

    /**
     * @param  array<string, mixed>  $proposed
     * @return array<string, string|null>
     */
    private function mappedOf(array $proposed): array
    {
        $mapped = [];
        foreach (self::MAPPED_FIELDS as $field) {
            if (! array_key_exists($field, $proposed)) {
                continue;
            }
            $value = $proposed[$field];
            if ($value !== null && ! is_string($value)) {
                throw new EhiImmunizationApplyRefused(EhiImmunizationApplyRefused::MALFORMED_PROPOSAL, 'a proposed field is not text');
            }
            $mapped[$field] = $value;
        }

        return $mapped;
    }

    /** @return array<string, string|null> */
    private function currentFields(PhrImmunization $record): array
    {
        $current = [];
        foreach (self::MAPPED_FIELDS as $field) {
            $value = $field === 'administered_on'
                ? $record->administered_on?->format('Y-m-d')
                : $record->getAttribute($field);
            $current[$field] = $value === null ? null : (string) $value;
        }

        return $current;
    }

    /** @return array<string, string|null> */
    private function values(PhrEhiRevision $revision): array
    {
        $values = [];
        foreach ((array) $revision->values_json as $column => $value) {
            $values[(string) $column] = is_string($value) ? $value : null;
        }

        return $values;
    }

    private function keyValue(PhrEhiEntity $entity, string $column): string
    {
        $key = (array) $entity->key_json;

        return is_string($key[$column] ?? null) ? $key[$column] : '';
    }

    private function fold(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = mb_strtolower(trim($value));

        return $value === '' ? null : $value;
    }
}
