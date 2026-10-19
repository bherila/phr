<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrEhiBlob;
use App\Models\PhrEhiPackageMember;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\Ehi\EhiBlobVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:ehi:verify {--patient= : PHR patient id} {--actor= : Acting user id} {--package= : EHI package id (default: every package of the patient)}')]
#[Description('Re-hash the stored bytes of EHI packages and report missing or tampered blobs')]
class PhrEhiVerifyCommand extends BaseEhiCommand
{
    public function handle(PhrPatientAccessService $accessService, EhiBlobVerifier $verifier): int
    {
        $patient = $accessService->accessiblePatient($this->intOptionRequired('patient'), $this->intOptionRequired('actor'));
        $packages = $this->packagesFor($patient, $this->optionalIntOption('package'));

        $problems = 0;
        foreach ($packages as $package) {
            $blobIds = PhrEhiPackageMember::query()->where('package_id', $package->id)->distinct()->pluck('blob_id');
            $counts = [EhiBlobVerifier::OK => 0, EhiBlobVerifier::MISSING => 0, EhiBlobVerifier::TAMPERED => 0];
            $verified = [];
            PhrEhiBlob::query()->where('patient_id', $patient->id)->whereIn('id', $blobIds)->orderBy('id')
                ->each(function (PhrEhiBlob $blob) use ($verifier, &$counts, &$verified): void {
                    $status = $verifier->inspect($blob->storage_disk, $blob->storage_path, $blob->sha256, $blob->byte_size);
                    $counts[$status]++;
                    if ($status === EhiBlobVerifier::OK) {
                        $verified[] = $blob->id;
                    }
                });
            foreach (array_chunk($verified, 500) as $chunk) {
                PhrEhiBlob::query()->whereIn('id', $chunk)->update(['verified_at' => now()]);
            }
            $problems += $counts[EhiBlobVerifier::MISSING] + $counts[EhiBlobVerifier::TAMPERED];

            $this->line(sprintf(
                'package %d: blobs %d, ok %d, missing %d, tampered %d',
                $package->id,
                $blobIds->count(),
                $counts[EhiBlobVerifier::OK],
                $counts[EhiBlobVerifier::MISSING],
                $counts[EhiBlobVerifier::TAMPERED],
            ));
        }

        if ($problems > 0) {
            $this->error("Verification failed: {$problems} blob(s) missing or tampered.");

            return self::FAILURE;
        }
        $this->info('Verification passed.');

        return self::SUCCESS;
    }
}
