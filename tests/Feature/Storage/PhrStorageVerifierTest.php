<?php

namespace Tests\Feature\Storage;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Support\Storage\PhrStorageMap;
use App\Support\Storage\PhrStorageVerifier;
use App\Support\Storage\StoragePruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NoLocalPathDisk;
use Tests\Support\UsesObjectStoreDisks;
use Tests\TestCase;

class PhrStorageVerifierTest extends TestCase
{
    use RefreshDatabase;
    use UsesObjectStoreDisks;

    private PhrPatient $patient;

    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useObjectStoreDisks();
        $this->ownerId = (int) $this->createUser()->id;
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->ownerId, 'display_name' => 'Synthetic Patient', 'relationship' => 'self']);
    }

    protected function tearDown(): void
    {
        $this->tearDownObjectStoreDisks();
        parent::tearDown();
    }

    private function document(string $key, string $bytes, ?string $hash = null): PhrDocument
    {
        Storage::disk('phr_documents')->put($key, $bytes);

        return PhrDocument::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->ownerId, 'uploaded_by_user_id' => $this->ownerId,
            'document_type' => 'other', 'source' => 'manual_upload', 'storage_disk' => 'phr_documents', 'storage_path' => $key,
            'original_filename' => 'private-name.txt', 'mime_type' => 'text/plain', 'byte_size' => strlen($bytes), 'file_hash' => $hash ?? hash('sha256', $bytes),
        ]);
    }

    /** @return array<string, int> */
    private function tally(array $disks = []): array
    {
        return app(PhrStorageVerifier::class)->verify($disks)['disks']['phr_documents'];
    }

    public function test_every_column_the_storage_map_references_is_checked_or_counted(): void
    {
        $checked = array_map(static fn (array $source): string => $source['table'].'.'.$source['key'], PhrStorageVerifier::sources());
        $mapped = array_map(static fn ($reference): string => $reference->table.'.'.$reference->column, PhrStorageMap::references()->references());

        $this->assertSame([], array_values(array_diff($mapped, $checked)), 'A new key column needs a line in PhrStorageVerifier::sources().');
    }

    public function test_every_source_names_real_columns(): void
    {
        foreach (PhrStorageVerifier::sources() as $source) {
            foreach (['disk', 'key', 'size', 'hash', 'status'] as $column) {
                if (isset($source[$column])) {
                    $this->assertTrue(Schema::hasColumn($source['table'], $source[$column]), "{$source['table']}.{$source[$column]}");
                }
            }
            $this->assertTrue(Schema::hasColumn($source['table'], 'id'), $source['table']);
        }
    }

    public function test_a_whole_store_passes_and_nothing_is_written_to_check_it(): void
    {
        $this->document('patients/1/documents/a/one.txt', 'first');
        $this->document('patients/1/documents/b/two.txt', 'second');
        foreach (self::OBJECT_STORE_DISKS as $disk => $directory) {
            $filesystem = Storage::disk($disk);
            if ($filesystem instanceof NoLocalPathDisk) {
                $filesystem->objectStore()->readOnly = true;
            }
        }
        $before = DB::table('phr_documents')->orderBy('id')->get()->toArray();

        $this->artisan('phr:storage:verify')->assertSuccessful();

        $this->assertEquals($before, DB::table('phr_documents')->orderBy('id')->get()->toArray());
        $tally = $this->tally();
        $this->assertSame(2, $tally['rows']);
        $this->assertSame(2, $tally['ok']);
        $this->assertSame(2, $tally['hashed']);
        $this->assertSame(strlen('first') + strlen('second'), $tally['bytes']);
        $this->assertSame(2, $tally['objects']);
        $this->assertSame(0, $tally['unreferenced']);
    }

    public function test_a_missing_object_a_wrong_size_and_a_wrong_hash_each_fail_without_naming_the_key(): void
    {
        $missing = $this->document('patients/1/documents/a/private-missing.txt', 'first');
        $short = $this->document('patients/1/documents/b/private-short.txt', 'second');
        $changed = $this->document('patients/1/documents/c/private-changed.txt', 'third');
        Storage::disk('phr_documents')->delete($missing->storage_path);
        Storage::disk('phr_documents')->put($short->storage_path, 's');
        Storage::disk('phr_documents')->put($changed->storage_path, 'THIRD');

        $tally = $this->tally();
        $this->assertSame(1, $tally['missing']);
        $this->assertSame(1, $tally['wrong_size']);
        $this->assertSame(1, $tally['wrong_hash']);
        $this->assertSame(0, $tally['ok']);

        $this->artisan('phr:storage:verify')
            ->expectsOutputToContain("phr_documents row {$missing->id}: missing")
            ->expectsOutputToContain("phr_documents row {$short->id}: wrong_size")
            ->expectsOutputToContain("phr_documents row {$changed->id}: wrong_hash")
            ->doesntExpectOutputToContain('private-')
            ->assertFailed();
    }

    public function test_size_only_skips_hashes(): void
    {
        $changed = $this->document('patients/1/documents/c/changed.txt', 'third');
        Storage::disk('phr_documents')->put($changed->storage_path, 'THIRD');

        $this->artisan('phr:storage:verify', ['--size-only' => true])->assertSuccessful();
        $this->assertSame(1, app(PhrStorageVerifier::class)->verify([], false)['disks']['phr_documents']['size_only']);
        $this->artisan('phr:storage:verify')->assertFailed();
    }

    public function test_a_hash_that_is_not_a_digest_falls_back_to_size(): void
    {
        $this->document('patients/1/documents/a/legacy.txt', 'first', 'not-a-sha256');

        $tally = $this->tally();
        $this->assertSame(1, $tally['ok']);
        $this->assertSame(1, $tally['size_only']);
    }

    public function test_an_unreferenced_object_and_a_quarantined_one_are_counted_and_do_not_fail(): void
    {
        $this->document('patients/1/documents/a/kept.txt', 'first');
        Storage::disk('phr_documents')->put('patients/1/documents/z/orphan.txt', 'orphan');
        Storage::disk('phr_documents')->put(StoragePruner::QUARANTINE_ROOT.'/2030-01-02-030405/patients/1/old.txt', 'held');

        $tally = $this->tally();
        $this->assertSame(2, $tally['objects']);
        $this->assertSame(1, $tally['unreferenced']);
        $this->assertSame(1, $tally['quarantined']);
        $this->artisan('phr:storage:verify')->assertSuccessful();
    }

    public function test_rows_still_mid_upload_are_counted_not_checked(): void
    {
        DB::table('phr_ehi_uploads')->insert(['patient_id' => $this->patient->id, 'actor_user_id' => $this->ownerId, 'storage_disk' => 'phr_documents', 'storage_path' => 'patients/1/ehi-uploads/x/package.zip', 'file_size_bytes' => 10, 'uploaded_bytes' => 5, 'status' => 'uploading', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        $tally = $this->tally();
        $this->assertSame(1, $tally['in_flight']);
        $this->assertSame(0, $tally['missing']);
        $this->artisan('phr:storage:verify')->assertSuccessful();
    }

    public function test_a_finished_dicom_upload_is_neither_checked_nor_in_flight(): void
    {
        foreach (['processed' => 'a', 'pending' => 'b'] as $status => $name) {
            DB::table('phr_dicom_uploads')->insert(['patient_id' => $this->patient->id, 'uploaded_by_user_id' => $this->ownerId, 'status' => $status, 'r2_prefix' => "patients/1/imaging/dicom/uploads/{$name}", 'created_at' => now(), 'updated_at' => now()]);
        }

        $tally = app(PhrStorageVerifier::class)->verify()['disks']['phr_dicom'];

        $this->assertSame(1, $tally['in_flight'], 'A finished upload must not hold a move back forever.');
        $this->assertSame(0, $tally['rows']);
        $this->assertSame(0, $tally['missing']);
    }

    public function test_a_pasted_genai_import_is_not_a_key(): void
    {
        $id = DB::table('genai_import_jobs')->insertGetId(['user_id' => $this->ownerId, 's3_path' => 'inline://paste/synthetic', 'original_filename' => 'paste', 'file_hash' => hash('sha256', 'x'), 'mime_type' => 'text/plain', 'file_size_bytes' => 1, 'job_type' => 'phr_document', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNotNull($id);
        $result = app(PhrStorageVerifier::class)->verify();
        $this->assertSame(0, $result['problem_count']);
        $this->assertSame(0, $result['disks']['s3']['rows']);
    }

    public function test_a_genai_staged_object_is_checked_by_size_not_its_job_hash(): void
    {
        Storage::disk('s3')->put('genai-import/1/x/note.txt', 'staged');
        // A job for a document with no hash records a hash of the key, not of the bytes.
        DB::table('genai_import_jobs')->insert(['user_id' => $this->ownerId, 's3_path' => 'genai-import/1/x/note.txt', 'original_filename' => 'note.txt', 'file_hash' => hash('sha256', 'genai-import/1/x/note.txt'), 'mime_type' => 'text/plain', 'file_size_bytes' => strlen('staged'), 'job_type' => 'phr_document', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        $result = app(PhrStorageVerifier::class)->verify();

        $this->assertSame(0, $result['problem_count']);
        $this->assertSame(1, $result['disks']['s3']['ok']);
        $this->assertSame(1, $result['disks']['s3']['size_only']);
    }

    public function test_a_disk_can_be_checked_alone(): void
    {
        $missing = $this->document('patients/1/documents/a/missing.txt', 'first');
        Storage::disk('phr_documents')->delete($missing->storage_path);

        $this->artisan('phr:storage:verify', ['--disk' => ['phr_exports']])->assertSuccessful();
        $this->artisan('phr:storage:verify', ['--disk' => ['phr_documents']])->assertFailed();
    }
}
