<?php

namespace App\Support\PHR;

use Illuminate\Database\Eloquent\Builder;

/**
 * A medication's setting (issue #413): the FHIR `medicationrequest-category` codes.
 *
 * - `community`: the patient's home medication list (what they take at home).
 * - `inpatient`: an order for a hospital stay, given there.
 * - `outpatient`: an order given in a clinic, an emergency department or a surgery centre.
 * - `discharge`: a medication on the list a hospital sent the patient home with.
 *
 * Null is unknown and is never read as `community`: a source that did not say is not
 * assumed to have meant the home list. The home list a person sees (and fills link
 * to) is `community` together with unknown, so a row nobody categorised stays where it
 * always was. Inpatient orders leave every default list.
 *
 * A row in any setting but `community` keeps its source's id scoped by the setting
 * (`discharge:` + the id): a C-CDA entry listed in both a document's Medications and
 * its Discharge Medications section is two facts, and the importer's upsert, which
 * matches on (patient, import source, external id), would otherwise move one row
 * between the two lists on every import.
 */
final class PhrMedicationCategory
{
    public const string INPATIENT = 'inpatient';

    public const string OUTPATIENT = 'outpatient';

    public const string COMMUNITY = 'community';

    public const string DISCHARGE = 'discharge';

    /** The list filter value for a row with no category. */
    public const string UNKNOWN = 'unknown';

    /** @var list<string> */
    public const array ALL = [self::INPATIENT, self::OUTPATIENT, self::COMMUNITY, self::DISCHARGE];

    /** @var list<string> what a list filter accepts: a category, or unknown */
    public const array FILTERS = [...self::ALL, self::UNKNOWN];

    /** FHIR MedicationRequest.category. */
    public const string REQUEST_SYSTEM = 'http://terminology.hl7.org/CodeSystem/medicationrequest-category';

    /** FHIR MedicationStatement.category; its codes are inpatient, outpatient, community and patientspecified. */
    public const string STATEMENT_SYSTEM = 'http://terminology.hl7.org/CodeSystem/medication-statement-category';

    /** The system URIs an R4 source may name the request categories by. */
    public const array REQUEST_SYSTEMS = [self::REQUEST_SYSTEM, 'http://hl7.org/fhir/medication-request-category'];

    /** The system URIs an R4 source may name the statement categories by. */
    public const array STATEMENT_SYSTEMS = [self::STATEMENT_SYSTEM, 'http://hl7.org/fhir/medication-statement-category'];

    /** The import source of a medication a prescription fill created (MedicationFillMedicationLinker). */
    public const string FILL_IMPORT_SOURCE = 'phr-fill';

    public const string CCDA_IMPORT_SOURCE = 'ccda';

    /** phr_medications.external_id width. */
    private const int MAX_EXTERNAL_ID_BYTES = 255;

    /** @var array<string, string> */
    private const array LABELS = [
        self::COMMUNITY => 'Home',
        self::INPATIENT => 'Hospital order',
        self::OUTPATIENT => 'Outpatient order',
        self::DISCHARGE => 'Discharge',
    ];

    /** A category this vocabulary knows, from a stated value; null otherwise. */
    public static function code(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return in_array($value, self::ALL, true) ? $value : null;
    }

    public static function label(?string $category): ?string
    {
        return $category === null ? null : (self::LABELS[$category] ?? null);
    }

    /** Whether a row in this category is on the patient's home list: community, or unknown. */
    public static function isHomeList(?string $category): bool
    {
        return $category === null || $category === self::COMMUNITY;
    }

    /**
     * The id a row in this category is stored under. A community or uncategorised row
     * keeps its source's id, as every row did before categories; any other is scoped.
     */
    public static function scopedExternalId(?string $externalId, ?string $category): ?string
    {
        if ($externalId === null || self::isHomeList($category)) {
            return $externalId;
        }
        $prefix = $category.':';
        if (str_starts_with($externalId, $prefix)) {
            return $externalId;
        }
        $scoped = $prefix.$externalId;

        return strlen($scoped) <= self::MAX_EXTERNAL_ID_BYTES ? $scoped : $prefix.'sha256:'.hash('sha256', $externalId);
    }

    /**
     * The category a FHIR resource's `category` states, from either category system
     * (or a coding with no system whose code is one of ours). The statement system's
     * `patientspecified` is not a setting.
     */
    public static function fromFhir(mixed $categories): ?string
    {
        $concepts = is_array($categories) && array_is_list($categories) ? $categories : [$categories];
        foreach ($concepts as $concept) {
            if (! is_array($concept)) {
                continue;
            }
            foreach (is_array($concept['coding'] ?? null) ? $concept['coding'] : [] as $coding) {
                if (! is_array($coding)) {
                    continue;
                }
                $system = $coding['system'] ?? null;
                $known = $system === null || in_array($system, [...self::REQUEST_SYSTEMS, ...self::STATEMENT_SYSTEMS], true);
                $code = $known ? self::code($coding['code'] ?? null) : null;
                if ($code !== null) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * Restricts a medication query to the given list filters (categories, and
     * `unknown` for a row with none).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $filters
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, array $filters, string $column = 'category'): Builder
    {
        $categories = array_values(array_intersect($filters, self::ALL));
        $unknown = in_array(self::UNKNOWN, $filters, true);

        return $query->where(function (Builder $matches) use ($categories, $unknown, $column): void {
            if ($categories !== []) {
                $matches->whereIn($column, $categories);
            }
            if ($unknown) {
                $matches->orWhereNull($column);
            }
            if ($categories === [] && ! $unknown) {
                $matches->whereRaw('1 = 0');
            }
        });
    }

    /**
     * Restricts a medication query to the home list: community, or unknown.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scopeHomeList(Builder $query, string $column = 'category'): Builder
    {
        return self::scope($query, [self::COMMUNITY, self::UNKNOWN], $column);
    }

    /**
     * The category a row written before categories existed holds: the one the
     * migration gave it (2026_11_23_100000). A row a person entered (no import source),
     * one a prescription fill created, and a C-CDA row the import still stands by (not
     * retracted, so read from a Medications section) are home medications. Anything
     * else is unknown. A native backup written before the column reads its rows the
     * same way, so a row restored from it matches the row the migration updated.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function backfilled(array $attributes): ?string
    {
        $source = $attributes['import_source'] ?? null;
        $home = $source === null
            || $source === self::FILL_IMPORT_SOURCE
            || ($source === self::CCDA_IMPORT_SOURCE && ($attributes['retracted_at'] ?? null) === null);

        return $home ? self::COMMUNITY : null;
    }
}
