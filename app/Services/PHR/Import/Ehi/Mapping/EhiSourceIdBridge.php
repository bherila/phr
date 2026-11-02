<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

use App\Models\PhrEhiProposal;

/**
 * The versioned contract for reading an identifier an earlier import stored.
 *
 * A record's existing identifiers are only ever read here; nothing changes them.
 * Exact matches are rare by construction: FHIR ids are opaque and current C-CDA
 * ids are content hashes, so most overlap surfaces as a probable duplicate
 * instead, which a person decides. A rule below holds only for a byte-exact match
 * on an identifier whose meaning the rule states. Records of another declared EHI
 * source are never bridged: its keys belong to another installation, and equal
 * numbers there mean nothing. Bumping VERSION is how a rule changes; links keep
 * the version and rule that justified them.
 */
final class EhiSourceIdBridge
{
    public const string VERSION = 'ehi-bridge-1';

    /** A person chose the existing record (link_existing). */
    public const string RULE_PERSON_CONFIRMED = 'person_confirmed';

    /**
     * An earlier C-CDA import kept the entry's own id extension, alone or as
     * `root|extension` (see PhrCcdaEntry::$sourceIds), and it equals the row's
     * ORDER_PROC_ID. That names the order rather than a component, so the rule
     * also needs the analyte to match.
     */
    public const string RULE_CCDA_ORDER_EXTENSION = 'ccda_order_extension';

    /** PhrCcdaImporter's import_source. */
    private const string CCDA_IMPORT_SOURCE = 'ccda';

    /** Current C-CDA identifiers are content hashes with this prefix, not ids. */
    private const string CCDA_HASH_PREFIX = 'ccda-';

    /**
     * The id extension a C-CDA record's external id preserves, or null if it
     * preserves none. Exact bytes: external ids are case-sensitive.
     */
    public static function ccdaExtension(?string $importSource, ?string $externalId): ?string
    {
        if ($importSource !== self::CCDA_IMPORT_SOURCE || $externalId === null || $externalId === '' || str_starts_with($externalId, self::CCDA_HASH_PREFIX)) {
            return null;
        }
        $separator = strrpos($externalId, '|');
        $extension = $separator === false ? $externalId : substr($externalId, $separator + 1);

        return $extension === '' ? null : $extension;
    }

    /** @return array{version: string, rule: string} */
    public static function matched(string $rule): array
    {
        return ['version' => self::VERSION, 'rule' => $rule];
    }

    /**
     * The bridge an applied decision records, or null when the decision is not a
     * bridge: a person's link, or accepting a corroboration a rule proposed.
     * Accepting a duplicate adds a separate record, so it bridges nothing.
     *
     * @return array{version: string, rule: string}|null
     */
    public static function of(PhrEhiProposal $proposal, string $decision): ?array
    {
        if ($decision === PhrEhiProposal::DECISION_LINK) {
            return self::matched(self::RULE_PERSON_CONFIRMED);
        }
        $bridge = $proposal->proposed_json['bridge'] ?? null;
        if ($proposal->kind !== PhrEhiProposal::KIND_CORROBORATE || ! is_array($bridge)
            || ! is_string($bridge['version'] ?? null) || ! is_string($bridge['rule'] ?? null)) {
            return null;
        }

        return ['version' => $bridge['version'], 'rule' => $bridge['rule']];
    }
}
