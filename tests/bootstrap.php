<?php

/**
 * PHPUnit Bootstrap File
 *
 * This file ensures that tests always use .env.testing (SQLite)
 * instead of .env (which may contain MySQL production credentials).
 */

// Set the environment to testing BEFORE loading anything
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

// Force SQLite configuration
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = ':memory:';

require __DIR__.'/../vendor/autoload.php';
