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
    # Resolve .env, the process environment, config/*.php and the package defaults that providers
    # merge at registration, exactly as the uncached migration will. Providers are registered but
    # not booted, so nothing connects before the check passes.
    # shellcheck disable=SC2016 # PHP variable names must remain literal.
    (cd "$root" && "$php" -d memory_limit=1G -d display_errors=0 -d log_errors=0 -r '
$pilot = "mariadb";
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
        Illuminate\Foundation\Bootstrap\RegisterFacades::class,
        Illuminate\Foundation\Bootstrap\RegisterProviders::class,
    ]);
    if ($app->configurationIsCached() || realpath($app->basePath()) !== realpath($base)) { exit(1); }
    $config = $app->make("config");
    if ($config->get("database.default") !== $pilot) { $refused[] = "database.default"; }
    $connection = $config->get("database.connections.".$pilot);
    if (!is_array($connection)) {
        $refused[] = "database.connections.".$pilot;
    } else {
        $checks = [
            "driver" => ($connection["driver"] ?? null) === $pilot,
            "url" => in_array($connection["url"] ?? null, [null, ""], true),
            "host" => ($connection["host"] ?? null) === "127.0.0.1",
            "port" => in_array($connection["port"] ?? null, ["3306", 3306], true),
            "unix_socket" => in_array($connection["unix_socket"] ?? null, [null, ""], true),
            "read" => !array_key_exists("read", $connection),
            "write" => !array_key_exists("write", $connection),
        ];
        foreach ($checks as $key => $ok) { if (!$ok) { $refused[] = "database.connections.".$pilot.".".$key; } }
    }
    // Any subsystem may name its own database connection (queue, cache, session, OAuth,
    // delegated access, identity tombstones, ...): every resolved selector must use the pilot one.
    $names = array_keys((array) $config->get("database.connections", []));
    $selectors = static function (array $node, string $path) use (&$selectors, &$refused, $names, $pilot): void {
        foreach ($node as $key => $value) {
            $here = $path === "" ? (string) $key : $path.".".$key;
            if ($here === "database.connections" || $here === "database.redis") { continue; }
            if (is_array($value)) { $selectors($value, $here); continue; }
            if (is_string($key) && preg_match("/(^|_)connection\$|^database\$/", $key) === 1
                && is_string($value) && in_array($value, $names, true) && $value !== $pilot) { $refused[] = $here; }
        }
    };
    $selectors($config->all(), "");
    // Redis is local over an absolute Unix socket (port 0 or empty) or on 127.0.0.1:6379.
    $localRedis = static function ($host, $port): bool {
        $socket = is_string($host) && str_starts_with($host, "/") && !in_array("..", explode("/", $host), true);

        return ($socket && in_array($port, [0, "0", "", null], true))
            || ($host === "127.0.0.1" && in_array($port, [6379, "6379"], true));
    };
    foreach ((array) $config->get("database.redis", []) as $name => $redis) {
        if ($name === "client" || $name === "options") { continue; }
        if (!is_array($redis) || $name === "clusters" || !in_array($redis["url"] ?? null, [null, ""], true)
            || !$localRedis($redis["host"] ?? null, $redis["port"] ?? null)) {
            $refused[] = "database.redis.".$name;
        }
    }
} catch (Throwable $error) { exit(1); }
if ($refused !== []) { fwrite(STDERR, "Refused settings: ".implode(", ", $refused).PHP_EOL); exit(1); }
') || {
        echo 'The resolved candidate configuration must use only the local MariaDB server; refusing to migrate.' >&2
        exit 1
    }
    echo 'Resolved candidate database configuration uses only the local server.'
}
pilot_assert_local_database "$@"
