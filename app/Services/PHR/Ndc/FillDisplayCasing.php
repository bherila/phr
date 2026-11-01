<?php

namespace App\Services\PHR\Ndc;

use App\Models\PhrMedicationFill;

/**
 * Display casing for the text a fill captures from a label or receipt. It is derived when a fill
 * is returned and never stored: the captured value stays as written. Text that is not all caps
 * is always returned as written.
 */
final class FillDisplayCasing
{
    /** Units and abbreviations in directions, by uppercase spelling. */
    private const array UNITS = ['MG' => 'mg', 'MCG' => 'mcg', 'UG' => 'mcg', 'ML' => 'mL', 'G' => 'g', 'IU' => 'IU', 'KG' => 'kg', 'L' => 'L', 'MEQ' => 'mEq', 'MMOL' => 'mmol'];

    /** Latin and clinical abbreviations that stay uppercase in directions. */
    private const array ABBREVIATIONS = [
        'PRN', 'PO', 'BID', 'TID', 'QID', 'QD', 'QHS', 'QAM', 'QPM', 'HS', 'AM', 'PM', 'AC', 'PC', 'IM', 'IV', 'SQ', 'SC',
        'SL', 'PR', 'OD', 'OS', 'OU', 'GT', 'GTT', 'NG', 'ER', 'XR', 'EC', 'DR', 'OTC', 'CPAP', 'BP', 'INR', 'HbA1c', 'NPO', 'ASAP', 'USP',
    ];

    /** The drug name as printed: lowercase when all caps, units in their standard case. Brand-case text is left alone. */
    public static function drugAsPrinted(?string $value): ?string
    {
        $value = self::clean($value);
        if ($value === null || ! NdcCasing::isAllCaps($value, true)) {
            return $value;
        }

        return NdcCasing::measureText($value);
    }

    public static function pharmacyName(?string $value): ?string
    {
        return NdcCasing::properName($value);
    }

    public static function pharmacyLocation(?string $value): ?string
    {
        return NdcCasing::properName($value, true);
    }

    public static function prescriberName(?string $value): ?string
    {
        return NdcCasing::properName($value);
    }

    /** Directions: sentence case when all caps; units and abbreviations (mg, PRN, PO, BID) keep their form. */
    public static function directions(?string $value): ?string
    {
        $value = self::clean($value);
        if ($value === null || ! NdcCasing::isAllCaps($value, false)) {
            return $value;
        }

        return (string) preg_replace_callback('/[A-Za-z][A-Za-z0-9]*/', static function (array $match) use ($value): string {
            $word = $match[0][0];
            $offset = $match[0][1];
            $upper = strtoupper($word);
            if (isset(self::UNITS[$upper])) {
                return self::UNITS[$upper];
            }
            $prefix = substr($value, 0, $offset);
            $starts = trim($prefix) === '' || preg_match('/[.!?]\s+\z/', $prefix) === 1;
            if (in_array($upper, self::ABBREVIATIONS, true) || preg_match('/\AQ\d+H\z/', $upper) === 1) {
                return $upper;
            }

            return $starts ? ucfirst(strtolower($word)) : strtolower($word);
        }, $value, -1, $count, PREG_OFFSET_CAPTURE);
    }

    /**
     * @return array{drug_as_printed: ?string, pharmacy_name: ?string, pharmacy_location: ?string, prescriber_name: ?string, directions: ?string}
     */
    public static function forFill(PhrMedicationFill $fill): array
    {
        return [
            'drug_as_printed' => self::drugAsPrinted($fill->drug_as_printed),
            'pharmacy_name' => self::pharmacyName($fill->pharmacy_name),
            'pharmacy_location' => self::pharmacyLocation($fill->pharmacy_location),
            'prescriber_name' => self::prescriberName($fill->prescriber_name),
            'directions' => self::directions($fill->directions),
        ];
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }
}
