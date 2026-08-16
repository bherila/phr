<?php

namespace Tests\Unit;

use App\Support\Storage\PhrStorageKey;
use App\Support\Storage\PhrStorageMap;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhrStorageKeyTest extends TestCase
{
    private const string UUID = '018f1f3a-6d18-7f42-a780-5dd94c10f312';

    public function test_keys_are_patient_first_and_deterministic(): void
    {
        $this->assertSame(
            'patients/41/documents/'.self::UUID.'/clinical_summary.pdf',
            PhrStorageKey::document(41, self::UUID, '../clinical summary.pdf'),
        );
        $this->assertSame(
            'patients/41/imaging/dicom/uploads/'.self::UUID,
            PhrStorageKey::dicomUpload(41, self::UUID),
        );
        $this->assertSame(
            'patients/41/imaging/dicom/derived/series/73/v2.bin.gz',
            PhrStorageKey::dicomDerivedSeries(41, 73, 2),
        );
        $this->assertSame(
            'patients/41/exports/'.self::UUID.'/patient-41-ccda.xml',
            PhrStorageKey::export(41, self::UUID, 'patient-41-ccda.xml'),
        );
        $this->assertSame(
            'patients/41/exports/'.self::UUID.'/phr-native-v1.zip',
            PhrStorageKey::nativeBackup(41, self::UUID),
        );
    }

    public function test_patient_names_and_mutable_metadata_cannot_enter_keys(): void
    {
        $first = PhrStorageKey::document(41, self::UUID, 'synthetic.pdf');
        $second = PhrStorageKey::document(42, self::UUID, 'synthetic.pdf');

        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith('patients/41/', $first);
        $this->assertStringStartsWith('patients/42/', $second);
    }

    #[DataProvider('unsafeFilenameProvider')]
    public function test_filenames_are_reduced_to_safe_single_segments(string $input, string $expected): void
    {
        $filename = PhrStorageKey::safeFilename($input, 'document');

        $this->assertSame($expected, $filename);
        $this->assertStringNotContainsString('/', $filename);
        $this->assertStringNotContainsString('\\', $filename);
        $this->assertNotContains($filename, ['.', '..']);
    }

    /** @return array<string, array{string, string}> */
    public static function unsafeFilenameProvider(): array
    {
        return [
            'unix traversal' => ['../../synthetic record.pdf', 'synthetic_record.pdf'],
            'windows traversal' => ['..\\..\\synthetic?.xml', 'synthetic_.xml'],
            'dot segment' => ['..', 'document'],
            'control and separators' => ["folder/line\nitem|one.txt", 'line_item_one.txt'],
        ];
    }

    public function test_dicom_objects_support_canonical_and_legacy_uploads_without_accepting_traversal(): void
    {
        $relativePath = 'STUDY/SERIES/IMAGE0001.dcm';

        $this->assertSame(
            'patients/41/imaging/dicom/uploads/'.self::UUID.'/'.$relativePath,
            PhrStorageKey::dicomObject(PhrStorageKey::dicomUpload(41, self::UUID), $relativePath),
        );
        $this->assertSame(
            'phr/dicom/patients/41/uploads/'.self::UUID.'/'.$relativePath,
            PhrStorageKey::dicomObject('phr/dicom/patients/41/uploads/'.self::UUID, $relativePath),
        );

        $this->expectException(InvalidArgumentException::class);
        PhrStorageKey::dicomObject(PhrStorageKey::dicomUpload(41, self::UUID), '../IMAGE0001.dcm');
    }

    public function test_gc_sweeps_canonical_and_legacy_namespaces_during_rollout(): void
    {
        $this->assertSame(['patients', 'phr/dicom', 'derived/volume-cache'], PhrStorageMap::disks()['phr_dicom']);
        $this->assertSame(['patients', 'phr/documents'], PhrStorageMap::disks()['phr_documents']);
        $this->assertSame(['patients', 'phr/exports', 'phr/native-backups'], PhrStorageMap::disks()['phr_exports']);
    }
}
