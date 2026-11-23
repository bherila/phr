<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrDocument;
use App\Models\PhrMedication;
use App\Models\PhrPatient;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Services\PHR\Import\PhrCcdaMisroutedMedications;
use App\Services\PHR\Import\PhrCcdaMisroutedMedicationsOutcome;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Eloquent\Builder;

/**
 * Retracts the medication rows C-CDA imports made of hospital medication lists
 * before #406, for documents nobody will import again (re-importing one does the
 * same itself). See PhrCcdaMisroutedMedications for how the rows are found and
 * what is retracted, restored, or left for a person.
 *
 * Per patient, every stored document a C-CDA medication row links to is read
 * again, and the ids its hospital lists gave rows, less every id those
 * documents' own medication lists give, are retracted where the row links to one
 * of them. A row imported without a stored document, or from one that can no
 * longer be read, cannot be checked: it is counted and left as it is, never
 * guessed at. Archived patients are skipped.
 *
 * Safe to run repeatedly: a retracted row is no longer selected. Deliberately not
 * part of the deploy.
 */
#[Signature('phr:ccda:retract-hospital-medications {--dry-run : Report what would be retracted, without writing} {--patient= : Only this PHR patient id}')]
#[Description('Retract medications earlier C-CDA imports read from hospital medication lists (admission, administered, discharge)')]
final class PhrCcdaRetractHospitalMedicationsCommand extends BasePhrCommand
{
    private const string IMPORT_SOURCE = 'ccda';

    public function handle(PhrCcdaMisroutedMedications $misrouted, PhrPatientArtifactWriteGuard $writeGuard): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('patient') === null ? null : $this->intOptionRequired('patient');
        $prefix = $dryRun ? '[dry run] ' : '';
        $totals = ['retracted' => 0, 'restored' => 0, 'confirmed' => 0, 'filled' => 0, 'unchecked' => 0];

        $patientIds = $this->ccdaMedications()->when($only !== null, fn (Builder $query) => $query->where('patient_id', $only))
            ->distinct()->orderBy('patient_id')->pluck('patient_id')->map(static fn (mixed $id): int => (int) $id);
        foreach ($patientIds as $patientId) {
            $patient = PhrPatient::query()->find($patientId);
            if ($patient === null || $patient->archived_at !== null) {
                $this->line("Patient #{$patientId} is archived or gone; skipped.");

                continue;
            }

            $readable = [];
            $unreadable = [];
            $former = [];
            $current = [];
            $documentIds = $this->ccdaMedications()->where('patient_id', $patientId)->whereNotNull('source_document_id')
                ->distinct()->orderBy('source_document_id')->pluck('source_document_id')->map(static fn (mixed $id): int => (int) $id);
            foreach ($documentIds as $documentId) {
                $document = PhrDocument::query()->withTrashed()->where('patient_id', $patientId)->find($documentId);
                $documents = $document === null ? null : $misrouted->storedDocuments($document);
                if ($documents === null) {
                    $unreadable[] = $documentId;

                    continue;
                }
                $readable[] = $documentId;
                foreach ($documents as $xpath) {
                    $ids = $misrouted->ids($xpath);
                    $former = [...$former, ...$ids['former']];
                    $current = [...$current, ...$ids['current']];
                }
            }

            $stale = array_values(array_diff($former, $current));
            $current = array_values(array_unique($current));
            /** @var PhrCcdaMisroutedMedicationsOutcome $outcome */
            $outcome = $writeGuard->run($patientId, fn (): PhrCcdaMisroutedMedicationsOutcome => $misrouted->apply($patientId, $stale, $current, $readable, $dryRun));
            $unchecked = $this->ccdaMedications()->where('patient_id', $patientId)->whereNull('retracted_at')
                ->where(fn (Builder $query) => $query->whereNull('source_document_id')->orWhereIn('source_document_id', $unreadable))->count();

            $this->report($prefix, $patientId, $outcome, $unchecked, $dryRun);
            $totals['retracted'] += count($outcome->retracted);
            $totals['restored'] += count($outcome->restored);
            $totals['confirmed'] += count($outcome->confirmed);
            $totals['filled'] += count($outcome->filled);
            $totals['unchecked'] += $unchecked;
        }

        $verb = $dryRun ? 'would retract' : 'retracted';
        $this->info("{$prefix}{$verb} {$totals['retracted']} medication row(s) read from hospital medication lists and ".($dryRun ? 'would restore' : 'restored')." {$totals['restored']}; "
            ."left {$totals['confirmed']} a person confirmed and {$totals['filled']} with prescription fills for a person to check; {$totals['unchecked']} could not be checked.");

        return self::SUCCESS;
    }

    private function report(string $prefix, int $patientId, PhrCcdaMisroutedMedicationsOutcome $outcome, int $unchecked, bool $dryRun): void
    {
        $ids = static fn (array $rows): string => $rows === [] ? '' : ' (#'.implode(', #', $rows).')';
        if ($outcome->retracted !== []) {
            $this->line("{$prefix}Patient #{$patientId}: ".($dryRun ? 'would retract' : 'retracted').' '.count($outcome->retracted).' medication row(s) read from hospital medication lists'.$ids($outcome->retracted).'.');
        }
        if ($outcome->restored !== []) {
            $this->line("{$prefix}Patient #{$patientId}: ".($dryRun ? 'would restore' : 'restored').' '.count($outcome->restored).' medication row(s) a document lists as current medications'.$ids($outcome->restored).'.');
        }
        if ($outcome->confirmed !== []) {
            $this->warn("{$prefix}Patient #{$patientId}: left ".count($outcome->confirmed).' medication row(s) from hospital medication lists that a person confirmed'.$ids($outcome->confirmed).'; check whether they are home medications.');
        }
        if ($outcome->filled !== []) {
            $this->warn("{$prefix}Patient #{$patientId}: left ".count($outcome->filled).' medication row(s) from hospital medication lists that prescription fills are linked to'.$ids($outcome->filled).'; check whether they are home medications.');
        }
        if ($unchecked > 0) {
            $this->warn("{$prefix}Patient #{$patientId}: {$unchecked} C-CDA medication row(s) have no stored source document, or one that cannot be read, so the list they came from cannot be checked; re-importing their source file retracts any read from a hospital medication list.");
        }
    }

    /** @return Builder<PhrMedication> */
    private function ccdaMedications(): Builder
    {
        return PhrMedication::query()->where('import_source', self::IMPORT_SOURCE);
    }
}
