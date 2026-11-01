<?php

namespace App\Services\PHR\Eob;

use App\Services\PHR\Import\DeltaDentalEobFingerprint;
use App\Services\PHR\Import\MeritainEobFingerprint;
use Illuminate\Support\Carbon;

/**
 * An EOB claim in the shape the importers' parsers produce, whoever read it.
 *
 * An agent's claim is normalized into the parsers' shape (strings trimmed, money
 * as two-decimal strings, dates as Y-m-d, totals summed from the lines when not
 * stated) so that the importers' own fingerprints identify it: the same claim
 * read by a server parser and by an agent is one claim.
 */
final class EobClaim
{
    public const array CLAIM_TYPES = ['medical', 'dental', 'vision', 'pharmacy', 'unknown'];

    /** @var list<string> */
    public const array HEADER_TEXT = [
        'claim_number', 'administrator', 'carrier', 'plan_name', 'group_number',
        'participant_name', 'patient_name', 'provider_name', 'provider_phone', 'payment_to', 'provider_tin', 'check_number',
        'member_id',
    ];

    /** @var list<string> */
    public const array HEADER_DATES = ['submission_date', 'print_date', 'processed_date'];

    /** @var list<string> */
    public const array HEADER_MONEY = [
        'check_amount', 'total_accepted_fee', 'total_charges', 'total_provider_discount', 'total_ineligible_amount',
        'total_deductible_applied', 'total_copay_applied', 'total_benefit_percent', 'total_carrier_payment',
        'total_plan_payment', 'total_patient_responsibility',
    ];

    /** Header total => the line column it sums when the claim does not state it. */
    private const array SUMMED = [
        'total_accepted_fee' => 'accepted_fee', 'total_charges' => 'total_charges',
        'total_provider_discount' => 'provider_discount', 'total_ineligible_amount' => 'ineligible_amount',
        'total_deductible_applied' => 'deductible_applied', 'total_copay_applied' => 'copay_applied',
        'total_carrier_payment' => 'carrier_payment', 'total_plan_payment' => 'plan_payment',
        'total_patient_responsibility' => 'patient_responsibility',
    ];

    /** @var list<string> */
    public const array LINE_MONEY = [
        'accepted_fee', 'total_charges', 'provider_discount', 'ineligible_amount', 'deductible_applied',
        'copay_applied', 'benefit_percent', 'carrier_payment', 'plan_payment', 'patient_responsibility',
    ];

    /**
     * @param  array<string, mixed>  $data  the request's data object (header fields and lines)
     * @param  bool  $deriveTotals  sum the lines into totals the claim does not state
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public static function normalize(array $data, bool $deriveTotals = true): array
    {
        $header = ['claim_type' => in_array($data['claim_type'] ?? null, self::CLAIM_TYPES, true) ? $data['claim_type'] : 'unknown'];
        foreach (self::HEADER_TEXT as $key) {
            $header[$key] = self::text($data[$key] ?? null);
        }
        foreach (self::HEADER_DATES as $key) {
            $header[$key] = self::date($data[$key] ?? null);
        }
        foreach (self::HEADER_MONEY as $key) {
            $header[$key] = self::money($data[$key] ?? null);
        }

        $lines = [];
        $rawLines = array_values((array) ($data['lines'] ?? []));
        // A line without a number takes the next one no other line claims, so explicit and
        // omitted numbers can never collide.
        $taken = [];
        foreach ($rawLines as $raw) {
            if (isset(((array) $raw)['line_number'])) {
                $taken[(int) ((array) $raw)['line_number']] = true;
            }
        }
        $next = 0;
        foreach ($rawLines as $line) {
            $line = (array) $line;
            if (isset($line['line_number'])) {
                $number = (int) $line['line_number'];
            } else {
                do {
                    $next++;
                } while (isset($taken[$next]));
                $number = $next;
            }
            $normalized = [
                'line_number' => $number,
                'procedure_code' => strtoupper((string) self::text($line['procedure_code'] ?? null)),
                'revenue_code' => self::text($line['revenue_code'] ?? null),
                'code_type' => self::text($line['code_type'] ?? null) ?? 'unknown',
                'description' => self::text($line['description'] ?? null),
                'service_start' => self::date($line['service_start'] ?? null),
                'service_end' => self::date($line['service_end'] ?? $line['service_start'] ?? null),
                'notes_applied' => isset($line['notes_applied']) && is_array($line['notes_applied']) ? array_values($line['notes_applied']) : null,
            ];
            foreach (self::LINE_MONEY as $key) {
                $normalized[$key] = self::money($line[$key] ?? null);
            }
            if (isset($line['fill']) && is_array($line['fill']) && $line['fill'] !== []) {
                $normalized['fill'] = $line['fill'];
            }
            $lines[] = $normalized;
        }

        if ($deriveTotals) {
            foreach (self::SUMMED as $total => $column) {
                $values = array_filter(array_column($lines, $column), static fn (mixed $value): bool => $value !== null);
                if ($header[$total] === null && $values !== []) {
                    $header[$total] = number_format(array_sum(array_map('floatval', $values)), 2, '.', '');
                }
            }
        }

        return ['header' => $header, 'lines' => $lines];
    }

    /**
     * The fingerprint the importers would give this claim: the dental importer's for a
     * dental claim, the Meritain importer's for everything else.
     *
     * @param  array{header: array<string, mixed>, lines: list<array<string, mixed>>}  $claim
     */
    public static function fingerprint(array $claim): string
    {
        $parsed = [...$claim['header'], 'lines' => array_map(static fn (array $line): array => array_diff_key($line, ['fill' => true]), $claim['lines'])];
        $parsed['service_date'] = $claim['lines'][0]['service_start'] ?? null;

        return $claim['header']['claim_type'] === 'dental'
            ? (new DeltaDentalEobFingerprint)->fromParsed($parsed)
            : (new MeritainEobFingerprint)->fromParsed($parsed);
    }

    /**
     * An external identity for a claim the caller did not name: the claim number
     * (with its type and processed date) when there is one, so a re-read of a
     * corrected claim is recognised as that claim; otherwise its content.
     *
     * @param  array{header: array<string, mixed>, lines: list<array<string, mixed>>}  $claim
     */
    public static function externalId(array $claim, string $fingerprint): string
    {
        $number = $claim['header']['claim_number'];
        if ($number === null) {
            return 'eob:fp:'.substr($fingerprint, 0, 40);
        }

        return 'eob:claim:'.substr(hash('sha256', strtolower($claim['header']['claim_type'].'|'.$number.'|'.($claim['header']['processed_date'] ?? ''))), 0, 40);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $text = self::text($value);

        return $text === null ? null : Carbon::parse($text)->toDateString();
    }

    private static function money(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }
}
