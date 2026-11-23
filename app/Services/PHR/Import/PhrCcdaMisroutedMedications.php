<?php

namespace App\Services\PHR\Import;

use App\Models\PhrDocument;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Support\PHR\PhrMedicationCategory;
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
 * it), and the row links only to the first of them. So before a row is
 * retracted, the patient's other stored documents are read: one whose own
 * medication list names the id keeps the row, whichever document it links to.
 * A document imported without being stored cannot be consulted; importing it
 * again restores a row its medication list names, since only this cleanup
 * retracts C-CDA medication rows.
 */
final class PhrCcdaMisroutedMedications
{
    private const string IMPORT_SOURCE = 'ccda';

    private const string ZIP_SIGNATURE = "PK\x03\x04";

    /** Enough of a stored file to tell XML or a ZIP from anything else. */
    private const int PREFIX_BYTES = 4096;

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
        $medications = $catalog->medications();

        $former = [];
        $current = [];
        foreach ($xpath->query('//*[local-name()="section"]') ?: [] as $section) {
            if (! $section instanceof DOMElement) {
                continue;
            }
            if ($this->formerlyMedications($catalog, $medications, $section)) {
                $former = [...$former, ...$this->rowIds($xpath, $medications, $section, $medications->formerEntries($section))];
            }
            // Only the home list's rows are "current": since #413 the discharge list
            // is read too, but as its own setting under scoped ids
            // (PhrMedicationCategory::scopedExternalId), which no misrouted row has.
            // A home-list row the misrouting made under a hospital list's id is still
            // retracted, and the list's own row is written beside it.
            $route = $catalog->route($section);
            if ($route->reader === $medications && $route->category === PhrMedicationCategory::COMMUNITY) {
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
     * `$corroborated`, when given, names the ids other documents list as
     * current medications; it is asked only once a row would be retracted, and
     * a row it names is left as it is. A row's source document is the first one
     * that wrote it, so a later summary listing the same id corroborates the row
     * without any row linking to that summary.
     *
     * @param  list<string>  $staleIds
     * @param  list<string>  $currentIds
     * @param  list<int|null>  $sources
     * @param  (callable(): list<string>)|null  $corroborated
     */
    public function apply(int $patientId, array $staleIds, array $currentIds, array $sources, bool $dryRun = false, ?callable $corroborated = null): PhrCcdaMisroutedMedicationsOutcome
    {
        return DB::transaction(function () use ($patientId, $staleIds, $currentIds, $sources, $dryRun, $corroborated): PhrCcdaMisroutedMedicationsOutcome {
            $elsewhere = null;
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
                    if ($corroborated !== null) {
                        $elsewhere ??= array_flip($corroborated());
                        if (isset($elsewhere[(string) $row->external_id])) {
                            continue;
                        }
                    }
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
                        // A home list names it: a row retracted before categories were
                        // stored comes back as the home medication it is.
                        $row->forceFill(['retracted_at' => null, 'category' => $row->category ?? PhrMedicationCategory::COMMUNITY])->save();
                    }
                }
            }

            return new PhrCcdaMisroutedMedicationsOutcome($retracted, $restored, $confirmed, $filled);
        });
    }

    /**
     * The medication row ids every stored document of the patient gives, read
     * from the documents themselves, never from which document a row links to
     * (a row links only to the first document that wrote it). `readable` and
     * `unreadable` are the documents that hold, or might hold, C-CDA content;
     * one that cannot be read leaves its rows unchecked.
     *
     * @return array{former: list<string>, current: list<string>, readable: list<int>, unreadable: list<int>}
     */
    public function storedIds(int $patientId, ?int $exceptDocumentId = null): array
    {
        $ids = ['former' => [], 'current' => [], 'readable' => [], 'unreadable' => []];
        $documents = PhrDocument::query()->withTrashed()->where('patient_id', $patientId)->whereNotNull('storage_path')
            ->when($exceptDocumentId !== null, fn (Builder $query) => $query->whereKeyNot($exceptDocumentId));
        foreach ($documents->lazyById(100) as $document) {
            $xpaths = $this->storedDocuments($document);
            if ($xpaths === null) {
                $ids['unreadable'][] = (int) $document->id;

                continue;
            }
            $ids['readable'][] = (int) $document->id;
            foreach ($xpaths as $xpath) {
                $found = $this->ids($xpath);
                $ids['former'] = [...$ids['former'], ...$found['former']];
                $ids['current'] = [...$ids['current'], ...$found['current']];
            }
        }
        $ids['former'] = array_values(array_unique($ids['former']));
        $ids['current'] = array_values(array_unique($ids['current']));

        return $ids;
    }

    /**
     * The C-CDA documents a stored document holds: itself, or the C-CDA members
     * of a ZIP. Empty for a document that is not XML or a ZIP (a PDF, a photo),
     * or is XML of another kind, decided from its first bytes so such a file is
     * never read whole. Null when it may be a C-CDA but cannot be read; the
     * caller reports its rows rather than guess which list they came from.
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
            $prefix = LocalObjectFile::prefix($disk, $key, self::PREFIX_BYTES);
            if (str_starts_with($prefix, self::ZIP_SIGNATURE)) {
                return LocalObjectFile::with($disk, $key, fn (string $path): ?array => $this->zipMembers($path));
            }
            $opening = PhrStructuredFormat::normalize($prefix);
            if ($opening === '' || $opening[0] !== '<') {
                return [];
            }
            if ((int) $document->byte_size > PhrStructuredDocumentImporter::MAX_DIRECT_BYTES) {
                return null;
            }
            $loaded = $this->load((string) Storage::disk($disk)->get($key));
            if ($loaded === null) {
                return null;
            }

            return $this->isCcda($loaded) ? [new DOMXPath($loaded)] : [];
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
     * The ids a section's medication rows may be stored under: its entries'
     * when it has any, else its table rows'. An entry's table-row id is among
     * them: the narrative-table reader stored the row under it, and only an
     * import that reads the entry moves it onto the entry's id
     * (PhrCcdaLegacyRows), which a skipped section's import never does.
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
            // An entry of another list (an activity inside a hospital-list act) is
            // not a home medication, whichever section holds it.
            if ($entry->skip || $entry->jobType !== $reader->jobType() || ! PhrMedicationCategory::isHomeList(self::category($entry))) {
                continue;
            }
            foreach ([$entry->record['external_id'] ?? null, $entry->narrativeId] as $id) {
                if (is_string($id)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    private static function category(PhrCcdaEntry $entry): ?string
    {
        $category = $entry->record['category'] ?? null;

        return is_string($category) ? $category : null;
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
                $loaded = $this->load($contents);
                if ($loaded !== null && $this->isCcda($loaded)) {
                    $documents[] = new DOMXPath($loaded);
                }
            }

            return $documents;
        } finally {
            $zip->close();
        }
    }

    /** The parsed XML, or null for content that is not well-formed XML. */
    private function load(string $contents): ?DOMDocument
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

        return $loaded ? $document : null;
    }

    /** Recognized as PhrStructuredFormat::detect() recognizes a C-CDA. */
    private function isCcda(DOMDocument $document): bool
    {
        return $document->documentElement?->localName === 'ClinicalDocument'
            && $document->documentElement->namespaceURI === 'urn:hl7-org:v3';
    }
}
