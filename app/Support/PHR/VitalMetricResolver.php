<?php

namespace App\Support\PHR;

use App\Models\PhrPatientVital;

class VitalMetricResolver
{
    /**
     * @return array<int, array{key: string, label: string, value: float, unit: ?string}>
     */
    public function metricCandidates(PhrPatientVital $vital): array
    {
        $name = strtolower(trim((string) ($vital->vital_name ?? '')));

        if ($this->isBloodPressure($name, $vital)) {
            $systolic = $this->toFloat($vital->value_numeric);
            $diastolic = $this->toFloat($vital->value_numeric_secondary);

            return array_values(array_filter([
                $systolic !== null ? [
                    'key' => 'systolic_bp',
                    'label' => 'Systolic BP',
                    'value' => $systolic,
                    'unit' => $vital->unit ?? 'mmHg',
                ] : null,
                $diastolic !== null ? [
                    'key' => 'diastolic_bp',
                    'label' => 'Diastolic BP',
                    'value' => $diastolic,
                    'unit' => $vital->secondary_unit ?? $vital->unit ?? 'mmHg',
                ] : null,
            ]));
        }

        $value = $this->toFloat($vital->value_numeric) ?? $this->toFloat($vital->vital_value);
        if ($value === null) {
            return [];
        }

        $unit = $vital->unit;

        return [[
            'key' => $this->metricKeyFor($name, $unit),
            'label' => $vital->vital_name ?: 'Vital',
            'value' => $value,
            'unit' => $unit,
        ]];
    }

    private function isBloodPressure(string $name, PhrPatientVital $vital): bool
    {
        if ($vital->value_numeric_secondary === null) {
            return false;
        }

        return str_contains($name, 'blood pressure')
            || str_contains($name, 'bp')
            || strtolower((string) ($vital->unit ?? '')) === 'mmhg';
    }

    private function metricKeyFor(string $name, ?string $unit): string
    {
        $base = $this->slug($name !== '' ? $name : 'vital');
        $unitSlug = $this->slug((string) ($unit ?? ''));

        return $unitSlug !== '' ? "{$base}_{$unitSlug}" : $base;
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';

        return trim($value, '_');
    }

    private function toFloat(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $matches) === 1) {
            return (float) $matches[0];
        }

        return null;
    }
}
