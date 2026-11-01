<?php

namespace App\Services\PHR\Import;

use RuntimeException;
use Symfony\Component\Process\Process;

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
}
