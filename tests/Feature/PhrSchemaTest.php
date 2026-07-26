<?php

namespace Tests\Feature;

use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrPatientVital;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhrSchemaTest extends TestCase
{
    public function test_phr_schema_supports_patients_access_and_extended_records(): void
    {
        $owner = $this->createUser();
        $sharedUser = $this->createUser();
        $otherUser = $this->createUser();

        $this->assertTrue(Schema::hasTable('phr_patients'));
        $this->assertTrue(Schema::hasTable('phr_patient_user_access'));
        $this->assertTrue(Schema::hasColumn('phr_lab_results', 'patient_id'));
        $this->assertTrue(Schema::hasColumn('phr_lab_results', 'value_numeric'));
        $this->assertTrue(Schema::hasColumn('phr_lab_results', 'created_at'));
        $this->assertTrue(Schema::hasColumn('phr_patient_vitals', 'observed_at'));
        $this->assertTrue(Schema::hasColumn('phr_patient_vitals', 'value_numeric_secondary'));
        $this->assertTrue(Schema::hasIndex('phr_patient_user_access', 'phr_patient_access_patient_user_unique'));
        $this->assertTrue(Schema::hasTable('phr_portal_messages'));
        $this->assertTrue(Schema::hasColumn('phr_portal_messages', 'source_refs'));
        $this->assertTrue(Schema::hasIndex('phr_portal_messages', 'phr_pm_imp_uid'));
        $this->assertTrue(Schema::hasTable('phr_negative_assertions'));
        $this->assertTrue(Schema::hasColumn('phr_negative_assertions', 'statement'));
        $this->assertTrue(Schema::hasIndex('phr_negative_assertions', 'phr_na_imp_uid'));

        $patient = PhrPatient::create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Primary profile',
            'relationship' => 'self',
        ]);

        PhrPatientUserAccess::create([
            'patient_id' => $patient->id,
            'user_id' => $sharedUser->id,
            'access_level' => PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        $labResult = PhrLabResult::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'test_name' => 'Metabolic panel',
            'analyte' => 'Glucose',
            'value' => '95',
            'value_numeric' => '95',
            'unit' => 'mg/dL',
            'range_min' => '70',
            'range_max' => '99',
        ]);

        $vital = PhrPatientVital::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'vital_name' => 'Blood Pressure',
            'vital_date' => '2026-05-16',
            'observed_at' => '2026-05-16 09:30:00',
            'vital_value' => '120/80',
            'value_numeric' => '120',
            'value_numeric_secondary' => '80',
            'unit' => 'mmHg',
            'secondary_unit' => 'mmHg',
        ]);

        $this->assertSame([$patient->id], PhrPatient::accessibleBy($owner->id)->pluck('id')->all());
        $this->assertSame([$patient->id], PhrPatient::accessibleBy($sharedUser->id)->pluck('id')->all());
        $this->assertSame([], PhrPatient::accessibleBy($otherUser->id)->pluck('id')->all());
        $this->assertTrue($owner->ownedPhrPatients()->whereKey($patient->id)->exists());
        $this->assertTrue($sharedUser->accessiblePhrPatients()->whereKey($patient->id)->exists());
        $this->assertSame('95.0000000000', $labResult->fresh()->value_numeric);
        $this->assertSame('80.0000000000', $vital->fresh()->value_numeric_secondary);
        $this->assertSame($patient->id, $patient->labResults()->sole()->patient_id);
        $this->assertSame($patient->id, $patient->vitals()->sole()->patient_id);
    }

    public function test_legacy_phr_tables_are_normalized_to_user_one_patient(): void
    {
        $this->createUser(['id' => 1]);

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('phr_lab_results');
        Schema::dropIfExists('phr_patient_vitals');
        Schema::dropIfExists('phr_patient_user_access');
        Schema::dropIfExists('phr_patients');
        Schema::enableForeignKeyConstraints();

        $baselineMigration = include database_path('migrations/2026_05_17_042848_create_missing_phr_tables_if_needed.php');
        $baselineMigration->up();

        DB::table('phr_lab_results')->insert([
            'id' => 10,
            'user_id' => 'legacy-auth-uuid',
            'test_name' => 'CBC',
            'collection_datetime' => '2026-05-16 08:00:00',
            'result_datetime' => '2026-05-16 12:00:00',
            'analyte' => 'WBC',
            'value' => '5.5',
            'unit' => '10^3/uL',
            'range_min' => '4.0',
            'range_max' => '11.0',
        ]);

        DB::table('phr_patient_vitals')->insert([
            'id' => 20,
            'user_id' => 'legacy-auth-uuid',
            'vital_name' => 'Blood Pressure',
            'vital_date' => '2026-05-16',
            'vital_value' => '120/80',
        ]);

        $normalizeMigration = include database_path('migrations/2026_05_17_042849_normalize_phr_patient_schema.php');
        $normalizeMigration->up();

        $patient = PhrPatient::where('owner_user_id', 1)->where('display_name', 'Legacy PHR Patient')->sole();
        $labResult = PhrLabResult::findOrFail(10);
        $vital = PhrPatientVital::findOrFail(20);

        $this->assertSame(1, $labResult->user_id);
        $this->assertSame($patient->id, $labResult->patient_id);
        $this->assertSame('5.5000000000', $labResult->value_numeric);
        $this->assertSame('4.0000000000', $labResult->range_min);
        $this->assertSame(1, $vital->user_id);
        $this->assertSame($patient->id, $vital->patient_id);
        $this->assertSame('120.0000000000', $vital->value_numeric);
        $this->assertSame('80.0000000000', $vital->value_numeric_secondary);
        $this->assertTrue(Schema::hasColumn('phr_lab_results', 'created_at'));
        $this->assertDatabaseHas('phr_patient_user_access', [
            'patient_id' => $patient->id,
            'user_id' => 1,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
        ]);
    }
}
