<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Mail\GenAiJobCompleteMail;
use App\GenAiProcessor\Mail\GenAiJobDeferredMail;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Services\PhrExternalEnqueueUnauthorized;
use App\GenAiProcessor\Services\PhrExternalGenAiRequestService;
use App\GenAiProcessor\Services\PhrGenAiExecutionModeService;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Models\UserAiConfiguration;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

use function App\Support\Logging\safelog_test_error_log_should_throw;

require_once __DIR__.'/../../../Unit/Support/Logging/SafeLogErrorLogStub.php';

/**
 * Issue #154: an optional diagnostic must not change the outcome of the
 * operation it describes - not the job status, not the retry charge, not a
 * mail, not the remaining iterations of a recovery loop.
 *
 * `config/logging.php` runs the stack with `ignore_exceptions = false`, so a
 * broken log destination makes `Log::*` throw. Every test here breaks it the
 * same way the real failure does - a `Log` write that throws - and, where it
 * matters, also breaks SafeLog's own `error_log()` fallback, then drives the
 * real call path and asserts the business outcome is exactly what a healthy
 * logger produces.
 *
 * These run on SQLite `:memory:`, one connection, one process. They pin
 * control flow around the diagnostics; they say nothing about InnoDB
 * locking under real contention.
 */
final class GenAiDiagnosticLoggingResilienceTest extends TestCase
{
    use RefreshDatabase;

    /** Stands in for anything a log line must never carry. */
    private const CANARY = 'SYNTHETIC-CANARY-LOG-LEAK-7Q3';

    private bool $primaryLogThrows = false;

    /** @var list<MessageLogged> */
    private array $logged = [];

    private ?string $fallbackFile = null;

    private bool $failProposalCounts = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Bus::fake();
        Mail::fake();

