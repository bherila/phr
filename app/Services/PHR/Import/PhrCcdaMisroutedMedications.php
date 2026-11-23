<?php

namespace App\Services\PHR\Import;

use App\Models\PhrDocument;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Support\PHR\PhrReviewStatus;
use App\Support\Storage\LocalObjectFile;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Medication rows the C-CDA import made of a hospital stay's medication lists
 * before #406, found again and retracted.
 *
 * Before #406 a section no reader's template named was routed by its title, so
 * a discharge summary's Admission Medications, Medications Administered and
 * Hospital Discharge Medications ("medication" in each) became active home
 * medications, and so did activities inside Admission or Discharge Medication
 * acts. A row keeps no mark of the section it came from: its id is the reader's
 * prefix and a hash of the activity's id or content. So the rows are found the
 * one reliable way, by reading the source again: the ids the routing before
 * #406 gave the document's medication rows ("former"), less the ids the current
 * routing gives them ("current"), are the rows only the misrouting made.
 *
 * Retraction is the C-CDA import withdrawing claims it made in error
 * (PhrRecordLifecycle): the row stays, out of every list and export, neither
 * deleted nor rejected, and its review status is left as it was. What a person
 * decided is theirs, and is reported instead of retracted: a row they confirmed,
 * and a row prescription fills are linked to (retracting it would leave those
 * fills on a withdrawn medication). A row they rejected is already out of the
 * list and is retracted with the rest.
 *
 * Every document that lists an id writes the same row (the upserter matches on
 * it), so a document whose own medication list names a retracted row's id
 * restores it: only this cleanup retracts C-CDA medication rows.
 */
final class PhrCcdaMisroutedMedications
{
    private const string IMPORT_SOURCE = 'ccda';

    private const string ZIP_SIGNATURE = "PK\x03\x04";

    /** Ids per query, well below every database's bound-parameter limit. */
    private const int CHUNK = 500;

    /**
     * The medication row ids the routing before #406 gave the document, and
     * those the current routing gives it.
     *
     * @return array{former: list<string>, current: list<string>}
     */
    public function ids(DOMXPath $xpath): array
    {
        $catalog = new PhrCcdaSectionCatalog(new PhrCcdaXml($xpath));
        $medications = null;
        foreach ($catalog->readers as $reader) {
            if ($reader instanceof PhrCcdaMedicationActivities) {
                $medications = $reader;
            }
        }
        if ($medications === null) {
            return ['former' => [], 'current' => []];
        }

        $former = [];
        $current = [];
        foreach ($xpath->query('//*[local-name()="section"]') ?: [] as $section) {
            if (! $section instanceof DOMElement) {
                continue;
            }
            if ($this->formerlyMedications($catalog, $medications, $section)) {
                $former = [...$former, ...$this->rowIds($xpath, $medications, $section, $medications->formerEntries($section))];
            }
            if ($catalog->route($section)->reader === $medications) {
                $current = [...$current, ...$this->rowIds($xpath, $medications, $section, $medications->entries($section))];
            }
        }

        return ['former' => array_values(array_unique($former)), 'current' => array_values(array_unique($current))];
    }

    /**
     * Retracts the patient's live C-CDA medication rows of `$staleIds` that name
     * one of `$sources` as their source document (null: imported without one),
     * and restores the rows of `$currentIds` a cleanup retracted. A dry run
     * reports the same without writing. Running it again changes nothing.
     *
     * @param  list<string>  $staleIds
     * @param  list<string>  $currentIds
     * @param  list<int|null>  $sources
     */
    public function apply(int $patientId, array $staleIds, array $currentIds, array $sources, bool $dryRun = false): PhrCcdaMisroutedMedicationsOutcome
    {
        return DB::transaction(function () use ($patientId, $staleIds, $currentIds, $sources, $dryRun): PhrCcdaMisroutedMedicationsOutcome {
            $retracted = [];
            $confirmed = [];
            $filled = [];
            foreach ($sources === [] ? [] : array_chunk($staleIds, self::CHUNK) as $chunk) {
                $rows = $this->rows($patientId, $chunk)->whereNull('retracted_at')
                    ->where(function (Builder $query) use ($sources): void {
                        $documents = array_values(array_filter($sources, static fn (?int $source): bool => $source !== null));
                        $query->whereIn('source_document_id', $documents);
                        if (in_array(null, $sources, true)) {
                            $query->orWhereNull('source_document_id');
                        }
                    })
                    ->orderBy('id')->lockForUpdate()->get();
                $withFills = PhrMedicationFill::query()->whereIn('medication_id', $rows->modelKeys())->whereNull('retracted_at')
                    ->distinct()->pluck('medication_id')->map(static fn (mixed $id): int => (int) $id)->all();
                foreach ($rows as $row) {
                    $id = (int) $row->id;
                    if ($row->review_status === PhrReviewStatus::CONFIRMED) {
                        $confirmed[] = $id;
                    } elseif (in_array($id, $withFills, true)) {
                        $filled[] = $id;
                    } else {
                        $retracted[] = $id;
                        if (! $dryRun) {
                            $row->forceFill(['retracted_at' => now()])->save();
                        }
                    }
                }
            }

            $restored = [];
            foreach (array_chunk($currentIds, self::CHUNK) as $chunk) {
                foreach ($this->rows($patientId, $chunk)->whereNotNull('retracted_at')->orderBy('id')->lockForUpdate()->get() as $row) {
                    $restored[] = (int) $row->id;
                    if (! $dryRun) {
                        $row->forceFill(['retracted_at' => null])->save();
                    }
                }
            }

            return new PhrCcdaMisroutedMedicationsOutcome($retracted, $restored, $confirmed, $filled);
        });
    }

