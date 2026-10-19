<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiBlob;
use App\Models\PhrEhiFinding;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiTableAccount;
use App\Services\PHR\Import\Ehi\EhiDialect;
use App\Services\PHR\Import\Ehi\EhiDirectorySource;
use App\Services\PHR\Import\Ehi\EhiEntry;
use App\Services\PHR\Import\Ehi\EhiFindingCode;
use App\Services\PHR\Import\Ehi\EhiIntakeService;
use App\Services\PHR\Import\Ehi\EhiLimits;
use App\Services\PHR\Import\Ehi\EhiMemberKind;
use App\Services\PHR\Import\Ehi\EhiPackageRejected;
use App\Services\PHR\Import\Ehi\EhiPackageSource;
use App\Services\PHR\Import\Ehi\EhiZipSource;
use App\Support\Storage\PhrStorageKey;
use Closure;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;
use ZipArchive;

class EhiIntakeServiceTest extends TestCase
{
    use EhiIntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
    }

    protected function tearDown(): void
    {
        $this->tearDownEhi();
        parent::tearDown();
    }

    public function test_directory_intake_preserves_and_accounts_every_member(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $synthetic = EhiSyntheticPackage::standard();

        $result = $this->ehiIntakeDirectory($synthetic, $patient, $owner->id);
        $package = $result->package;

        self::assertFalse($result->replayed);
        self::assertFalse($result->resumed);
        self::assertSame(PhrEhiPackage::INTAKE_DIRECTORY, $package->intake_kind);
        self::assertSame(PhrEhiPackage::PRESERVATION_PRESERVED, $package->preservation_status);
        self::assertSame(PhrEhiPackage::ACCOUNTING_ACCOUNTED, $package->accounting_status);
        self::assertSame(PhrEhiPackage::CLINICAL_NOT_STARTED, $package->clinical_status);
        self::assertSame(PhrEhiPackage::BINDING_UNBOUND, $package->binding_status);
        self::assertSame(EhiSyntheticPackage::PATIENT_KEY, $package->detected_source_patient_key);
        self::assertSame(EhiSyntheticPackage::MEMBERS, $package->member_count);
        self::assertSame(array_sum(array_map('strlen', $synthetic->files)), $package->total_bytes);
        self::assertNull($package->original_archive_sha256);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $package->dictionary_fingerprint);
        self::assertNull($package->lease_token);
        self::assertNull($package->lease_expires_at);
        self::assertSame($owner->id, $package->created_by_user_id);

        // Every member has a manifest row whose bytes are on disk, content-addressed.
        $members = PhrEhiPackageMember::query()->where('package_id', $package->id)->get()->keyBy('path');
        self::assertSame(array_keys($synthetic->files), $members->keys()->sort(SORT_STRING)->values()->all());
        foreach ($synthetic->files as $path => $bytes) {
            $member = $members[$path];
            self::assertSame(hash('sha256', $bytes), $member->sha256);
            self::assertSame(strlen($bytes), $member->byte_size);
            self::assertSame(PhrStorageKey::ehiBlob($patient->id, $member->sha256), $member->blob->storage_path);
            self::assertNotNull($member->blob->verified_at);
            self::assertSame($bytes, Storage::disk((string) config('phr.ehi_disk'))->get($member->blob->storage_path));
        }
        self::assertSame(EhiSyntheticPackage::DISTINCT_BLOBS, PhrEhiBlob::query()->count(), 'identical members share one blob');
        self::assertCount(EhiSyntheticPackage::DISTINCT_BLOBS, $this->ehiStoredObjects($patient));

        self::assertSame([
            EhiMemberKind::TABLE => 9,
            EhiMemberKind::DICTIONARY => 9,
            EhiMemberKind::INDEX => 1,
            EhiMemberKind::README => 1,
            EhiMemberKind::NOTE => 1,
            EhiMemberKind::MEDIA => 1,
            EhiMemberKind::UNKNOWN => 1,
        ], $result->summary->membersByKind);
        self::assertSame('ORDER_RESULTS', $members['ORDER_RESULTS.tsv']->table_name);

        // Every table member has exactly one account.
        $tableMembers = $members->where('kind', EhiMemberKind::TABLE);
        self::assertCount(EhiSyntheticPackage::TABLE_MEMBERS, $tableMembers);
        foreach ($tableMembers as $member) {
            self::assertSame(1, PhrEhiTableAccount::query()->where('member_id', $member->id)->count());
        }
    }

    public function test_accounting_reconciles_and_records_states_and_findings(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);

        $result = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id);
        $accounts = PhrEhiTableAccount::query()->where('package_id', $result->package->id)->get()->keyBy('table_name');

        // source_row_count = valid + malformed data rows, and every valid row has a disposition.
        foreach ($accounts as $account) {
            self::assertSame($account->source_row_count, $account->malformed_row_count + array_sum($account->disposition_counts_json ?? []), $account->table_name);
            self::assertSame(EhiDialect::provisional()->version, $account->parser_version);
        }
        self::assertSame(0, $result->summary->unreconciledTables);
        self::assertSame(13, $result->summary->sourceRows);
        self::assertSame(1, $result->summary->malformedRows);
        self::assertSame([PhrEhiTableAccount::DISPOSITION_RETAINED_UNMAPPED => 11, PhrEhiTableAccount::DISPOSITION_OUT_OF_SCOPE => 1], $result->summary->dispositions);

        self::assertCount(10, $accounts);
        self::assertSame(PhrEhiTableAccount::STATE_PARSED, $accounts['PAT_ENC']->state);
        self::assertSame(3, $accounts['PAT_ENC']->source_row_count);
        self::assertSame(0, $accounts['PAT_ENC']->malformed_row_count);
        self::assertSame([PhrEhiTableAccount::DISPOSITION_RETAINED_UNMAPPED => 3], $accounts['PAT_ENC']->disposition_counts_json);
        self::assertSame(3, $accounts['IMMUNE']->source_row_count);
        self::assertSame(1, $accounts['IMMUNE']->malformed_row_count);
        self::assertSame([PhrEhiTableAccount::DISPOSITION_RETAINED_UNMAPPED => 2], $accounts['IMMUNE']->disposition_counts_json);
        self::assertSame([PhrEhiTableAccount::DISPOSITION_OUT_OF_SCOPE => 1], $accounts['ARPB_TRANSACTIONS']->disposition_counts_json);
        self::assertSame(PhrEhiTableAccount::STATE_HEADER_ONLY, $accounts['ORDER_PROC']->state);
        self::assertSame(0, $accounts['ORDER_PROC']->source_row_count);
        self::assertSame(PhrEhiTableAccount::STATE_EMPTY, $accounts['HNO_INFO']->state);
        self::assertNull($accounts['HNO_INFO']->header_columns_json);
        self::assertSame(PhrEhiTableAccount::STATE_MISSING, $accounts['PAT_ADDRESS']->state);
        self::assertNull($accounts['PAT_ADDRESS']->member_id);
        self::assertSame(0, $accounts['PAT_ADDRESS']->source_row_count);
        self::assertSame(['PAT_ID', 'PAT_NAME', 'BIRTH_DATE', 'SYNTH_EXTRA_COL'], $accounts['PATIENT']->header_columns_json);
        self::assertSame(['SYNTH_EXTRA_COL'], $accounts['PATIENT']->unknown_columns_json);
        self::assertSame(['PAT_MRN_ID'], $accounts['PATIENT']->missing_columns_json);
        self::assertSame([
            PhrEhiTableAccount::STATE_PARSED => 7,
            PhrEhiTableAccount::STATE_HEADER_ONLY => 1,
            PhrEhiTableAccount::STATE_EMPTY => 1,
            PhrEhiTableAccount::STATE_MALFORMED => 0,
            PhrEhiTableAccount::STATE_MISSING => 1,
        ], $result->summary->tablesByState);

        self::assertSame([
            EhiFindingCode::MALFORMED_ROW => 1,
            EhiFindingCode::UNKNOWN_COLUMN => 1,
            EhiFindingCode::UNKNOWN_MEMBER => 1,
            EhiFindingCode::UNKNOWN_TABLE => 1,
        ], $result->summary->findingsByCode);
        $malformed = PhrEhiFinding::query()->where('code', EhiFindingCode::MALFORMED_ROW)->sole();
        self::assertSame(4, $malformed->line_number);
        self::assertSame($accounts['IMMUNE']->member_id, $malformed->member_id); // sensitive-scan-ignore: package member FK, not an insurance member id
        self::assertSame(['code' => 'field_count_mismatch', 'expected_fields' => 3, 'actual_fields' => 2], $malformed->detail_json);
        $column = PhrEhiFinding::query()->where('code', EhiFindingCode::UNKNOWN_COLUMN)->sole();
        self::assertSame('SYNTH_EXTRA_COL', $column->column_name);
        $unknownTable = PhrEhiFinding::query()->where('code', EhiFindingCode::UNKNOWN_TABLE)->sole();
        self::assertSame($accounts[EhiSyntheticPackage::MARKER]->member_id, $unknownTable->member_id); // sensitive-scan-ignore: package member FK, not an insurance member id

        // Findings never carry a cell value.
        foreach (PhrEhiFinding::query()->get() as $finding) {
            self::assertStringNotContainsString(EhiSyntheticPackage::MARKER, json_encode($finding->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    public function test_findings_per_member_are_capped_with_a_truncation_finding(): void
    {
        config(['phr.ehi_max_findings_per_member' => 2]);
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $package = EhiSyntheticPackage::standard()->with('IMMUNE.tsv', "IMMUNE_ID\tDOSE\tNDC_CODE\n1\n2\n3\n4\t1 mL\t\n5\n");

        $result = $this->ehiIntakeDirectory($package, $patient, $owner->id);

        self::assertSame(2, $result->summary->findingsByCode[EhiFindingCode::MALFORMED_ROW]);
        $truncated = PhrEhiFinding::query()->where('code', EhiFindingCode::FINDINGS_TRUNCATED)->sole();
        self::assertSame(['omitted' => 2], $truncated->detail_json);
        $account = PhrEhiTableAccount::query()->where('table_name', 'IMMUNE')->sole();
        self::assertSame(5, $account->source_row_count);
        self::assertSame(4, $account->malformed_row_count, 'counts are never capped');
    }

    public function test_repacked_zip_replays_the_same_package_without_new_rows_or_blobs(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $synthetic = EhiSyntheticPackage::standard();

        $first = $this->ehiIntakeDirectory($synthetic, $patient, $owner->id);
        $counts = $this->rowCounts();
        $objects = $this->ehiStoredObjects($patient);

        $deflated = $synthetic->writeZip($this->ehiRoot.'/deflate.zip');
        $stored = $synthetic->writeZip($this->ehiRoot.'/stored.zip', ZipArchive::CM_STORE, reverse: true);
        self::assertNotSame(hash_file('sha256', $deflated), hash_file('sha256', $stored));

        foreach ([$deflated, $stored] as $index => $zip) {
            $replay = $this->ehiIntake(new EhiZipSource($zip), $patient, $owner->id);

            self::assertTrue($replay->replayed);
            self::assertFalse($replay->resumed);
            self::assertSame($first->package->id, $replay->package->id);
            self::assertSame($first->package->manifest_digest, $replay->package->manifest_digest);
            self::assertSame($index + 2, $replay->package->intake_count);
            self::assertEquals($first->summary, $replay->summary);
            self::assertSame($counts, $this->rowCounts());
            self::assertSame($objects, $this->ehiStoredObjects($patient));
        }
    }

    public function test_zip_intake_records_original_archive_metadata(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $zip = EhiSyntheticPackage::standard()->writeZip($this->ehiRoot.'/package.zip');

        $result = $this->ehiIntake(new EhiZipSource($zip), $patient, $owner->id);

        self::assertSame(PhrEhiPackage::INTAKE_ZIP, $result->package->intake_kind);
        self::assertSame(hash_file('sha256', $zip), $result->package->original_archive_sha256);
        self::assertSame(filesize($zip), $result->package->original_archive_bytes);
        self::assertSame(EhiSyntheticPackage::MEMBERS, $result->summary->members);
        self::assertSame([], Storage::disk((string) config('phr.ehi_disk'))->files("patients/{$patient->id}"), 'the archive itself is never stored');
    }

    public function test_changed_content_creates_a_new_package_sharing_unchanged_blobs(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $first = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id, 'first');

        $second = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard()->withChangedResults(), $patient, $owner->id, 'second');

        self::assertNotSame($first->package->id, $second->package->id);
        self::assertFalse($second->replayed);
        self::assertSame(EhiSyntheticPackage::DISTINCT_BLOBS + 1, PhrEhiBlob::query()->count());
        self::assertCount(EhiSyntheticPackage::DISTINCT_BLOBS + 1, $this->ehiStoredObjects($patient));

        $before = PhrEhiPackageMember::query()->where('package_id', $first->package->id)->pluck('blob_id', 'path');
        $after = PhrEhiPackageMember::query()->where('package_id', $second->package->id)->pluck('blob_id', 'path');
        foreach ($before as $path => $blobId) {
            if ($path === 'ORDER_RESULTS.tsv') {
                self::assertNotSame($blobId, $after[$path]);
            } else {
                self::assertSame($blobId, $after[$path], 'unchanged members share blobs');
            }
        }
        self::assertSame(1, PhrEhiTableAccount::query()->where('package_id', $second->package->id)->where('table_name', 'ORDER_RESULTS')->sole()->source_row_count);
    }

    public function test_multiple_source_patients_are_refused_before_anything_is_stored(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);

        $this->assertRefused(EhiPackageRejected::MULTIPLE_PATIENTS, fn () => $this->ehiIntakeDirectory(EhiSyntheticPackage::standard()->withSecondPatient(), $patient, $owner->id));
        $this->assertNoEhiRows();
    }

    public function test_package_without_a_source_patient_is_refused_before_anything_is_stored(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);

        $this->assertRefused(EhiPackageRejected::NO_PATIENT, fn () => $this->ehiIntakeDirectory(EhiSyntheticPackage::standard()->withoutPatientKeys(), $patient, $owner->id));
        $this->assertNoEhiRows();
    }

    public function test_blank_patient_key_in_a_patient_table_is_refused_before_anything_is_stored(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);

        $this->assertRefused(EhiIntakeService::REJECT_UNSCOPED_PATIENT_ROW, fn () => $this->ehiIntakeDirectory(EhiSyntheticPackage::standard()->withBlankPatientKey(), $patient, $owner->id));
        $this->assertNoEhiRows();
    }

    public function test_table_bytes_changed_to_the_same_length_during_accounting_are_refused(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        // PAT_ENC.tsv is opened to peek, to hash, then to account: the third read
        // sees same-length bytes that differ, while the file itself never changes
        // (so the later preservation pass would hash clean).
        $source = $this->swappingSource('PAT_ENC.tsv', 3, static fn (string $bytes): string => str_replace("\t1001\t", "\t1009\t", $bytes));

        $this->assertRefused(EhiPackageRejected::SIZE_MISMATCH, fn () => $this->ehiIntake($source, $patient, $owner->id));
        $this->assertNoEhiRows();
    }

    public function test_an_unsupported_name_table_is_still_patient_scoped(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $foreign = EhiSyntheticPackage::standard()->with("Caf\u{00E9}.tsv", "PAT_ID\tSYNTH_A\n".EhiSyntheticPackage::OTHER_PATIENT_KEY."\t1\n");
        $blank = EhiSyntheticPackage::standard()->with("Caf\u{00E9}.tsv", "PAT_ID\tSYNTH_A\n\t1\n");

        $this->assertRefused(EhiPackageRejected::MULTIPLE_PATIENTS, fn () => $this->ehiIntakeDirectory($foreign, $patient, $owner->id, 'foreign'));
        $this->assertRefused(EhiIntakeService::REJECT_UNSCOPED_PATIENT_ROW, fn () => $this->ehiIntakeDirectory($blank, $patient, $owner->id, 'blank'));
        $this->assertNoEhiRows();
    }

    public function test_a_readme_named_table_is_still_patient_scoped(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $other = "PAT_ID\tSYNTH_A\n".EhiSyntheticPackage::OTHER_PATIENT_KEY."\t1\n";

        $this->assertRefused(EhiPackageRejected::MULTIPLE_PATIENTS, fn () => $this->ehiIntakeDirectory(EhiSyntheticPackage::standard()->with('README.tsv', $other), $patient, $owner->id, 'tsv'));
        $this->assertRefused(EhiPackageRejected::MULTIPLE_PATIENTS, fn () => $this->ehiIntakeDirectory(EhiSyntheticPackage::standard()->with('docs/readme-tables.txt', $other), $patient, $owner->id, 'txt'));
        $this->assertNoEhiRows();
    }

    public function test_column_lists_too_large_for_a_backup_record_are_refused(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        // A quarter of this record limit is smaller than the standard PATIENT
        // header plus its unknown and missing columns.
        config(['phr.native_restore_max_record_bytes' => 256]);

        $this->assertRefused(EhiIntakeService::REJECT_ACCOUNT_COLUMNS_TOO_LARGE, fn () => $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id));
        $this->assertNoEhiRows();
    }

    public function test_a_member_row_lost_with_its_blob_row_is_recreated_not_replayed_over(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $package = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id)->package;
        $blob = PhrEhiBlob::query()->where('sha256', hash('sha256', EhiSyntheticPackage::standard()->files['PAT_ENC.tsv']))->sole();
        $blob->delete();
        self::assertSame(EhiSyntheticPackage::MEMBERS - 1, PhrEhiPackageMember::query()->where('package_id', $package->id)->count(), 'the member row cascades');

        $again = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id);

        self::assertFalse($again->replayed);
        self::assertTrue($again->resumed);
        self::assertSame(PhrEhiPackage::PRESERVATION_PRESERVED, $again->package->preservation_status);
        self::assertSame(EhiSyntheticPackage::MEMBERS, PhrEhiPackageMember::query()->where('package_id', $package->id)->count());
        self::assertSame(EhiSyntheticPackage::DISTINCT_BLOBS, PhrEhiBlob::query()->count());
        self::assertTrue($this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id)->replayed);
    }

    public function test_a_first_line_that_differs_from_the_hashed_bytes_is_refused(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        // Only the classification peek (the first open) sees a different header,
        // so kind, table, and detection would describe bytes that are not stored.
        $source = $this->swappingSource('PAT_ENC.tsv', 1, static fn (string $bytes): string => str_replace('CONTACT_DATE', 'CONTACT_DATX', $bytes));

        $this->assertRefused(EhiPackageRejected::SIZE_MISMATCH, fn () => $this->ehiIntake($source, $patient, $owner->id));
        $this->assertNoEhiRows();
    }

    public function test_a_package_accounted_under_other_rule_versions_is_reaccounted_not_replayed(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $first = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id);
        $package = $first->package;
        $current = $package->checkpoint_json['versions'];
        $package->forceFill(['checkpoint_json' => [...$package->checkpoint_json, 'versions' => [...$current, 'dialect' => 'ehi-tsv-0/older']]])->save();
        PhrEhiTableAccount::query()->where('package_id', $package->id)->update(['parser_version' => 'ehi-tsv-0/older']);
        PhrEhiFinding::query()->where('package_id', $package->id)->delete();

        $again = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id);

        self::assertFalse($again->replayed);
        self::assertTrue($again->resumed);
        self::assertSame($package->id, $again->package->id);
        self::assertSame(PhrEhiPackage::ACCOUNTING_ACCOUNTED, $again->package->accounting_status);
        self::assertSame($current, $again->package->checkpoint_json['versions']);
        self::assertSame(0, PhrEhiTableAccount::query()->where('package_id', $package->id)->where('parser_version', 'ehi-tsv-0/older')->count());
        self::assertGreaterThan(0, PhrEhiFinding::query()->where('package_id', $package->id)->count(), 'findings are re-derived');

        $replay = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id);
        self::assertTrue($replay->replayed, 'once current, the package replays');
    }

    public function test_reaccounting_that_would_change_the_source_patient_key_is_refused(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $package = $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id)->package;
        $package->forceFill([
            'detected_source_patient_key' => EhiSyntheticPackage::OTHER_PATIENT_KEY,
            'checkpoint_json' => [...$package->checkpoint_json, 'versions' => [...$package->checkpoint_json['versions'], 'classifier' => 'ehi-classify-0']],
        ])->save();

        $this->assertRefused(EhiIntakeService::REJECT_PATIENT_KEY_CHANGED, fn () => $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id));
        self::assertSame(PhrEhiPackage::ACCOUNTING_ACCOUNTED, $package->fresh()->accounting_status, 'the recorded accounting is left as it was');
    }

    public function test_non_ehi_package_is_refused_rather_than_imported_empty(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        // Only a readme and a note survive: no tables, so not an EHI Tables export.
        $other = EhiSyntheticPackage::standard();
        foreach (array_keys($other->files) as $path) {
            if (! in_array($path, ['README.txt', 'notes/note-'.EhiSyntheticPackage::MARKER.'.rtf'], true)) {
                $other = $other->without($path);
            }
        }

        $this->assertRefused(EhiPackageRejected::NOT_EHI, fn () => $this->ehiIntakeDirectory($other->with('records.json', '{"resourceType":"Bundle"}'), $patient, $owner->id));
        $this->assertNoEhiRows();
    }

    public function test_hostile_packages_are_refused_and_leave_nothing(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);

        $dir = $this->ehiDirectory(EhiSyntheticPackage::standard(), 'linked');
        file_put_contents($this->ehiRoot.'/outside.tsv', "PAT_ID\tX\nZ1\t1\n");
        symlink($this->ehiRoot.'/outside.tsv', $dir.'/LINKED.tsv');
        $this->assertRefused(EhiPackageRejected::SYMLINK, fn () => $this->ehiIntake(new EhiDirectorySource($dir), $patient, $owner->id));

        $zipPath = EhiSyntheticPackage::standard()->writeZip($this->ehiRoot.'/traversal.zip');
        $zip = new ZipArchive;
        self::assertTrue($zip->open($zipPath));
        self::assertTrue($zip->addFromString('../escape.tsv', "PAT_ID\tX\nZ1\t1\n"));
        $zip->close();
        $this->assertRefused(EhiPackageRejected::PATH_UNSAFE, fn () => $this->ehiIntake(new EhiZipSource($zipPath), $patient, $owner->id));

        config(['phr.ehi_max_members' => 5]);
        $this->assertRefused(EhiPackageRejected::TOO_MANY_MEMBERS, fn () => $this->ehiIntakeDirectory(EhiSyntheticPackage::standard(), $patient, $owner->id, 'many'));

        $this->assertNoEhiRows();
    }

    public function test_duplicate_table_names_are_refused(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $package = EhiSyntheticPackage::standard()->with('more/patient.tsv', "PAT_ID\tPAT_NAME\n".EhiSyntheticPackage::PATIENT_KEY."\tx\n");

        $this->assertRefused(EhiIntakeService::REJECT_DUPLICATE_TABLE, fn () => $this->ehiIntakeDirectory($package, $patient, $owner->id));
        $this->assertNoEhiRows();
    }

    public function test_malformed_row_in_a_patient_table_is_refused_before_anything_is_stored(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $a = EhiSyntheticPackage::PATIENT_KEY;
        $b = EhiSyntheticPackage::OTHER_PATIENT_KEY;
        $cases = [
            // A valid row for A, and a field-count mismatch that carries B.
            'mismatch' => EhiSyntheticPackage::standard()->with('PAT_IMMUNIZATIONS.tsv', "PAT_ID\tLINE\tIMMUNE_ID\n{$a}\t1\t501\n{$b}\t2\n"),
            // An unusable header that still names PAT_ID: every row is unscoped.
            'header' => EhiSyntheticPackage::standard()->with('PAT_IMMUNIZATIONS.tsv', "PAT_ID\tPAT_ID\tIMMUNE_ID\n{$b}\t{$b}\t502\n"),
            // A header that cannot be split may have the column.
            'undecodable' => EhiSyntheticPackage::standard()->with('PAT_IMMUNIZATIONS.tsv', "PAT_ID\tLINE\xFF\n{$b}\t2\n"),
        ];
        foreach ($cases as $name => $package) {
            $this->assertRefused(EhiIntakeService::REJECT_UNSCOPED_PATIENT_ROW, fn () => $this->ehiIntakeDirectory($package, $patient, $owner->id, $name));
            $this->assertNoEhiRows();
        }
    }

    public function test_rows_after_an_unusable_header_are_counted_as_malformed(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        // IMMUNE has no PAT_ID column, so its unscoped rows are accounted, not refused.
        $package = EhiSyntheticPackage::standard()->with('IMMUNE.tsv', "IMMUNE_ID\tIMMUNE_ID\tDOSE\n501\t501\t1 mL\n502\n\n503\t503\t2 mL");

        $result = $this->ehiIntakeDirectory($package, $patient, $owner->id);

        $account = PhrEhiTableAccount::query()->where('table_name', 'IMMUNE')->sole();
        self::assertSame(PhrEhiTableAccount::STATE_MALFORMED, $account->state);
        self::assertNull($account->header_columns_json);
        self::assertSame(4, $account->source_row_count);
        self::assertSame(4, $account->malformed_row_count);
        self::assertSame([PhrEhiTableAccount::DISPOSITION_RETAINED_UNMAPPED => 0], $account->disposition_counts_json);
        self::assertSame(0, $result->summary->unreconciledTables);
        self::assertSame(1, $result->summary->tablesByState[PhrEhiTableAccount::STATE_MALFORMED]);
        self::assertSame(1, $result->summary->findingsByCode[EhiFindingCode::MALFORMED_HEADER]);
        self::assertSame(4, $result->summary->findingsByCode[EhiFindingCode::MALFORMED_ROW]);
        $rows = PhrEhiFinding::query()->where('member_id', $account->member_id)->where('code', EhiFindingCode::MALFORMED_ROW)->orderBy('line_number')->get(); // sensitive-scan-ignore: package member FK, not an insurance member id
        self::assertSame([2, 3, 4, 5], $rows->pluck('line_number')->all());
        foreach ($rows as $finding) {
            self::assertSame('header_unusable', $finding->detail_json['code']);
        }

        // The cap applies to drained rows as to any other; the counts never are capped.
        config(['phr.ehi_max_findings_per_member' => 2]);
        $other = $this->ehiPatient($owner);
        $capped = $this->ehiIntakeDirectory($package, $other, $owner->id, 'capped');
        self::assertSame(1, $capped->summary->findingsByCode[EhiFindingCode::MALFORMED_HEADER]);
        self::assertSame(1, $capped->summary->findingsByCode[EhiFindingCode::MALFORMED_ROW]);
        self::assertSame(4, $capped->summary->malformedRows);
        $truncated = PhrEhiFinding::query()->where('package_id', $capped->package->id)->where('code', EhiFindingCode::FINDINGS_TRUNCATED)->sole();
        self::assertSame(['omitted' => 3], $truncated->detail_json);
    }

    public function test_non_identifier_table_names_are_unknown_members_not_tables(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $rows = "IMMUNE_ID\tDOSE\n1\t1 mL\n";
        // Café and CAFE compare equal under a case-insensitive collation; only the
        // ASCII one is a table, so neither a refusal nor a merged account follows.
        $package = EhiSyntheticPackage::standard()
            ->with("one/Caf\u{e9}.tsv", $rows)
            ->with('two/CAFE.tsv', $rows.'2\t2 mL\n')
            ->with("three/\u{8868}.tsv", $rows);

        $result = $this->ehiIntakeDirectory($package, $patient, $owner->id);

        $members = PhrEhiPackageMember::query()->where('package_id', $result->package->id)->get()->keyBy('path');
        foreach (["one/Caf\u{e9}.tsv", "three/\u{8868}.tsv"] as $path) {
            self::assertSame(EhiMemberKind::UNKNOWN, $members[$path]->kind);
            self::assertNull($members[$path]->table_name);
            $finding = PhrEhiFinding::query()->where('member_id', $members[$path]->id)->sole(); // sensitive-scan-ignore: package member FK, not an insurance member id
            self::assertSame(EhiFindingCode::UNSUPPORTED_TABLE_NAME, $finding->code);
            self::assertNull($finding->column_name);
            self::assertNull($finding->detail_json);
        }
        self::assertSame(EhiMemberKind::TABLE, $members['two/CAFE.tsv']->kind);
        self::assertSame(2, PhrEhiTableAccount::query()->where('package_id', $result->package->id)->where('table_name', 'CAFE')->sole()->source_row_count);
        self::assertSame(2, $result->summary->findingsByCode[EhiFindingCode::UNSUPPORTED_TABLE_NAME]);
        self::assertSame(EhiSyntheticPackage::TABLE_MEMBERS + 1, $result->summary->membersByKind[EhiMemberKind::TABLE]);
        self::assertSame(0, $result->summary->unreconciledTables);
    }

    public function test_replay_repairs_missing_and_tampered_blobs_without_new_rows(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $synthetic = EhiSyntheticPackage::standard();
        $first = $this->ehiIntakeDirectory($synthetic, $patient, $owner->id);
        $counts = $this->rowCounts();
        $disk = Storage::disk((string) config('phr.ehi_disk'));
        $blobs = PhrEhiBlob::query()->orderBy('id')->get();
        $disk->delete($blobs[0]->storage_path);
        $disk->put($blobs[1]->storage_path, 'synthetic tampered bytes');
        PhrEhiBlob::query()->update(['verified_at' => null]);

        // Counts puts: only the two damaged objects may be written.
        $failing = $this->ehiFailingDisk(failOnPut: 0);
        $replay = $this->ehiIntakeDirectory($synthetic, $patient, $owner->id, 'again');
        $this->ehiRestoreDisk($failing);

        self::assertTrue($replay->replayed);
        self::assertSame($first->package->id, $replay->package->id);
        self::assertSame(2, $replay->repaired);
        self::assertSame(2, $failing->puts, 'intact objects are never rewritten');
        self::assertSame($counts, $this->rowCounts());
        foreach ($blobs as $blob) {
            self::assertSame($blob->sha256, hash('sha256', (string) $disk->get($blob->storage_path)));
        }
        self::assertSame($blobs->count(), PhrEhiBlob::query()->whereNotNull('verified_at')->count());
        self::assertEquals($first->summary, $replay->summary);

        $again = $this->ehiIntakeDirectory($synthetic, $patient, $owner->id, 'third');
        self::assertSame(0, $again->repaired);
        self::assertSame($counts, $this->rowCounts());
    }

    public function test_package_without_a_dictionary_is_accounted_with_a_finding(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $package = EhiSyntheticPackage::standard();
        foreach (array_keys($package->files) as $path) {
            if (str_starts_with($path, 'docs/')) {
                $package = $package->without($path);
            }
        }

        $result = $this->ehiIntakeDirectory($package, $patient, $owner->id);

        self::assertNull($result->package->dictionary_fingerprint);
        self::assertSame(1, $result->summary->findingsByCode[EhiFindingCode::DICTIONARY_EMPTY]);
        self::assertArrayNotHasKey(EhiFindingCode::UNKNOWN_TABLE, $result->summary->findingsByCode);
        self::assertSame(0, $result->summary->tablesByState[PhrEhiTableAccount::STATE_MISSING]);
        self::assertSame(0, $result->summary->unreconciledTables);
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        return [
            'packages' => PhrEhiPackage::query()->count(),
            'blobs' => PhrEhiBlob::query()->count(),
            'members' => PhrEhiPackageMember::query()->count(),
            'accounts' => PhrEhiTableAccount::query()->count(),
            'findings' => PhrEhiFinding::query()->count(),
        ];
    }

    /**
     * The standard package, except that the Nth open of one member returns its
     * bytes transformed; the file on disk never changes.
     *
     * @param  callable(string): string  $transform
     */
    private function swappingSource(string $basename, int $onOpen, callable $transform): EhiPackageSource
    {
        $inner = new EhiDirectorySource($this->ehiDirectory(EhiSyntheticPackage::standard()));

        return new class($inner, $basename, $onOpen, $transform(...)) implements EhiPackageSource
        {
            private int $opens = 0;

            public function __construct(private readonly EhiPackageSource $inner, private readonly string $basename, private readonly int $onOpen, private readonly Closure $transform) {}

            public function kind(): string
            {
                return $this->inner->kind();
            }

            public function entries(): array
            {
                return $this->inner->entries();
            }

            public function open(EhiEntry $entry)
            {
                $stream = $this->inner->open($entry);
                if (basename($entry->originalPath) !== $this->basename || ++$this->opens !== $this->onOpen) {
                    return $stream;
                }
                $bytes = (string) stream_get_contents($stream);
                fclose($stream);
                $swapped = fopen('php://memory', 'w+b');
                fwrite($swapped, ($this->transform)($bytes));
                rewind($swapped);

                return $swapped;
            }

            public function originalArchive(EhiLimits $limits): ?array
            {
                return $this->inner->originalArchive($limits);
            }

            public function close(): void
            {
                $this->inner->close();
            }
        };
    }

    private function assertRefused(string $reason, callable $intake): void
    {
        try {
            $intake();
            self::fail("Expected the package to be refused ({$reason}).");
        } catch (EhiPackageRejected $rejected) {
            self::assertSame($reason, $rejected->reason);
            self::assertStringNotContainsString(EhiSyntheticPackage::MARKER, $rejected->getMessage());
            self::assertStringNotContainsString($this->ehiRoot, $rejected->getMessage());
        }
    }
}
