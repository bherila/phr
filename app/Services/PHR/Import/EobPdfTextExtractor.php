<?php

namespace App\Services\PHR\Import;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** The text layer of a PDF, in layout mode, as the EOB parsers read it. */
class EobPdfTextExtractor
{
    public function extract(string $path): string
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

    /** A bounded diagnostic read; output is streamed, never buffered by Process. */
    public function extractBounded(string $path, int $maxBytes): string
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('The PDF text limit must be positive.');
        }
        $process = $this->textProcess($path);
        $process->setTimeout(30);
        $process->disableOutput();
        $text = '';
        try {
            $process->run(static function (string $type, string $chunk) use (&$text, $maxBytes): void {
                if ($type !== Process::OUT) {
                    return;
                }
                if (strlen($text) + strlen($chunk) > $maxBytes) {
                    throw new RuntimeException('The PDF text exceeds the diagnostic limit.');
                }
                $text .= $chunk;
            });
        } catch (Throwable $failure) {
            $process->stop(0);
            throw $failure;
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException('pdftotext failed.');
        }
        if (trim($text) === '') {
            throw new RuntimeException('The PDF has no extractable text.');
        }

        return $text;
    }

    protected function textProcess(string $path): Process
    {
        return new Process(['pdftotext', '-layout', $path, '-']);
    }
}
