<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\NativeBackup\PhrNativeArchiveBuilder;
use App\Services\PHR\NativeBackup\PhrNativeRestoreArchive;
use App\Services\PHR\NativeBackup\PhrNativeRestoreArchiveReader;
use App\Services\PHR\NativeBackup\PhrNativeRestorePlanner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhrNativeRestorePlannerLookupsTest extends TestCase
{
    public function test_projection_identities_are_batched_and_fresh_for_every_plan(): void
    {
        [$patient, $archive] = $this->fixture(24);
        $planner = app(PhrNativeRestorePlanner::class);
        $inverseReads = [];
        $forwardReads = 0;
        $payloadReads = 0;
        DB::listen(static function (QueryExecuted $event) use (&$inverseReads, &$forwardReads, &$payloadReads): void {
            $sql = str_replace(['"', '`'], '', $event->sql);
            if (str_starts_with($sql, 'select record_id, native_id from phr_native_record_identities ')) {
                $inverseReads[] = $event->bindings;
            }
            if (str_starts_with($sql, 'select native_id, record_id from phr_native_record_identities ')) {
                $forwardReads++;
            }
            if (str_starts_with($sql, 'select * from phr_documents ')) {
                $payloadReads++;
            }
        });
        $identityCount = DB::table('phr_native_record_identities')->count();
        $plan = $planner->plan($archive, $patient->owner_user_id, false);
        self::assertSame([], $plan->blockers);
        self::assertSame(['create' => 0, 'skip' => 24, 'block' => 0], $plan->tables['phr_documents']);
        self::assertLessThanOrEqual(2, $forwardReads);
        self::assertSame(1, $payloadReads);
        self::assertLessThanOrEqual(5, count($inverseReads), 'Inverse lookups scale with pages and relationship tables, not individual records.');
        foreach ($inverseReads as $bindings) {
            self::assertSame($patient->id, $bindings[0]);
            self::assertLessThanOrEqual(500, count($bindings) - 2);
        }
        $inverseReads = [];
        self::assertEquals($plan, $planner->plan($archive, $patient->owner_user_id, false));
        self::assertNotEmpty($inverseReads, 'A new plan must reread its identities.');
        self::assertLessThanOrEqual(5, count($inverseReads));
        self::assertSame($identityCount, DB::table('phr_native_record_identities')->count());
    }

    public function test_projection_pages_bound_identity_reads_and_resolve_distant_relationships(): void
    {
        [$patient, $initialArchive] = $this->fixture(520);
        $documents = PhrDocument::query()->where('patient_id', $patient->id)->orderBy('id')->pluck('id');
        $first = (int) $documents->first();
        $last = (int) $documents->last();
        foreach ([$first, $last] as $id) {
            PhrEob::create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'source_document_id' => $id, 'import_source' => 'synthetic', 'external_id' => 'lookup-'.$id, 'claim_type' => 'medical', 'review_status' => 'confirmed']);
        }
        unset($initialArchive);
        $built = app(PhrNativeArchiveBuilder::class)->build($patient);
        $archive = app(PhrNativeRestoreArchiveReader::class)->openLocal($built->path);
        $reads = [];
        DB::listen(static function (QueryExecuted $event) use (&$reads): void {
            $sql = str_replace(['"', '`'], '', $event->sql);
            if (str_starts_with($sql, 'select record_id, native_id from phr_native_record_identities ') && ($event->bindings[1] ?? null) === 'phr_documents') {
                $reads[] = array_slice($event->bindings, 2);
            }
        });
        $plan = app(PhrNativeRestorePlanner::class)->plan($archive, $patient->owner_user_id, false);
        self::assertSame([], $plan->blockers);
        self::assertSame(520, $plan->tables['phr_documents']['skip']);
        self::assertSame(2, $plan->tables['phr_eobs']['skip']);
        self::assertSame([500, 20, 2], array_map('count', $reads));
        self::assertSame([$first, $last], $reads[2], 'A later page resolves only its actual distant references.');
        self::assertEqualsCanonicalizing($documents->all(), [...$reads[0], ...$reads[1]], 'Pages request exactly the document IDs, regardless of unordered SQL result order.');
    }

    public function test_reused_planner_observes_target_edits_and_removed_then_restored_identities(): void
    {
        [$patient, $archive] = $this->fixture(2);
        $planner = app(PhrNativeRestorePlanner::class);
        $original = $planner->plan($archive, $patient->owner_user_id, false);
        $document = PhrDocument::query()->where('patient_id', $patient->id)->firstOrFail();
        $attributes = $document->getRawOriginal();
        $document->update(['title' => 'Synthetic changed title']);
        $changed = $planner->plan($archive, $patient->owner_user_id, false, true);
        self::assertContains('record_conflict', $changed->blockers);
        self::assertSame(1, $changed->tables['phr_documents']['block']);
        self::assertNotSame($original->digest, $changed->digest);
        DB::table('phr_documents')->where('id', $document->id)->update($attributes);
        $identity = DB::table('phr_native_record_identities')->where('patient_id', $patient->id)->where('record_table', 'users')->where('record_id', $patient->owner_user_id)->first();
        self::assertNotNull($identity);
        DB::table('phr_native_record_identities')->where('id', $identity->id)->delete();
        $missingReads = 0;
        DB::listen(static function (QueryExecuted $event) use (&$missingReads): void {
            $sql = str_replace(['"', '`'], '', $event->sql);
            if (str_starts_with($sql, 'select record_id, native_id from phr_native_record_identities ') && ($event->bindings[1] ?? null) === 'users') {
                $missingReads++;
            }
        });
        self::assertContains('current_identity_missing', $planner->plan($archive, $patient->owner_user_id, false)->blockers);
        self::assertSame(2, $missingReads, 'A missing actor is read once per root/document page, never once per document.');
        DB::table('phr_native_record_identities')->insert((array) $identity);
        self::assertEquals($original, $planner->plan($archive, $patient->owner_user_id, false));
    }

    public function test_policy_excluded_current_rows_block_but_deleted_rows_keep_their_durable_identity(): void
    {
        [$patient, $initialArchive] = $this->fixture(1);
        unset($initialArchive);
        $uploadId = DB::table('phr_dicom_uploads')->insertGetId(['patient_id' => $patient->id,
            'uploaded_by_user_id' => $patient->owner_user_id, 'status' => 'processed',
            'total_files' => 0, 'stored_files' => 0, 'total_bytes' => 0, 'stored_bytes' => 0,
            'r2_prefix' => 'synthetic-policy-upload', 'created_at' => now(), 'updated_at' => now()]);
        $built = app(PhrNativeArchiveBuilder::class)->build($patient);
        $archive = app(PhrNativeRestoreArchiveReader::class)->openLocal($built->path);
        $planner = app(PhrNativeRestorePlanner::class);
        DB::table('phr_dicom_uploads')->where('id', $uploadId)->update(['status' => 'failed']);
        $excluded = $planner->plan($archive, $patient->owner_user_id, false, true);
        self::assertSame(['create' => 0, 'skip' => 0, 'block' => 1], $excluded->tables['phr_dicom_uploads']);
        self::assertContains('record_conflict', $excluded->blockers);
        DB::table('phr_dicom_uploads')->where('id', $uploadId)->delete();
        $deleted = $planner->plan($archive, $patient->owner_user_id, false, true);
        self::assertSame(['create' => 1, 'skip' => 0, 'block' => 0], $deleted->tables['phr_dicom_uploads']);
        self::assertSame([], $deleted->blockers);
    }

    public function test_actor_mappings_and_patient_scope_are_rechecked_between_invocations(): void
    {
        [$patient, $archive] = $this->fixture(1, true);
        $planner = app(PhrNativeRestorePlanner::class);
        $original = $planner->plan($archive, $patient->owner_user_id, true);
        self::assertSame([], $original->blockers);
        $identity = DB::table('phr_native_record_identities')->where('patient_id', $patient->id)->where('record_table', 'users')->where('record_id', '!=', $patient->owner_user_id)->first();
        self::assertNotNull($identity);
        DB::table('phr_native_record_identities')->where('id', $identity->id)->delete();
        self::assertContains('actor_mapping_missing', $planner->plan($archive, $patient->owner_user_id, true)->blockers);
        DB::table('phr_native_record_identities')->insert((array) $identity);
        self::assertEquals($original, $planner->plan($archive, $patient->owner_user_id, true));
        self::assertContains('patient_not_owned', $planner->plan($archive, (int) $identity->record_id, true)->blockers);
        [$otherPatient, $otherArchive] = $this->fixture(1);
        $other = $planner->plan($otherArchive, $otherPatient->owner_user_id, false);
        self::assertSame([], $other->blockers);
        self::assertSame($otherPatient->id, $other->targetPatientId);
        self::assertArrayNotHasKey($original->patientNativeId, $other->actions['phr_patients']);
    }

    /** @return array{PhrPatient, PhrNativeRestoreArchive} */
    private function fixture(int $documents, bool $shared = false): array
    {
        Storage::fake('phr_documents');
        $patient = PhrPatient::create(['owner_user_id' => $this->createUser()->id, 'display_name' => 'Synthetic lookup patient', 'relationship' => 'self']);
        if ($shared) {
            PhrPatientUserAccess::create(['patient_id' => $patient->id, 'user_id' => $this->createUser()->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER, 'granted_by_user_id' => $patient->owner_user_id]);
        }
        for ($index = 0; $index < $documents; $index++) {
            PhrDocument::create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'title' => 'Synthetic lookup document', 'document_type' => 'other', 'source' => 'manual_upload', 'byte_size' => 0]);
        }
        $built = app(PhrNativeArchiveBuilder::class)->build($patient);

        return [$patient, app(PhrNativeRestoreArchiveReader::class)->openLocal($built->path)];
    }
}
