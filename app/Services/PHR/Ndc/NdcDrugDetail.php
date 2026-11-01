<?php

namespace App\Services\PHR\Ndc;

use App\Models\PhrMedicationFill;

/**
 * The drug detail a fill's stored snapshot (`fda_snapshot`) carries beyond its columns:
 * ingredients with strengths, class, application number, labeler, and for a RxNav snapshot
 * the DEA schedule, imprint and the like. Display only; read from the snapshot, no lookup.
 * Text is the FDA's/NLM's as listed, so the client casing rules do not apply.
 */
final class NdcDrugDetail
{
    /**
     * @return array<string, mixed>
     */
    public static function forFill(PhrMedicationFill $fill): array
    {
        $snapshot = is_array($fill->fda_snapshot) ? $fill->fda_snapshot : [];
        $rxnav = $fill->ndc_source === PhrMedicationFill::SOURCE_RXNAV;

        $detail = $rxnav ? self::fromRxNav($snapshot, (string) $fill->ndc_10) : self::fromOpenFda($snapshot);

        return [
            'ndc_source' => $fill->ndc_source,
            'discontinued' => $fill->isDiscontinued(),
            'ndc_10' => $fill->ndc_10,
            'ingredients' => $detail['ingredients'],
            'dosage_form' => $fill->dosage_form,
            'route' => $fill->route,
            'pharm_class' => $detail['pharm_class'],
            'application_number' => $detail['application_number'],
            'marketing_category' => $detail['marketing_category'],
            'manufacturer' => $fill->labeler_name,
            'package_description' => $fill->package_description,
            'dea_schedule' => $detail['dea_schedule'],
            'imprint' => $detail['imprint'],
            'color' => $detail['color'],
            'shape' => $detail['shape'],
            'spl_set_id' => $detail['spl_set_id'],
            'links' => NdcLabelService::links($detail['spl_set_id'], $detail['product_ndc']),
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private static function fromOpenFda(array $product): array
    {
        $ingredients = [];
        foreach ((array) ($product['active_ingredients'] ?? []) as $ingredient) {
            if (is_array($ingredient) && is_string($ingredient['name'] ?? null)) {
                $ingredients[] = [
                    'name' => NdcCasing::lowercase($ingredient['name']),
                    'strength' => NdcCasing::measureText(is_string($ingredient['strength'] ?? null) ? $ingredient['strength'] : null),
                ];
            }
        }
        $setId = ((array) ($product['openfda']['spl_set_id'] ?? []))[0] ?? null;

        return [
            'ingredients' => $ingredients,
            'pharm_class' => array_values(array_filter((array) ($product['pharm_class'] ?? []), is_string(...))),
            'application_number' => self::text($product['application_number'] ?? null),
            'marketing_category' => self::text($product['marketing_category'] ?? null),
            'dea_schedule' => self::text($product['dea_schedule'] ?? null),
            'imprint' => null,
            'color' => null,
            'shape' => null,
            'spl_set_id' => is_string($setId) ? $setId : null,
            'product_ndc' => self::text($product['product_ndc'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private static function fromRxNav(array $snapshot, string $ndc10): array
    {
        $properties = RxNavListing::properties($snapshot);
        $values = $properties['values'];
        $ingredients = array_map(static fn (array $ingredient): array => [
            'name' => NdcCasing::lowercase($ingredient['name']),
            'strength' => NdcCasing::measureText($ingredient['strength']),
        ], RxNavListing::ingredients($snapshot));
        $application = $values['NDA'] ?? $values['ANDA'] ?? $values['BLA'] ?? null;

        return [
            'ingredients' => $ingredients,
            'pharm_class' => [],
            'application_number' => self::text($application),
            'marketing_category' => self::text($values['MARKETING_CATEGORY'] ?? null),
            'dea_schedule' => self::text($values['DCSA'] ?? null),
            'imprint' => self::text($values['IMPRINT_CODE'] ?? null),
            'color' => self::text($values['COLORTEXT'] ?? $values['COLOR'] ?? null),
            'shape' => self::text($values['SHAPE'] ?? null),
            'spl_set_id' => $properties['spl_set_id'],
            'product_ndc' => RxNavListing::productNdc($snapshot),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
