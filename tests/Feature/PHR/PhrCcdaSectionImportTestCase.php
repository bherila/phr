<?php

namespace Tests\Feature\PHR;

use App\Models\PhrNegativeAssertion;
use App\Models\PhrPatient;
use App\Support\PHR\PhrReviewStatus;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ImportsCcdaFixtures;
use Tests\Support\PhrCcdaSyntheticDocument;
use Tests\TestCase;

/**
 * The contract every C-CDA section reader keeps, checked against one synthetic
 * fixture per section (tests/Fixtures/ccda):
 *
 * - each field comes from the structured entry, one test per field;
 * - a document without entries (PHR's own export) still imports its table;
 * - re-importing changes nothing;
 * - rows the narrative-table reader made of the same document are updated in
 *   place, not duplicated, and a confirmed row the source changed waits for
 *   review again;
 * - a row an earlier load keyed `<label>:<kind>:<key>` by the entry's id is
 *   adopted by that exact id only.
 */
abstract class PhrCcdaSectionImportTestCase extends TestCase
{
    use ImportsCcdaFixtures;

    /** @return class-string<Model> */
    abstract protected static function model(): string;

    abstract protected static function fixture(): string;

    /** The column that tells the fixture's rows apart. */
    abstract protected static function keyColumn(): string;

    /**
     * Row key (rowKey()) => the fixture entry that row comes from.
     *
     * @return array<string, string>
     */
    abstract protected static function entries(): array;

    /**
     * Column => (fixture entry => stored value).
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected static function expectedFields(): array;

    /**
     * Column => stored value, for the one record PHR's own export carries.
     *
     * @return array<string, mixed>
     */
    abstract protected static function exportedRecord(): array;

    /**
     * After the same document was imported without its entries, then with
     * them: the counts of the second import, the key value the narrative-table
     * reader gave one row, and that row's key value once corrected.
     *
     * @return array{created: int, updated: int, unchanged: int, narrative_key: string, corrected_key: string}
     */
    abstract protected static function narrativeAdoption(): array;

    /**
     * An earlier load's kind and the entry key (`root|extension`) it used for
     * one fixture entry, and that entry's external id.
     *
     * @return array{kind: string, key: string, case_variant: string, external_id: string}
     */
    abstract protected static function earlierLoad(): array;

    /**
     * Columns, beside the key column, an earlier-load row needs.
     *
     * @return array<string, mixed>
     */
    abstract protected static function earlierLoadColumns(): array;

    /** What tells one row from another in entries(): the key column, by default. */
    protected function rowKey(Model $row): string
    {
        return (string) $row->getAttribute(static::keyColumn());
    }

