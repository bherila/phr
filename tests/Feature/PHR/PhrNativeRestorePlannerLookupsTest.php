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
    public function test_shared_projection_identities_are_read_once_without_changing_the_plan(): void
    {
        [$patient, $archive] = $this->fixture(24);
        $planner = app(PhrNativeRestorePlanner::class);
        $inverseReads = 0;
        DB::listen(static function (QueryExecuted $event) use (&$inverseReads): void {
            $sql = str_replace(['"', '`'], '', $event->sql);
            if (str_starts_with($sql, 'select native_id from phr_native_record_identities ')) {
                $inverseReads++;
            }
        });
        $identityCount = DB::table('phr_native_record_identities')->count();
        $plan = $planner->plan($archive, $patient->owner_user_id, false);
        self::assertSame([], $plan->blockers);
        self::assertSame(['create' => 0, 'skip' => 24, 'block' => 0], $plan->tables['phr_documents']);
        // Each document has its own identity; root and owner references repeat.
        self::assertLessThanOrEqual(28, $inverseReads);
        $inverseReads = 0;
        self::assertEquals($plan, $planner->plan($archive, $patient->owner_user_id, false));
        self::assertGreaterThan(0, $inverseReads, 'A new plan must reread its identities.');
        self::assertLessThanOrEqual(28, $inverseReads);
        self::assertSame($identityCount, DB::table('phr_native_record_identities')->count());
    }

    public function test_projection_identity_cache_evicts_cold_entries_but_keeps_hot_relationships(): void
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
            if (str_starts_with($sql, 'select native_id from phr_native_record_identities ')) {
                $key = implode(':', $event->bindings);
                $reads[$key] = ($reads[$key] ?? 0) + 1;
            }
        });
        $plan = app(PhrNativeRestorePlanner::class)->plan($archive, $patient->owner_user_id, false);
        self::assertSame([], $plan->blockers);
        self::assertSame(520, $plan->tables['phr_documents']['skip']);
        self::assertSame(2, $plan->tables['phr_eobs']['skip']);
        self::assertSame(2, $reads[$patient->id.':phr_documents:'.$first], 'A cold entry beyond the 512-entry bound must be reread.');
        self::assertSame(1, $reads[$patient->id.':phr_documents:'.$last]);
        self::assertSame(1, $reads[$patient->id.':users:'.$patient->owner_user_id], 'The repeatedly used actor must stay hot.');
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
            if (str_starts_with($sql, 'select native_id from phr_native_record_identities ') && ($event->bindings[1] ?? null) === 'users') {
                $missingReads++;
            }
        });
        self::assertContains('current_identity_missing', $planner->plan($archive, $patient->owner_user_id, false)->blockers);
        self::assertSame(1, $missingReads, 'Repeated missing projection identities are bounded within one plan.');
        DB::table('phr_native_record_identities')->insert((array) $identity);
        self::assertEquals($original, $planner->plan($archive, $patient->owner_user_id, false));
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
