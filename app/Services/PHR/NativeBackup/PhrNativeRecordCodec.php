<?php

namespace App\Services\PHR\NativeBackup;

use Illuminate\Support\Facades\Schema;

final class PhrNativeRecordCodec
{
    /** @var array<string, array<string, string>> */
    private array $columnTypes = [];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $relationships
     * @return array{nativeId: string, contentHash: string, attributes: array<string, mixed>, relationships: array<string, mixed>}
     */
    public function record(string $nativeId, array $attributes, array $relationships): array
    {
        ksort($attributes);
        ksort($relationships);
        $content = ['attributes' => $attributes, 'relationships' => $relationships];

        return [
            'nativeId' => $nativeId,
            'contentHash' => hash('sha256', self::canonicalJson($content)),
            'attributes' => $attributes,
            'relationships' => $relationships,
        ];
    }

    public function encodeValue(string $table, string $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $this->columnTypes($table)[$column] ?? 'string';
        if (preg_match('/(blob|binary)/i', $type)) {
            return ['encoding' => 'base64', 'data' => base64_encode((string) $value)];
        }
        if (preg_match('/(int|serial)/i', $type)) {
            return (int) $value;
        }
        if (preg_match('/(real|float|double|decimal|numeric)/i', $type)) {
            return (string) $value;
        }

        return (string) $value;
    }

    public static function decodeValue(mixed $value): mixed
    {
        if (is_array($value) && ($value['encoding'] ?? null) === 'base64' && isset($value['data'])) {
            $decoded = base64_decode((string) $value['data'], true);
            if ($decoded === false) {
                throw new NativeBackupException('invalid_archive');
            }

            return $decoded;
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    public static function canonicalJson(array $value): string
    {
        $sorted = self::sortRecursively($value);
        $json = json_encode($sorted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json;
    }

    /** @return array<string, string> */
    private function columnTypes(string $table): array
    {
        if (isset($this->columnTypes[$table])) {
            return $this->columnTypes[$table];
        }

        $types = [];
        foreach (Schema::getColumns($table) as $column) {
            $types[$column['name']] = (string) ($column['type_name'] ?? $column['type'] ?? 'string');
        }

        return $this->columnTypes[$table] = $types;
    }

    private static function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursively($item);
        }

        return $value;
    }
}
