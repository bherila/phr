<?php

namespace App\Services\PHR\Import;

use DOMElement;

/**
 * The C-CDA sections PHR recognizes, by template and LOINC section code, and the
 * reader each one goes to.
 *
 * A title is a vendor's wording, and a discharge summary's titles share words
 * with the sections PHR reads: "Hospital Discharge Medications" and "Medications
 * Administered" say "medication", "Procedure Findings" says "procedure", "Reason
 * for Visit" says "visit", "Encounter Diagnosis" says "encounter". Matched by
 * title, those became active home medications, procedures and visits (#406). So
 * a section is routed by what it is coded as, and only one coded as nothing this
 * catalog knows by its title:
 *
 * 1. a template, then a LOINC section code, of a section a reader reads routes
 *    it to that reader (or to Results);
 * 2. a template or code of a known section no reader reads (UNREAD) routes it to
 *    nothing: its entries are counted as skipped, never read by title;
 * 3. a section with no code this catalog knows is matched by title: the exact
 *    title of a known unread section first, then the readers' title words, in
 *    the order the narrative-table reader has always matched them.
 */
final class PhrCcdaSectionCatalog
{
    private const string LOINC = '2.16.840.1.113883.6.1';

    /** The Results section, read by PhrCcdaResultObservations rather than a section reader. */
    private const array RESULTS = ['templates' => ['2.16.840.1.113883.10.20.22.2.3', '2.16.840.1.113883.10.20.22.2.3.1'], 'codes' => ['30954-2']];

    public const string ADMISSION_MEDICATIONS = 'admission_medications';

    public const string MEDICATIONS_ADMINISTERED = 'medications_administered';

    public const string DISCHARGE_MEDICATIONS = 'discharge_medications';

    /**
     * A hospital stay's medication lists. They are orders and administrations,
     * not the patient's home medications, and PHR has no medication setting to
     * keep them apart yet (#413), so they are skipped. Before #406 their titles
     * sent them to the Medications reader; PhrCcdaMisroutedMedications retracts
     * the rows that made.
     */
    public const array HOSPITAL_MEDICATIONS = [self::ADMISSION_MEDICATIONS, self::MEDICATIONS_ADMINISTERED, self::DISCHARGE_MEDICATIONS];

    /**
     * Known sections no reader reads: their C-CDA templates, LOINC codes, and the
     * titles that name one when it carries neither. The list holds the sections
     * whose titles share a word with a section PHR reads, plus common narrative
     * sections of PHR's own and vendors' exports; a section coded as anything
     * else is matched by title as before.
     *
     * @var array<string, array{templates: list<string>, codes: list<string>, titles: list<string>}>
     */
    private const array UNREAD = [
        self::ADMISSION_MEDICATIONS => ['templates' => ['2.16.840.1.113883.10.20.22.2.44'], 'codes' => ['42346-7'], 'titles' => ['admission medications', 'medications on admission']],
        self::MEDICATIONS_ADMINISTERED => ['templates' => ['2.16.840.1.113883.10.20.22.2.38'], 'codes' => ['29549-3'], 'titles' => ['medications administered']],
        // 75311-1 is the code a Discharge Medications section may carry beside 10183-2.
        self::DISCHARGE_MEDICATIONS => ['templates' => ['2.16.840.1.113883.10.20.22.2.11', '2.16.840.1.113883.10.20.22.2.11.1'], 'codes' => ['10183-2', '75311-1'], 'titles' => ['hospital discharge medications', 'discharge medications']],
        'reason_for_visit' => ['templates' => ['2.16.840.1.113883.10.20.22.2.12'], 'codes' => ['29299-5'], 'titles' => ['reason for visit']],
        'chief_complaint_and_reason_for_visit' => ['templates' => ['2.16.840.1.113883.10.20.22.2.13'], 'codes' => ['46239-0'], 'titles' => ['chief complaint and reason for visit']],
        // Coded as the Encounter Diagnosis act is (29308-4, "Diagnosis"); C-CDA has no section template for it.
        'encounter_diagnosis' => ['templates' => [], 'codes' => ['29308-4'], 'titles' => ['encounter diagnosis', 'encounter diagnoses']],
        'procedure_findings' => ['templates' => ['2.16.840.1.113883.10.20.22.2.28'], 'codes' => ['59776-5'], 'titles' => ['procedure findings']],
        'postprocedure_diagnosis' => ['templates' => ['2.16.840.1.113883.10.20.22.2.36'], 'codes' => ['59769-0'], 'titles' => ['postprocedure diagnosis']],
        'planned_procedure' => ['templates' => ['2.16.840.1.113883.10.20.22.2.30'], 'codes' => ['59772-4'], 'titles' => ['planned procedure', 'planned procedures']],
        'procedure_indications' => ['templates' => ['2.16.840.1.113883.10.20.22.2.29'], 'codes' => ['59768-2'], 'titles' => ['procedure indications']],
        'procedure_description' => ['templates' => ['2.16.840.1.113883.10.20.22.2.27'], 'codes' => ['29554-3'], 'titles' => ['procedure description']],
        'procedure_specimens_taken' => ['templates' => ['2.16.840.1.113883.10.20.22.2.31'], 'codes' => ['59773-2'], 'titles' => ['procedure specimens taken']],
        'procedure_implants' => ['templates' => ['2.16.840.1.113883.10.20.22.2.40'], 'codes' => ['59771-6'], 'titles' => ['procedure implants']],
        'plan_of_treatment' => ['templates' => ['2.16.840.1.113883.10.20.22.2.10'], 'codes' => ['18776-5'], 'titles' => ['plan of treatment', 'plan of care']],
        'social_history' => ['templates' => ['2.16.840.1.113883.10.20.22.2.17'], 'codes' => ['29762-2'], 'titles' => ['social history']],
    ];

