<?php

namespace App\Services\PHR\Import;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrNativeRecordIdentity;
use App\Models\PhrPatient;
use App\Support\Storage\LocalObjectFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Read-only findings, not proposed corrections: CSV rows alone cannot prove line amounts. */
final readonly class DeltaDentalEobAudit
{
    private const int MAX_LINES = 1000;

    private const int MAX_PDF_BYTES = 20 * 1024 * 1024;

    private const int MAX_DETAILS = 100;

    private const array MONEY = [
        'total_accepted_fee' => 'accepted_fee',
        'total_deductible_applied' => 'deductible_applied',
        'total_carrier_payment' => 'carrier_payment',
        'total_plan_payment' => 'plan_payment',
        'total_patient_responsibility' => 'patient_responsibility',
    ];

    private const array CSV_COLUMNS = [
        'accepted_fee' => 'Accepted Fee',
        'deductible_applied' => 'Claim Deductible',
        'carrier_payment' => 'Delta Dental Pays',
        'patient_responsibility' => 'You Pay',
    ];

    private const array FLAGS = [
        'line_totals_mismatch', 'first_line_equals_header_total',
        'legacy_csv_first_row_unverified', 'legacy_csv_unverified',
        'incomplete_amounts', 'line_patient_mismatch', 'line_limit_exceeded',
        'pdf_totals_mismatch', 'pdf_unavailable',
    ];

    public function __construct(private DeltaDentalClaimAmounts $amounts, private EobPdfTextExtractor $pdf) {}

    /**
     * Never assigns missing public ids or writes audit rows. Default output is
     * counts only; optional details are bounded and contain opaque ids and codes.
     *
     * @return array{report_version: string, counts: array<string, int>, records: list<array{public_id: string, flags: list<string>}>}
     */
    public function run(PhrPatient $patient, bool $verifyPdfs = false, bool $details = false): array
    {
        $counts = array_fill_keys(['scanned', 'flagged', 'pdf_totals_match', 'details_omitted', ...self::FLAGS], 0);
        $records = [];
        $claims = PhrEob::query()->where('patient_id', $patient->id)
            ->where('import_source', DeltaDentalEobImporter::IMPORT_SOURCE)
            ->select(['id', 'patient_id', 'source_document_id', 'claim_number', 'parsed_data', 'parser_version', ...array_keys(self::MONEY)])
            ->addSelect(['audit_public_id' => PhrNativeRecordIdentity::query()->select('native_id')
                ->where('patient_id', $patient->id)->where('record_table', 'phr_eobs')
                ->whereColumn('record_id', 'phr_eobs.id')->limit(1)])
            ->withCount('lines')->lazyById(50);

        foreach ($claims as $claim) {
            $counts['scanned']++;
            $totals = null;
            $flags = [];
            if ((int) $claim->getAttribute('lines_count') > self::MAX_LINES) {
                $flags[] = 'line_limit_exceeded';
            } else {
                $lines = $claim->lines()->where('patient_id', $patient->id)->orderBy('line_number')->orderBy('id')
                    ->get(['id', 'eob_id', 'patient_id', 'line_number', 'parsed_data', ...array_values(self::MONEY)]);
                if ($lines->count() !== (int) $claim->getAttribute('lines_count')) {
                    $flags[] = 'line_patient_mismatch';
                }
                if ($verifyPdfs) {
                    try {
                        $totals = $this->pdfTotals($claim);
                    } catch (Throwable) {
                        // Parser and filesystem errors may contain clinical values or paths.
                        $flags[] = 'pdf_unavailable';
                    }
                }
                $assessment = $this->assess($claim, $lines->all(), $totals);
                array_push($flags, ...$assessment['flags']);
                if ($assessment['pdf_totals_match'] && $flags === []) {
                    $counts['pdf_totals_match']++;
                }
            }
            $flags = array_values(array_unique($flags));
            if ($flags === []) {
                continue;
            }
            $counts['flagged']++;
            foreach ($flags as $flag) {
                $counts[$flag]++;
            }
            if ($details) {
                $publicId = $claim->getAttribute('audit_public_id');
                if (count($records) < self::MAX_DETAILS && is_string($publicId) && $publicId !== '') {
                    $records[] = ['public_id' => $publicId, 'flags' => $flags];
                } else {
                    $counts['details_omitted']++;
                }
            }
        }

        return ['report_version' => 'delta-dental-audit-v1', 'counts' => $counts, 'records' => $records];
    }

    /**
     * @param  list<PhrEobLine>  $lines
     * @param  array<string, int>|null  $pdfTotals
     * @return array{flags: list<string>, pdf_totals_match: bool}
     */
    private function assess(PhrEob $claim, array $lines, ?array $pdfTotals): array
    {
        $flags = [];
        $complete = $lines !== [];
        $matchesPdf = $pdfTotals !== null;
        foreach (self::MONEY as $header => $column) {
            $total = $this->cents($claim->getAttribute($header));
            $values = array_map(fn (PhrEobLine $line): ?int => $this->cents($line->getAttribute($column)), $lines);
            if ($total === null || in_array(null, $values, true) || $values === []) {
                $complete = false;

                continue;
            }
            $sum = array_sum($values);
            if ($sum !== $total) {
                $flags[] = 'line_totals_mismatch';
            }
            if (count($values) > 1 && $values[0] > 0 && $values[0] === $total && array_sum(array_slice($values, 1)) > 0) {
                $flags[] = 'first_line_equals_header_total';
            }
            if ($pdfTotals !== null && ($total !== $pdfTotals[$column] || $sum !== $pdfTotals[$column])) {
                $flags[] = 'pdf_totals_mismatch';
                $matchesPdf = false;
            }
        }
        if (! $complete) {
            $flags[] = 'incomplete_amounts';
        }
        if ($pdfTotals === null && count($lines) > 1 && $this->hasNonzeroRemainingLine($lines)) {
            $parsed = (array) $claim->parsed_data;
            if (($parsed['amount_validation']['source'] ?? null) !== 'authoritative_claim_pdf'
                && ($claim->getAttribute('parser_version') === 'delta-dental-csv-v1' || isset($parsed['source_csv']) || isset($parsed['rows']))) {
                $firstParsed = $lines[0]->getAttribute('parsed_data');
                $row = is_array($firstParsed) ? ($firstParsed['source_row'] ?? null) : null;
                $row ??= $parsed['rows'][0] ?? null;
                if (! is_array($row)) {
                    $flags[] = 'legacy_csv_unverified';
                } else {
                    $matches = $this->firstLineStillMatchesCsv($lines[0], $row);
                    if ($matches === null) {
                        $flags[] = 'legacy_csv_unverified';
                    } elseif ($matches) {
                        // This catches the old 301 header with 178/46/77 lines too.
                        // It does not claim the CSV's first amount is a known total.
                        $flags[] = 'legacy_csv_first_row_unverified';
                    }
                }
            }
        }

        return ['flags' => array_values(array_unique($flags)), 'pdf_totals_match' => $matchesPdf && $complete];
    }

    /** @param list<PhrEobLine> $lines */
    private function hasNonzeroRemainingLine(array $lines): bool
    {
        foreach (array_slice($lines, 1) as $line) {
            foreach (self::CSV_COLUMNS as $column => $label) {
                $amount = $this->cents($line->getAttribute($column));
                if ($amount !== null && $amount !== 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<array-key, mixed> $row */
    private function firstLineStillMatchesCsv(PhrEobLine $line, array $row): ?bool
    {
        $matches = true;
        foreach (self::CSV_COLUMNS as $column => $label) {
            $source = $this->cents($row[$label] ?? null);
            if ($source === null) {
                return null;
            }
            if ($source !== $this->cents($line->getAttribute($column))) {
                $matches = false;
            }
        }

        return $matches;
    }

    /** @return array<string, int> */
    private function pdfTotals(PhrEob $claim): array
    {
        if ($claim->claim_number === null || $claim->claim_number === '') {
            throw new RuntimeException('The source claim cannot be identified.');
        }
        $document = PhrDocument::query()->where('patient_id', $claim->patient_id)->find($claim->source_document_id);
        if (! $document instanceof PhrDocument || $document->storage_disk !== PhrDocument::STORAGE_DISK
            || $document->storage_path === null || $document->mime_type !== 'application/pdf' || $document->file_hash === null
            || $document->byte_size > self::MAX_PDF_BYTES) {
            throw new RuntimeException('The source PDF cannot be verified.');
        }
        $disk = Storage::disk(PhrDocument::STORAGE_DISK);
        if (! $disk->exists($document->storage_path) || $disk->size($document->storage_path) !== $document->byte_size) {
            throw new RuntimeException('The source PDF bytes changed.');
        }
        $expectedHash = (string) $document->file_hash;
        $totals = LocalObjectFile::with(PhrDocument::STORAGE_DISK, $document->storage_path, function (string $path) use ($expectedHash, $claim): array {
            if (! hash_equals($expectedHash, (string) hash_file('sha256', $path))) {
                throw new RuntimeException('The source PDF bytes changed.');
            }

            return $this->amounts->totalsFromPdf((string) $claim->claim_number, $this->pdf->extractBounded($path, 1024 * 1024));
        });
        $totals['plan_payment'] = $totals['carrier_payment'];

        return $totals;
    }

    private function cents(mixed $value): ?int
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/^-?\d+(?:\.\d{1,2})?$/', (string) $value) !== 1) {
            return null;
        }

        return (int) round((float) $value * 100);
    }
}
