<?php

namespace App\Console\Commands\Phr;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\PhrStructuredDataImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Signature('phr:genai:enqueue {--patient= : PHR patient id} {--actor= : Acting user id} {--file= : Local file to enqueue} {--type=phr_document : PHR GenAI job type}')]
#[Description('Submit a local file to the GenAI PHR import queue')]
class PhrGenAiEnqueueCommand extends BasePhrCommand
{
    public function handle(PhrPatientAccessService $accessService): int
    {
        $patient = $this->writablePatient($accessService);
        $actorId = $this->intOptionRequired('actor');
        $file = $this->fileOptionRequired('file');
        $type = (string) $this->option('type');
        if (! PhrStructuredDataImporter::isPhrJobType($type)) {
            $this->error("Unsupported PHR GenAI job type: {$type}");

            return self::FAILURE;
        }

        $filename = basename($file);
        $fileHash = hash_file('sha256', $file);
        if ($fileHash === false) {
            $this->error("Unable to hash {$file}.");

            return self::FAILURE;
        }

        $s3Key = 'genai-import/'.$actorId.'/'.Str::uuid().'/'.preg_replace('/[^\w.\-]/', '_', $filename);
        $stream = fopen($file, 'rb');
        if ($stream === false) {
            $this->error("Unable to read {$file}.");

            return self::FAILURE;
        }

        try {
            $stored = Storage::disk('s3')->put($s3Key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $stored) {
            $this->error("Unable to upload {$file} to S3.");

            return self::FAILURE;
        }

        $job = GenAiImportJob::create([
            'user_id' => $actorId,
            'job_type' => $type,
            'file_hash' => $fileHash,
            'original_filename' => $filename,
            's3_path' => $s3Key,
            'mime_type' => mime_content_type($file) ?: 'application/pdf',
            'file_size_bytes' => filesize($file) ?: 0,
            'context_json' => json_encode(['patient_id' => $patient->id]),
            'status' => 'pending',
        ]);

        ParseImportJob::dispatch($job->id);
        $this->info("Queued GenAI PHR job {$job->id}.");

        return self::SUCCESS;
    }
}
