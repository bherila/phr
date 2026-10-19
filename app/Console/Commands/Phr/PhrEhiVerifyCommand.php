<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrEhiBlob;
use App\Models\PhrEhiPackage;
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
            $memberRows = PhrEhiPackageMember::query()->where('package_id', $package->id)->count();
            $blobIds = PhrEhiPackageMember::query()->where('package_id', $package->id)->distinct()->pluck('blob_id');
            $counts = [EhiBlobVerifier::OK => 0, EhiBlobVerifier::MISSING => 0, EhiBlobVerifier::TAMPERED => 0];
            $found = 0;
            // Observational: read access may verify, so nothing is written here
            // (blob verified_at is recorded by intake, which holds write access).
            PhrEhiBlob::query()->where('patient_id', $patient->id)->whereIn('id', $blobIds)->orderBy('id')
                ->each(function (PhrEhiBlob $blob) use ($verifier, &$counts, &$found): void {
                    $found++;
                    $counts[$verifier->inspect($blob->storage_disk, $blob->storage_path, $blob->sha256, $blob->byte_size)]++;
                });
            // A referenced blob row that is gone is as missing as its object.
            $counts[EhiBlobVerifier::MISSING] += $blobIds->count() - $found;
            // The manifest is authoritative for a preserved package: a member row
            // lost with its blob row (the FK cascades) leaves no blob to check,
            // so it is counted here instead of passing silently.
            $lostMembers = $package->preservation_status === PhrEhiPackage::PRESERVATION_PRESERVED
                ? max(0, (int) $package->member_count - $memberRows)
                : 0;
            $problems += $counts[EhiBlobVerifier::MISSING] + $counts[EhiBlobVerifier::TAMPERED] + $lostMembers;

            $this->line(sprintf(
                'package %d: members %d of %d, blobs %d, ok %d, missing %d, tampered %d',
                $package->id,
                $memberRows,
                (int) $package->member_count,
                $blobIds->count(),
                $counts[EhiBlobVerifier::OK],
                $counts[EhiBlobVerifier::MISSING],
                $counts[EhiBlobVerifier::TAMPERED],
            ));
        }

        if ($problems > 0) {
            $this->error("Verification failed: {$problems} blob(s) or member(s) missing or tampered.");

            return self::FAILURE;
        }
        $this->info('Verification passed.');

        return self::SUCCESS;
    }
}
