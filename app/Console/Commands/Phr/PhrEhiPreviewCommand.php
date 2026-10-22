<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrEhiPackage;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\Ehi\EhiProjectionRefused;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

#[Signature('phr:ehi:preview {--patient= : PHR patient id} {--actor= : Acting user id} {--package= : Bound EHI package id}')]
#[Description('Project a bound EHI package and persist reviewable clinical proposals (no clinical writes)')]
class PhrEhiPreviewCommand extends BaseEhiCommand
{
    public function handle(PhrPatientAccessService $accessService, EhiProposalService $proposals): int
    {
        $patient = $this->writablePatient($accessService);
        $package = PhrEhiPackage::query()->where('patient_id', $patient->id)->find($this->intOptionRequired('package'));
        if ($package === null) {
            $this->error('--package does not name an EHI package of this patient.');

            return self::INVALID;
        }

        try {
            $preview = $proposals->preview($package, $patient, $this->intOptionRequired('actor'));
        } catch (EhiProjectionRefused $refused) {
            $this->line("refused: {$refused->reason}");

            return self::FAILURE;
        } catch (AuthorizationException|ModelNotFoundException $denied) {
            throw $denied;
        } catch (Throwable $failure) {
            // Never print the message: it could carry a storage or source detail.
            $this->line('failed: unexpected ('.class_basename($failure).')');

            return self::FAILURE;
        }

        $projection = $preview->projection;
        $this->line("package: {$preview->package->id}");
        $this->line("clinical: {$preview->package->clinical_status}");
        $this->line(sprintf(
            'projection: tables projected %d, key mismatch %d, absent %d%s',
            $projection->tablesProjected,
            $projection->tablesMismatched,
            $projection->tablesAbsent,
            $projection->replayed ? ' (already projected)' : '',
        ));
        $this->line('rows: '.$this->counts($projection->dispositions));
        if ($preview->counts === []) {
            $this->line('proposals: (no mappings registered)');
        }
        foreach ($preview->counts as $mapping => $kinds) {
            $this->line("proposals {$mapping}: ".($kinds === [] ? '(none)' : $this->counts($kinds)));
        }
        $this->line('statuses: '.($preview->statuses === [] ? '(none)' : $this->counts($preview->statuses)));
        $this->line('mapping findings: '.($preview->findings === [] ? '(none)' : $this->counts($preview->findings)));
        $this->line("preview digest: {$preview->digest}");

        return self::SUCCESS;
    }
}
