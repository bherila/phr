<?php

namespace Tests\Feature\PHR;

use App\Models\PhrPatient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PhrMedicationFillNdc10BackfillMigrationTest extends TestCase
{
    private const string MIGRATION = 'migrations/2026_11_01_000002_backfill_ndc_10_on_phr_medication_fills.php';

    /** [captured, ndc_10 before, ndc_10 after, ndc_11 after] */
    private const array CASES = [
        'rule 1' => ['00299-5918-25', null, '0299-5918-25', '00299591825'],
        'rule 2, no dashes' => ['54868092502', null, '54868-925-02', '54868092502'],
        'rule 2 wins over rule 3' => ['60505-0134-00', null, '60505-134-00', '60505013400'],
        'rule 3' => ['58151-1557-07', null, '58151-1557-7', '58151155707'],
        'no padded zero' => ['58151-1557-77', null, null, null],
        'ten digits stay as they are' => ['6050501340', null, null, null],
        'already filled' => ['00299-5918-25', '0299-5918-25', '0299-5918-25', null],
    ];

    public function test_eleven_digit_fills_get_their_ten_digit_form_and_nothing_else_changes(): void
    {
        $patient = PhrPatient::query()->create(['owner_user_id' => $this->createUser()->id, 'display_name' => 'Synthetic Migration Patient', 'relationship' => 'self']);
        $stamp = '2026-08-15 04:58:59';
        $ids = [];
        foreach (self::CASES as $name => [$captured, $before]) {
            $ids[$name] = DB::table('phr_medication_fills')->insertGetId([
                'patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'ndc_as_captured' => $captured, 'ndc_10' => $before,
                'ndc_status' => 'ambiguous', 'filled_on' => '2026-08-01', 'quantity' => 30, 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
        }

        $this->migration()->up();
        $this->migration()->up();

        foreach (self::CASES as $name => [, , $ten, $eleven]) {
            $row = DB::table('phr_medication_fills')->where('id', $ids[$name])->first();
            $this->assertSame($ten, $row->ndc_10, $name);
            $this->assertSame($eleven, $row->ndc_11, $name);
            $this->assertSame('ambiguous', $row->ndc_status, $name);
            $this->assertSame($stamp, $row->updated_at, $name);
        }
        $this->migration()->down();
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }
}