    /**
     * The section readers, in the order a section's title is matched.
     *
     * @var list<PhrCcdaSectionReader>
     */
    public readonly array $readers;

    public function __construct(private readonly PhrCcdaXml $xml)
    {
        $this->readers = [
            new PhrCcdaVitalSignObservations($xml),
            new PhrCcdaMedicationActivities($xml),
            new PhrCcdaProblemObservations($xml),
            new PhrCcdaProcedureActivities($xml),
            new PhrCcdaImmunizationActivities($xml),
            new PhrCcdaAllergyObservations($xml),
            new PhrCcdaEncounterActivities($xml),
        ];
    }

    public function route(DOMElement $section): PhrCcdaSectionRoute
    {
        // A known template names the section's reader, whatever its title or
        // code says: "Laboratory Procedures" is still a Procedures section.
        $templates = $this->templates($section);
        $route = $this->coded(static fn (array $known): bool => array_intersect($known['templates'], $templates) !== [], fn (PhrCcdaSectionReader $reader): bool => array_intersect($reader->sectionTemplates(), $templates) !== []);
        $code = $this->code($section);
        if ($route === null && $code !== null) {
            $route = $this->coded(static fn (array $known): bool => in_array($code, $known['codes'], true), static fn (PhrCcdaSectionReader $reader): bool => $reader->sectionCode() === $code);
        }
        if ($route !== null) {
            return $route;
        }

        $title = $this->title($section);
        $normalized = preg_replace('/\s+/', ' ', $title) ?? $title;
        foreach (self::UNREAD as $name => $known) {
            if (in_array($normalized, $known['titles'], true)) {
                return PhrCcdaSectionRoute::unread($name);
            }
        }
        if (self::isResultsTitle($title)) {
            return PhrCcdaSectionRoute::results();
        }
        $reader = $this->readerByTitle($title);

        return $reader === null ? PhrCcdaSectionRoute::unknown() : PhrCcdaSectionRoute::reader($reader);
    }

    /**
     * The reader a section's template names, if any.
     *
     * @param  list<string>  $templates
     */
    public function readerByTemplates(array $templates): ?PhrCcdaSectionReader
    {
        foreach ($this->readers as $reader) {
            if (array_intersect($reader->sectionTemplates(), $templates) !== []) {
                return $reader;
            }
        }

        return null;
    }

    /**
     * The reader whose title words a lowercased section title holds, in the
     * order the narrative-table reader has always matched titles.
     */
    public function readerByTitle(string $title): ?PhrCcdaSectionReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->matchesTitle($title)) {
                return $reader;
            }
        }

        return null;
    }

    /** Vital signs are matched first, so a "vital signs results" title stays a vitals section. */
    public static function isResultsTitle(string $title): bool
    {
        return ! str_contains($title, 'vital') && (str_contains($title, 'result') || str_contains($title, 'lab'));
    }

    /**
     * The section's template roots.
     *
     * @return list<string>
     */
    public function templates(DOMElement $section): array
    {
        $templates = [];
        foreach ($this->xml->children($section, 'templateId') as $templateId) {
            $root = $this->xml->attribute($templateId, 'root');
            if ($root !== null) {
                $templates[] = $root;
            }
        }

        return $templates;
    }

    /** The section's title, lowercased and trimmed. */
    public function title(DOMElement $section): string
    {
        return strtolower(trim((string) $this->xml->child($section, 'title')?->textContent));
    }

    /**
     * The section's LOINC code. A code that names no code system is taken as
     * LOINC, the only system C-CDA section codes come from.
     */
    private function code(DOMElement $section): ?string
    {
        $code = $this->xml->child($section, 'code');
        $system = $this->xml->attribute($code, 'codeSystem');

        return $system === null || $system === self::LOINC ? $this->xml->attribute($code, 'code') : null;
    }

    /**
     * The route a template or code gives: a reader's section, then Results, then
     * a known unread section.
     *
     * @param  callable(array{templates: list<string>, codes: list<string>}): bool  $known
     * @param  callable(PhrCcdaSectionReader): bool  $read
     */
    private function coded(callable $known, callable $read): ?PhrCcdaSectionRoute
    {
        foreach ($this->readers as $reader) {
            if ($read($reader)) {
                return PhrCcdaSectionRoute::reader($reader);
            }
        }
        if ($known(self::RESULTS)) {
            return PhrCcdaSectionRoute::results();
        }
        foreach (self::UNREAD as $name => $section) {
            if ($known($section)) {
                return PhrCcdaSectionRoute::unread($name);
            }
        }

        return null;
    }
}
