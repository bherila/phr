<?php

namespace App\Console\Commands\Phr;

use App\DataTransferObjects\PHR\DocumentUploadData;
use App\Models\PhrDocument;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Documents\PhrDocumentUploadService;
use App\Services\PHR\Import\PhrDocumentProcessingService;
use App\Services\PHR\Import\PhrStructuredDataImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

#[Signature('phr:genai:enqueue {--patient= : PHR patient id} {--actor= : Acting user id} {--file= : Local file to enqueue} {--type=phr_document : PHR GenAI job type} {--document-type=other : PHR document type recorded for the stored source document}')]
#[Description('Store a local file as a PHR document and submit it to the GenAI PHR import queue')]
class PhrGenAiEnqueueCommand extends BasePhrCommand
{
    public function handle(
        PhrPatientAccessService $accessService,
        PhrDocumentUploadService $uploads,
        PhrDocumentProcessingService $processing,
    ): int {
        $patient = $this->writablePatient($accessService);
        $actorId = $this->intOptionRequired('actor');
        $file = $this->fileOptionRequired('file');
        $type = (string) $this->option('type');
        if (! PhrStructuredDataImporter::isPhrJobType($type)) {
            $this->error("Unsupported PHR GenAI job type: {$type}");

            return self::FAILURE;
        }
        $documentType = (string) $this->option('document-type');
        if (! in_array($documentType, PhrDocument::DOCUMENT_TYPES, true)) {
            $this->error("Unsupported PHR document type: {$documentType}");

            return self::FAILURE;
        }

        // The queue authorizes external (subscription-client) work against the
        // job's source document, so the CLI stores the file as a real patient
        // document first and stages the import from it exactly as the browser
        // upload-then-process path does.
        try {
            $document = $uploads->upload(
                $patient,
                $actorId,
                DocumentUploadData::fromValidated(
                    new UploadedFile($file, basename($file), mime_content_type($file) ?: null, null, true),
                    ['document_type' => $documentType],
                ),
            )->document;
        } catch (Throwable $exception) {
            $this->error('Unable to store '.$file.' as a PHR document: '.$this->reason($exception));

            return self::FAILURE;
        }

        try {
            $result = $processing->create($patient, $actorId, (int) $document->id, $type);
        } catch (Throwable $exception) {
            // The staging copy and the job row are created together, so nothing
            // dispatchable survives this failure. The stored document stays
            // reviewable and can be queued again.
            $this->error("Unable to queue document {$document->id} for GenAI import: ".$this->reason($exception));

            return self::FAILURE;
        }

        $this->info("Queued GenAI PHR job {$result->job->id} for document {$document->id}.");

        return self::SUCCESS;
    }

    private function reason(Throwable $exception): string
    {
        $message = $exception->getMessage();
        if ($message !== '' && ($exception instanceof HttpException || $exception instanceof InvalidArgumentException)) {
            return $message;
        }

        return $exception::class;
    }
}
