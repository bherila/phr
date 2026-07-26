<?php

namespace App\GenAiProcessor\Mail;

use App\GenAiProcessor\Models\GenAiImportJob;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GenAiJobCompleteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public GenAiImportJob $job
    ) {}

    public function envelope(): Envelope
    {
        $status = $this->job->status === 'parsed' ? 'Ready for Review' : ucfirst($this->job->status);

        return new Envelope(
            subject: "GenAI Import {$status} — {$this->job->original_filename}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.genai.job-complete',
            with: [
                'filename' => $this->job->original_filename,
                'jobType' => $this->job->job_type,
                'status' => $this->job->status,
                'resultCount' => $this->job->results()->count(),
                'errorMessage' => $this->job->error_message,
            ],
        );
    }
}
