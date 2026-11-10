<?php

namespace Tests\Support;

use InvalidArgumentException;

/**
 * Isolated disposable loopback database configuration for resource harnesses.
 *
 * Explicit safe fields prevent inheriting application sockets, URLs, hosts
 * or credentials from .env.
 */
final class DisposableResourceConnection
{
    public const string SYNTHETIC_PASSWORD = 'synthetic-resource-password';

    public const string SCHEMA_PREFIX = 'phr_resource_';

    public static function schema(string $directory): string
    {
        return self::SCHEMA_PREFIX.substr(hash('sha256', $directory), 0, 24);
    }

    /**
     * @return array<string, mixed>
     */
    public static function mysql(int $port, string $directory): array
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('--mysql-port must be a valid loopback TCP port.');
        }

        return [
            'driver' => 'mysql',
            'url' => null,
            'host' => '127.0.0.1',
            'port' => $port,
            'database' => self::schema($directory),
            'username' => 'root',
            'password' => self::SYNTHETIC_PASSWORD,
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [],
        ];
    }
}
