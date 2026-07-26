<?php

namespace App\Services\PHR\DICOM;

use App\Models\PhrDicomInstance;
use App\Models\PhrDicomSeries;

class VolumeSeriesInspector
{
    /** @var list<string> */
    private const SUPPORTED_TRANSFER_SYNTAX_UIDS = [
        '1.2.840.10008.1.2',
        '1.2.840.10008.1.2.1',
        '1.2.840.10008.1.2.4.90',
        '1.2.840.10008.1.2.4.91',
    ];

    /**
     * @return array{
     *     eligible: bool,
     *     reasons: list<string>,
     *     warnings: list<string>,
     *     volume: array<string, mixed>|null,
     *     instances: list<array<string, mixed>>,
     *     excluded_instance_count: int
     * }
     */
    public function inspect(PhrDicomSeries $series): array
    {
        $instanceCount = $series->instances->count();

        if (! in_array(strtoupper((string) $series->modality), ['CT', 'MR'], true)) {
            return $this->ineligible(['unsupported_modality'], $instanceCount);
        }

        $geometryInstances = [];
        foreach ($series->instances as $instance) {
            $geometry = $this->geometry($instance);
            if ($geometry !== null) {
                $geometryInstances[] = $geometry;
            }
        }

        if ($geometryInstances === []) {
            return $this->ineligible(['missing_geometry'], $instanceCount);
        }

        $transferSyntaxInstances = array_values(array_filter(
            $geometryInstances,
            fn (array $item): bool => ($item['instance']->number_of_frames ?? 1) <= 1
                && in_array($item['instance']->transfer_syntax_uid, self::SUPPORTED_TRANSFER_SYNTAX_UIDS, true),
        ));

        if ($transferSyntaxInstances === []) {
            return $this->ineligible(['unsupported_transfer_syntax'], $instanceCount);
        }

        $monochromeInstances = array_values(array_filter(
            $transferSyntaxInstances,
            fn (array $item): bool => $this->isMonochrome($item['instance']),
        ));

        if ($monochromeInstances === []) {
            return $this->ineligible(['not_monochrome'], $instanceCount);
        }

        $groups = [];
        foreach ($monochromeInstances as $item) {
            $groups[$this->orientationKey($item['orientation'])][] = $item;
        }

        $dominantGroup = [];
        foreach ($groups as $group) {
            if (count($group) > count($dominantGroup)) {
                $dominantGroup = $group;
            }
        }

        $excludedInstanceCount = $instanceCount - count($dominantGroup);
        $reasons = [];

        if (count($dominantGroup) < 20) {
            $reasons[] = 'too_few_slices';
        }

        if ($this->uniqueCount($dominantGroup, fn (array $item): string => $item['rows'].'x'.$item['columns']) > 1) {
            $reasons[] = 'inconsistent_dimensions';
        }

        if ($this->uniqueCount($dominantGroup, fn (array $item): string => $this->pixelSpacingKey($item['pixel_spacing'])) > 1) {
            $reasons[] = 'inconsistent_pixel_spacing';
        }

        if ($this->uniqueCount($dominantGroup, fn (array $item): string => (string) ($item['bits_allocated'] ?? 'null')) > 1) {
            $reasons[] = 'inconsistent_bits_allocated';
        }

        $orientation = $dominantGroup[0]['orientation'];
        $normal = $this->crossProduct(
            array_slice($orientation, 0, 3),
            array_slice($orientation, 3, 3),
        );

        foreach ($dominantGroup as &$item) {
            $item['projection'] = $this->dotProduct($item['position'], $normal);
        }
        unset($item);

        usort(
            $dominantGroup,
            fn (array $left, array $right): int => $left['projection'] <=> $right['projection'],
        );

        $deltas = [];
        for ($index = 1, $count = count($dominantGroup); $index < $count; $index++) {
            $delta = $dominantGroup[$index]['projection'] - $dominantGroup[$index - 1]['projection'];
            if ($delta < 1e-3) {
                $reasons[] = 'duplicate_positions';
                break;
            }

            $deltas[] = $delta;
        }

        if ($reasons !== []) {
            return $this->ineligible(array_values(array_unique($reasons)), $excludedInstanceCount);
        }

        $sliceSpacing = $this->median($deltas);
        $warnings = [];
        foreach ($deltas as $delta) {
            if (abs($delta - $sliceSpacing) > $sliceSpacing * 0.1) {
                $warnings[] = 'non_uniform_spacing';
                break;
            }
        }

        $first = $dominantGroup[0];

        return [
            'eligible' => true,
            'reasons' => [],
            'warnings' => $warnings,
            'volume' => [
                'rows' => $first['rows'],
                'columns' => $first['columns'],
                'pixel_spacing' => $first['pixel_spacing'],
                'slice_spacing' => $sliceSpacing,
                'slice_count' => count($dominantGroup),
                'orientation' => $orientation,
                'origin' => $first['position'],
                'bits_allocated' => $first['bits_allocated'],
                'pixel_representation' => $first['pixel_representation'],
                'default_window' => $this->pairedMetadata($dominantGroup, 'WindowCenter', 'WindowWidth', 'center', 'width'),
                'rescale' => $this->pairedMetadata($dominantGroup, 'RescaleSlope', 'RescaleIntercept', 'slope', 'intercept'),
            ],
            'instances' => array_map(fn (array $item): array => [
                'id' => $item['instance']->id,
                'sop_instance_uid' => $item['instance']->sop_instance_uid,
                'position' => $item['position'],
                'projection' => $item['projection'],
                'transfer_syntax_uid' => $item['instance']->transfer_syntax_uid,
            ], $dominantGroup),
            'excluded_instance_count' => $excludedInstanceCount,
        ];
    }

