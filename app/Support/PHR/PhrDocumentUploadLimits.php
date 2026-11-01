<?php

namespace App\Support\PHR;

use App\Http\Requests\PHR\StorePhrDocumentRequest;

final class PhrDocumentUploadLimits
{
    public const int MAX_KILOBYTES = 51_200;

    public const int MAX_BYTES = self::MAX_KILOBYTES * 1024;

    /**
     * The content types a document upload accepts, with the filename extensions
     * each may carry. The same set as the `mimes:` rule of {@see StorePhrDocumentRequest}.
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
        'text/html' => ['html', 'htm'],
    ];

    /** Whether a filename's extension is one the content type may carry. */
    public static function allows(string $contentType, string $filename): bool
    {
        $extensions = self::CONTENT_TYPES[strtolower($contentType)] ?? null;

        return $extensions !== null && in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $extensions, true);
    }
}
