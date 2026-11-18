<?php

namespace App\Services\PHR\Eob;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A pure correspondence plan; a line number alone does not identify a service. */
final readonly class EobLineCorrespondence
{
    /** @param list<int|null> $existingIds existing row ID for each incoming line, or a new row */
    private function __construct(public array $existingIds) {}

    /**
     * @param  list<array{id: int, attributes: array<string, mixed>}>  $existing
     * @param  list<array<string, mixed>>  $incoming  normalized EobClaim lines
     * @param  list<int>  $linkedIds  lines whose medication evidence must survive
     */
    public static function plan(array $existing, array $incoming, array $linkedIds): self
    {
        $old = [];
        $oldGroups = [];
        foreach ($existing as $row) {
            $old[$row['id']] = $row['attributes'];
            $oldGroups[self::serviceKey($row['attributes'])][] = $row['id'];
        }
        $newGroups = [];
        foreach ($incoming as $index => $line) {
            $newGroups[self::serviceKey($line)][] = $index;
        }
        $matches = array_fill(0, count($incoming), null);
        $used = [];
        $linked = array_fill_keys($linkedIds, true);
        foreach ($newGroups as $key => $indices) {
            $ids = $oldGroups[$key] ?? [];
            if (count($ids) > 1 || count($indices) > 1) {
                // Amounts are mutable: a repeated service's old amount appearing
                // elsewhere is not proof that its evidence should move there.
                foreach ($ids as $id) {
                    if (! isset($linked[$id])) {
                        continue;
                    }
                    foreach ($indices as $index) {
                        if ($old[$id]['line_number'] === $incoming[$index]['line_number']
                            && self::contentKey($old[$id]) === self::contentKey($incoming[$index])) {
                            $matches[$index] = $id;
                            $used[$id] = true;
                            break;
                        }
                    }
                    if (! isset($used[$id])) {
                        self::refuse();
                    }
                }
                $ids = array_values(array_filter($ids, static fn (int $id): bool => ! isset($used[$id])));
                $indices = array_values(array_filter($indices, static fn (int $index): bool => $matches[$index] === null));
            }
            $fullOld = [];
            $fullNew = [];
            foreach ($ids as $id) {
                $fullOld[self::contentKey($old[$id])][] = $id;
            }
            foreach ($indices as $index) {
                $fullNew[self::contentKey($incoming[$index])][] = $index;
            }
            foreach ($fullNew as $content => $sameContentIndices) {
                $sameContentIds = $fullOld[$content] ?? [];
                if (count($sameContentIds) === 1 && count($sameContentIndices) === 1) {
                    $matches[$sameContentIndices[0]] = $sameContentIds[0];
                    $used[$sameContentIds[0]] = true;
                } else {
                    // Identical repeated services can keep their own number. If
                    // those anchors disappear, never guess which linked row moved.
                    foreach ($sameContentIndices as $index) {
                        foreach ($sameContentIds as $id) {
                            if (! isset($used[$id]) && $old[$id]['line_number'] === $incoming[$index]['line_number']) {
                                $matches[$index] = $id;
                                $used[$id] = true;
                                break;
                            }
                        }
                    }
                }
            }
            $remainingIds = array_values(array_filter($ids, static fn (int $id): bool => ! isset($used[$id])));
            $remainingIndices = array_values(array_filter($indices, static fn (int $index): bool => $matches[$index] === null));
            if (count($remainingIds) === 1 && count($remainingIndices) === 1) {
                // A unique service can have corrected amounts or notes without
                // changing the row to which existing evidence points.
                $matches[$remainingIndices[0]] = $remainingIds[0];
                $used[$remainingIds[0]] = true;
            }
        }
        foreach ($linkedIds as $id) {
            if (! isset($used[$id])) {
                self::refuse();
            }
        }
        // Unlinked rows retain the existing number-based editing behavior.
        $byNumber = [];
        foreach ($old as $id => $line) {
            if (! isset($used[$id])) {
                $byNumber[$line['line_number']] = $id;
            }
        }
        foreach ($incoming as $index => $line) {
            if ($matches[$index] === null && isset($byNumber[$line['line_number']])) {
                $matches[$index] = $byNumber[$line['line_number']];
            }
        }

        return new self($matches);
    }

    private static function refuse(): never
    {
        throw new ConflictHttpException('The corrected lines do not uniquely identify a service linked to a medication fill. Review and unlink or relink that evidence before replacing the service.');
    }

    /** @param array<string, mixed> $line */
    private static function serviceKey(array $line): string
    {
        $identity = [];
        foreach (['procedure_code', 'code_type', 'revenue_code', 'description', 'service_start', 'service_end'] as $field) {
            $identity[$field] = $line[$field] ?? null;
        }

        return json_encode($identity, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $line */
    private static function contentKey(array $line): string
    {
        unset($line['line_number'], $line['fill']);
        ksort($line);

        return json_encode($line, JSON_THROW_ON_ERROR);
    }
}
