<?php

/**
 * PHPUnit Bootstrap File
 *
 * This file ensures that tests always use .env.testing (SQLite)
 * instead of .env (which may contain MySQL production credentials).
 * PHR_TEST_DATABASE=pgsql selects a local PostgreSQL server instead.
 */

// Set the environment to testing BEFORE loading anything
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

// Force SQLite configuration, unless a run opts in to a disposable local PostgreSQL.
$testEnvironment = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'];

if (getenv('PHR_TEST_DATABASE') === 'pgsql') {
    $host = (string) getenv('PHR_TEST_PGSQL_HOST') ?: '127.0.0.1';
    $port = (string) getenv('PHR_TEST_PGSQL_PORT') ?: '5432';
    $username = (string) getenv('PHR_TEST_PGSQL_USERNAME') ?: 'postgres';
    $password = (string) getenv('PHR_TEST_PGSQL_PASSWORD');

    if (! in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
        throw new RuntimeException("SAFETY ERROR: PostgreSQL tests must use a local server, not '{$host}'.");
    }

    // Each ParaTest worker gets its own database, as it would its own in-memory SQLite one.
    $database = 'phr_test_'.((int) getenv('TEST_TOKEN'));
    $server = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $username, $password);
    $exists = $server->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
    $exists->execute([$database]);
    if ($exists->fetchColumn() === false) {
        $server->exec('CREATE DATABASE '.$database);
    }

    $testEnvironment = [
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => $host,
        'DB_PORT' => $port,
        'DB_DATABASE' => $database,
        'DB_USERNAME' => $username,
        'DB_PASSWORD' => $password,
    ];
}

foreach ($testEnvironment as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
