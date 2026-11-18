<?php

namespace Tests\Feature\Database;

use App\Services\PHR\NativeBackup\NativeRestoreException;
use App\Services\PHR\NativeBackup\PhrNativeRestoreRecordWriter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class PhrNativeRestoreRecordWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public static function keyedTables(): iterable
    {
        yield 'entities' => ['phr_ehi_entities', ['source_id' => 7, 'table_name' => 'SYNTHETIC', 'key_digest' => 'synthetic-key']];
        yield 'revisions' => ['phr_ehi_revisions', ['entity_id' => 7, 'content_digest' => 'synthetic-content']];
        yield 'occurrences' => ['phr_ehi_occurrences', ['member_id' => 7, 'line_number' => 1]];
        yield 'proposals' => ['phr_ehi_proposals', ['package_id' => 7, 'mapping' => 'synthetic', 'mapping_version' => 'v1', 'subject_key' => 'synthetic-subject']];
        yield 'proposal revisions' => ['phr_ehi_proposal_revisions', ['proposal_id' => 7, 'revision_id' => 1]];
        yield 'binary legacy tuple' => ['phr_ehi_entities', ['source_id' => 7, 'table_name' => "synthetic-\xff", 'key_digest' => 'synthetic-key']];
        foreach (['immunization_id', 'document_id', 'lab_result_id', 'office_visit_id'] as $target) {
            yield 'record links '.$target => ['phr_ehi_record_links', ['revision_id' => 7, $target => 9, 'link_kind' => 'projected']];
        }
    }

    #[DataProvider('keyedTables')]
    public function test_each_supported_key_maps_actual_rows_in_input_order(string $table, array $key): void
    {
        $this->createTable($table, $key);
        $rows = [];
        $last = array_key_last($key);
        foreach ([3, 1, 2] as $value) {
            $changed = is_int($key[$last]) ? $value : 'synthetic-'.$value;
            $rows[] = ['patient_id' => 1, ...array_replace($key, [$last => $changed]), 'marker' => 'synthetic-row-'.$value];
        }
        DB::statement('PRAGMA reverse_unordered_selects = ON');
        $ids = DB::transaction(fn (): array => (new PhrNativeRestoreRecordWriter)->insertPage(1, $table, $rows));
        self::assertCount(3, $ids);
        foreach ($ids as $position => $id) {
            self::assertSame($rows[$position]['marker'], DB::table($table)->where('id', $id)->value('marker'));
        }
    }

    public function test_page_batches_read_actual_nonconsecutive_ids_and_stay_tenant_scoped(): void
    {
        $table = 'phr_ehi_occurrences';
        $key = ['member_id' => 7, 'line_number' => 1];
        $this->createTable($table, $key);
        DB::table($table)->insert(['patient_id' => 2, 'member_id' => 8, 'line_number' => 1, 'marker' => 'other-patient']);
        // Trigger inserts make generated IDs nonconsecutive. A sequence inferred
        // from lastInsertId or the batch's size would assign identities wrongly.
        DB::unprepared('CREATE TRIGGER synthetic_id_gaps AFTER INSERT ON phr_ehi_occurrences WHEN NEW.marker != \'gap\' BEGIN INSERT INTO phr_ehi_occurrences (patient_id, member_id, line_number, marker) VALUES (NEW.patient_id, NEW.member_id, -NEW.line_number, \'gap\'); END');
        $rows = [];
        foreach (range(1, 401) as $line) {
            $rows[] = ['patient_id' => 1, 'member_id' => 7, 'line_number' => $line, 'marker' => 'synthetic-'.$line];
        }
        $inserts = 0;
        $readbacks = [];
        $measuring = true;
        DB::listen(static function (QueryExecuted $event) use (&$inserts, &$readbacks, &$measuring): void {
            if (! $measuring) {
                return;
            }
            if (str_starts_with($event->sql, 'insert into "phr_ehi_occurrences"')) {
                $inserts++;
            } elseif (str_starts_with($event->sql, 'select ') && str_contains($event->sql, 'from "phr_ehi_occurrences"')) {
                $readbacks[] = [$event->sql, $event->bindings];
            }
        });
        DB::statement('PRAGMA reverse_unordered_selects = ON');
        $ids = DB::transaction(fn (): array => (new PhrNativeRestoreRecordWriter)->insertPage(1, $table, $rows));
        $measuring = false;
        self::assertSame(3, $inserts);
        self::assertCount(3, $readbacks);
        foreach ($readbacks as [$sql, $bindings]) {
            self::assertStringContainsString('"patient_id" = ?', $sql);
            self::assertSame(1, $bindings[0]);
            self::assertLessThanOrEqual(401, count($bindings));
        }
        self::assertCount(401, $ids);
        self::assertSame(2, $ids[1] - $ids[0]);
        foreach ($ids as $position => $id) {
            self::assertSame($position + 1, DB::table($table)->where('id', $id)->value('line_number'));
            self::assertSame(1, DB::table($table)->where('id', $id)->value('patient_id'));
        }
        self::assertSame('other-patient', DB::table($table)->where('patient_id', 2)->value('marker'));
    }

    public function test_a_key_collision_rolls_back_every_insert_and_preserves_the_other_patient(): void
    {
        $table = 'phr_ehi_occurrences';
        $this->createTable($table, ['member_id' => 7, 'line_number' => 1]);
        DB::table($table)->insert(['patient_id' => 2, 'member_id' => 7, 'line_number' => 2, 'marker' => 'other-patient']);
        try {
            DB::transaction(fn (): array => (new PhrNativeRestoreRecordWriter)->insertPage(1, $table, [
                ['patient_id' => 1, 'member_id' => 7, 'line_number' => 1, 'marker' => 'synthetic-first'],
                ['patient_id' => 1, 'member_id' => 7, 'line_number' => 2, 'marker' => 'synthetic-collision'],
            ]));
            self::fail('A unique-key collision must refuse the whole graph.');
        } catch (QueryException) {
            self::assertSame(['other-patient'], DB::table($table)->pluck('marker')->all());
        }
    }

    public function test_nonuniform_columns_keep_database_defaults_and_empty_pages_do_nothing(): void
    {
        $this->createTable('phr_ehi_occurrences', ['member_id' => 7, 'line_number' => 1]);
        $writer = new PhrNativeRestoreRecordWriter;
        self::assertSame([], $writer->insertPage(1, 'phr_ehi_occurrences', []));
        $ids = DB::transaction(fn (): array => $writer->insertPage(1, 'phr_ehi_occurrences', [
            ['patient_id' => 1, 'member_id' => 7, 'line_number' => 1, 'marker' => 'supplied'],
            ['patient_id' => 1, 'member_id' => 7, 'line_number' => 2],
        ]));
        self::assertSame('supplied', DB::table('phr_ehi_occurrences')->where('id', $ids[0])->value('marker'));
        self::assertSame('default-marker', DB::table('phr_ehi_occurrences')->where('id', $ids[1])->value('marker'));
        $this->expectException(NativeRestoreException::class);
        DB::transaction(fn (): array => $writer->insertPage(1, 'phr_ehi_occurrences', [['patient_id' => 2, 'member_id' => 7, 'line_number' => 3]]));
    }

    public function test_nullable_or_mixed_link_keys_preserve_every_row_via_the_fallback(): void
    {
        Schema::create('phr_ehi_record_links', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('revision_id');
            $table->string('link_kind');
            foreach (['immunization_id', 'document_id', 'lab_result_id', 'office_visit_id'] as $target) {
                $table->unsignedBigInteger($target)->nullable();
                $table->unique(['revision_id', $target, 'link_kind']);
            }
        });
        $base = ['patient_id' => 1, 'revision_id' => 7, 'link_kind' => 'projected', 'immunization_id' => null,
            'document_id' => null, 'lab_result_id' => null, 'office_visit_id' => null];
        $rows = [$base, $base, array_replace($base, ['lab_result_id' => 11]), array_replace($base, ['document_id' => 12])];
        $ids = DB::transaction(fn (): array => (new PhrNativeRestoreRecordWriter)->insertPage(1, 'phr_ehi_record_links', $rows));
        self::assertCount(4, array_unique($ids));
        foreach ($ids as $position => $id) {
            self::assertSame($rows[$position]['lab_result_id'], DB::table('phr_ehi_record_links')->where('id', $id)->value('lab_result_id'));
            self::assertSame($rows[$position]['document_id'], DB::table('phr_ehi_record_links')->where('id', $id)->value('document_id'));
        }
    }

    private function createTable(string $table, array $key): void
    {
        Schema::create($table, static function (Blueprint $blueprint) use ($key): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('patient_id');
            foreach ($key as $column => $value) {
                if (is_int($value)) {
                    $blueprint->integer($column);
                } else {
                    $blueprint->string($column);
                }
            }
            $blueprint->string('marker')->default('default-marker');
            $blueprint->unique(array_keys($key));
        });
    }
}
