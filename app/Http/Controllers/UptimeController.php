<?php

namespace App\Http\Controllers;

use App\Models\UptimeRun;
use App\Services\Uptime\UptimeJobCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UptimeController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless($request->user()?->hasRole('admin'), 403);

        $now = now();
        $current = collect(UptimeJobCatalog::jobs())->map(function (array $definition, string $jobName) use ($now): array {
            $latest = UptimeRun::query()
                ->where('job_name', $jobName)
                ->latest('started_at')
                ->latest('id')
                ->first();

            if ($latest === null) {
                $state = 'missing';
            } elseif ($latest->started_at->copy()->addSeconds($definition['stale_after_seconds'])->isBefore($now)) {
                $state = 'stale';
            } else {
                $state = $latest->status;
            }

            return [
                'job_name' => $jobName,
                'label' => $definition['label'],
                'state' => $state,
                'latest' => $latest,
            ];
        })->values();

        $history = UptimeRun::query()
            ->latest('started_at')
            ->latest('id')
            ->limit(200)
            ->get();

        return view('uptime.index', compact('current', 'history'));
    }
}