    /**
     * Statement => (column => stored value) for the section's negated entries.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function negations(): array
    {
        return [];
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function fields(): array
    {
        $cases = [];
        foreach (static::expectedFields() as $column => $expected) {
            $cases[$column] = [$column, $expected];
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('fields')]
    public function test_each_field_comes_from_its_entry(string $column, array $expected): void
    {
        $patient = $this->ccdaPatient();

        $result = $this->importCcda($this->ccdaFixture(static::fixture()), $patient);

        $this->assertSame(count(static::entries()) + count(static::negations()), $result->created, 'one record per entry; the table is not read a second time');
        $actual = [];
        foreach ($this->rows($patient) as $entry => $row) {
            $actual[$entry] = $this->stored($row, $column);
        }
        $this->assertSame($expected, $actual);
    }

    public function test_a_negated_entry_is_a_negative_assertion(): void
    {
        $patient = $this->ccdaPatient();

        $this->importCcda($this->ccdaFixture(static::fixture()), $patient);

        $expected = static::negations();
        $actual = [];
        foreach (PhrNegativeAssertion::query()->where('patient_id', $patient->id)->orderBy('id')->get() as $assertion) {
            $columns = array_keys($expected[$assertion->statement] ?? []);
            $actual[$assertion->statement] = array_combine($columns, array_map(fn (string $column): mixed => $this->stored($assertion, $column), $columns));
        }
        $this->assertSame($expected, $actual);
    }

    public function test_every_record_waits_for_review(): void
    {
        $patient = $this->ccdaPatient();

        $this->importCcda($this->ccdaFixture(static::fixture()), $patient);

        $this->assertSame([PhrReviewStatus::PENDING], static::model()::query()->where('patient_id', $patient->id)->distinct()->pluck('review_status')->all());
    }

    public function test_reimporting_the_same_document_changes_nothing(): void
    {
        $patient = $this->ccdaPatient();
        $this->importCcda($this->ccdaFixture(static::fixture()), $patient);

        $again = $this->importCcda($this->ccdaFixture(static::fixture()), $patient);

        $total = count(static::entries()) + count(static::negations());
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => $total], ['created' => $again->created, 'updated' => $again->updated, 'unchanged' => $again->unchanged]);
    }

    public function test_a_document_without_entries_still_imports_its_narrative_table(): void
    {
        $patient = $this->ccdaPatient();

        // PHR's own export carries each section's table but no entries.
        $this->importCcda(PhrCcdaSyntheticDocument::xml(), $patient);

        $row = static::model()::query()->where('patient_id', $patient->id)->sole();
        $expected = static::exportedRecord();
        $columns = array_keys($expected);
        $this->assertSame($expected, array_combine($columns, array_map(fn (string $column): mixed => $this->stored($row, $column), $columns)));
    }

    public function test_reimporting_a_document_first_imported_from_its_narrative_updates_the_same_rows(): void
    {
        $patient = $this->ccdaPatient();
        $xml = $this->ccdaFixture(static::fixture());
        $this->importCcda($this->withoutEntries($xml), $patient);
        $ids = $this->ids($patient);
        $expected = static::narrativeAdoption();
        $row = static::model()::query()->where('patient_id', $patient->id)->where(static::keyColumn(), $expected['narrative_key'])->sole();
        $row->forceFill(['review_status' => PhrReviewStatus::CONFIRMED])->save();

        $result = $this->importCcda($xml, $patient);

        $this->assertSame(
            ['created' => $expected['created'], 'updated' => $expected['updated'], 'unchanged' => $expected['unchanged']],
            ['created' => $result->created, 'updated' => $result->updated, 'unchanged' => $result->unchanged],
        );
        $this->assertSame($ids, array_slice($this->ids($patient), 0, count($ids)), 'the rows the narrative import made carry the corrected facts');
        $row->refresh();
        $this->assertSame($expected['corrected_key'], $row->getAttribute(static::keyColumn()));
        $this->assertSame(PhrReviewStatus::PENDING, $row->getAttribute('review_status'), 'a confirmed row the source changed waits for review again');

        $again = $this->importCcda($xml, $patient);
        $this->assertSame(['created' => 0, 'updated' => 0], ['created' => $again->created, 'updated' => $again->updated]);
    }

    public function test_a_row_an_earlier_load_keyed_by_the_entry_id_is_adopted_by_exact_id_only(): void
    {
        $patient = $this->ccdaPatient();
        $load = static::earlierLoad();
        $adopted = $this->earlierLoadRow($patient, "synthetic-load:{$load['kind']}:{$load['key']}");
        // External ids are case-sensitive in production; a collation must not
        // make a different id match.
        $other = $this->earlierLoadRow($patient, "synthetic-load:{$load['kind']}:{$load['case_variant']}");

        $result = $this->importCcda($this->ccdaFixture(static::fixture()), $patient);

        $this->assertSame(count(static::entries()) + count(static::negations()) - 1, $result->created);
        $row = static::model()::query()->findOrFail($adopted);
        $this->assertSame(['ccda', $load['external_id']], [$row->getAttribute('import_source'), $row->getAttribute('external_id')]);
        $untouched = static::model()::query()->findOrFail($other);
        $this->assertSame(['synthetic_load', PhrReviewStatus::CONFIRMED], [$untouched->getAttribute('import_source'), $untouched->getAttribute('review_status')]);
    }

    /** @return array<string, Model> fixture entry => row */
    private function rows(PhrPatient $patient): array
    {
        $rows = [];
        foreach (static::model()::query()->where('patient_id', $patient->id)->orderBy('id')->get() as $row) {
            $key = $this->rowKey($row);
            $rows[static::entries()[$key] ?? 'unexpected: '.$key] = $row;
        }

        return $rows;
    }

    /** A stored value as the expectations write it. */
    private function stored(Model $row, string $column): mixed
    {
        $value = $row->getAttribute($column);
        if ($value instanceof DateTimeInterface) {
            return $value->format(str_ends_with($column, '_at') ? 'Y-m-d H:i:s' : 'Y-m-d');
        }
        $cast = $row->getCasts()[$column] ?? null;
        if (is_string($cast) && str_starts_with($cast, 'decimal')) {
            return $this->ccdaDecimal($value);
        }

        return $value;
    }

    /** @return list<int> */
    private function ids(PhrPatient $patient): array
    {
        return static::model()::query()->where('patient_id', $patient->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function earlierLoadRow(PhrPatient $patient, string $externalId): int
    {
        $model = static::model();

        return DB::table((new $model)->getTable())->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'import_source' => 'synthetic_load',
            'external_id' => $externalId,
            ...static::earlierLoadColumns(),
            'review_status' => PhrReviewStatus::CONFIRMED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
