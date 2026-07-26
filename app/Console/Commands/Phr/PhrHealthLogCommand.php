<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrHealthLog;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\HealthLog\PhrHealthLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;
use stdClass;

#[Signature('phr:health-log:record
    {--patient= : PHR patient id}
    {--actor= : Acting user id}
    {--log= : Health log id or name; a new named log is created when needed}
    {--kind=custom : Log kind: meal, snack, symptom, or custom}
    {--description= : Description used when creating a named log}
    {--occurred-at= : Entry date-time; defaults to now}
    {--title= : Optional entry title}
    {--notes= : Optional entry notes}
    {--intensity= : Optional intensity from 0 to 10}
    {--tag=* : Repeatable entry tag}
    {--details= : Optional JSON object with structured details}
    {--format=table : Output format: table or json}')]
#[Description('Record an entry in a patient health log')]
class PhrHealthLogCommand extends BasePhrCommand
{
    public function handle(
        PhrPatientAccessService $accessService,
        PhrHealthLogService $healthLogService,
    ): int {
        $details = $this->parseDetails();
        if ($details === false) {
            return self::FAILURE;
        }

        $tags = $this->tags();
        $attributes = [
            'log' => $this->stringOption('log'),
            'kind' => $this->stringOption('kind') ?? 'custom',
            'description' => $this->stringOption('description'),
            'occurred_at' => $this->stringOption('occurred-at') ?? now()->toIso8601String(),
            'title' => $this->stringOption('title'),
            'notes' => $this->stringOption('notes'),
            'intensity' => $this->option('intensity'),
            'tags' => $tags,
            'details' => $details,
            'format' => $this->stringOption('format') ?? 'table',
        ];

        $validator = Validator::make($attributes, [
            'log' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(PhrHealthLog::KINDS)],
            'description' => ['nullable', 'string', 'max:1000'],
            'occurred_at' => ['required', 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'intensity' => ['nullable', 'integer', 'between:0,10'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:50', 'distinct'],
            'details' => ['nullable', 'array', 'max:50'],
            'format' => ['required', Rule::in(['table', 'json'])],
        ], [
            'details.array' => '--details must be a JSON object.',
            'format.in' => '--format must be table or json.',
            'intensity.between' => '--intensity must be between 0 and 10.',
            'kind.in' => '--kind must be meal, snack, symptom, or custom.',
            'log.required' => '--log is required.',
            'occurred_at.date' => '--occurred-at must be a valid date-time.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        /** @var array{log: string, kind: string, description: string|null, occurred_at: string, title: string|null, notes: string|null, intensity: int|string|null, tags: list<string>, details: array<string, mixed>|null, format: string} $validated */
        $validated = $validator->validated();

        try {
            $patient = $this->writablePatient($accessService);
            $actorId = $this->intOptionRequired('actor');
        } catch (AuthorizationException|ModelNotFoundException|\InvalidArgumentException $exception) {
            $this->error($exception instanceof ModelNotFoundException
                ? 'The patient is not accessible to the acting user.'
                : $exception->getMessage());

            return self::FAILURE;
        }

        $healthLog = $this->resolveHealthLog(
            $healthLogService,
            $patient,
            $actorId,
            $validated['log'],
            $validated['kind'],
            $validated['description'],
        );
        if ($healthLog === null) {
            return self::FAILURE;
        }

        $entry = $healthLogService->createEntry($patient, $healthLog, $actorId, [
            'occurred_at' => Carbon::parse($validated['occurred_at']),
            'title' => $validated['title'],
            'notes' => $validated['notes'],
            'intensity' => $validated['intensity'] === null ? null : (int) $validated['intensity'],
            'tags' => $validated['tags'],
            'details' => $validated['details'],
        ]);

        $occurredAt = $entry->occurred_at->toIso8601String();
        $payload = [
            'health_log' => [
                'id' => $healthLog->id,
                'patient_id' => $healthLog->patient_id,
                'name' => $healthLog->name,
                'kind' => $healthLog->kind,
                'description' => $healthLog->description,
            ],
            'entry' => [
                'id' => $entry->id,
                'health_log_id' => $entry->health_log_id,
                'patient_id' => $entry->patient_id,
                'occurred_at' => $occurredAt,
                'title' => $entry->title,
                'notes' => $entry->notes,
                'intensity' => $entry->intensity,
                'tags' => $entry->tags ?? [],
                'details' => $entry->details,
            ],
        ];

        if ($validated['format'] === 'json') {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Health log entry recorded.');
        $this->table(
            ['Entry', 'Log', 'Kind', 'Occurred at', 'Intensity', 'Title', 'Tags'],
            [[
                $entry->id,
                $healthLog->name,
                $healthLog->kind,
                $occurredAt,
                $entry->intensity ?? '',
                $entry->title ?? '',
                implode(', ', $entry->tags ?? []),
            ]],
        );

        return self::SUCCESS;
    }

    private function resolveHealthLog(
        PhrHealthLogService $healthLogService,
        PhrPatient $patient,
        int $actorId,
        string $log,
        string $kind,
        ?string $description,
    ): ?PhrHealthLog {
        if (! ctype_digit($log)) {
            return $healthLogService->findOrCreateLog($patient, $actorId, $log, $kind, $description);
        }

        $healthLog = PhrHealthLog::query()
            ->where('patient_id', $patient->id)
            ->find((int) $log);

        if (! $healthLog) {
            $this->error("Health log {$log} was not found for phr_patients#{$patient->id}.");

            return null;
        }

        return $healthLog;
    }

    /**
     * @return array<string, mixed>|false|null
     */
    private function parseDetails(): array|false|null
    {
        $value = $this->stringOption('details');
        if ($value === null) {
            return null;
        }

        try {
            $object = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
            $details = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('--details must be a valid JSON object: '.$exception->getMessage());

            return false;
        }

        if (! $object instanceof stdClass || ! is_array($details)) {
            $this->error('--details must be a JSON object.');

            return false;
        }

        /** @var array<string, mixed> $details */
        return $details;
    }

    /** @return list<string> */
    private function tags(): array
    {
        $tags = $this->option('tag');

        return array_values(array_filter(
            array_map(static fn (mixed $tag): string => is_scalar($tag) ? trim((string) $tag) : '', $tags),
            static fn (string $tag): bool => $tag !== '',
        ));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
