<?php

namespace Tests\Feature;

use App\Console\Commands\Phr\UptimeRunWorkerCommand;
use App\Models\UptimeRun;
use App\Models\User;
use App\Services\Uptime\UptimeJobCatalog;
use App\Services\Uptime\UptimeMonitor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class UptimeDashboardTest extends TestCase
{
    public function test_guests_are_redirected_and_non_admins_are_forbidden(): void
    {
        $this->get('/uptime')->assertRedirect('/login');

        $this->createAdminUser(); // Reserve user ID 1, which is always an admin.
        $user = $this->createUser();

        $this->actingAs($user)->get('/uptime')->assertForbidden();
    }

    public function test_admins_and_user_id_one_can_view_the_dashboard(): void
    {
        $firstUser = User::factory()->create(['user_role' => 'user']);

        $this->assertSame(1, $firstUser->id);
        $this->actingAs($firstUser)->get('/uptime')
            ->assertOk()
            ->assertViewIs('uptime.index')
            ->assertSee('cPanel Laravel scheduler')
            ->assertSee('cPanel queue worker');

        $this->actingAs($this->createAdminUser())->get('/uptime')->assertOk();
    }

    public function test_footer_link_is_visible_only_to_authenticated_admins(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createUser();

        $this->actingAs($admin)->get('/uptime')
            ->assertSee('href="'.route('uptime').'"', false)
            ->assertSee('>Uptime</a>', false);

        $this->actingAs($user)->get('/phr/patients')
            ->assertOk()
            ->assertDontSee('href="'.route('uptime').'"', false);

        auth()->logout();
        $this->get('/login')->assertDontSee('href="'.route('uptime').'"', false);
    }

    public function test_monitor_records_success_and_failure_without_sensitive_details(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        $monitor = app(UptimeMonitor::class);

        $this->assertSame(0, $monitor->run(
            UptimeJobCatalog::SCHEDULER,
            fn (): int => 0,
        ));

        try {
            $monitor->run(
                UptimeJobCatalog::QUEUE_WORKER,
                fn (): never => throw new RuntimeException('SECRET patient/path/file payload'),
            );
            $this->fail('The monitored exception should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SECRET', $exception->getMessage());
        }

        $success = UptimeRun::query()->where('job_name', UptimeJobCatalog::SCHEDULER)->sole();
        $this->assertSame('success', $success->status);
        $this->assertSame(0, $success->exit_code);
        $this->assertNotNull($success->finished_at);
        $this->assertNotNull($success->duration_ms);

        $failure = UptimeRun::query()->where('job_name', UptimeJobCatalog::QUEUE_WORKER)->sole();
        $this->assertSame('failure', $failure->status);
        $this->assertSame(1, $failure->exit_code);

        $this->assertSame([
            'id',
            'job_name',
            'status',
            'started_at',
            'finished_at',
            'duration_ms',
            'exit_code',
        ], Schema::getColumnListing('uptime_runs'));
        $this->assertStringNotContainsString('SECRET', UptimeRun::query()->get()->toJson());
    }

    public function test_dashboard_marks_an_absent_five_minute_heartbeat_stale_after_fifteen_minutes(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        UptimeRun::query()->create([
            'job_name' => UptimeJobCatalog::SCHEDULER,
            'status' => 'success',
            'started_at' => now()->subMinutes(16),
            'finished_at' => now()->subMinutes(16),
            'duration_ms' => 25,
            'exit_code' => 0,
        ]);

        $response = $this->actingAs($this->createAdminUser())->get('/uptime')->assertOk();
        $scheduler = $response->viewData('current')->firstWhere('job_name', UptimeJobCatalog::SCHEDULER);

        $this->assertSame('stale', $scheduler['state']);
        $response->assertSee('stale');
        $response->assertSee('2026-08-02 11:44:00');
    }

    public function test_prune_command_retains_only_the_configured_history_window(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        foreach ([31, 29] as $ageInDays) {
            UptimeRun::query()->create([
                'job_name' => UptimeJobCatalog::SCHEDULER,
                'status' => 'success',
                'started_at' => now()->subDays($ageInDays),
                'finished_at' => now()->subDays($ageInDays),
                'duration_ms' => 1,
                'exit_code' => 0,
            ]);
        }

        $this->artisan('phr:uptime:prune', ['--days' => 30])
            ->expectsOutput('Pruned 1 uptime run(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('uptime_runs', 1);
        $this->assertTrue(UptimeRun::query()->sole()->started_at->equalTo(now()->subDays(29)));
    }

    public function test_scheduled_task_wrapper_records_the_allow_listed_task(): void
    {
        $this->artisan('phr:uptime:run-task', ['job' => 'genai:requeue-stale'])
            ->assertSuccessful();

        $this->assertDatabaseHas('uptime_runs', [
            'job_name' => 'genai:requeue-stale',
            'status' => 'success',
            'exit_code' => 0,
        ]);

        $this->artisan('phr:uptime:run-task', ['job' => 'not:a:real-task'])
            ->assertFailed();
        $this->assertDatabaseMissing('uptime_runs', ['job_name' => 'not:a:real-task']);
    }

    public function test_queue_worker_wrapper_records_an_empty_queue_drain(): void
    {
        config(['queue.default' => 'database']);
        $this->assertSame(256, UptimeRunWorkerCommand::MEMORY_LIMIT_MEGABYTES);

        // Exercise the wrapper above Laravel's 128 MB queue-worker default so
        // removing the explicit ceiling reproduces EXIT_MEMORY_LIMIT (12).
        $targetUsage = 160 * 1024 * 1024;
        $memoryPressure = str_repeat('x', max(0, $targetUsage - memory_get_usage(true)));
        $this->assertGreaterThan(128 * 1024 * 1024, memory_get_usage(true));

        $this->artisan('phr:uptime:run-worker')->assertSuccessful();
        $this->assertIsString($memoryPressure);

        $this->assertDatabaseHas('uptime_runs', [
            'job_name' => UptimeJobCatalog::QUEUE_WORKER,
            'status' => 'success',
            'exit_code' => 0,
        ]);
    }

    public function test_schedule_uses_monitored_task_wrappers_and_daily_retention(): void
    {
        $this->assertSame(0, Artisan::call('schedule:list', ['--no-ansi' => true]));
        $display = Artisan::output();

        $this->assertStringContainsString("phr:uptime:run-task 'phr:dicom:gc'", $display);
        $this->assertStringContainsString("phr:uptime:run-task 'phr:exports:purge'", $display);
        $this->assertStringContainsString("phr:uptime:run-task 'genai:requeue-stale'", $display);
        $this->assertStringContainsString('phr:uptime:prune --days=30', $display);
    }
}
