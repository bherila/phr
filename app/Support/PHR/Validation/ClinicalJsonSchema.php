<?php

namespace App\Support\PHR\Validation;

final class ClinicalJsonSchema
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    public static function object(array $properties, array $required = []): array
    {
        return array_filter([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ], static fn (mixed $value): bool => $value !== []);
    }

    /** @return array<string, mixed> */
    public static function nullableString(?string $format = null, ?int $maxLength = null): array
    {
        return array_filter([
            'type' => ['string', 'null'],
            'format' => $format,
            'maxLength' => $maxLength,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function codes(): array
    {
        return [
            'type' => ['array', 'null'],
            'maxItems' => 100,
            'items' => self::object([
                'code' => ['type' => 'string', 'maxLength' => 20],
                'description' => ['type' => 'string', 'maxLength' => 255],
            ], ['code', 'description']),
        ];
    }
}
