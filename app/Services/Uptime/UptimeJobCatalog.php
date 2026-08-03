<?php

namespace App\Services\Uptime;

use InvalidArgumentException;

final class UptimeJobCatalog
{
    public const string SCHEDULER = 'phr-laravel-scheduler';

    public const string QUEUE_WORKER = 'phr-laravel-queue-worker';

    /**
     * Fixed identifiers are a privacy boundary: arbitrary command text must never
     * become uptime history.
     *
     * @return array<string, array{label: string, stale_after_seconds: int}>
     */
    public static function jobs(): array
    {
        return [
            self::SCHEDULER => [
                'label' => 'cPanel Laravel scheduler',
                'stale_after_seconds' => 15 * 60,
            ],
            self::QUEUE_WORKER => [
                'label' => 'cPanel queue worker',
                'stale_after_seconds' => 15 * 60,
            ],
            'genai:requeue-stale' => [
                'label' => 'Deferred AI import recovery',
                'stale_after_seconds' => 15 * 60,
            ],
            'phr:dicom:gc' => [
                'label' => 'DICOM storage cleanup',
                'stale_after_seconds' => 2 * 60 * 60,
            ],
            'phr:exports:purge' => [
                'label' => 'Expired export cleanup',
                'stale_after_seconds' => 26 * 60 * 60,
            ],
            'phr:native-backups:purge' => [
                'label' => 'Expired native backup cleanup',
                'stale_after_seconds' => 26 * 60 * 60,
            ],
        ];
    }

    /** @return array<string, string> */
    public static function scheduledCommands(): array
    {
        return [
            'genai:requeue-stale' => 'genai:requeue-stale',
            'phr:dicom:gc' => 'phr:dicom:gc',
            'phr:exports:purge' => 'phr:exports:purge',
            'phr:native-backups:purge' => 'phr:native-backups:purge',
        ];
    }

    /** @return array{label: string, stale_after_seconds: int} */
    public static function get(string $jobName): array
    {
        return self::jobs()[$jobName]
            ?? throw new InvalidArgumentException('Unknown monitored uptime job.');
    }

    public static function scheduledCommand(string $jobName): string
    {
        return self::scheduledCommands()[$jobName]
            ?? throw new InvalidArgumentException('Unknown monitored scheduled task.');
    }
}