    /**
     * The C-CDA documents a stored document holds: itself, or the C-CDA members
     * of a MyChart ZIP. Null when it cannot be read; the caller reports its rows
     * rather than guess which list they came from.
     *
     * @return list<DOMXPath>|null
     */
    public function storedDocuments(PhrDocument $document): ?array
    {
        $disk = (string) $document->storage_disk;
        $key = $document->storage_path;
        if ($disk === '' || $key === null) {
            return null;
        }
        try {
            if (! Storage::disk($disk)->exists($key)) {
                return null;
            }
            if (str_starts_with(LocalObjectFile::prefix($disk, $key, 4), self::ZIP_SIGNATURE)) {
                return LocalObjectFile::with($disk, $key, fn (string $path): ?array => $this->zipMembers($path));
            }
            $xpath = $this->xpath((string) Storage::disk($disk)->get($key));

            return $xpath === null ? null : [$xpath];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the routing before #406 read the section as medications: by the
     * Medications template or, with no reader's template, by a title that is not
     * a results title and that the Medications reader matched first.
     */
    private function formerlyMedications(PhrCcdaSectionCatalog $catalog, PhrCcdaMedicationActivities $medications, DOMElement $section): bool
    {
        $byTemplate = $catalog->readerByTemplates($catalog->templates($section));
        if ($byTemplate !== null) {
            return $byTemplate === $medications;
        }
        $title = $catalog->title($section);

        return ! PhrCcdaSectionCatalog::isResultsTitle($title) && $catalog->readerByTitle($title) === $medications;
    }

    /**
     * The ids the importer gives a section's medication rows: its entries' when
     * it has any, else its table rows'.
     *
     * @param  list<PhrCcdaEntry>  $entries
     * @return list<string>
     */
    private function rowIds(DOMXPath $xpath, PhrCcdaMedicationActivities $reader, DOMElement $section, array $entries): array
    {
        if ($entries === []) {
            return array_map($reader->narrativeId(...), PhrCcdaNarrativeRow::rows($xpath, $section));
        }

        $ids = [];
        foreach ($entries as $entry) {
            if ($entry->skip || $entry->jobType !== $reader->jobType()) {
                continue;
            }
            $id = $entry->record === null ? $entry->narrativeId : ($entry->record['external_id'] ?? null);
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<string>  $externalIds
     * @return Builder<PhrMedication>
     */
    private function rows(int $patientId, array $externalIds): Builder
    {
        return PhrMedication::query()->where('patient_id', $patientId)->where('import_source', self::IMPORT_SOURCE)->whereIn('external_id', $externalIds);
    }

    /**
     * The C-CDA members of a ZIP, as the MyChart importer reads them: XML
     * members recognized as C-CDA by their content.
     *
     * @return list<DOMXPath>|null
     */
    private function zipMembers(string $path): ?array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        try {
            $documents = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = strtolower((string) $zip->getNameIndex($index));
                if (! str_ends_with($name, '.xml')) {
                    continue;
                }
                $contents = $zip->getFromIndex($index);
                if ($contents === false) {
                    return null;
                }
                $xpath = $this->xpath($contents);
                if ($xpath !== null) {
                    $documents[] = $xpath;
                }
            }

            return $documents;
        } finally {
            $zip->close();
        }
    }

    /** A C-CDA document's XPath, or null for content that is not one. */
    private function xpath(string $contents): ?DOMXPath
    {
        $contents = PhrStructuredFormat::normalize($contents);
        if (! str_starts_with($contents, '<')) {
            return null;
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($contents, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // Recognized as PhrStructuredFormat::detect() recognizes a C-CDA.
        return $loaded && $document->documentElement?->localName === 'ClinicalDocument'
            && $document->documentElement->namespaceURI === 'urn:hl7-org:v3' ? new DOMXPath($document) : null;
    }
}
