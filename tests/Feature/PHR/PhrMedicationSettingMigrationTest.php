<?php

namespace Tests\Feature\PHR;

use App\Models\PhrPatient;
use App\Support\PHR\PhrMedicationCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The migration that adds a medication's setting (#413) makes a home medication of the
 * rows that are one, and leaves every other row's setting unknown.
 */
final class PhrMedicationSettingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_rows_from_a_person_a_fill_or_a_standing_ccda_import_become_home_medications(): void
    {
        $migration = require database_path('migrations/2026_11_23_100000_add_medication_setting_to_phr_medications.php');
        $this->assertInstanceOf(Migration::class, $migration);
        $migration->down();
        $patient = PhrPatient::query()->create(['owner_user_id' => $this->createUser()->id, 'display_name' => 'Synthetic Patient', 'relationship' => 'self']);
        $rows = [
            'entered' => [null, null],
            'filled' => ['phr-fill', null],
            'ccda' => ['ccda', null],
            'ccda retracted' => ['ccda', '2026-11-20 10:00:00'],
            'fhir' => ['fhir', null],
            'agent' => ['synthetic-agent', null],
        ];
        foreach ($rows as $name => [$source, $retracted]) {
            DB::table('phr_medications')->insert([
                'patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'name' => $name, 'status' => 'active',
                'import_source' => $source, 'external_id' => $source === null ? null : 'synthetic-'.$name, 'retracted_at' => $retracted,
                'review_status' => 'pending_review', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migration->up();

        $categories = DB::table('phr_medications')->orderBy('id')->pluck('category', 'name')->all();
        $this->assertSame([
            'entered' => 'community',
            'filled' => 'community',
            'ccda' => 'community',
            'ccda retracted' => null,
            'fhir' => null,
            'agent' => null,
        ], $categories);
        // The same rule reads a native backup written before the column.
        foreach ($rows as $name => [$source, $retracted]) {
            $this->assertSame($categories[$name], PhrMedicationCategory::backfilled(['import_source' => $source, 'retracted_at' => $retracted]), $name);
        }
    }

    public function test_a_row_off_the_home_list_keeps_its_source_id_scoped_by_its_setting(): void
    {
        $this->assertSame('ccda-med-1', PhrMedicationCategory::scopedExternalId('ccda-med-1', 'community'));
        $this->assertSame('ccda-med-1', PhrMedicationCategory::scopedExternalId('ccda-med-1', null));
        $this->assertSame('discharge:ccda-med-1', PhrMedicationCategory::scopedExternalId('ccda-med-1', 'discharge'));
        $this->assertSame('discharge:ccda-med-1', PhrMedicationCategory::scopedExternalId('discharge:ccda-med-1', 'discharge'), 'scoping twice changes nothing');
        $long = str_repeat('x', 250);
        $this->assertSame('inpatient:sha256:'.hash('sha256', $long), PhrMedicationCategory::scopedExternalId($long, 'inpatient'));
        $this->assertSame('inpatient', PhrMedicationCategory::fromFhir([['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/medicationrequest-category', 'code' => 'inpatient']]]]));
        $this->assertNull(PhrMedicationCategory::fromFhir([['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/medication-statement-category', 'code' => 'patientspecified']]]]));
        $this->assertNull(PhrMedicationCategory::fromFhir([['coding' => [['system' => 'urn:other', 'code' => 'inpatient']]]]), 'a code of another system is not a setting');
    }
}
