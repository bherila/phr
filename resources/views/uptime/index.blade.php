@extends('layouts.app')

@section('title', 'Uptime | ' . config('app.name', 'Personal Health Record'))

@section('content')
  <div class="mx-auto w-full max-w-6xl space-y-8 px-4 py-10 sm:px-6">
    <header class="space-y-2">
      <p class="text-sm font-medium uppercase tracking-wider text-muted-foreground">Admin operations</p>
      <h1 class="text-3xl font-semibold text-foreground">Uptime</h1>
      <p class="max-w-3xl text-sm text-muted-foreground">
        Sanitized cPanel cron and Laravel task history. A stale status means an expected heartbeat has not arrived.
        Times are shown in UTC.
      </p>
    </header>

    <section aria-labelledby="current-status-heading">
      <h2 id="current-status-heading" class="mb-4 text-xl font-semibold text-foreground">Current status</h2>
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($current as $job)
          @php
            $stateClasses = match ($job['state']) {
              'success' => 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
              'running' => 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
              'failure' => 'bg-red-500/15 text-red-700 dark:text-red-300',
              default => 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
            };
          @endphp
          <article class="rounded-lg border border-border bg-card p-5 text-card-foreground shadow-sm">
            <div class="flex items-start justify-between gap-3">
              <div>
                <h3 class="font-semibold">{{ $job['label'] }}</h3>
                <p class="mt-1 font-mono text-xs text-muted-foreground">{{ $job['job_name'] }}</p>
              </div>
              <span class="rounded-full px-2.5 py-1 text-xs font-semibold uppercase {{ $stateClasses }}">
                {{ $job['state'] }}
              </span>
            </div>
            @if ($job['latest'])
              <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div>
                  <dt class="text-muted-foreground">Last started</dt>
                  <dd>{{ $job['latest']->started_at->utc()->format('Y-m-d H:i:s') }}</dd>
                </div>
                <div>
                  <dt class="text-muted-foreground">Duration</dt>
                  <dd>{{ $job['latest']->duration_ms === null ? 'In progress' : number_format($job['latest']->duration_ms) . ' ms' }}</dd>
                </div>
              </dl>
            @else
              <p class="mt-4 text-sm text-muted-foreground">No run has been recorded yet.</p>
            @endif
          </article>
        @endforeach
      </div>
    </section>

    <section aria-labelledby="run-history-heading">
      <div class="mb-4 flex items-end justify-between gap-4">
        <h2 id="run-history-heading" class="text-xl font-semibold text-foreground">Run history</h2>
        <p class="text-xs text-muted-foreground">Latest 200 runs; records older than 30 days are pruned daily.</p>
      </div>
      <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-sm">
        <table class="min-w-full divide-y divide-border text-left text-sm">
          <thead class="bg-muted/50 text-muted-foreground">
            <tr>
              <th class="px-4 py-3 font-medium">Job</th>
              <th class="px-4 py-3 font-medium">Status</th>
              <th class="px-4 py-3 font-medium">Started (UTC)</th>
              <th class="px-4 py-3 font-medium">Finished (UTC)</th>
              <th class="px-4 py-3 font-medium">Duration</th>
              <th class="px-4 py-3 font-medium">Exit code</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            @forelse ($history as $run)
              <tr>
                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">{{ $run->job_name }}</td>
                <td class="whitespace-nowrap px-4 py-3">{{ ucfirst($run->status) }}</td>
                <td class="whitespace-nowrap px-4 py-3">{{ $run->started_at->utc()->format('Y-m-d H:i:s') }}</td>
                <td class="whitespace-nowrap px-4 py-3">{{ $run->finished_at?->utc()->format('Y-m-d H:i:s') ?? '—' }}</td>
                <td class="whitespace-nowrap px-4 py-3">{{ $run->duration_ms === null ? '—' : number_format($run->duration_ms) . ' ms' }}</td>
                <td class="whitespace-nowrap px-4 py-3">{{ $run->exit_code ?? '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">No runs have been recorded yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>
  </div>
@endsection