        // Every Log write is recorded, and - once a test breaks the primary
        // sink - throws from inside Log::*, exactly where a failing Monolog
        // handler throws with ignore_exceptions = false.
        Log::listen(function (MessageLogged $entry): void {
            $this->logged[] = $entry;
            if ($this->primaryLogThrows) {
                throw new RuntimeException('The log destination is unavailable.');
            }
        });
    }

    protected function tearDown(): void
    {
        safelog_test_error_log_should_throw(false);
        if (is_string($this->fallbackFile) && file_exists($this->fallbackFile)) {
            unlink($this->fallbackFile);
        }
        parent::tearDown();
    }

    /** @return array<string, array{bool}> */
    public static function brokenSinks(): array
    {
        return [
            'primary log throws' => [false],
            'primary log and error_log fallback both throw' => [true],
        ];
    }

    public function test_stale_dispatches_return_cleanly_when_both_log_sinks_throw(): void
    {
        [, $job] = $this->apiJob();
        $job->forceFill(['status' => 'parsed'])->save();
        $before = $this->jobRow($job->id);
        $this->breakLogging(fallbackThrows: true);

        // Returning at all is the assertion: a dispatch that has nothing to
        // do must not become a failed queue job ($tries = 1).
        (new ParseImportJob(999_999))->handle();
        (new ParseImportJob($job->id))->handle();

        $this->assertSame($before, $this->jobRow($job->id));
    }

    #[DataProvider('brokenSinks')]
    public function test_quota_deferral_is_identical_with_a_throwing_logger(bool $fallbackThrows): void
    {
        config()->set('genai.daily_request_limit', 0);
        $this->freezeTime();
        $this->fakeProvider();

        [, $healthy] = $this->apiJob();
        (new ParseImportJob($healthy->id))->handle();
        $baseline = $this->outcome($healthy->id);
        $this->assertSame('queued_tomorrow', $baseline['status'], 'Fixture precondition: the quota must defer the job.');

        [, $job] = $this->apiJob();
        $this->breakLogging($fallbackThrows);
        (new ParseImportJob($job->id))->handle();

        // Same status, same scheduled release, no retry charged, no error.
        $this->assertSame($baseline, $this->outcome($job->id));
        Mail::assertSent(GenAiJobDeferredMail::class, 2);
        Http::assertNothingSent();
        if (! $fallbackThrows) {
            $this->assertStringContainsString('ParseImportJob: quota exhausted, deferred', $this->fallback());
            $this->assertStringNotContainsString('ParseImportJob: unexpected error', $this->fallback());
        }
    }

    public function test_deferred_mail_failure_with_a_throwing_logger_keeps_the_deferral(): void
    {
        config()->set('genai.daily_request_limit', 0);
        $this->freezeTime();
        [, $job] = $this->apiJob();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('mail transport down: '.self::CANARY));
        $this->breakLogging(fallbackThrows: false);

        (new ParseImportJob($job->id))->handle();

        $outcome = $this->outcome($job->id);
        $this->assertSame('queued_tomorrow', $outcome['status']);
        $this->assertSame(0, $outcome['retry_count']);
        $this->assertNull($outcome['error_message']);
        $this->assertStringContainsString('Failed to send deferred mail', $this->fallback());
        $this->assertStringNotContainsString(self::CANARY, $this->fallback());
        $this->assertStringNotContainsString('ParseImportJob: unexpected error', $this->fallback());
    }

    #[DataProvider('brokenSinks')]
    public function test_completed_api_import_is_not_failed_or_charged_when_the_logger_throws(bool $fallbackThrows): void
    {
        $this->fakeProvider();
        [, $job] = $this->apiJob();
        $this->breakLogging($fallbackThrows);

        (new ParseImportJob($job->id))->handle();

        $job->refresh();
        $this->assertSame('parsed', $job->status);
        $this->assertNotNull($job->parsed_at);
        $this->assertNull($job->error_message);
        $this->assertSame(0, (int) $job->retry_count);
        $this->assertSame(1, $job->results()->count());
        Mail::assertSent(GenAiJobCompleteMail::class, 1);
        if (! $fallbackThrows) {
            $this->assertStringContainsString('ParseImportJob: success', $this->fallback());
            $this->assertStringContainsString('"created_count":1', $this->fallback());
            $this->assertStringNotContainsString('ParseImportJob: unexpected error', $this->fallback());
        }
    }

    public function test_success_diagnostic_context_cannot_fail_a_completed_api_import(): void
    {
        $this->fakeProvider();
        [, $job] = $this->apiJob();
        // Any count of the job's proposals fails while the job runs. Only the
        // diagnostic would ever ask for one after the proposals are committed.
        $this->failProposalCounts();

        (new ParseImportJob($job->id))->handle();
        $this->failProposalCounts = false;

        $job->refresh();
        $this->assertSame('parsed', $job->status);
        $this->assertSame(0, (int) $job->retry_count);
        $this->assertSame(1, $job->results()->count());
        Mail::assertSent(GenAiJobCompleteMail::class, 1);
        $this->assertNotContains('ParseImportJob: unexpected error', $this->loggedMessages());
        $this->assertSame(
            ['job_id' => $job->id, 'created_count' => 1],
            $this->loggedContext('ParseImportJob: success'),
        );
    }

    #[DataProvider('brokenSinks')]
    public function test_unexpected_error_is_still_recorded_when_the_logger_throws(bool $fallbackThrows): void
    {
        // A document import must be an object; a bare list makes the proposal
        // application throw an UnexpectedValueException - the generic catch.
        $this->fakeProvider(modelText: '["'.self::CANARY.'"]');
        [, $job] = $this->apiJob();
        $this->breakLogging($fallbackThrows);

        (new ParseImportJob($job->id))->handle();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('An unexpected import error occurred.', $job->error_message);
        $this->assertSame(1, (int) $job->retry_count, 'The intended failure charges exactly one retry.');
        $this->assertSame(0, $job->results()->count());
        Mail::assertNothingSent();
        if (! $fallbackThrows) {
            $fallback = $this->fallback();
            $this->assertStringContainsString('ParseImportJob: unexpected error', $fallback);
            $this->assertStringContainsString('UnexpectedValueException', $fallback);
            $this->assertStringNotContainsString(self::CANARY, $fallback);
            $this->assertStringNotContainsString('must be an object', $fallback);
        }
    }

    public function test_superseded_api_handoff_returns_cleanly_when_both_log_sinks_throw(): void
    {
        [$user, $job] = $this->apiJob();
        // The user switches to external processing while the API request is in
        // flight: the mode change bumps the generation and dispatches its own
        // successor, so this run's handoff is stale.
        $this->fakeProvider(during: function () use ($user): void {
            app(PhrGenAiExecutionModeService::class)->update($user, GenAiImportJob::EXECUTION_EXTERNAL);
        });
        $this->breakLogging(fallbackThrows: true);

        (new ParseImportJob($job->id))->handle();

        $job->refresh();
        $this->assertSame(GenAiImportJob::EXECUTION_EXTERNAL, $job->execution_mode);
        $this->assertSame(1, (int) $job->mcp_generation);
        $this->assertSame('pending', $job->status, 'The successor owns the row; the stale run must leave it alone.');
        $this->assertNull($job->error_message);
        $this->assertSame(0, (int) $job->retry_count);
        $this->assertNull($job->raw_response);
        Bus::assertDispatched(ParseImportJob::class, 1);
    }

    public function test_a_failing_diagnostic_read_does_not_recover_a_successful_reused_enqueue(): void
    {
        [, $job, $requestId] = $this->linkedExternalJob();
        $before = $this->jobRow($job->id);
        $request = DB::table('genai_mcp_requests')->where('id', $requestId)->first();
        $failed = $this->failRequestReadsIssuedByParseImportJob();

        // A stale-pending redispatch: enqueue() reuses the link and conforms
        // the import to its still-queued request. Only the job's own re-read
        // of the request, made for the diagnostic, fails.
        (new ParseImportJob($job->id))->handle();

        $this->assertTrue($failed->hit, 'Fixture precondition: the diagnostic read must have been attempted and failed.');
        $this->assertSame($before, $this->jobRow($job->id), 'A successful enqueue was rewritten by recovery.');
        $this->assertEquals($request, DB::table('genai_mcp_requests')->where('id', $requestId)->first());
        $this->assertNotContains('ParseImportJob: external enqueue deferred to recovery', $this->loggedMessages());
        $this->assertNotContains('ParseImportJob: external enqueue recovery left the import unchanged', $this->loggedMessages());
        $this->assertSame(
            ['job_id' => $job->id, 'import_status' => 'pending', 'request_status' => null],
            $this->loggedContext('ParseImportJob: external enqueue reconciled'),
        );
    }

    public function test_terminalization_logs_a_fixed_reason_code_and_never_exception_text(): void
    {
        [, $job, $requestId] = $this->linkedExternalJob();
        $this->denyWritesWithCanaryInThePreviousException();

        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job->refresh());
            $this->fail('An unauthorized link was reused.');
        } catch (PhrExternalEnqueueUnauthorized $exception) {
            $this->assertSame(PhrExternalEnqueueUnauthorized::PATIENT_GRANT_UNAVAILABLE, $exception->reasonCode);
            $message = $exception->getMessage();
        }

        $this->assertSame(
            ['job_id' => $job->id, 'reason_code' => PhrExternalEnqueueUnauthorized::PATIENT_GRANT_UNAVAILABLE],
            $this->loggedContext('External GenAI import terminalized after authorization was lost.'),
        );
        $everything = json_encode(array_map(
            static fn (MessageLogged $entry): array => [$entry->message, $entry->context],
            $this->logged,
        ), JSON_UNESCAPED_SLASHES);
        $this->assertIsString($everything);
        $this->assertStringNotContainsString($message, $everything);
        $this->assertStringNotContainsString(self::CANARY, $everything);
        $this->assertTerminalized($job, $requestId);
    }

    #[DataProvider('brokenSinks')]
    public function test_terminalization_stays_terminal_and_permanent_when_the_logger_throws(bool $fallbackThrows): void
    {
        [, $job, $requestId] = $this->linkedExternalJob();
        $this->denyWritesWithCanaryInThePreviousException();
        $this->breakLogging($fallbackThrows);

        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job->refresh());
            $this->fail('An unauthorized link was reused.');
        } catch (PhrExternalEnqueueUnauthorized $exception) {
            // The caller must still learn the loss is permanent: a log
            // failure escaping in its place reads as a transient error.
            $message = $exception->getMessage();
        }

        $this->assertTerminalized($job, $requestId);
        if (! $fallbackThrows) {
            $fallback = $this->fallback();
            $this->assertStringContainsString('External GenAI import terminalized after authorization was lost.', $fallback);
            $this->assertStringContainsString(PhrExternalEnqueueUnauthorized::PATIENT_GRANT_UNAVAILABLE, $fallback);
            $this->assertStringNotContainsString($message, $fallback);
            $this->assertStringNotContainsString(self::CANARY, $fallback);
        }
    }

    #[DataProvider('brokenSinks')]
    public function test_orphan_sweep_continues_past_a_failed_cancellation_when_the_logger_throws(bool $fallbackThrows): void
    {
        [$user] = $this->externalFixture();
        $mailbox = $this->mailbox($user);
        $stuck = $this->orphan($mailbox, minutesOld: 40);
        $next = $this->orphan($mailbox, minutesOld: 30);
        $failed = false;
        McpRequest::updating(function (McpRequest $model) use ($stuck, &$failed): void {
            if (! $failed && (string) $model->id === $stuck && $model->status === McpRequestStatus::Cancelled) {
                $failed = true;
                throw new QueryException('mysql', 'update genai_mcp_requests', [self::CANARY], new RuntimeException('Connection refused'));
            }
        });
        $this->breakLogging($fallbackThrows);

        $this->artisan('genai:cancel-orphaned-requests')->assertSuccessful();

        $this->assertTrue($failed, 'Fixture precondition: the first cancellation must have failed.');
        $this->assertSame(McpRequestStatus::Pending->value, $this->requestStatus($stuck), 'It stays queued for the next sweep.');
        $this->assertSame(McpRequestStatus::Cancelled->value, $this->requestStatus($next), 'The sweep stopped before the next orphan.');
        if (! $fallbackThrows) {
            $this->assertStringContainsString('could not be cancelled', $this->fallback());
            $this->assertStringNotContainsString(self::CANARY, $this->fallback());
            $this->assertStringNotContainsString($stuck, $this->fallback());
        }
    }

    #[DataProvider('brokenSinks')]
    public function test_execution_mode_change_completes_its_recovery_when_the_logger_throws(bool $fallbackThrows): void
    {
        [$user, $first, $firstRequest] = $this->linkedExternalJob();
        [, $second, $secondRequest] = $this->linkedExternalJob($user);
        $failed = false;
        McpRequest::updating(function (McpRequest $model) use ($firstRequest, &$failed): void {
            if (! $failed && (string) $model->id === $firstRequest && $model->status === McpRequestStatus::Cancelled) {
                $failed = true;
                throw new QueryException('mysql', 'update genai_mcp_requests', [self::CANARY], new RuntimeException('Connection refused'));
            }
        });
        Bus::fake();
        $this->breakLogging($fallbackThrows);

        app(PhrGenAiExecutionModeService::class)->update($user, GenAiImportJob::EXECUTION_API);

        $this->assertTrue($failed, 'Fixture precondition: the first cancellation must have failed.');
        $this->assertSame(GenAiImportJob::EXECUTION_API, $user->refresh()->genAiExecutionMode());
        $this->assertSame(McpRequestStatus::Cancelled->value, $this->requestStatus($secondRequest));
        foreach ([$first, $second] as $job) {
            $job->refresh();
            $this->assertSame(GenAiImportJob::EXECUTION_API, $job->execution_mode);
            $this->assertSame('pending', $job->status);
            $this->assertNull($job->mcp_request_id);
        }
        // Both successors were handed to the queue; none was left to recovery.
        Bus::assertDispatched(ParseImportJob::class, 2);
    }

    #[DataProvider('brokenSinks')]
    public function test_applied_external_completion_is_acknowledged_when_the_logger_throws(bool $fallbackThrows): void
    {
        [, $job] = $this->completedExternalRequest();
        $this->breakLogging($fallbackThrows);

        $this->artisan('genai:mcp:deliver')->assertSuccessful();

        $this->assertDeliveredOnce($job);
        if (! $fallbackThrows) {
            $this->assertStringContainsString('External GenAI import completion applied.', $this->fallback());
        }
    }

    public function test_completion_diagnostic_context_cannot_leave_an_applied_completion_unacknowledged(): void
    {
        [, $job] = $this->completedExternalRequest();
        // Any count of the job's proposals fails while the delivery runs. Only
        // the diagnostic asks for one once the completion is applied.
        $this->failProposalCounts();

        $this->artisan('genai:mcp:deliver')->assertSuccessful();
        $this->failProposalCounts = false;

        $this->assertDeliveredOnce($job);
        $this->assertSame(
            ['job_id' => $job->id, 'result_count' => null, 'created_count' => 1],
            $this->loggedContext('External GenAI import completion applied.'),
        );
    }

    private function assertDeliveredOnce(GenAiImportJob $job): void
    {
        $job->refresh();
        $this->assertSame('parsed', $job->status);
        $this->assertSame(1, $job->results()->count());
        $delivery = DB::table('genai_mcp_deliveries')->where('request_id', $job->mcp_request_id)->sole();
        $this->assertNotNull($delivery->acknowledged_at, 'An applied completion was left for redelivery.');
        $this->assertNull($delivery->last_error);
        Mail::assertSent(GenAiJobCompleteMail::class, 1);
    }

    private function assertTerminalized(GenAiImportJob $job, string $requestId): void
    {
        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertNull($job->mcp_request_id);
        $this->assertSame('External processing was cancelled because access or source state changed.', $job->error_message);
        $this->assertSame(McpRequestStatus::Cancelled->value, $this->requestStatus($requestId));
    }

    /**
     * Break the primary Log destination, and optionally SafeLog's error_log()
     * fallback too. PHPUnit resets the `error_log` directive per test method,
     * so the redirect happens here, inside the test body.
     */
    private function breakLogging(bool $fallbackThrows): void
    {
        $file = tempnam(sys_get_temp_dir(), 'genai-diagnostic-');
        $this->assertIsString($file);
        $this->fallbackFile = $file;
        ini_set('error_log', $file);
        $this->primaryLogThrows = true;
        safelog_test_error_log_should_throw($fallbackThrows);
    }

    /**
     * Make every `count(*)` over genai_import_results throw until the flag is
     * cleared. A QueryExecuted listener runs inside the query call, so the
     * failure surfaces exactly where the query was issued.
     */
    private function failProposalCounts(): void
    {
        $this->failProposalCounts = true;
        DB::listen(function (QueryExecuted $query): void {
            if ($this->failProposalCounts
                && str_contains($query->sql, 'count(*)')
                && str_contains($query->sql, 'genai_import_results')) {
                throw new RuntimeException('diagnostic-only read failed');
            }
        });
    }

    /**
     * Fail every read of genai_mcp_requests that ParseImportJob issues itself,
     * as opposed to through the enqueue service or the status map. The job's
     * only direct read of a request is the one its reconciled diagnostic
     * makes, so this fails exactly that read and nothing the enqueue needs.
     */
    private function failRequestReadsIssuedByParseImportJob(): object
    {
        $failed = new class
        {
            public bool $hit = false;
        };
        DB::listen(function (QueryExecuted $query) use ($failed): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'genai_mcp_requests')) {
                return;
            }
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $class = $frame['class'] ?? '';
                if (! str_starts_with($class, 'App\\')) {
                    continue;
                }
                if ($class === ParseImportJob::class) {
                    $failed->hit = true;

                    throw new RuntimeException('diagnostic-only read failed');
                }

                return;
            }
        });

        return $failed;
    }

    private function fallback(): string
    {
        return is_string($this->fallbackFile) ? (string) file_get_contents($this->fallbackFile) : '';
    }

    /** @return list<string> */
    private function loggedMessages(): array
    {
        return array_map(static fn (MessageLogged $entry): string => $entry->message, $this->logged);
    }

    /**
     * The context of the most recent write of $message - fixtures that run the
     * job themselves log the same events earlier in the test.
     *
     * @return array<string, mixed>|null
     */
    private function loggedContext(string $message): ?array
    {
        foreach (array_reverse($this->logged) as $entry) {
            if ($entry->message === $message) {
                return $entry->context;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function outcome(int $jobId): array
    {
        $job = GenAiImportJob::query()->findOrFail($jobId);

        return [
            'status' => $job->status,
            'scheduled_for' => $job->scheduled_for?->toIso8601String(),
            'retry_count' => (int) $job->retry_count,
            'error_message' => $job->error_message,
            'execution_mode' => $job->execution_mode,
            'mcp_generation' => (int) $job->mcp_generation,
        ];
    }

    /** @return array<string, mixed>|null */
    private function jobRow(int $jobId): ?array
    {
        $row = DB::table('genai_import_jobs')->where('id', $jobId)->first();

        return $row === null ? null : (array) $row;
    }

    private function requestStatus(string $requestId): ?string
    {
        $status = DB::table('genai_mcp_requests')->where('id', $requestId)->value('status');

        return is_string($status) ? $status : null;
    }

    /**
     * A write-grant check that denies with an exception whose message carries
     * the canary. It becomes the `previous` of PhrExternalEnqueueUnauthorized,
     * so nothing about it may reach a log.
     */
    private function denyWritesWithCanaryInThePreviousException(): void
    {
        $this->app->instance(PhrPatientAccessService::class, new class(self::CANARY) extends PhrPatientAccessService
        {
            public function __construct(private readonly string $canary) {}

            public function writablePatient(int $patientId, int $userId): PhrPatient
            {
                throw new AuthorizationException('write access revoked; '.$this->canary);
            }
        });
    }

    private function fakeProvider(string $modelText = '', ?\Closure $during = null): void
    {
        $text = $modelText !== '' ? $modelText : (string) json_encode($this->importPayload());
        Http::fake([
            'api.anthropic.com/v1/files*' => Http::response(['id' => 'file_synthetic'], 200),
            'api.anthropic.com/v1/messages' => function () use ($text, $during) {
                if ($during !== null) {
                    $during();
                }

                return Http::response([
                    'content' => [['type' => 'text', 'text' => $text]],
                    'usage' => ['input_tokens' => 11, 'output_tokens' => 22],
                ], 200);
            },
        ]);
    }

    /** @return array<string, mixed> */
    private function importPayload(): array
    {
        return [
            'schema_version' => 'phr_pdf_bundle.v1',
            'source_document' => [
                'record_key' => 'synthetic-document',
                'title' => 'Synthetic document',
                'document_type' => 'lab_report',
                'summary' => 'Synthetic summary',
            ],
            'records' => [
                'conditions' => [], 'allergies' => [], 'immunizations' => [],
                'medications' => [], 'vitals' => [],
                'lab_results' => [[
                    'record_key' => 'synthetic-analyte',
                    'analyte' => 'Synthetic analyte',
                    'value' => '1.0',
                ]],
                'procedures' => [], 'encounters' => [],
                'portal_messages' => [], 'negative_assertions' => [],
            ],
        ];
    }

    /** @return array{User, GenAiImportJob} */
    private function apiJob(): array
    {
        [$user, $job] = $this->importFixture(GenAiImportJob::EXECUTION_API);
        UserAiConfiguration::query()->create([
            'user_id' => $user->id,
            'name' => 'Synthetic provider',
            'provider' => 'anthropic',
            'api_key' => 'synthetic-key',
            'model' => 'synthetic-model',
            'is_active' => true,
        ]);

        return [$user, $job->refresh()];
    }

    /** @return array{User, GenAiImportJob} */
    private function externalFixture(?User $user = null): array
    {
        return $this->importFixture(GenAiImportJob::EXECUTION_EXTERNAL, $user);
    }

    /** @return array{User, GenAiImportJob, string} */
    private function linkedExternalJob(?User $user = null): array
    {
        [$user, $job] = $this->externalFixture($user);
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;
        $this->assertNotSame('', $requestId, 'Fixture precondition: the job must be linked to a request.');

        return [$user, $job, $requestId];
    }

    /**
     * An external job whose request a client has claimed and completed, with
     * the durable delivery still waiting for `genai:mcp:deliver`.
     *
     * @return array{User, GenAiImportJob}
     */
    private function completedExternalRequest(): array
    {
        [$user, $job, $requestId] = $this->linkedExternalJob();
        $mailboxId = (string) DB::table('genai_mcp_requests')->where('id', $requestId)->value('mailbox_id');
        $context = new ExecutionContext(
            principalKey: sprintf('phr:user:%d:oauth:%s', $user->id, hash('sha256', 'synthetic-'.$user->id)),
            mailboxIds: [$mailboxId],
            scopes: [AgentApiScopes::GENAI_READ, AgentApiScopes::GENAI_WORK],
        );
        $queue = app(McpQueueService::class);
        $claim = $queue->claim($context, 'phr-imports');
        $this->assertIsArray($claim);
        $queue->complete($context, $requestId, $claim['request']['lease_token'], [
            'text' => '',
            'tool_calls' => [[
                'name' => 'submit_phr_import',
                'input' => $this->importPayload(),
            ]],
        ], ['client' => 'synthetic-harness', 'model' => 'synthetic-model']);
        $this->assertSame(1, DB::table('genai_mcp_deliveries')->where('request_id', $requestId)->count());

        return [$user, $job->refresh()];
    }

    private function mailbox(User $user): McpMailbox
    {
        return McpMailbox::query()->firstOrCreate([
            'owner_type' => User::class,
            'owner_id' => (string) $user->id,
            'name' => PhrExternalGenAiRequestService::MAILBOX,
        ], ['enabled' => true]);
    }

    /** A pending request no import references, old enough for the sweep. */
    private function orphan(McpMailbox $mailbox, int $minutesOld): string
    {
        $request = McpRequest::query()->create([
            'mailbox_id' => $mailbox->id,
            'queue' => PhrExternalGenAiRequestService::MAILBOX,
            'status' => McpRequestStatus::Pending,
            'payload' => ['synthetic' => true],
            'available_at' => now(),
        ]);
        DB::table('genai_mcp_requests')->where('id', $request->id)->update([
            'created_at' => now()->subMinutes($minutesOld),
        ]);

        return (string) $request->id;
    }

    /** @return array{User, GenAiImportJob} */
    private function importFixture(string $executionMode, ?User $user = null): array
    {
        $user ??= User::factory()->create([
            'user_role' => 'user',
            'genai_execution_mode' => $executionMode,
        ]);
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $user->id,
            'display_name' => 'Synthetic Diagnostic Patient',
            'relationship' => 'self',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $user->id,
            'granted_at' => now(),
        ]);
        $bytes = "%PDF-1.4\nSynthetic diagnostic document ".uniqid('', true)."\n%%EOF\n";
        $path = 'genai-import/'.$user->id.'/diagnostic/'.hash('sha256', $bytes).'.pdf';
        Storage::disk('s3')->put($path, $bytes);
        $document = PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'uploaded_by_user_id' => $user->id,
            'title' => 'Synthetic diagnostic document',
            'document_type' => 'lab_report',
            'original_filename' => 'synthetic-private-name.pdf',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'patients/'.$patient->id.'/documents/diagnostic.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'file_hash' => hash('sha256', $bytes),
            'source' => 'manual_upload',
        ]);
        $job = GenAiImportJob::query()->create([
            'user_id' => $user->id,
            'job_type' => 'phr_document',
            'file_hash' => hash('sha256', $bytes),
            'original_filename' => 'synthetic-private-name.pdf',
            's3_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => strlen($bytes),
            'context_json' => json_encode(['patient_id' => $patient->id, 'document_id' => $document->id], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'execution_mode' => $executionMode,
        ]);
        $document->update(['genai_job_id' => $job->id]);

        return [$user, $job];
    }
}
