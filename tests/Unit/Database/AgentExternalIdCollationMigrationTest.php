<?php

namespace Tests\Unit\Database;

use App\Models\PhrDocument;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * The collation migration itself cannot be exercised here: it returns early on
 * SQLite, which is the only backend the suite runs against. What can be pinned
 * is its table list, and that is where the defect actually recurs -- a new
 * agent-writable resource keyed on external_id that nobody remembers to add
 * would silently reintroduce the production/SQLite identity disagreement.
 */
final class AgentExternalIdCollationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_covers_every_agent_writable_table_keyed_on_external_id(): void
    {
        $expected = [];
        foreach (AgentClinicalResourceCatalog::writableIds() as $resource) {
            $definition = AgentClinicalResourceCatalog::definition($resource);
            $this->assertIsArray($definition);
            /** @var Model $model */
            $model = new $definition['model'];
            $expected[] = $model->getTable();
        }
        // documents.upload is an agent write path outside the clinical catalog,
        // and phr_documents carries the same composite identity index.
        $expected[] = (new PhrDocument)->getTable();

        $expected = array_values(array_filter(
            $expected,
            static fn (string $table): bool => Schema::hasColumn($table, 'external_id'),
        ));
        sort($expected);

        $covered = (new ReflectionClass($this->migration()))->getConstant('TABLES');
        $this->assertIsArray($covered);
        // A table created after that migration carries the same collation from its own creation.
        foreach ($this->laterMigrations() as $later) {
            $reflection = new ReflectionClass($later);
            $this->assertSame('utf8mb4_bin', $reflection->getConstant('EXTERNAL_ID_COLLATION'), 'A table created later must also compare external IDs bytewise.');
            $covered[] = $reflection->getConstant('TABLE');
        }
        sort($covered);

        $this->assertSame(
            $expected,
            $covered,
            'Every agent-writable table with an external_id column must carry the binary collation.',
        );
    }

    public function test_its_rollback_is_a_deliberate_no_op(): void
    {
        // Reversing to a case-insensitive collation could violate the unique
        // identity indexes once case-distinct external IDs exist, so down() must
        // stay empty rather than becoming "helpfully" symmetrical later.
        $down = (new ReflectionClass($this->migration()))->getMethod('down');
        $body = array_slice(
            file((string) $down->getFileName()),
            $down->getStartLine(),
            $down->getEndLine() - $down->getStartLine() - 1,
        );
        $statements = array_filter(
            array_map(trim(...), $body),
            static fn (string $line): bool => $line !== '' && $line !== '{' && $line !== '}'
                && ! str_starts_with($line, '//'),
        );

        $this->assertSame([], array_values($statements));
    }

    /** @return list<object> migrations that create a table keyed on external_id with the binary collation */
    private function laterMigrations(): array
    {
        return [require database_path('migrations/2026_11_01_000000_create_phr_medication_fills_table.php')];
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_22_120000_make_agent_external_id_binary.php');
    }
}
