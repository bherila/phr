<?php

namespace App\Console\Commands\Phr;

use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\PhrImportResult;
use App\Services\PHR\Import\PhrStructuredDataImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:import:pdf {--patient= : PHR patient id} {--actor= : Acting user id} {--file= : Source PDF/image file path} {--type=phr_document : PHR GenAI job type to import} {--json= : Extracted JSON path, or - for STDIN}')]
#[Description('Import Codex/GenAI-extracted PDF data into a PHR patient')]
class PhrImportPdfExtractionCommand extends BasePhrCommand
{
    public function handle(PhrPatientAccessService $accessService, PhrStructuredDataImporter $importer): int
    {
        $patient = $this->writablePatient($accessService);
        $actorId = $this->intOptionRequired('actor');
        $type = (string) $this->option('type');
        if (! PhrStructuredDataImporter::isPhrJobType($type)) {
            $this->error("Unsupported PHR import type: {$type}");

            return self::FAILURE;
        }

        $payload = $this->readJsonPayload();
        $result = new PhrImportResult;
        $file = $this->option('file');
        $document = null;

        if (is_string($file) && trim($file) !== '') {
            $document = $importer->storeLocalDocument($patient, $actorId, $file, [
                ...$payload,
                'source' => 'cli_pdf',
                'document_type' => $type === 'phr_document' ? ($payload['source_document']['document_type'] ?? $payload['document_type'] ?? 'other') : 'other',
                'summary' => $payload['summary'] ?? null,
                'extracted_text' => $payload['extracted_text'] ?? $payload['text'] ?? null,
            ]);
            $result->addDocument();
            $result->addCreated();

            if ($type === 'phr_document') {
                if ($payload !== []) {
                    $result->merge($importer->importDocumentBundle($patient, $actorId, $document, $payload, [
                        'import_source' => 'cli_pdf',
                        'source' => 'cli_pdf',
                    ]));
                }

                $this->info("Stored document {$document->id}.");
                $this->lineImportResult($result);

                return self::SUCCESS;
            }
        }

        if ($payload === []) {
            $this->error('--json is required unless --type=phr_document and --file is supplied.');

            return self::FAILURE;
        }

        $result->merge($importer->importPayload($patient, $actorId, $type, $payload, [
            'import_source' => 'cli_pdf',
            'source' => 'cli_pdf',
            'source_document_id' => isset($document) ? $document->id : null,
        ]));
        $this->lineImportResult($result);

        return self::SUCCESS;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readJsonPayload(): array
    {
        $jsonPath = $this->option('json');
        if (! is_string($jsonPath) || trim($jsonPath) === '') {
            return [];
        }

        $contents = $jsonPath === '-' ? stream_get_contents(STDIN) : file_get_contents($jsonPath);
        if (! is_string($contents) || trim($contents) === '') {
            throw new \InvalidArgumentException('--json must point to readable JSON content.');
        }

        $payload = json_decode($contents, true);
        if (! is_array($payload)) {
            throw new \InvalidArgumentException('--json content must decode to an object or array.');
        }

        return $payload;
    }
}
