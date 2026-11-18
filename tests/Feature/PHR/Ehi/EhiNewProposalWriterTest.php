<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiProposal;
use App\Models\PhrEhiProposalRevision;
use App\Models\PhrEhiRevision;
use App\Services\PHR\Import\Ehi\EhiNewProposalWriter;
use App\Services\PHR\Import\Ehi\EhiProjectionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonSerializable;
use LogicException;
use Tests\Support\EhiProjectionFixtures;
use Tests\TestCase;

final class EhiNewProposalWriterTest extends TestCase
{
    use EhiProjectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhiProjection();
    }

    protected function tearDown(): void
    {
        $this->tearDownEhiProjection();
        parent::tearDown();
    }

    public function test_reads_actual_nonconsecutive_ids_and_preserves_casts_defaults_and_revision_order(): void
    {
        [$patientId, $packageId, $revisions] = $this->fixture();
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Trigger-created rows force gaps; reversed reads prohibit positional ID mapping.
            DB::unprepared("CREATE TRIGGER synthetic_proposal_gaps AFTER INSERT ON phr_ehi_proposals WHEN NEW.mapping != 'synthetic-gap' BEGIN INSERT INTO phr_ehi_proposals (patient_id, package_id, mapping, mapping_version, subject_key, revision_digest, kind, preview_digest) VALUES (NEW.patient_id, NEW.package_id, 'synthetic-gap', NEW.mapping_version, NEW.subject_key, NEW.revision_digest, NEW.kind, NEW.preview_digest); END");
            DB::statement('PRAGMA reverse_unordered_selects = ON');
        } else {
            // PostgreSQL sequences can likewise generate nonconsecutive IDs.
            // ALTER SEQUENCE rolls back with the test's enclosing transaction.
            DB::statement('ALTER SEQUENCE phr_ehi_proposals_id_seq INCREMENT BY 2');
        }
        $rows = [$this->row('a', array_reverse($revisions)), $this->row('b', [$revisions[0]])];
        DB::transaction(fn () => (new EhiNewProposalWriter)->insert($patientId, $packageId, 9, $rows));
        $proposals = PhrEhiProposal::query()->where('mapping', 'synthetic-writer')->orderBy('id')->get();
        self::assertCount(2, $proposals);
        self::assertSame(2, $proposals[1]->id - $proposals[0]->id);
        foreach ($proposals as $index => $proposal) {
            self::assertSame($rows[$index]['attributes']['subject_key'], $proposal->subject_key);
            self::assertSame($rows[$index]['attributes']['proposed_json'], $proposal->proposed_json);
            self::assertSame([17, 23], $proposal->candidate_ids_json);
            self::assertSame(PhrEhiProposal::STATUS_PENDING, $proposal->status);
            self::assertSame(9, $proposal->preview_generation);
            self::assertNull($proposal->target_id);
            self::assertNotNull($proposal->created_at);
            self::assertNotNull($proposal->updated_at);
            self::assertSame($rows[$index]['revisions'], PhrEhiProposalRevision::idsFor($proposal));
            self::assertSame($patientId, $proposal->patient_id);
            self::assertSame($packageId, $proposal->package_id);
        }
    }

    public function test_streams_large_dependency_fanout_without_deleting_new_proposals_links(): void
    {
        [$patientId, $packageId, $revisions] = $this->fixture();
        $base = PhrEhiRevision::query()->findOrFail($revisions[0])->getAttributes();
        unset($base['id']);
        $added = [];
        foreach (range(1, 501) as $number) {
            $added[] = [...$base, 'content_digest' => hash('sha256', 'synthetic-revision-'.$number)];
        }
        DB::table('phr_ehi_revisions')->insert($added);
        $ids = PhrEhiRevision::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $inserts = [];
        $deletes = 0;
        $measuring = true;
        DB::listen(static function (QueryExecuted $query) use (&$inserts, &$deletes, &$measuring): void {
            if (! $measuring) {
                return;
            }
            if (str_starts_with($query->sql, 'insert into "phr_ehi_proposal_revisions"')) {
                $inserts[] = count($query->bindings) / 6;
            }
            if (str_starts_with($query->sql, 'delete from "phr_ehi_proposal_revisions"')) {
                $deletes++;
            }
        });
        DB::transaction(fn () => (new EhiNewProposalWriter)->insert($patientId, $packageId, 2, [$this->row('fanout', $ids)]));
        $measuring = false;
        self::assertSame([200, 200, count($ids) - 400], $inserts);
        self::assertSame(0, $deletes);
        self::assertSame($ids, PhrEhiProposalRevision::idsFor(PhrEhiProposal::query()->sole()));
        self::assertSame(range(0, count($ids) - 1), DB::table('phr_ehi_proposal_revisions')->orderBy('position')->pluck('position')->all());
    }

    public function test_unique_collision_rolls_back_new_rows_and_preserves_original_proposal(): void
    {
        [$patientId, $packageId, $revisions] = $this->fixture();
        $writer = new EhiNewProposalWriter;
        DB::transaction(fn () => $writer->insert($patientId, $packageId, 1, [$this->row('original', $revisions)]));
        $original = PhrEhiProposal::query()->sole()->getAttributes();
        try {
            DB::transaction(fn () => $writer->insert($patientId, $packageId, 2, [$this->row('new', $revisions), $this->row('original', [])]));
            self::fail('A duplicate unique tuple must fail.');
        } catch (QueryException) {
            self::assertSame($original, PhrEhiProposal::query()->sole()->getAttributes());
            self::assertSame($revisions, PhrEhiProposalRevision::idsFor(PhrEhiProposal::query()->sole()));
        }
    }

    public function test_large_input_bounds_inserts_and_scopes_each_readback_to_the_unique_tuple(): void
    {
        [$patientId, $packageId] = $this->fixture();
        $rows = array_map(fn (int $number): array => $this->row('bounded-'.$number, []), range(1, 401));
        $inserts = [];
        $readbacks = [];
        $measuring = true;
        DB::listen(static function (QueryExecuted $query) use (&$inserts, &$readbacks, &$measuring): void {
            if (! $measuring) {
                return;
            }
            if (str_starts_with($query->sql, 'insert into "phr_ehi_proposals"')) {
                $inserts[] = count($query->bindings) / 14;
            } elseif (str_starts_with($query->sql, 'select ') && str_contains($query->sql, 'from "phr_ehi_proposals"')) {
                $readbacks[] = $query;
            }
        });
        DB::transaction(fn () => (new EhiNewProposalWriter)->insert($patientId, $packageId, 4, $rows));
        $measuring = false;
        self::assertSame([200, 200, 1], $inserts);
        self::assertCount(3, $readbacks);
        foreach ($readbacks as $query) {
            self::assertStringContainsString('"patient_id" = ?', $query->sql);
            self::assertStringContainsString('"package_id" = ?', $query->sql);
            self::assertStringContainsString('"mapping" = ?', $query->sql);
            self::assertStringContainsString('"mapping_version" = ?', $query->sql);
            self::assertSame([$patientId, $packageId, 'synthetic-writer', 'v1'], array_slice($query->bindings, 0, 4));
            self::assertLessThanOrEqual(204, count($query->bindings));
        }
        self::assertSame(401, PhrEhiProposal::query()->count());
    }

    public function test_different_mapping_or_attribute_shapes_preserve_individual_defaults(): void
    {
        [$patientId, $packageId] = $this->fixture();
        $rows = [$this->row('one', []), $this->row('two', [])];
        $rows[1]['attributes']['mapping'] = 'synthetic-other';
        $rows[1]['attributes']['target_id'] = 42;
        DB::transaction(fn () => (new EhiNewProposalWriter)->insert($patientId, $packageId, 1, $rows));
        $proposals = PhrEhiProposal::query()->orderBy('id')->get();
        self::assertNull($proposals[0]->target_id);
        self::assertSame(42, $proposals[1]->target_id);
        self::assertSame(['synthetic-writer', 'synthetic-other'], $proposals->pluck('mapping')->all());
    }

    public function test_short_or_space_ended_subject_keys_keep_individual_inserts(): void
    {
        [$patientId, $packageId] = $this->fixture();
        $rows = [$this->row('one', []), $this->row('two', [])];
        $rows[0]['attributes']['subject_key'] = 'short';
        $rows[1]['attributes']['subject_key'] = 'short with space ';
        $inserts = [];
        $measuring = true;
        DB::listen(static function (QueryExecuted $query) use (&$inserts, &$measuring): void {
            if ($measuring && str_starts_with($query->sql, 'insert into "phr_ehi_proposals"')) {
                $inserts[] = count($query->bindings);
            }
        });
        DB::transaction(fn () => (new EhiNewProposalWriter)->insert($patientId, $packageId, 1, $rows));
        $measuring = false;
        self::assertCount(2, $inserts);
        self::assertSame($inserts[0], $inserts[1]);
        $subjects = PhrEhiProposal::query()->orderBy('id')->pluck('subject_key')->all();
        if (DB::connection()->getDriverName() === 'pgsql') {
            // PostgreSQL CHAR pads short keys; creation must still succeed via
            // actual generated IDs rather than a keyed batch readback.
            self::assertSame(['short', 'short with space'], array_map('rtrim', $subjects));
        } else {
            self::assertSame(['short', 'short with space '], $subjects);
        }
    }

    public function test_model_observers_retain_per_row_create_and_dependency_order(): void
    {
        [$patientId, $packageId, $revisions] = $this->fixture();
        $dispatcher = PhrEhiProposal::getEventDispatcher();
        PhrEhiProposal::setEventDispatcher(clone $dispatcher);
        $seen = [];
        try {
            PhrEhiProposal::creating(static function (PhrEhiProposal $proposal) use (&$seen): void {
                $seen[] = [PhrEhiProposal::query()->count(), PhrEhiProposalRevision::query()->count()];
                $proposal->kind = PhrEhiProposal::KIND_RETAIN;
            });
            DB::transaction(fn () => (new EhiNewProposalWriter)->insert($patientId, $packageId, 1, [$this->row('one', $revisions), $this->row('two', [])]));
            self::assertSame([[0, 0], [1, count($revisions)]], $seen);
            self::assertSame([PhrEhiProposal::KIND_RETAIN], PhrEhiProposal::query()->distinct()->pluck('kind')->all());
        } finally {
            PhrEhiProposal::setEventDispatcher($dispatcher);
        }
    }

    public function test_mutable_json_values_keep_original_fill_and_insert_interleaving(): void
    {
        [$patientId, $packageId] = $this->fixture();
        $value = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return PhrEhiProposal::query()->count();
            }
        };
        $rows = [$this->row('one', []), $this->row('two', [])];
        foreach ($rows as &$row) {
            $row['attributes']['proposed_json'] = ['existing_proposals' => $value];
        }
        unset($row);
        DB::transaction(fn () => (new EhiNewProposalWriter)->insert($patientId, $packageId, 1, $rows));
        self::assertSame([['existing_proposals' => 0], ['existing_proposals' => 1]], PhrEhiProposal::query()->orderBy('id')->get()->pluck('proposed_json')->all());
    }

    public function test_empty_is_noop_and_nonempty_requires_a_transaction(): void
    {
        $writer = new EhiNewProposalWriter;
        $writer->insert(1, 1, 1, []);
        self::assertSame(0, PhrEhiProposal::query()->count());
        // The fixture base owns an outer test transaction; close it to exercise
        // the helper's production guard without executing any insert.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $this->expectException(LogicException::class);
        $writer->insert(1, 1, 1, [$this->row('guard', [])]);
    }

    /** @return array{int, int, list<int>} */
    private function fixture(): array
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        $package = $this->boundPackage($this->itemPackage($this->standardItems()), $patient, $owner);
        app(EhiProjectionService::class)->project($package, $owner->id);

        return [$patient->id, $package->id, PhrEhiRevision::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all()];
    }

    /** @param list<int> $revisions
     * @return array{attributes: array<string, mixed>, revisions: list<int>}
     */
    private function row(string $subject, array $revisions): array
    {
        return ['attributes' => [
            'mapping' => 'synthetic-writer', 'mapping_version' => 'v1',
            'subject_key' => hash('sha256', $subject), 'revision_digest' => hash('sha256', 'synthetic-revision'),
            'kind' => PhrEhiProposal::KIND_CREATE, 'preview_digest' => hash('sha256', 'synthetic-preview'),
            'proposed_json' => ['synthetic' => '雪/é', 'nested' => ['answer' => 42]],
            'candidate_ids_json' => [17, 23],
        ], 'revisions' => $revisions];
    }
}
