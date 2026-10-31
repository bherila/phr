<?php

namespace Tests\Feature\PHR;

use App\Models\PhrPatient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PhrLabReferenceRangeMigrationTest extends TestCase
{
    private const string MIGRATION = 'migrations/2026_10_31_000001_undouble_phr_lab_reference_range_text.php';

    /** stored value => value after the migration */
    private const array EXPECTED = [
        // The doubled forms production holds.
        'NEGNEG' => 'NEG',
        'Not DetectedNot Detected' => 'Not Detected',
        'NonreactiveNonreactive' => 'Nonreactive',
        'IndexIndex' => 'Index',
        'mIU/mLmIU/mL' => 'mIU/mL',
        // Numbers whose digits repeat are values, not repetitions.
        '11' => '11',
        '2020' => '2020',
        // Halves that differ only by case are not the same string, whatever the
        // column's collation says.
        'Not DetectedNot detected' => 'Not DetectedNot detected',
        // Ordinary ranges and near misses.
        'NEG' => 'NEG',
        'NEG NEG' => 'NEG NEG',
        '13.6 - 17.5' => '13.6 - 17.5',
        '<200' => '<200',
        'Negative' => 'Negative',
        '' => '',
    ];

    public function test_a_doubled_range_text_is_stored_once_and_nothing_else_changes(): void
    {
        $patient = $this->patient();
        $stamp = '2026-08-15 04:58:59';
        $ids = [];
        foreach (array_keys(self::EXPECTED) as $text) {
            $ids[(string) $text] = $this->insertLab($patient, (string) $text, $stamp);
        }
        $nullId = $this->insertLab($patient, null, $stamp);
        $trashedId = $this->insertLab($patient, 'NEGNEG', $stamp, deleted: true);

        $this->migration()->up();

        foreach (self::EXPECTED as $stored => $expected) {
            $this->assertSame($expected, $this->textOf($ids[(string) $stored]), "stored '{$stored}'");
        }
        $this->assertNull($this->textOf($nullId));
        // Withdrawn rows are still restorable and exported, so they are repaired too.
        $this->assertSame('NEG', $this->textOf($trashedId));
        // The unit-only row keeps its unit where it was.
        $this->assertSame('mIU/mL', DB::table('phr_lab_results')->where('id', $ids['mIU/mLmIU/mL'])->value('range_unit'));
        // Undoing a load error is not an edit: a native backup taken before the
        // migration must still recognise these rows, and its hash covers updated_at.
        $this->assertSame([$stamp], DB::table('phr_lab_results')->distinct()->pluck('updated_at')->all());
    }

    public function test_it_is_idempotent_and_down_leaves_the_repaired_values(): void
    {
        $patient = $this->patient();
        $id = $this->insertLab($patient, 'Not DetectedNot Detected', '2026-08-15 04:58:59');
        // Four copies did not come from the load, and halving them would let a
        // second run halve them again.
        $four = $this->insertLab($patient, 'NEGNEGNEGNEG', '2026-08-15 04:58:59');

        $this->migration()->up();
        $this->assertSame(['Not Detected', 'NEGNEGNEGNEG'], [$this->textOf($id), $this->textOf($four)]);

        $this->migration()->up();
        $this->assertSame(['Not Detected', 'NEGNEGNEGNEG'], [$this->textOf($id), $this->textOf($four)]);

        $this->migration()->down();
        $this->assertSame('Not Detected', $this->textOf($id));
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

    private function insertLab(PhrPatient $patient, ?string $text, string $stamp, bool $deleted = false): int
    {
        return DB::table('phr_lab_results')->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'import_source' => 'synthetic_ccda',
            'analyte' => 'Synthetic analyte',
            'unit' => $text === 'mIU/mLmIU/mL' ? 'mIU/mL' : null,
            'range_unit' => $text === 'mIU/mLmIU/mL' ? 'mIU/mL' : null,
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
