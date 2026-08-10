<?php

namespace App\Services\PHR\Import;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrPatient;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class MeritainEobImporter
{
    public const string IMPORT_SOURCE = 'meritain_eob';

    public function __construct(
        private MeritainEobParser $parser,
        private MeritainEobFingerprint $fingerprint,
        private PhrDocumentImporter $documentImporter,
    ) {}

    /**
     * @return array{scanned: int, imported: int, skipped: int, duplicates: int, lines: int, failures: int, warnings: array<int, string>}
     */
    public function importDirectory(PhrPatient $patient, int $actorUserId, string $directory, bool $dryRun = false): array
    {
        if (! is_dir($directory) || ! is_readable($directory)) {
            throw new RuntimeException("EOB directory is not readable: {$directory}");
        }

        $paths = array_values(array_filter(
            glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.pdf') ?: [],
            static fn (string $path): bool => ! str_starts_with(strtolower(basename($path)), 'exportprintclaimsummary')
        ));
        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
        $result = [
            'scanned' => 0,
            'imported' => 0,
            'skipped' => 0,
            'duplicates' => 0,
            'lines' => 0,
            'failures' => 0,
            'warnings' => [],
        ];
        $seenClaimFingerprints = [];

        foreach ($paths as $path) {
            $result['scanned']++;
            try {
                $sha256 = hash_file('sha256', $path);
                if ($sha256 === false) {
                    throw new RuntimeException('Unable to hash the PDF.');
                }

                $externalId = 'eob:meritain:'.$sha256;
                if (PhrEob::query()
                    ->where('patient_id', $patient->id)
                    ->where('import_source', self::IMPORT_SOURCE)
                    ->where('external_id', $externalId)
                    ->exists()) {
                    $result['skipped']++;

                    continue;
                }

                $text = $this->extractText($path);
                $parsed = $this->parser->parse($text, basename($path));
                if (($parsed['lines'] ?? []) === []) {
                    throw new RuntimeException('No EOB service lines were extracted.');
                }

                $claimFingerprint = $this->fingerprint->fromParsed($parsed);
                if (isset($seenClaimFingerprints[$claimFingerprint]) || PhrEob::query()
                    ->where('patient_id', $patient->id)
                    ->where('import_source', self::IMPORT_SOURCE)
                    ->where('claim_fingerprint', $claimFingerprint)
                    ->exists()) {
                    $result['duplicates']++;

                    continue;
                }
                $seenClaimFingerprints[$claimFingerprint] = true;

                $result['lines'] += count($parsed['lines']);
                if ($dryRun) {
                    $result['imported']++;

                    continue;
                }

                DB::transaction(function () use ($patient, $actorUserId, $path, $sha256, $externalId, $claimFingerprint, $parsed): void {
                    $document = PhrDocument::withTrashed()
                        ->where('patient_id', $patient->id)
                        ->where('file_hash', $sha256)
                        ->first();

                    if ($document === null) {
                        $document = $this->documentImporter->storeLocalDocument($patient, $actorUserId, $path, [
                            'title' => $parsed['claim_number'] !== null
                                ? 'Meritain EOB '.$parsed['claim_number']
                                : pathinfo($path, PATHINFO_FILENAME),
                            'document_type' => 'insurance',
                            'observed_at' => $parsed['processed_date'] ?? $parsed['print_date'],
                            'extracted_text' => $parsed['raw_text'],
                            'source' => 'manual_upload',
                            'tags' => [
                                'eob',
                                'meritain',
                                ($parsed['claim_type'] ?? 'unknown').'-eob',
                            ],
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
                        'payment_to' => $parsed['payment_to'],
                        'provider_tin' => $parsed['provider_tin'],
                        'check_number' => $parsed['check_number'],
                        'check_amount' => $parsed['check_amount'],
                        'print_date' => $parsed['print_date'],
                        'processed_date' => $parsed['processed_date'],
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
                        'raw_text' => $parsed['raw_text'],
                        'parser_version' => MeritainEobParser::PARSER_VERSION,
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
                $result['warnings'][] = basename($path).': '.$exception->getMessage();
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
