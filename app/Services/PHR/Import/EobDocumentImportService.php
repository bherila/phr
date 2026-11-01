<?php

namespace App\Services\PHR\Import;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrPatient;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Imports one stored document as an EOB with the server's own parsers, when it
 * is a format they read (a Meritain EOB PDF, or a Delta Dental claim CSV), and
 * says so plainly when it is not so the caller can extract the claim itself.
 *
 * Idempotent on the claim fingerprint, across sources: a claim an agent upserted
 * earlier, or a console importer wrote, is the same claim and is not written
 * again. New claims land pending review, like every agent-triggered write.
 */
final class EobDocumentImportService
{
    public const string CREATED = 'created';

    public const string UNCHANGED = 'unchanged';

    public const string UNRECOGNISED = 'unrecognised_format';

    public function __construct(
        private readonly MeritainEobParser $meritainParser,
        private readonly MeritainEobFingerprint $meritainFingerprint,
        private readonly MeritainEobImporter $meritain,
        private readonly DeltaDentalEobCsvParser $deltaParser,
        private readonly DeltaDentalEobFingerprint $deltaFingerprint,
        private readonly DeltaDentalEobImporter $delta,
        private readonly EobPdfTextExtractor $pdf,
    ) {}

    /**
     * @return array{outcome: string, format: ?string, eobs: list<array{eob: PhrEob, outcome: string}>}
     */
    public function import(PhrPatient $patient, PhrDocument $document): array
    {
        if ($document->storage_disk !== PhrDocument::STORAGE_DISK || $document->storage_path === null
            || ! Storage::disk(PhrDocument::STORAGE_DISK)->exists($document->storage_path)) {
            throw ValidationException::withMessages(['document_id' => 'The document has no stored file to import.']);
        }
        $path = Storage::disk(PhrDocument::STORAGE_DISK)->path($document->storage_path);
        $prefix = (string) file_get_contents($path, false, null, 0, 4096);

        if (str_starts_with($prefix, '%PDF')) {
            return $this->importMeritain($patient, $document, $path);
        }
        $firstLine = strtok(ltrim($prefix, "\xEF\xBB\xBF"), "\r\n");
        if ($firstLine === DeltaDentalEobCsvParser::headerLine()) {
            return $this->importDelta($patient, $document, $path);
        }

        return $this->unrecognised();
    }

    /** @return array{outcome: string, format: ?string, eobs: list<array{eob: PhrEob, outcome: string}>} */
    private function importMeritain(PhrPatient $patient, PhrDocument $document, string $path): array
    {
        try {
            $parsed = $this->meritainParser->parse($this->pdf->extract($path), (string) $document->original_filename);
        } catch (RuntimeException) {
            // No text layer (a scan) or no pdftotext: nothing the parser can read.
            return $this->unrecognised();
        }
        if (($parsed['lines'] ?? []) === [] || ($parsed['claim_number'] ?? null) === null) {
            return $this->unrecognised();
        }

        $fingerprint = $this->meritainFingerprint->fromParsed($parsed);
        $hash = $document->file_hash ?? (string) hash_file('sha256', $path);

        return $this->persist(
            $patient,
            $fingerprint,
            MeritainEobImporter::IMPORT_SOURCE,
            'eob:meritain:'.$hash,
            'meritain',
            fn (): PhrEob => $this->meritain->persistClaim(
                $patient, $parsed, (int) $document->id, 'eob:meritain:'.$hash, $fingerprint, (string) $parsed['raw_text'], PhrReviewStatus::PENDING,
            ),
        );
    }

    /** @return array{outcome: string, format: ?string, eobs: list<array{eob: PhrEob, outcome: string}>} */
    private function importDelta(PhrPatient $patient, PhrDocument $document, string $path): array
    {
        try {
            $claims = $this->deltaParser->parse($path);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['document_id' => 'The document is a Delta Dental claim export that could not be read: '.$exception->getMessage()]);
        }

        $eobs = [];
        foreach ($claims as $parsed) {
            $fingerprint = $this->deltaFingerprint->fromParsed($parsed);
            $rawText = implode("\n", array_map(static fn (array $line): string => (string) ($line['raw_text'] ?? ''), $parsed['lines']));
            $result = $this->persist(
                $patient,
                $fingerprint,
                DeltaDentalEobImporter::IMPORT_SOURCE,
                'eob:delta-dental:'.$parsed['claim_number'],
                'delta_dental',
                fn (): PhrEob => $this->delta->persistClaim(
                    $patient, $parsed, (int) $document->id, 'eob:delta-dental:'.$parsed['claim_number'], $fingerprint, $rawText, PhrReviewStatus::PENDING,
                ),
            );
            array_push($eobs, ...$result['eobs']);
        }
        $created = array_filter($eobs, static fn (array $entry): bool => $entry['outcome'] === self::CREATED) !== [];

        return ['outcome' => $created ? self::CREATED : self::UNCHANGED, 'format' => 'delta_dental', 'eobs' => $eobs];
    }

    /**
     * @param  callable(): PhrEob  $create
     * @return array{outcome: string, format: ?string, eobs: list<array{eob: PhrEob, outcome: string}>}
     */
    private function persist(PhrPatient $patient, string $fingerprint, string $source, string $externalId, string $format, callable $create): array
    {
        $eob = DB::transaction(function () use ($patient, $fingerprint, $source, $externalId, $create): array {
            PhrPatient::query()->whereKey($patient->id)->lockForUpdate()->first();
            $existing = PhrEob::query()->where('patient_id', $patient->id)
                ->where(fn ($match) => $match->where('claim_fingerprint', $fingerprint)
                    ->orWhere(fn ($same) => $same->where('import_source', $source)->where('external_id', $externalId)))
                ->orderBy('id')->first();
            if ($existing instanceof PhrEob) {
                return [$existing, self::UNCHANGED];
            }

            return [$create(), self::CREATED];
        });

        return ['outcome' => $eob[1], 'format' => $format, 'eobs' => [['eob' => $eob[0], 'outcome' => $eob[1]]]];
    }

    /** @return array{outcome: string, format: ?string, eobs: list<array{eob: PhrEob, outcome: string}>} */
    private function unrecognised(): array
    {
        return ['outcome' => self::UNRECOGNISED, 'format' => null, 'eobs' => []];
    }
}
