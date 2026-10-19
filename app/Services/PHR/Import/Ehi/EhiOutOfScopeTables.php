<?php

namespace App\Services\PHR\Import\Ehi;

/**
 * Versioned registry of export tables whose rows are accounted `out_of_scope`
 * rather than `retained_unmapped`. Either way the rows stay in the preserved
 * member bytes and are counted; the disposition only records that no clinical
 * mapping is planned for them.
 *
 * Deliberately conservative (VERSION ehi-oos-1): only professional-billing and
 * hospital-billing transaction ledgers, which carry charges, payments and
 * adjustments rather than clinical facts. Encounter, order, result, note,
 * medication, immunization, problem, and message tables never belong here, nor
 * do claim or coverage tables a later EOB reconciliation might want. Matching is
 * exact on the upper-cased table name. Any change must bump VERSION.
 */
final class EhiOutOfScopeTables
{
    public const string VERSION = 'ehi-oos-1';

    public const array TABLES = [
        'ARPB_TRANSACTIONS',
        'ARPB_TX_ACTIONS',
        'ARPB_TX_MATCH_HX',
        'ARPB_TX_VOID',
        'HSP_TRANSACTIONS',
    ];

    public static function contains(string $tableName): bool
    {
        return in_array(strtoupper($tableName), self::TABLES, true);
    }
}