    /**
     * @return array{instance: PhrDicomInstance, position: list<float>, orientation: list<float>, pixel_spacing: list<float>, rows: int, columns: int, bits_allocated: int|null, pixel_representation: int|null}|null
     */
    private function geometry(PhrDicomInstance $instance): ?array
    {
        $metadata = $instance->metadata_json ?? [];
        $position = $this->numericList($metadata['ImagePositionPatient'] ?? null, 3);
        $orientation = $this->numericList($metadata['ImageOrientationPatient'] ?? null, 6);
        $pixelSpacing = $this->numericList($metadata['PixelSpacing'] ?? null, 2);

        if ($position === null || $orientation === null || $pixelSpacing === null || $instance->rows === null || $instance->columns === null) {
            return null;
        }

        return [
            'instance' => $instance,
            'position' => $position,
            'orientation' => $orientation,
            'pixel_spacing' => $pixelSpacing,
            'rows' => $instance->rows,
            'columns' => $instance->columns,
            'bits_allocated' => $this->integer($metadata['BitsAllocated'] ?? null),
            'pixel_representation' => $this->integer($metadata['PixelRepresentation'] ?? null),
        ];
    }

    private function isMonochrome(PhrDicomInstance $instance): bool
    {
        $metadata = $instance->metadata_json ?? [];
        $photometricInterpretation = $metadata['PhotometricInterpretation'] ?? null;
        $samplesPerPixel = $this->integer($metadata['SamplesPerPixel'] ?? null);

        return ($photometricInterpretation === null || in_array(strtoupper(trim((string) $photometricInterpretation)), ['MONOCHROME1', 'MONOCHROME2'], true))
            && ($samplesPerPixel === null || $samplesPerPixel === 1);
    }

    /**
     * @param  list<float>  $values
     */
    private function orientationKey(array $values): string
    {
        return implode(',', array_map(fn (float $value): string => (string) round($value, 4), $values));
    }

    /**
     * @param  list<float>  $values
     */
    private function pixelSpacingKey(array $values): string
    {
        return implode(',', array_map(fn (float $value): string => (string) round($value, 4), $values));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function uniqueCount(array $items, callable $key): int
    {
        $values = [];
        foreach ($items as $item) {
            $values[$key($item)] = true;
        }

        return count($values);
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     * @return list<float>
     */
    private function crossProduct(array $left, array $right): array
    {
        return [
            $left[1] * $right[2] - $left[2] * $right[1],
            $left[2] * $right[0] - $left[0] * $right[2],
            $left[0] * $right[1] - $left[1] * $right[0],
        ];
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function dotProduct(array $left, array $right): float
    {
        return $left[0] * $right[0] + $left[1] * $right[1] + $left[2] * $right[2];
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        if (count($values) % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, float>|null
     */
    private function pairedMetadata(array $items, string $firstMetadataKey, string $secondMetadataKey, string $firstResultKey, string $secondResultKey): ?array
    {
        foreach ($items as $item) {
            $metadata = $item['instance']->metadata_json ?? [];
            $first = $this->number($metadata[$firstMetadataKey] ?? null);
            $second = $this->number($metadata[$secondMetadataKey] ?? null);

            if ($first !== null && $second !== null) {
                return [$firstResultKey => $first, $secondResultKey => $second];
            }
        }

        return null;
    }

    /**
     * @return list<float>|null
     */
    private function numericList(mixed $value, int $expectedCount): ?array
    {
        if (! is_array($value) || count($value) !== $expectedCount) {
            return null;
        }

        $numbers = [];
        foreach ($value as $item) {
            $number = $this->number($item);
            if ($number === null) {
                return null;
            }

            $numbers[] = $number;
        }

        return $numbers;
    }

    private function integer(mixed $value): ?int
    {
        $number = $this->number($value);

        return $number === null ? null : (int) $number;
    }

    private function number(mixed $value): ?float
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    /**
     * @param  list<string>  $reasons
     * @return array{eligible: false, reasons: list<string>, warnings: list<string>, volume: null, instances: list<array<string, mixed>>, excluded_instance_count: int}
     */
    private function ineligible(array $reasons, int $excludedInstanceCount): array
    {
        return [
            'eligible' => false,
            'reasons' => $reasons,
            'warnings' => [],
            'volume' => null,
            'instances' => [],
            'excluded_instance_count' => $excludedInstanceCount,
        ];
    }
}
