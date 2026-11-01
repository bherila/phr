#!/usr/bin/env bash
# Refuse migration unless the candidate's resolved configuration reaches only the local server.
# The workflow prepends this to the candidate-secret provisioner as one pre-migrate hook, so it
# must neither exit on success nor declare names at top level.
set -euo pipefail
pilot_assert_local_database() {
    [[ $# -eq 3 ]] || exit 2
    local candidate=$1 php=$2 stable=$3
    [[ $stable =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ && $stable != *..* ]] || exit 2
    [[ $candidate = ".deployments/$stable/releases/"* && $candidate != *..* ]] || exit 2
    [[ $php = /* && -x $php ]] || exit 1
    local root="$HOME/$candidate"
    [[ -d $root && ! -L $root && -f "$root/.env" && -f "$root/vendor/autoload.php" && -f "$root/bootstrap/app.php" ]] || {
        echo 'The candidate is not ready for database verification.' >&2
        exit 1
    }
    # Resolve .env, the process environment and config/*.php exactly as the uncached migration
    # will, without registering providers, so nothing can connect before the check passes.
    # shellcheck disable=SC2016 # PHP variable names must remain literal.
    (cd "$root" && "$php" -d memory_limit=1G -d display_errors=0 -d log_errors=0 -r '
$local = static fn ($name): bool => $name === null || $name === "" || $name === "mysql";
$refused = [];
try {
    $base = getcwd();
    $uncached = $base."/.pilot-uncached-config/config.php";
    if (file_exists(dirname($uncached))) { exit(1); }
    putenv("APP_CONFIG_CACHE=".$uncached);
    $_ENV["APP_CONFIG_CACHE"] = $_SERVER["APP_CONFIG_CACHE"] = $uncached;
    require $base."/vendor/autoload.php";
    $app = require $base."/bootstrap/app.php";
    $app->bootstrapWith([
        Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
    ]);
    if ($app->configurationIsCached() || realpath($app->basePath()) !== realpath($base)) { exit(1); }
    $config = $app->make("config");
    if ($config->get("database.default") !== "mysql") { $refused[] = "database.default"; }
    $mysql = $config->get("database.connections.mysql");
    if (!is_array($mysql)) {
        $refused[] = "database.connections.mysql";
    } else {
        $checks = [
            "driver" => ($mysql["driver"] ?? null) === "mysql",
            "url" => in_array($mysql["url"] ?? null, [null, ""], true),
            "host" => ($mysql["host"] ?? null) === "127.0.0.1",
            "port" => in_array($mysql["port"] ?? null, ["3306", 3306], true),
            "unix_socket" => in_array($mysql["unix_socket"] ?? null, [null, ""], true),
            "read" => !array_key_exists("read", $mysql),
            "write" => !array_key_exists("write", $mysql),
        ];
        foreach ($checks as $key => $ok) { if (!$ok) { $refused[] = "database.connections.mysql.".$key; } }
    }
    $selectors = ["queue.batching.database", "queue.failed.database", "session.connection", "passport.connection"];
    foreach ((array) $config->get("cache.stores", []) as $name => $store) {
        if (is_array($store) && ($store["driver"] ?? null) === "database") {
            array_push($selectors, "cache.stores.$name.connection", "cache.stores.$name.lock_connection");
        }
    }
    foreach ((array) $config->get("queue.connections", []) as $name => $queue) {
        if (is_array($queue) && ($queue["driver"] ?? null) === "database") { $selectors[] = "queue.connections.$name.connection"; }
    }
    foreach ($selectors as $selector) { if (!$local($config->get($selector))) { $refused[] = $selector; } }
    foreach ((array) $config->get("database.redis", []) as $name => $redis) {
        if ($name === "client" || $name === "options") { continue; }
        if (!is_array($redis) || $name === "clusters" || !in_array($redis["url"] ?? null, [null, ""], true)
            || ($redis["host"] ?? null) !== "127.0.0.1" || !in_array($redis["port"] ?? null, ["6379", 6379], true)) {
            $refused[] = "database.redis.".$name;
        }
    }
} catch (Throwable $error) { exit(1); }
if ($refused !== []) { fwrite(STDERR, "Refused settings: ".implode(", ", $refused).PHP_EOL); exit(1); }
') || {
        echo 'The resolved candidate configuration must use only the local MySQL server; refusing to migrate.' >&2
        exit 1
    }
    echo 'Resolved candidate database configuration uses only the local server.'
}
pilot_assert_local_database "$@"
