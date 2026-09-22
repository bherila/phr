<?php

namespace Tests\Unit\Support\Logging;

use App\Support\Logging\SafeLog;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__.'/SafeLogErrorLogStub.php';

/**
 * PHPUnit itself redirects the `error_log` ini directive for the duration of
 * every test method (see `PHPUnit\Framework\TestCase\ErrorLogCapture`), so a
 * test that wants to inspect what SafeLog's fallback actually wrote must
 * redirect `error_log` to its own file from *inside* the test method - a
 * redirect made in `setUp()` is itself overridden once the test body starts
 * running. `redirectErrorLogTo()` below does that, and its file is cleaned
 * up in `tearDown()`.
 */
final class SafeLogTest extends TestCase
{
    private ?string $errorLogFile = null;

    protected function tearDown(): void
    {
        if (is_string($this->errorLogFile) && file_exists($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }

        parent::tearDown();
    }

    public function test_writes_through_to_the_configured_log_channel_when_it_is_healthy(): void
    {
        $this->redirectErrorLogTo();
        Log::spy();

        SafeLog::warning('safelog.test.healthy_write', ['job_id' => 42]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('safelog.test.healthy_write', ['job_id' => 42]);
        $this->assertSame('', trim($this->readFallbackSink()), 'A healthy Log write must never fall through to the sink.');
    }

    public function test_error_level_writes_through_to_the_configured_log_channel(): void
    {
        Log::spy();

        SafeLog::error('safelog.test.healthy_error', ['job_id' => 7]);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('safelog.test.healthy_error', ['job_id' => 7]);
    }

    public function test_info_level_writes_through_at_info_when_the_logger_is_healthy(): void
    {
        $this->redirectErrorLogTo();
        Log::spy();

        SafeLog::info('safelog.test.healthy_info', ['job_id' => 3]);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('safelog.test.healthy_info', ['job_id' => 3]);
        Log::shouldNotHaveReceived('warning');
        $this->assertSame('', trim($this->readFallbackSink()));
    }

    public function test_info_level_falls_back_at_info_without_throwing_when_the_log_facade_throws(): void
    {
        $this->redirectErrorLogTo();
        Log::shouldReceive('info')
            ->once()
            ->andThrow(new RuntimeException('log destination unavailable - raw text that must not appear'));

        SafeLog::info('safelog.test.throwing_info', ['job_id' => 11]);

        $fallback = $this->readFallbackSink();
        $this->assertStringContainsString('[phr.safelog.info] safelog.test.throwing_info', $fallback);
        $this->assertStringContainsString('"job_id":11', $fallback);
        $this->assertStringNotContainsString('raw text that must not appear', $fallback);
    }

    public function test_falls_back_without_throwing_when_the_log_facade_throws(): void
    {
        $this->redirectErrorLogTo();
        Log::shouldReceive('error')
            ->once()
            ->andThrow(new RuntimeException('storage/logs is full - unrelated raw exception text that must never appear anywhere'));

        SafeLog::error('safelog.test.throwing_logger', ['job_id' => 99]);

        $fallback = $this->readFallbackSink();
        $this->assertStringContainsString('safelog.test.throwing_logger', $fallback);
        $this->assertStringContainsString('"job_id":99', $fallback);
        $this->assertStringNotContainsString('storage/logs is full', $fallback);
    }

    public function test_fallback_write_does_not_throw_when_its_own_destination_is_unavailable(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->andThrow(new RuntimeException('log sink unavailable'));

        // PHP's real error_log() degrades on its own rather than throwing,
        // even pointed at an unusable destination, so forcing a genuine
        // throw out of error_log() itself (via the namespaced stub required
        // above) is the only honest way to exercise SafeLog::fallback()'s
        // own guard.
        \App\Support\Logging\safelog_test_error_log_should_throw(true);

        try {
            SafeLog::warning('safelog.test.double_failure', ['job_id' => 1]);

            // Reaching this line without an exception is the assertion: a
            // failure of the fallback must not throw and must not change
            // the caller's business outcome.
            $this->assertTrue(true);
        } finally {
            \App\Support\Logging\safelog_test_error_log_should_throw(false);
        }
    }

    public function test_context_normalization_drops_unsafe_values_without_throwing(): void
    {
        Log::spy();

        SafeLog::warning('safelog.test.unsafe_context', [
            'job_id' => 5,
            'exception' => RuntimeException::class,
            'nested' => ['unreviewed' => 'data'],
            'object' => new RuntimeException('should be dropped, never serialized'),
        ]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $this->assertSame(['job_id', 'exception'], array_keys($context));
                $this->assertSame(5, $context['job_id']);
                $this->assertSame(RuntimeException::class, $context['exception']);

                return true;
            });
    }

    public function test_long_string_context_values_are_bounded(): void
    {
        Log::spy();

        SafeLog::warning('safelog.test.long_value', ['note' => str_repeat('a', 5000)]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $this->assertLessThanOrEqual(501, mb_strlen($context['note']));

                return true;
            });
    }

    /**
     * Overrides PHPUnit's own per-test `error_log` redirect with a real file
     * this test can read back. Must be called from inside the test method
     * (not `setUp()`) - PHPUnit applies its own redirect around each test
     * method invocation, after `setUp()` has already run.
     */
    private function redirectErrorLogTo(): void
    {
        $this->errorLogFile = tempnam(sys_get_temp_dir(), 'safelog-test-');
        ini_set('error_log', $this->errorLogFile);
    }

    private function readFallbackSink(): string
    {
        if ($this->errorLogFile === null) {
            return '';
        }
        $contents = file_get_contents($this->errorLogFile);

        return $contents === false ? '' : $contents;
    }
}
