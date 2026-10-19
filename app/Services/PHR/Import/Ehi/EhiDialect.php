<?php

namespace App\Services\PHR\Import\Ehi;

use InvalidArgumentException;

/**
 * The exact text dialect EhiTsvReader enforces. Nothing is sniffed: the reader
 * applies this dialect strictly and reports every deviation as a malformed row.
 *
 * Records end at LF; a CR immediately before that LF is part of the terminator
 * (CRLF). Any other CR is data. Values are never transcoded: a dialect only
 * declares an encoding it validates against.
 *
 * `version` is what accounting stores as `parser_version` (at most 32 bytes), so
 * any change to the rules must produce a different string. A dialect built
 * without an explicit version derives one from its settings.
 */
final readonly class EhiDialect
{
    public const string QUOTE_NONE = 'none';

    public const string QUOTE_RFC4180 = 'rfc4180';

    public const string ENCODING_UTF8 = 'UTF-8';

    /** Bumped when the reader's record/field rules change for the same settings. */
    public const string RULES_VERSION = 'ehi-tsv-1';

    public string $version;

    public function __construct(
        public string $delimiter = "\t",
        public string $quoteMode = self::QUOTE_NONE,
        public string $encoding = self::ENCODING_UTF8,
        public bool $allowBom = true,
        /** A cell exactly equal to this (unquoted) is surfaced as null; null means no marker. */
        public ?string $nullMarker = null,
        ?string $version = null,
    ) {
        if (strlen($delimiter) !== 1 || $delimiter === '"' || $delimiter === "\n" || $delimiter === "\r") {
            throw new InvalidArgumentException('EHI dialect delimiter must be one byte other than a quote or line break.');
        }
        if (! in_array($quoteMode, [self::QUOTE_NONE, self::QUOTE_RFC4180], true)) {
            throw new InvalidArgumentException('EHI dialect quote mode is not supported.');
        }
        if ($encoding !== self::ENCODING_UTF8) {
            throw new InvalidArgumentException('EHI dialect encoding is not supported.');
        }
        if ($nullMarker === '' || ($nullMarker !== null && strpbrk($nullMarker, $delimiter."\r\n\"") !== false)) {
            throw new InvalidArgumentException('EHI dialect null marker must be non-empty and contain no delimiter, quote, or line break.');
        }

        $version ??= self::RULES_VERSION.'/'.substr(hash('sha256', $this->descriptor()), 0, 16);
        if ($version === '' || strlen($version) > 32) {
            throw new InvalidArgumentException('EHI dialect version must be 1-32 bytes.');
        }
        $this->version = $version;
    }

    /**
     * Strict defaults until a real export confirms the dialect: tab-separated,
     * no quoting, UTF-8 with an optional BOM on the header, LF or CRLF, no null marker.
     */
    public static function provisional(): self
    {
        return new self(version: self::RULES_VERSION.'/provisional-1');
    }

    public function withQuoteMode(string $quoteMode): self
    {
        return new self($this->delimiter, $quoteMode, $this->encoding, $this->allowBom, $this->nullMarker);
    }

    public function withNullMarker(?string $nullMarker): self
    {
        return new self($this->delimiter, $this->quoteMode, $this->encoding, $this->allowBom, $nullMarker);
    }

    /** Canonical description of every setting; the derived version hashes it. */
    public function descriptor(): string
    {
        return json_encode([
            'rules' => self::RULES_VERSION,
            'delimiter' => bin2hex($this->delimiter),
            'quote' => $this->quoteMode,
            'encoding' => $this->encoding,
            'bom' => $this->allowBom,
            'null' => $this->nullMarker === null ? null : bin2hex($this->nullMarker),
        ], JSON_THROW_ON_ERROR);
    }
}
