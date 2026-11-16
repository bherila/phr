<?php

namespace Tests\Feature;

use Tests\TestCase;

class DatabaseSessionTimezoneTest extends TestCase
{
    public function test_application_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_mysql_family_sessions_use_utc(): void
    {
        foreach (['mysql', 'mariadb'] as $connection) {
            $this->assertSame('+00:00', config("database.connections.{$connection}.timezone"),
                "The {$connection} session must use UTC rather than the server's SYSTEM timezone.");
        }
    }
}
