<?php

namespace App\Console\Commands\Phr;

use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\DeltaDentalEobAudit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:eobs:audit-delta-dental {--patient= : PHR patient id} {--actor= : Acting user id} {--verify-pdfs : Check hash-verified stored claim PDFs} {--details : Include up to 100 opaque EOB ids and finding codes} {--json : Print structured JSON}')]
#[Description('Read-only audit of imported Delta Dental EOB amounts; never corrects records')]
final class PhrAuditDeltaDentalEobsCommand extends BasePhrCommand
{
    public function handle(PhrPatientAccessService $access, DeltaDentalEobAudit $audit): int
    {
        $patient = $access->accessiblePatient($this->intOptionRequired('patient'), $this->intOptionRequired('actor'));
        $report = $audit->run($patient, (bool) $this->option('verify-pdfs'), (bool) $this->option('details'));
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($report['counts'] as $name => $count) {
                $this->line($name.': '.$count);
            }
            foreach ($report['records'] as $record) {
                $this->line($record['public_id'].': '.implode(', ', $record['flags']));
            }
        }

        return self::SUCCESS;
    }
}
