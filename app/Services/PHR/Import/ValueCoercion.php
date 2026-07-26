<?php

namespace App\Services\PHR\Import;

use App\Models\PhrDocument;
use Illuminate\Support\Carbon;

final class ValueCoercion
{
    /**
     * @param  array<string, mixed>  $record
     * @param  array{external_id?: string|null}  $options
     */
    public static function externalId(array $record, array $options): ?string
    {
        return self::string($options['external_id'] ?? null)
            ?? self::string($record['external_id'] ?? $record['record_key'] ?? $record['source_key'] ?? $record['id'] ?? null);
    }

    public static function requiredString(mixed $value): ?string
    {
        $string = self::string($value);

        return $string === '' ? null : $string;
    }

    public static function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            $trimmed = trim((string) $value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    public static function numeric(mixed $value): ?string
    {
        $string = self::string($value);
        if ($string === null) {
            return null;
        }

        $normalized = str_replace(',', '', $string);

        return is_numeric($normalized) ? $normalized : null;
    }

    public static function integer(mixed $value): ?int
    {
        $string = self::numeric($value);

        return $string === null ? null : (int) $string;
    }

    public static function date(mixed $value): ?string
    {
        $string = self::string($value);
        if ($string === null) {
            return null;
        }

        try {
            return Carbon::parse($string)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function dateTime(mixed $value): ?string
    {
        $string = self::string($value);
        if ($string === null) {
            return null;
        }

        try {
            return Carbon::parse($string)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array{code: string, description: string}>|null
     */
    public static function codes(mixed $value): ?array
    {
        if (! is_array($value)) {
            $string = self::string($value);
            if ($string === null) {
                return null;
            }

            return array_map(
                static fn (string $code): array => ['code' => trim($code), 'description' => ''],
                array_filter(explode(',', $string), static fn (string $code): bool => trim($code) !== '')
            );
        }

        $codes = [];
        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $codes[] = ['code' => trim($entry), 'description' => ''];

                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            $code = self::string($entry['code'] ?? null);
            if ($code === null) {
                continue;
            }

            $codes[] = [
                'code' => $code,
                'description' => self::string($entry['description'] ?? $entry['display'] ?? null) ?? '',
            ];
        }

        return $codes === [] ? null : $codes;
    }

    /**
     * @return array<int, string>|null
     */
    public static function tags(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return null;
        }

        $tags = [];
        foreach ($value as $tag) {
            $tagText = self::string($tag);
            if ($tagText === null) {
                continue;
            }

            $tags[strtolower($tagText)] = $tagText;
        }

        return $tags === [] ? null : array_values($tags);
    }

    /**
     * @return array<int, string>|null
     */
    public static function stringList(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return null;
        }

        $strings = [];
        foreach ($value as $entry) {
            $string = self::string($entry);
            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return $strings === [] ? null : $strings;
    }

    public static function normalizeDocumentSource(mixed $source): string
    {
        $value = self::string($source);

        return match ($value) {
            'genai', 'genai_import' => 'genai_import',
            'fhir', 'fhir_import' => 'fhir_import',
            'ccda', 'ccda_import' => 'ccda_import',
            'mychart', 'mychart_zip' => 'mychart_zip',
            default => 'manual_upload',
        };
    }

    public static function normalizeDocumentType(mixed $type): string
    {
        $value = self::string($type);

        return in_array($value, PhrDocument::DOCUMENT_TYPES, true) ? $value : 'other';
    }
}
