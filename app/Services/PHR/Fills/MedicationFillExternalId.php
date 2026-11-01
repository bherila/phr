<?php

namespace App\Services\PHR\Fills;

/**
 * The deterministic identity of a fill, so a receipt and a label photo of the
 * same dispensing land on one record instead of two.
 */
final class MedicationFillExternalId
{
    /**
     * Pharmacy, Rx number and fill date when the Rx number is known; otherwise
     * the source document and where in it the fill was read. Null when neither
     * is available, in which case the caller must supply an external ID.
     *
     * @param  array<string, mixed>  $data
     */
    public static function derive(array $data, ?int $sourceDocumentId): ?string
    {
        $rx = self::normalize($data['rx_number'] ?? null);
        $pharmacy = self::normalize($data['pharmacy_name'] ?? null);
        $filledOn = isset($data['filled_on']) ? substr((string) $data['filled_on'], 0, 10) : '';
        if ($rx !== '' && $pharmacy !== '' && $filledOn !== '') {
            return 'rx:'.substr(hash('sha256', $pharmacy.'|'.$rx.'|'.$filledOn), 0, 40);
        }

        if ($sourceDocumentId !== null) {
            $locator = self::normalize($data['source_locator'] ?? null);

            return 'doc:'.substr(hash('sha256', $sourceDocumentId.'|'.$locator), 0, 40);
        }

        return null;
    }

    private static function normalize(mixed $value): string
    {
        return is_scalar($value) ? strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $value)) : '';
    }
}
