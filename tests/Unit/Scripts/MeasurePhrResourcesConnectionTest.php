<?php

namespace Tests\Unit\Scripts;

use Illuminate\Database\Connectors\MySqlConnector;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\DisposableResourceConnection;

final class MeasurePhrResourcesConnectionTest extends TestCase
{
    public function test_disposable_mysql_connection_clears_inherited_socket_and_targets_loopback(): void
    {
        $directory = '/tmp/phr-resource-synthetic-'.bin2hex(random_bytes(8));
        $inheritedSocket = '/var/run/mysqld/synthetic-inherited.sock';

        // Pure array assertions: never touch the application's config or connections,
        // which would leak a MySQL default into every later test in the process.
        $configured = DisposableResourceConnection::mysql(3307, $directory);
        self::assertSame('mysql', $configured['driver']);
        self::assertSame('', $configured['unix_socket']);
        self::assertSame('127.0.0.1', $configured['host']);
        self::assertSame(3307, $configured['port']);
        self::assertSame('root', $configured['username']);
        self::assertSame(DisposableResourceConnection::SYNTHETIC_PASSWORD, $configured['password']);
        self::assertStringStartsWith(DisposableResourceConnection::SCHEMA_PREFIX, $configured['database']);
        self::assertStringNotContainsString('production', $configured['database']);

        $connector = new class extends MySqlConnector
        {
            public function dsn(array $config): string
            {
                return $this->getDsn($config);
            }
        };

        $dsn = $connector->dsn($configured);
        self::assertStringContainsString('host=127.0.0.1', $dsn);
        self::assertStringContainsString('port=3307', $dsn);
        self::assertStringContainsString('dbname='.$configured['database'], $dsn);
        self::assertStringNotContainsString('unix_socket', $dsn);
        self::assertStringNotContainsString($inheritedSocket, $dsn);
    }

    public function test_disposable_mysql_configuration_refuses_invalid_ports(): void
    {
        $directory = '/tmp/phr-resource-synthetic-test';

        $this->expectException(InvalidArgumentException::class);
        DisposableResourceConnection::mysql(0, $directory);
    }
}
