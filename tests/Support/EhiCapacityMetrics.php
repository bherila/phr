<?php

namespace Tests\Support;

use App\Services\PHR\Import\Ehi\EhiPreviewBudget;
use App\Services\PHR\NativeBackup\PhrNativeBackupCatalog;
use App\Services\PHR\ResourceByteSize;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** Aggregate-only synthetic telemetry. SQL text, bindings and source values never leave the fixture. */
final class EhiCapacityMetrics
{
    public array $stages = [];

    private int $queries = 0;

    private int $bindings = 0;

    private float $queryMilliseconds = 0;

    private float $lockingQueryMilliseconds = 0;

    private array $shapes = [];

    private array $transactions = [];

    private float $longestTransaction = 0;

    private int $transactionCount = 0;

    private ?string $activeStage = null;

    private int $activeStarted = 0;

    private int $lastActivePublished = 0;

    public function __construct(private readonly ?string $directory = null)
    {
        DB::listen(function (QueryExecuted $event): void {
            $this->queries++;
            $this->bindings = max($this->bindings, count($event->bindings));
            $this->queryMilliseconds += $event->time;
            if (str_contains(strtolower($event->sql), 'for update')) {
                $this->lockingQueryMilliseconds = max($this->lockingQueryMilliseconds, $event->time);
            }
            $shape = hash('sha256', preg_replace('/\s+/', ' ', $event->sql));
            $this->shapes[$shape] = ($this->shapes[$shape] ?? 0) + 1;
            $this->publishActive();
        });
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event): void {
            $this->transactions[$event->connection->transactionLevel()] = hrtime(true);
            $this->transactionCount++;
        });
        foreach ([TransactionCommitted::class, TransactionRolledBack::class] as $event) {
            Event::listen($event, function (TransactionCommitted|TransactionRolledBack $completed): void {
                foreach ($this->transactions as $level => $start) {
                    if ($level > $completed->connection->transactionLevel()) {
                        $this->longestTransaction = max($this->longestTransaction, (hrtime(true) - $start) / 1e9);
                        unset($this->transactions[$level]);
                    }
                }
            });
        }
    }

    public function stage(string $name, callable $operation, ?int $patient = null): mixed
    {
        $this->queries = $this->bindings = $this->transactionCount = 0;
        $this->queryMilliseconds = $this->lockingQueryMilliseconds = $this->longestTransaction = 0;
        $this->shapes = [];
        memory_reset_peak_usage();
        $start = hrtime(true);
        $this->activeStage = $name;
        $this->activeStarted = $start;
        $this->publishActive(true);
        $this->marker($name);
        try {
            return $operation();
        } finally {
            $this->publishActive(true);
            arsort($this->shapes);
            $this->stages[$name] = [
                'seconds' => round((hrtime(true) - $start) / 1e9, 6),
                'queries' => $this->queries, 'max_bindings' => $this->bindings,
                'query_seconds' => round($this->queryMilliseconds / 1000, 6),
                'max_locking_statement_seconds' => round($this->lockingQueryMilliseconds / 1000, 6),
                // Transaction lifetime is a conservative lock-holding window, not a server lock-wait measurement.
                'transactions' => $this->transactionCount, 'max_transaction_seconds' => round($this->longestTransaction, 6),
                'query_shape_counts' => array_slice($this->shapes, 0, 20, true),
                'peak_php_bytes' => memory_get_peak_usage(true),
            ];
            $this->activeStage = null;
            if ($patient !== null) {
                $this->stages[$name]['durable'] = $this->durable($patient);
            }
            if ($this->directory !== null) {
                file_put_contents($this->directory.'/qualification-progress.json', json_encode(['stages' => $this->stages], JSON_THROW_ON_ERROR));
                $this->writeActive(['stage' => null]);
            }
            $this->marker(null);
        }
    }

    public function durable(int $patient): array
    {
        $sizes = new ResourceByteSize;
        $tables = [];
        foreach (PhrNativeBackupCatalog::included() as $table => $definition) {
            $query = DB::table($table)->where($table === 'phr_patients' ? 'id' : $definition['patient_column'], $patient);
            $sum = $query->selectRaw('COUNT(*) AS n, COALESCE(SUM('.$sizes->row($table).'), 0) AS bytes')->first();
            $tables[$table] = ['rows' => (int) $sum->n, 'bytes' => (int) $sum->bytes];
        }

        $previewTables = array_intersect_key($tables, array_flip(EhiPreviewBudget::TABLES));

        return ['rows' => array_sum(array_column($tables, 'rows')), 'bytes' => array_sum(array_column($tables, 'bytes')), 'tables' => $tables,
            // Persisted lower bound only: admission also reserves transient work.
            'persisted_preview_work' => ['rows' => array_sum(array_column($previewTables, 'rows')), 'bytes' => array_sum(array_column($previewTables, 'bytes'))]];
    }

    /** At most one aggregate checkpoint per two seconds; no SQL or source values. */
    private function publishActive(bool $force = false): void
    {
        if ($this->directory === null || $this->activeStage === null) {
            return;
        }
        $now = hrtime(true);
        if (! $force && $now - $this->lastActivePublished < 2_000_000_000) {
            return;
        }
        $this->lastActivePublished = $now;
        $shapes = $this->shapes;
        arsort($shapes);
        $openSeconds = 0;
        foreach ($this->transactions as $started) {
            $openSeconds = max($openSeconds, ($now - $started) / 1e9);
        }
        $this->writeActive(['stage' => $this->activeStage, 'metrics_incomplete' => true,
            'seconds' => round(($now - $this->activeStarted) / 1e9, 6),
            'queries' => $this->queries, 'max_bindings' => $this->bindings,
            'query_seconds' => round($this->queryMilliseconds / 1000, 6),
            'max_locking_statement_seconds' => round($this->lockingQueryMilliseconds / 1000, 6),
            'transactions' => $this->transactionCount, 'max_transaction_seconds' => round($this->longestTransaction, 6),
            'max_open_transaction_seconds' => round($openSeconds, 6),
            'query_shape_counts' => array_slice($shapes, 0, 20, true), 'peak_php_bytes' => memory_get_peak_usage(true)]);
    }

    private function writeActive(array $value): void
    {
        $path = $this->directory.'/qualification-active.json';
        file_put_contents($path.'.tmp', json_encode($value, JSON_THROW_ON_ERROR));
        rename($path.'.tmp', $path);
    }

    private function marker(?string $stage): void
    {
        if ($this->directory !== null) {
            file_put_contents($this->directory.'/qualification-stage.json', json_encode(['stage' => $stage], JSON_THROW_ON_ERROR));
        }
    }
}
