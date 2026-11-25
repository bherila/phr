<?php

namespace App\Services\PHR\Documents;

use App\Models\PhrDocument;
use App\Support\PHR\PhrDocumentUploadLimits;
use App\Support\PHR\PhrHtmlText;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The text of a stored document, for a reader that needs the words and not the
 * file (phr#407):
 *
 *  - text/plain and text/markdown: the file's own text;
 *  - text/html: the extracted text, or the visible text with the markup removed.
 *    Raw markup is never returned: it is a prompt-injection surface;
 *  - anything else: the extracted text, when there is any.
 *
 * Bytes are read through the document disk's stream, so the file may be on an
 * object store. Text that is not valid UTF-8 has its invalid sequences replaced.
 */
final class PhrDocumentTextReader
{
    public const string SOURCE_FILE = 'file';

    public const string SOURCE_EXTRACTED_TEXT = 'extracted_text';

    /** The types whose stored bytes are themselves the document's text. */
    private const array PLAIN_TEXT_TYPES = ['text/plain', PhrDocumentUploadLimits::MARKDOWN, 'text/x-markdown'];

    private const string HTML = 'text/html';

    /** A file larger than this is not read inline; it can still be downloaded. */
    public const int MAX_INLINE_BYTES = 16 * 1024 * 1024;

    /**
     * @return array{text: string, source: string, format: string, markup_stripped: bool}|null
     *                                                                                         null when the document has no text to give inline
     */
    public function read(PhrDocument $document): ?array
    {
        $type = self::normalizedType($document->mime_type);
        $extracted = is_string($document->extracted_text) && trim($document->extracted_text) !== ''
            ? mb_scrub($document->extracted_text, 'UTF-8')
            : null;

        if (in_array($type, self::PLAIN_TEXT_TYPES, true)) {
            $bytes = $this->fileBytes($document);
            if ($bytes !== null) {
                return [
                    'text' => mb_scrub($bytes, 'UTF-8'),
                    'source' => self::SOURCE_FILE,
                    'format' => $type === 'text/plain' ? 'text/plain' : PhrDocumentUploadLimits::MARKDOWN,
                    'markup_stripped' => false,
                ];
            }

            return $extracted === null ? null : self::extracted($extracted, false);
        }

        if ($type === self::HTML) {
            if ($extracted !== null) {
                // Extracted text should already be text; an extraction that kept
                // the markup is treated like the file itself.
                return self::extracted($extracted, PhrHtmlText::looksLikeMarkup($extracted));
            }
            $bytes = $this->fileBytes($document);
            if ($bytes === null) {
                return null;
            }

            $text = PhrHtmlText::toText($bytes);

            return $text === null ? null : [
                'text' => $text,
                'source' => self::SOURCE_FILE,
                'format' => 'text/plain',
                'markup_stripped' => true,
            ];
        }

        return $extracted === null ? null : self::extracted($extracted, false);
    }

    public static function normalizedType(?string $mimeType): string
    {
        return strtolower(trim(explode(';', (string) $mimeType, 2)[0]));
    }

    /** @return array{text: string, source: string, format: string, markup_stripped: bool}|null */
    private static function extracted(string $text, bool $strip): ?array
    {
        $text = $strip ? PhrHtmlText::toText($text) : $text;

        return $text === null ? null : [
            'text' => $text,
            'source' => self::SOURCE_EXTRACTED_TEXT,
            'format' => 'text/plain',
            'markup_stripped' => $strip,
        ];
    }

    /** The stored bytes, or null when the document has no stored file. */
    private function fileBytes(PhrDocument $document): ?string
    {
        // Pinned to the document disk, as every document read is.
        if ($document->storage_disk !== PhrDocument::STORAGE_DISK || ! is_string($document->storage_path)) {
            return null;
        }
        if ($document->byte_size > self::MAX_INLINE_BYTES) {
            throw new UnprocessableEntityHttpException('The document is too large to read inline. Read the file with documents.download_access.create.');
        }
        $disk = Storage::disk(PhrDocument::STORAGE_DISK);
        if (! $disk->exists($document->storage_path)) {
            return null;
        }
        $stream = $disk->readStream($document->storage_path);
        if (! is_resource($stream)) {
            throw new HttpException(503, 'The document file could not be read.');
        }
        $limit = self::MAX_INLINE_BYTES;
        $bytes = '';
        try {
            // A read loop, not stream_get_contents: an object-store stream
            // cannot report its size ahead of the read.
            while (! feof($stream) && strlen($bytes) <= $limit) {
                $chunk = fread($stream, 1_048_576);
                if ($chunk === false) {
                    throw new HttpException(503, 'The document file could not be read.');
                }
                $bytes .= $chunk;
            }
        } finally {
            fclose($stream);
        }
        if (strlen($bytes) > $limit) {
            throw new UnprocessableEntityHttpException('The document is too large to read inline. Read the file with documents.download_access.create.');
        }

        return $bytes;
    }
}
