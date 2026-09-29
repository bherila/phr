<?php

namespace App\Console\Commands\Phr;

/**
 * Validates the shared --disk / --artifact / --patient scope options of the PHR
 * storage migration and cleanup commands.
 *
 * Every rejection is a {@see PhrStorageOptionException}, whose message is fixed
 * text the commands may print as-is.
 */
class PhrStorageScopeOptions
{
    private const array ARTIFACT_DISKS = [
        'documents' => 'phr_documents',
        'dicom-originals' => 'phr_dicom',
        'dicom-derived' => 'phr_dicom',
        'exports' => 'phr_exports',
        'native-backups' => 'phr_exports',
    ];

    /**
     * @param  list<string>  $disks
     * @param  list<string>  $artifacts
     * @return array{disk: ?string, artifact: ?string, patientId: ?int}
     *
     * @throws PhrStorageOptionException
     */
    public function parse(mixed $disk, mixed $artifact, mixed $patient, array $disks, array $artifacts): array
    {
        if ($disk !== null && (! is_string($disk) || ! in_array($disk, $disks, true))) {
            throw PhrStorageOptionException::invalidDisk($disks);
        }
        if ($artifact !== null && (! is_string($artifact) || ! in_array($artifact, $artifacts, true))) {
            throw PhrStorageOptionException::invalidArtifact($artifacts);
        }

        $patientId = null;
        if ($patient !== null) {
            if (! is_string($patient) || ! ctype_digit($patient) || (int) $patient < 1) {
                throw PhrStorageOptionException::invalidPatient();
            }
            $patientId = (int) $patient;
        }

        if ($disk !== null && $artifact !== null) {
            $artifactDisk = self::ARTIFACT_DISKS[$artifact] ?? throw PhrStorageOptionException::invalidArtifact($artifacts);
            if ($artifactDisk !== $disk) {
                throw PhrStorageOptionException::incompatibleScope();
            }
        }

        return ['disk' => $disk, 'artifact' => $artifact, 'patientId' => $patientId];
    }
}
