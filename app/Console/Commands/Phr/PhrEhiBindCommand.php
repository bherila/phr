<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrEhiPackage;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\Ehi\EhiBindingRefused;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiPackageSummary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:ehi:bind {--patient= : PHR patient id} {--actor= : Acting user id (must own the patient)} {--package= : EHI package id} {--source= : Existing EHI source id} {--new-source-label= : Label for a new EHI source} {--yes : Bind without asking for confirmation}')]
#[Description('Bind a preserved EHI package to a new or existing declared source, after review')]
class PhrEhiBindCommand extends BaseEhiCommand
{
    public function handle(PhrPatientAccessService $accessService, EhiBindingService $binding): int
    {
        $patient = $this->ownedPatient($accessService);
        $actor = $this->intOptionRequired('actor');
        $package = PhrEhiPackage::query()->where('patient_id', $patient->id)->find($this->intOptionRequired('package'));
        if ($package === null) {
            $this->error('--package does not name an EHI package of this patient.');

            return self::INVALID;
        }
        $sourceId = $this->optionalIntOption('source');
        $label = $this->optionalStringOption('new-source-label');
        if (($sourceId === null) === ($label === null)) {
            $this->error('Pass exactly one of --source or --new-source-label.');

            return self::INVALID;
        }

        $this->linePackage($package, EhiPackageSummary::forPackage($package));
        $suggestions = $binding->suggestions($package);
        $this->line('suggested sources: '.($suggestions->isEmpty() ? '(none)' : ''));
        foreach ($suggestions as $suggestion) {
            $match = $package->dictionary_fingerprint !== null && $suggestion->dictionary_fingerprint === $package->dictionary_fingerprint;
            $this->line(sprintf('  source %d: %s (dictionary %s)', $suggestion->id, $suggestion->label, $match ? 'matches' : 'differs'));
        }

        $target = $sourceId !== null ? "existing source {$sourceId}" : 'a new source';
        if (! $this->option('yes') && ! $this->confirm("Bind package {$package->id} to {$target}?")) {
            $this->line('Not bound.');

            return self::FAILURE;
        }

        try {
            $bound = $binding->bind($package, $patient, $actor, $sourceId, $label);
        } catch (EhiBindingRefused $refused) {
            $this->line("refused: {$refused->reason}");

            return self::FAILURE;
        }

        $this->line("binding: {$bound->binding_status} (source {$bound->source_id})");

        return self::SUCCESS;
    }
}
