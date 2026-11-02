<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Lab;

use App\Models\PhrEhiRevision;
use App\Services\PHR\Import\Ehi\Mapping\Document\EhiDocumentValues;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;

/**
 * The rows a lab result joins to, indexed once by exact key strings so a result
 * needs no query of its own. Holds only the columns the mapping reads, and the
 * preview budget bounds how many rows that can be. Only what this package supplied
 * and did not quarantine is visible (EhiMappingContext). Join keys are compared as
 * exact strings, never as numbers: "64000.1" and "64000.10" are different keys
 * here, and a row that matches nothing is reported rather than coerced to match.
 */
final class EhiLabEvidence
{
    /** ORDER_PROC columns the mapping reads. */
    private const array ORDER_COLUMNS = [
        'PAT_ID', 'PAT_ENC_CSN_ID', 'DESCRIPTION', 'DISPLAY_NAME', 'PROC_ID_PROC_NAME',
        'ORDER_TYPE_C_NAME', 'ORDER_STATUS_C_NAME', 'LAB_STATUS_C_NAME',
        'AUTHRZING_PROV_ID_PROV_NAME', 'RESULT_TIME',
    ];

    /** @var array<string, array{id: int, ambiguous: bool, values: array<string, string|null>}> */
    private array $orders = [];

    /** @var array<string, array{id: int, ambiguous: bool, owner: string|null}> */
    private array $encounters = [];

    /** @var array<string, list<array{id: int, ambiguous: bool, line: string, text: string}>> */
    private array $comments = [];

    /** @var array<string, list<array{id: int, ambiguous: bool, line: string, text: string}>> */
    private array $narratives = [];

    /** @var array<string, true> */
    private array $usedOrders = [];

    /** @var array<string, true> */
    private array $usedComments = [];

    /** @var array<string, true> */
    private array $usedNarratives = [];

    public static function load(EhiMappingContext $context, string $orderTable, string $encounterTable, string $commentTable, string $narrativeTable): self
    {
        $evidence = new self;
        self::stream($context, $orderTable, function (PhrEhiRevision $row, bool $ambiguous) use ($evidence): void {
            $id = EhiDocumentValues::cell($row, 'ORDER_PROC_ID');
            if ($id !== null) {
                $values = [];
                foreach (self::ORDER_COLUMNS as $column) {
                    $values[$column] = EhiDocumentValues::cell($row, $column);
                }
                $evidence->orders[$id] = ['id' => $row->id, 'ambiguous' => $ambiguous || isset($evidence->orders[$id]), 'values' => $values];
            }
        });
        self::stream($context, $encounterTable, function (PhrEhiRevision $row, bool $ambiguous) use ($evidence): void {
            $csn = EhiDocumentValues::cell($row, 'PAT_ENC_CSN_ID');
            if ($csn !== null) {
                $evidence->encounters[$csn] = ['id' => $row->id, 'ambiguous' => $ambiguous || isset($evidence->encounters[$csn]), 'owner' => EhiDocumentValues::cell($row, 'PAT_ID')];
            }
        });
        self::stream($context, $commentTable, function (PhrEhiRevision $row, bool $ambiguous) use ($evidence): void {
            $key = self::resultKey(EhiDocumentValues::cell($row, 'ORDER_ID'), EhiDocumentValues::cell($row, 'CONTACT_DATE_REAL'), EhiDocumentValues::cell($row, 'LINE'));
            $evidence->comments[$key][] = ['id' => $row->id, 'ambiguous' => $ambiguous, 'line' => (string) EhiDocumentValues::cell($row, 'LINE_COMMENT'), 'text' => (string) EhiDocumentValues::cell($row, 'RESULTS_CMT')];
        });
        self::stream($context, $narrativeTable, function (PhrEhiRevision $row, bool $ambiguous) use ($evidence): void {
            $key = self::contactKey(EhiDocumentValues::cell($row, 'ORDER_PROC_ID'), EhiDocumentValues::cell($row, 'ORD_DATE_REAL'));
            $evidence->narratives[$key][] = ['id' => $row->id, 'ambiguous' => $ambiguous, 'line' => (string) EhiDocumentValues::cell($row, 'LINE'), 'text' => (string) EhiDocumentValues::cell($row, 'NARRATIVE')];
        });

        return $evidence;
    }

    /** @return array{id: int, ambiguous: bool, values: array<string, string|null>}|null */
    public function order(string $orderProcId): ?array
    {
        $order = $this->orders[$orderProcId] ?? null;
        if ($order !== null) {
            $this->usedOrders[$orderProcId] = true;
        }

        return $order;
    }

    /** @return array{id: int, ambiguous: bool, owner: string|null}|null */
    public function encounter(string $csn): ?array
    {
        return $this->encounters[$csn] ?? null;
    }

    /**
     * The result's ordered comment lines.
     *
     * @return list<array{id: int, ambiguous: bool, line: string, text: string}>
     */
    public function commentsOf(string $orderProcId, string $contact, string $line): array
    {
        $key = self::resultKey($orderProcId, $contact, $line);
        if (isset($this->comments[$key])) {
            $this->usedComments[$key] = true;
        }

        return $this->comments[$key] ?? [];
    }

    /**
     * The order contact's narrative lines.
     *
     * @return list<array{id: int, ambiguous: bool, line: string, text: string}>
     */
    public function narrativeOf(string $orderProcId, string $contact): array
    {
        $key = self::contactKey($orderProcId, $contact);
        if (isset($this->narratives[$key])) {
            $this->usedNarratives[$key] = true;
        }

        return $this->narratives[$key] ?? [];
    }

    /** Orders no result of this package named: kept as evidence, nothing to review. */
    public function unusedOrderCount(): int
    {
        return count(array_diff_key($this->orders, $this->usedOrders));
    }

    /** Comment lines whose result this package did not supply under the exact key. */
    public function unmatchedCommentCount(): int
    {
        return (int) array_sum(array_map('count', array_diff_key($this->comments, $this->usedComments)));
    }

    /** Narrative lines of an order contact with no result, as an imaging report has. */
    public function unmatchedNarrativeCount(): int
    {
        return (int) array_sum(array_map('count', array_diff_key($this->narratives, $this->usedNarratives)));
    }

    /** @param  callable(PhrEhiRevision, bool): void  $visit */
    private static function stream(EhiMappingContext $context, string $table, callable $visit): void
    {
        $previous = null;
        foreach ($context->lazyRevisions($table) as $revision) {
            // Every revision of one entity is adjacent; a second one is ambiguous.
            $repeated = $previous !== null && $previous === $revision->entity_id;
            $previous = $revision->entity_id;
            $visit($revision, $repeated);
        }
    }

    private static function resultKey(?string $order, ?string $contact, ?string $line): string
    {
        return implode("\0", [(string) $order, (string) $contact, (string) $line]);
    }

    private static function contactKey(?string $order, ?string $contact): string
    {
        return implode("\0", [(string) $order, (string) $contact]);
    }
}
