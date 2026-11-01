<?php

namespace App\Services\PHR\Import;

use DOMElement;

/**
 * Procedures section: one procedure per Procedure Activity (procedure,
 * observation or act).
 *
 * Only procedures that happened are records. A planned one (any mood but
 * EVN) is skipped; a negated one (not done) is a negative assertion.
 */
final class PhrCcdaProcedureActivities extends PhrCcdaSectionReader
{
    private const array ACTIVITY = [
        '2.16.840.1.113883.10.20.22.4.14',
        '2.16.840.1.113883.10.20.22.4.13',
        '2.16.840.1.113883.10.20.22.4.12',
    ];

    private const array INDICATION = ['2.16.840.1.113883.10.20.22.4.19'];

    private const string CPT = '2.16.840.1.113883.6.12';

    private const string SNOMED = '2.16.840.1.113883.6.96';

    public function sectionTemplates(): array
    {
        return ['2.16.840.1.113883.10.20.22.2.7', '2.16.840.1.113883.10.20.22.2.7.1'];
    }

    public function matchesTitle(string $title): bool
    {
        return str_contains($title, 'procedure');
    }

    public function jobType(): string
    {
        return 'phr_procedure';
    }

    public function idPrefix(): string
    {
        return 'ccda-procedure-';
    }

    public function legacyKind(): string
    {
        return 'procedure';
    }

    public function entries(DOMElement $section): array
    {
        $entries = [];
        foreach ($this->xml->entryElements($section, '*', self::ACTIVITY) as $activity) {
            if (! in_array($this->xml->attribute($activity, 'moodCode'), [null, 'EVN'], true)) {
                $entries[] = $this->skipped();

                continue;
            }

            $code = $this->xml->child($activity, 'code');
            $text = $this->xml->child($activity, 'text');
            $name = $this->xml->codeName($code) ?? $this->xml->edText($text) ?? $this->xml->codeLabel($code);
            [$performed] = $this->xml->period($this->xml->child($activity, 'effectiveTime'));

            if ($this->negated($activity)) {
                $statement = $this->xml->edText($text) ?? ($name === null ? 'Procedure not performed' : "{$name} not performed");
                $entries[] = $this->negation($activity, 'other', 'procedures', $statement, $performed);

                continue;
            }

            $entries[] = $this->entry($activity, $name === null ? null : $this->record($activity, $name, $code, $performed), $this->references($activity));
        }

        return $entries;
    }

    public function narrativeRecord(array $cells): array
    {
        return [
            'name' => $cells[0] ?? null,
            'performed_on' => $cells[1] ?? null,
            'status' => $cells[2] ?? 'completed',
            'external_id' => $this->narrativeId($cells),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function record(DOMElement $activity, string $name, ?DOMElement $code, ?string $performed): array
    {
        $performer = $this->xml->path($activity, 'performer', 'assignedEntity');
        $location = null;
        foreach ($this->xml->children($activity, 'participant') as $participant) {
            if ($this->xml->attribute($participant, 'typeCode') === 'LOC') {
                $location = $this->xml->path($participant, 'participantRole', 'playingEntity');
                break;
            }
        }
        $indication = $this->xml->related($activity, 'observation', self::INDICATION)[0] ?? null;

        return [
            'name' => $name,
            'cpt_code' => $this->xml->codeIn($code, self::CPT),
            'snomed_code' => $this->xml->codeIn($code, self::SNOMED),
            'performed_at' => $this->xml->hasTime($performed) ? $performed : null,
            'performed_on' => $performed,
            'performer_name' => $this->xml->nameText($this->xml->path($performer, 'assignedPerson', 'name')),
            'performer_specialty' => $this->xml->codeName($this->xml->child($performer, 'code')),
            'facility_name' => $this->xml->nameText($this->xml->path($performer, 'representedOrganization', 'name'))
                ?? $this->xml->nameText($this->xml->child($location, 'name')),
            'status' => $this->status($this->xml->attribute($this->xml->child($activity, 'statusCode'), 'code')),
            'reason' => $this->xml->codeName($this->xml->child($indication, 'value')),
            'external_id' => $this->externalId($activity, [$name, $performed]),
        ];
    }

    /** A Procedure Activity's statusCode in the app's procedure statuses. */
    private function status(?string $code): ?string
    {
        return match ($code) {
            'completed' => 'completed',
            'active' => 'in_progress',
            'new' => 'preparation',
            'aborted', 'cancelled' => 'cancelled',
            'nullified' => 'entered_in_error',
            default => null,
        };
    }
}
