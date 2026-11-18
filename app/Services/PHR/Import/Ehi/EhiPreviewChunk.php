<?php

namespace App\Services\PHR\Import\Ehi;

use ReflectionReference;

/**
 * Exact default-json_encode sizing without re-encoding every preceding proposal.
 *
 * @template T of array<array-key, mixed>
 */
final class EhiPreviewChunk
{
    /** @var array<array-key, T> */
    private array $rows = [];

    /** @var array<array-key, true> */
    private array $dirty = [];

    /** @var array<array-key, int> */
    private array $valueBytes = [];

    /** @var array<array-key, int> */
    private array $keyBytes = [];

    private int $valuesBytes = 0;

    private int $objectKeysBytes = 0;

    private bool $isList = true;

    private bool $mutable = false;

    /** @param T $row */
    public function put(int|string $key, array $row): void
    {
        // Let PHP normalize numeric-string keys exactly as the persisted array does.
        $key = array_key_first([$key => true]);
        if (! array_key_exists($key, $this->rows)) {
            $this->isList = $this->isList && $key === count($this->rows);
        }
        $this->rows[$key] = $row;
        $this->dirty[$key] = true;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /** @return array<array-key, T> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function bytes(): int
    {
        if ($this->mutable) {
            return strlen(json_encode($this->rows, JSON_THROW_ON_ERROR));
        }

        foreach ($this->dirty as $key => $_) {
            if (! $this->stable($this->rows[$key])) {
                // Mapping extensions can yield objects or references that change
                // earlier rows. Preserve the original whole-array encoding then,
                // including its side effects and exceptions, until this chunk ends.
                $this->mutable = true;

                return strlen(json_encode($this->rows, JSON_THROW_ON_ERROR));
            }
            if (! array_key_exists($key, $this->keyBytes)) {
                $this->keyBytes[$key] = strlen(json_encode((string) $key, JSON_THROW_ON_ERROR)) + 1;
                $this->objectKeysBytes += $this->keyBytes[$key];
            }
            // The enclosing chunk consumes one of json_encode's default 512 levels.
            $bytes = strlen(json_encode($this->rows[$key], JSON_THROW_ON_ERROR, 511));
            $this->valuesBytes += $bytes - ($this->valueBytes[$key] ?? 0);
            $this->valueBytes[$key] = $bytes;
        }
        $this->dirty = [];

        return 2 + max(0, count($this->rows) - 1) + $this->valuesBytes
            + ($this->isList ? 0 : $this->objectKeysBytes);
    }

    public function clear(): void
    {
        $this->rows = $this->dirty = $this->valueBytes = $this->keyBytes = [];
        $this->valuesBytes = $this->objectKeysBytes = 0;
        $this->isList = true;
        $this->mutable = false;
    }

    /** @param array<array-key, mixed> $row */
    private function stable(array $row, int $depth = 1): bool
    {
        // Fall back before recursive references or excessive nesting can recurse.
        if ($depth >= 511) {
            return false;
        }
        foreach ($row as $key => $value) {
            if (ReflectionReference::fromArrayElement($row, $key) !== null) {
                return false;
            }
            if (is_array($value)) {
                if (! $this->stable($value, $depth + 1)) {
                    return false;
                }
            } elseif ($value !== null && ! is_scalar($value)) {
                return false;
            }
        }

        return true;
    }
}
