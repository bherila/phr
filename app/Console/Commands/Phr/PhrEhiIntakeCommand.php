<?php

namespace App\Console\Commands\Phr;

use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\Ehi\EhiDirectorySource;
use App\Services\PHR\Import\Ehi\EhiIntakeFailed;
use App\Services\PHR\Import\Ehi\EhiIntakeService;
use App\Services\PHR\Import\Ehi\EhiPackageRejected;
use App\Services\PHR\Import\Ehi\EhiPackageSource;
use App\Services\PHR\Import\Ehi\EhiZipSource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

#[Signature('phr:ehi:intake {--patient= : PHR patient id} {--actor= : Acting user id} {--dir= : Extracted EHI package directory} {--zip= : EHI package ZIP}')]
#[Description('Preserve and account for an Epic EHI Tables package (no clinical writes)')]
class PhrEhiIntakeCommand extends BaseEhiCommand
{
    public function handle(PhrPatientAccessService $accessService, EhiIntakeService $intake): int
    {
        $patient = $this->writablePatient($accessService);
        $dir = $this->optionalStringOption('dir');
        $zip = $this->optionalStringOption('zip');
        if (($dir === null) === ($zip === null)) {
            $this->error('Pass exactly one of --dir or --zip.');

            return self::INVALID;
        }

        $source = null;
        try {
            $source = $this->openSource($dir, $zip);
            $result = $intake->intake($source, $patient, $this->intOptionRequired('actor'));
        } catch (EhiPackageRejected $rejected) {
            $this->line("refused: {$rejected->reason}");

            return self::FAILURE;
        } catch (EhiIntakeFailed $failed) {
            $this->line("failed: {$failed->reason}");

            return self::FAILURE;
        } catch (AuthorizationException|ModelNotFoundException $denied) {
            throw $denied;
        } catch (Throwable $failure) {
            // Never print the message: it could carry a storage or source detail.
            $this->line('failed: unexpected ('.class_basename($failure).')');

            return self::FAILURE;
        } finally {
            $source?->close();
        }

        $this->line('replayed: '.($result->replayed ? 'yes' : 'no'));
        $this->line('resumed: '.($result->resumed ? 'yes' : 'no'));
        $this->linePackage($result->package, $result->summary);

        return self::SUCCESS;
    }

    private function openSource(?string $dir, ?string $zip): EhiPackageSource
    {
        return $dir !== null ? new EhiDirectorySource($dir) : new EhiZipSource((string) $zip);
    }
}
