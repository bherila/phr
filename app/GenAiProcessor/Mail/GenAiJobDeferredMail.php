<?php

namespace App\GenAiProcessor\Mail;

use App\GenAiProcessor\Models\GenAiImportJob;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GenAiJobDeferredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public GenAiImportJob $job
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'GenAI Import Deferred — '.$this->job->original_filename,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.genai.job-deferred',
            with: [
                'filename' => $this->job->original_filename,
                'jobType' => $this->job->job_type,
                'scheduledFor' => $this->job->scheduled_for?->format('F j, Y'),
            ],
        );
    }
}
