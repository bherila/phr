<?php

namespace App\Support\PHR;

use App\Rules\PhrDocumentFileType;

final class PhrDocumentUploadLimits
{
    public const int MAX_KILOBYTES = 51_200;

    public const int MAX_BYTES = self::MAX_KILOBYTES * 1024;

    /**
     * The content types a document upload accepts, with the filename extensions
     * each may carry. The same set as the {@see PhrDocumentFileType} rule.
     *
     * @var array<string, list<string>>
     */
    public const array CONTENT_TYPES = [
        'application/pdf' => ['pdf'],
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/tiff' => ['tif', 'tiff'],
        'text/plain' => ['txt'],
        'text/markdown' => ['md', 'markdown'],
        'text/html' => ['html', 'htm'],
    ];

    /**
     * Markdown is plain text to finfo, so its bytes never identify it: the
     * filename does. A file with one of these extensions whose bytes sniff as
     * any text type is stored as text/markdown.
     *
     * @var list<string>
     */
    public const array MARKDOWN_EXTENSIONS = ['md', 'markdown'];

    public const string MARKDOWN = 'text/markdown';

    /** Whether a filename's extension is one the content type may carry. */
    public static function allows(string $contentType, string $filename): bool
    {
        $contentType = strtolower($contentType);
        $extensions = self::CONTENT_TYPES[$contentType] ?? null;
        if ($extensions === null) {
            return false;
        }
        // Clients (and finfo) commonly report markdown as text/plain.
        if ($contentType === 'text/plain' && self::isMarkdownFilename($filename)) {
            return true;
        }

        return in_array(self::extension($filename), $extensions, true);
    }

    /** @return list<string> every accepted filename extension */
    public static function extensions(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::CONTENT_TYPES))));
    }

    public static function isMarkdownFilename(string $filename): bool
    {
        return in_array(self::extension($filename), self::MARKDOWN_EXTENSIONS, true);
    }

    /**
     * The content type a document is stored with: what its bytes sniff as,
     * except that a markdown file sniffs as plain text (or, holding inline HTML,
     * as HTML) and is stored as what its name says it is.
     */
    public static function storedMimeType(?string $sniffed, string $filename): ?string
    {
        if (self::isMarkdownFilename($filename) && is_string($sniffed) && str_starts_with(strtolower($sniffed), 'text/')) {
            return self::MARKDOWN;
        }

        return $sniffed;
    }

    private static function extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
}
