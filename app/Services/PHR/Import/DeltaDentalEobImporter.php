<?php

namespace App\Services\PHR\Import;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrPatient;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class DeltaDentalEobImporter
{
    public const string IMPORT_SOURCE = 'delta_dental_eob';

    public function __construct(
        private DeltaDentalEobCsvParser $parser,
        private DeltaDentalEobFingerprint $fingerprint,
        private PhrDocumentImporter $documentImporter,
    ) {}

    /**
     * @return array{scanned: int, imported: int, skipped: int, lines: int, failures: int, warnings: list<string>}
     */
    public function importDirectory(PhrPatient $patient, int $actorUserId, string $directory, bool $dryRun = false): array
    {
        if (! is_dir($directory) || ! is_readable($directory)) {
            throw new RuntimeException("Delta Dental EOB directory is not readable: {$directory}");
        }

        $claims = $this->parser->parseDirectory($directory);
        $result = [
            'scanned' => 0,
            'imported' => 0,
            'skipped' => 0,
            'lines' => 0,
            'failures' => 0,
            'warnings' => [],
        ];

        foreach ($claims as $parsed) {
            $result['scanned']++;
            try {
                $claimNumber = (string) $parsed['claim_number'];
                $externalId = 'eob:delta-dental:'.$claimNumber;
                if (PhrEob::query()
                    ->where('patient_id', $patient->id)
                    ->where('import_source', self::IMPORT_SOURCE)
                    ->where('external_id', $externalId)
                    ->exists()) {
                    $result['skipped']++;

                    continue;
                }

                $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR."Delta EOB {$claimNumber}.pdf";
                if (! is_file($path) || ! is_readable($path)) {
                    throw new RuntimeException('Authoritative claim PDF is missing.');
                }
                $sha256 = hash_file('sha256', $path);
                if ($sha256 === false) {
                    throw new RuntimeException('Unable to hash the authoritative claim PDF.');
                }
                $pdfText = $this->extractText($path);
                if (! str_contains($pdfText, $claimNumber)) {
                    throw new RuntimeException('Claim number was not found in the authoritative claim PDF.');
                }

                $claimFingerprint = $this->fingerprint->fromParsed($parsed);
                if (PhrEob::query()
                    ->where('patient_id', $patient->id)
                    ->where('import_source', self::IMPORT_SOURCE)
                    ->where('claim_fingerprint', $claimFingerprint)
                    ->exists()) {
                    $result['skipped']++;

                    continue;
                }

                $result['lines'] += count($parsed['lines']);
                if ($dryRun) {
                    $result['imported']++;

                    continue;
                }

                DB::transaction(function () use ($patient, $actorUserId, $path, $sha256, $externalId, $claimFingerprint, $pdfText, $parsed): void {
                    $document = PhrDocument::withTrashed()
                        ->where('patient_id', $patient->id)
                        ->where('file_hash', $sha256)
                        ->first();

                    if ($document === null) {
                        $document = $this->documentImporter->storeLocalDocument($patient, $actorUserId, $path, [
                            'title' => 'Delta Dental EOB '.$parsed['claim_number'],
                            'document_type' => 'insurance',
                            'observed_at' => $parsed['service_date'],
                            'extracted_text' => $pdfText,
                            'source' => 'manual_upload',
                            'tags' => ['eob', 'delta-dental', 'dental-eob'],
                            'import_source' => self::IMPORT_SOURCE,
                            'external_id' => $externalId,
                        ]);
                    } elseif ($document->trashed()) {
                        $document->restore();
                    }

                    $eob = PhrEob::create([
                        'patient_id' => $patient->id,
                        'user_id' => $patient->owner_user_id,
                        'source_document_id' => $document->id,
                        'import_source' => self::IMPORT_SOURCE,
                        'external_id' => $externalId,
                        'claim_fingerprint' => $claimFingerprint,
                        'claim_number' => $parsed['claim_number'],
                        'claim_type' => $parsed['claim_type'],
                        'administrator' => $parsed['administrator'],
                        'carrier' => $parsed['carrier'],
                        'plan_name' => $parsed['plan_name'],
                        'group_number' => $parsed['group_number'],
                        'member_id' => $parsed['member_id'],
                        'participant_name' => $parsed['participant_name'],
                        'patient_name' => $parsed['patient_name'],
                        'provider_name' => $parsed['provider_name'],
                        'provider_phone' => $parsed['provider_phone'],
                        'payment_to' => $parsed['payment_to'],
                        'provider_tin' => $parsed['provider_tin'],
                        'check_number' => $parsed['check_number'],
                        'check_amount' => $parsed['check_amount'],
                        'submission_date' => $parsed['submission_date'],
                        'print_date' => $parsed['print_date'],
                        'processed_date' => $parsed['processed_date'],
                        'total_accepted_fee' => $parsed['total_accepted_fee'],
                        'total_charges' => $parsed['total_charges'],
                        'total_provider_discount' => $parsed['total_provider_discount'],
                        'total_ineligible_amount' => $parsed['total_ineligible_amount'],
                        'total_deductible_applied' => $parsed['total_deductible_applied'],
                        'total_copay_applied' => $parsed['total_copay_applied'],
                        'total_benefit_percent' => $parsed['total_benefit_percent'],
                        'total_carrier_payment' => $parsed['total_carrier_payment'],
                        'total_plan_payment' => $parsed['total_plan_payment'],
                        'total_patient_responsibility' => $parsed['total_patient_responsibility'],
                        'parsed_data' => $parsed['parsed_data'],
                        'raw_text' => $pdfText,
                        'parser_version' => DeltaDentalEobCsvParser::PARSER_VERSION,
                    ]);

                    foreach ($parsed['lines'] as $line) {
                        PhrEobLine::create([
                            'eob_id' => $eob->id,
                            'patient_id' => $patient->id,
                            ...$line,
                        ]);
                    }
                });
                $result['imported']++;
            } catch (\Throwable $exception) {
                $result['failures']++;
                $result['warnings'][] = ($parsed['claim_number'] ?? 'unknown claim').': '.$exception->getMessage();
            }
        }

        return $result;
    }

    private function extractText(string $path): string
    {
        $process = new Process(['pdftotext', '-layout', $path, '-']);
        $process->setTimeout(120);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('pdftotext failed: '.trim($process->getErrorOutput()));
        }

        $text = $process->getOutput();
        if (trim($text) === '') {
            throw new RuntimeException('The PDF has no extractable text.');
        }

        return $text;
    }
}
