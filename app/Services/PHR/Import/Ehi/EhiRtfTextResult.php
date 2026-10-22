<?php

namespace App\Services\PHR\Import\Ehi;

/**
 * Plain text derived from an RTF member by EhiRtfText. Derived text is never the
 * original: the preserved RTF bytes stay the evidence, and `version` pins which
 * converter rules produced this text.
 *
 * When `unsupported` is set the input was not RTF or exceeded a structural limit,
 * and `text` is always empty. `malformed` means the input was RTF but structurally
 * damaged (unbalanced groups, a cut-off \bin); `text` is what could be recovered.
 */
final readonly class EhiRtfTextResult
{
    public function __construct(
        public string $text,
        public string $version,
        public bool $truncated = false,
        public bool $hadEmbeddedObjects = false,
        public bool $hadPictures = false,
        public bool $hadFields = false,
        public bool $unsupported = false,
        public bool $malformed = false,
    ) {}

    /** @return array{version: string, truncated: bool, embedded_objects: bool, pictures: bool, fields: bool, unsupported: bool, malformed: bool} */
    public function flags(): array
    {
        return [
            'version' => $this->version,
            'truncated' => $this->truncated,
            'embedded_objects' => $this->hadEmbeddedObjects,
            'pictures' => $this->hadPictures,
            'fields' => $this->hadFields,
            'unsupported' => $this->unsupported,
            'malformed' => $this->malformed,
        ];
    }
}
