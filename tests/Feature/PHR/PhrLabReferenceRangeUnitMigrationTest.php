<?php

namespace Tests\Feature\PHR;

use App\Models\PhrPatient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PhrLabReferenceRangeUnitMigrationTest extends TestCase
{
    private const string MIGRATION = 'migrations/2026_10_31_000002_drop_repeated_units_from_phr_lab_reference_range_text.php';

    /** [stored text, range_unit, text after the migration] */
    private const array CASES = [
        // The shapes production holds: the unit once more per bound.
        ['4.3 - 5.6 PERCENTPERCENTPERCENT', 'PERCENT', '4.3 - 5.6 PERCENT'],
        ['3.4 - 10.0 x10E9/Lx10E9/Lx10E9/L', 'x10E9/L', '3.4 - 10.0 x10E9/L'],
        ['<165 IU/mLIU/mL', 'IU/mL', '<165 IU/mL'],
        ['>59 mL/min/1.73m2mL/min/1.73m2', 'mL/min/1.73m2', '>59 mL/min/1.73m2'],
        // Unit stated once, or not at all.
        ['<0.1 x10E9/L', 'x10E9/L', '<0.1 x10E9/L'],
        ['13.6 - 17.5', 'g/dL', '13.6 - 17.5'],
        // UCUM's unitless "1": "<11" is a range, not a repeated unit.
        ['<11', '1', '<11'],
        // Only the row's own unit, byte for byte, whatever the collation says.
        ['4.3 - 5.6 PERCENTpercent', 'PERCENT', '4.3 - 5.6 PERCENTpercent'],
        ['4.3 - 5.6 PERCENTPERCENT', 'g/dL', '4.3 - 5.6 PERCENTPERCENT'],
        ['4.3 - 5.6 PERCENTPERCENT', null, '4.3 - 5.6 PERCENTPERCENT'],
    ];

    public function test_a_repeated_trailing_range_unit_is_stored_once_and_nothing_else_changes(): void
    {
        $patient = $this->patient();
        $stamp = '2026-08-15 04:58:59';
        $ids = [];
        foreach (self::CASES as $index => [$text, $unit]) {
            $ids[$index] = $this->insertLab($patient, $text, $unit, $stamp);
        }
        $trashedId = $this->insertLab($patient, '<165 IU/mLIU/mL', 'IU/mL', $stamp, deleted: true);

        $this->migration()->up();

        foreach (self::CASES as $index => [$stored, $unit, $expected]) {
            $this->assertSame($expected, $this->textOf($ids[$index]), "stored '{$stored}' with unit '{$unit}'");
        }
        $this->assertSame('<165 IU/mL', $this->textOf($trashedId));
        $this->assertSame([$stamp], DB::table('phr_lab_results')->distinct()->pluck('updated_at')->all());
    }

    public function test_it_is_idempotent_and_down_leaves_the_repaired_values(): void
    {
        $patient = $this->patient();
        $id = $this->insertLab($patient, '4.3 - 5.6 PERCENTPERCENTPERCENT', 'PERCENT', '2026-08-15 04:58:59');

        $this->migration()->up();
        $this->migration()->up();
        $this->assertSame('4.3 - 5.6 PERCENT', $this->textOf($id));

        $this->migration()->down();
        $this->assertSame('4.3 - 5.6 PERCENT', $this->textOf($id));
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }

    private function patient(): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $this->createUser()->id,
            'display_name' => 'Synthetic Migration Patient',
            'relationship' => 'self',
        ]);
    }

    private function insertLab(PhrPatient $patient, string $text, ?string $unit, string $stamp, bool $deleted = false): int
    {
        return DB::table('phr_lab_results')->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'import_source' => 'synthetic_ccda',
            'analyte' => 'Synthetic analyte',
            'unit' => $unit,
            'range_unit' => $unit,
            'reference_range_text' => $text,
            'review_status' => 'confirmed',
            'created_at' => $stamp,
            'updated_at' => $stamp,
            'deleted_at' => $deleted ? $stamp : null,
        ]);
    }

    private function textOf(int $id): ?string
    {
        $text = DB::table('phr_lab_results')->where('id', $id)->value('reference_range_text');

        return is_string($text) ? $text : null;
    }
}
