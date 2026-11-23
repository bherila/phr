<?php

namespace App\Rules;

use App\Support\PHR\PhrDocumentUploadLimits;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * The file types a document upload accepts: Laravel's `mimes:` rule over
 * {@see PhrDocumentUploadLimits::extensions()}, which judges the sniffed bytes,
 * plus markdown. finfo reports markdown as text/plain, or as some other text
 * type when it holds inline HTML or code, so a file named .md or .markdown is
 * accepted when its bytes are any text type.
 */
final readonly class PhrDocumentFileType implements ValidationRule
{
    /** @param list<string> $alsoSniffedAs further sniffed extensions to accept, such as csv for a .txt */
    public function __construct(private array $alsoSniffedAs = []) {}

    /** @param list<string> $extensions */
    public function alsoAccepting(array $extensions): self
    {
        return new self(array_values(array_unique([...$this->alsoSniffedAs, ...$extensions])));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof UploadedFile
            && $value->getPath() !== ''
            && PhrDocumentUploadLimits::isMarkdownFilename($value->getClientOriginalName())
            && str_starts_with(strtolower((string) $value->getMimeType()), 'text/')) {
            return;
        }

        $mimes = 'mimes:'.implode(',', [...PhrDocumentUploadLimits::extensions(), ...$this->alsoSniffedAs]);
        $validator = Validator::make([$attribute => $value], [$attribute => [$mimes]]);
        if ($validator->fails()) {
            $fail((string) $validator->errors()->first($attribute));
        }
    }
}
