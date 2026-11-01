<?php

namespace App\Services\PHR\Ndc;

/**
 * The FDA drug label ("prescribing information") for a package NDC or an SPL set id, read
 * on demand from openFDA's drug label endpoint and cached by OpenFdaClient. Only an NDC
 * or a set id is ever sent, never patient data. Label text is the FDA's text, returned as
 * published.
 */
class NdcLabelService
{
    public const string STATUS_FOUND = 'found';

    public const string STATUS_NOT_FOUND = 'not_found';

    public const string STATUS_UNAVAILABLE = 'unavailable';

    public const string STATUS_INVALID = 'invalid';

    /** Longest text kept per section; longer sections are cut and flagged. */
    public const int SECTION_LIMIT = 30000;

    /**
     * The label sections shown, in reading order: openFDA field => title.
     *
     * @var array<string, string>
     */
    public const array SECTIONS = [
        'boxed_warning' => 'Boxed warning',
        'indications_and_usage' => 'Indications and usage',
        'dosage_and_administration' => 'Dosage and administration',
        'dosage_forms_and_strengths' => 'Dosage forms and strengths',
        'contraindications' => 'Contraindications',
        'warnings_and_cautions' => 'Warnings and precautions',
        'warnings' => 'Warnings',
        'adverse_reactions' => 'Adverse reactions',
        'how_supplied' => 'How supplied',
        'storage_and_handling' => 'Storage and handling',
    ];

    public function __construct(private readonly OpenFdaClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function label(?string $ndc10, ?string $splSetId): array
    {
        $splSetId = $splSetId === null || trim($splSetId) === '' ? null : strtolower(trim($splSetId));
        $ndc = $ndc10 === null || trim($ndc10) === '' ? null : NdcNumber::toTenDigit($ndc10);
        if ($splSetId !== null && preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $splSetId) !== 1) {
            return $this->empty(self::STATUS_INVALID, $ndc, null);
        }
        if ($splSetId === null && $ndc === null) {
            return $this->empty(self::STATUS_INVALID, null, null);
        }

        try {
            $label = $this->client->findLabel($splSetId !== null ? 'set_id:"'.$splSetId.'"' : 'openfda.package_ndc:"'.$ndc.'"');
        } catch (OpenFdaUnavailableException) {
            return $this->empty(self::STATUS_UNAVAILABLE, $ndc, $splSetId);
        }
        if ($label === null) {
            return $this->empty(self::STATUS_NOT_FOUND, $ndc, $splSetId);
        }

        $setId = is_string($label['set_id'] ?? null) ? $label['set_id'] : $splSetId;

        return [
            'status' => self::STATUS_FOUND,
            'ndc_10' => $ndc,
            'spl_set_id' => $setId,
            'effective_date' => $this->date($label['effective_time'] ?? null),
            'version' => is_scalar($label['version'] ?? null) ? (string) $label['version'] : null,
            'source' => 'FDA drug label, as published by the FDA through openFDA',
            'sections' => $this->sections($label),
            'links' => self::links($setId, $ndc === null ? null : substr($ndc, 0, strrpos($ndc, '-') ?: 0)),
        ];
    }

    /**
     * External pages for a drug, for the person to open in a new tab.
     *
     * @return array{dailymed: ?string, ndc_directory: ?string}
     */
    public static function links(?string $splSetId, ?string $productNdc): array
    {
        return [
            'dailymed' => $splSetId === null ? null : 'https://dailymed.nlm.nih.gov/dailymed/lookup.cfm?setid='.rawurlencode($splSetId),
            'ndc_directory' => $productNdc === null || $productNdc === '' ? null : 'https://www.accessdata.fda.gov/scripts/cder/ndc/dsp_searchresult.cfm?q='.rawurlencode($productNdc),
        ];
    }

    /**
     * @param  array<string, mixed>  $label
     * @return list<array{key: string, title: string, text: string, truncated: bool}>
     */
    private function sections(array $label): array
    {
        $sections = [];
        foreach (self::SECTIONS as $key => $title) {
            $parts = array_values(array_filter(
                array_map(static fn (mixed $part): string => is_string($part) ? trim($part) : '', (array) ($label[$key] ?? [])),
                static fn (string $part): bool => $part !== '',
            ));
            if ($parts === []) {
                continue;
            }
            $text = implode("\n\n", $parts);
            $truncated = mb_strlen($text) > self::SECTION_LIMIT;
            $sections[] = [
                'key' => $key,
                'title' => $title,
                'text' => $truncated ? mb_substr($text, 0, self::SECTION_LIMIT) : $text,
                'truncated' => $truncated,
            ];
        }

        return $sections;
    }

    /** openFDA writes dates as YYYYMMDD. */
    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) === 1 ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(string $status, ?string $ndc, ?string $splSetId): array
    {
        return [
            'status' => $status,
            'ndc_10' => $ndc,
            'spl_set_id' => $splSetId,
            'effective_date' => null,
            'version' => null,
            'source' => 'FDA drug label, as published by the FDA through openFDA',
            'sections' => [],
            'links' => self::links($splSetId, null),
        ];
    }
}
