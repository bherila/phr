<?php

// Keep discovery in PHPUnit: its inventory includes inherited tests and every
// data-provider case. Timing weights affect placement, never test inclusion.
function shardXml(string $path): SimpleXMLElement
{
    $xml = simplexml_load_file($path, options: LIBXML_NONET);
    if ($xml === false) {
        throw new RuntimeException("Cannot read XML: {$path}");
    }

    return $xml;
}

function shardJson(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException("Missing JSON report: {$path}");
    }

    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function shardWriteJson(string $path, array $value): void
{
    if (file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
        throw new RuntimeException("Cannot write {$path}");
    }
}

function shardInventory(string $path): array
{
    $xml = shardXml($path);
    if ($xml->xpath('//*[local-name()="tests"]/*[local-name()!="testClass"]')) {
        throw new RuntimeException('Unsupported PHPUnit inventory entry; update the shard planner before adding PHPT tests.');
    }
    $classes = [];
    $seen = [];
    foreach ($xml->xpath('//*[local-name()="testClass"]') as $class) {
        $name = (string) $class['name'];
        foreach ($class->children() as $method) {
            $id = (string) $method['id'];
            if ($name === '' || ! str_starts_with($id, $name.'::') || isset($seen[$id]) || str_contains($id, "\n") || str_contains($id, "\r")) {
                throw new RuntimeException('Invalid or duplicate PHPUnit test ID.');
            }
            $seen[$id] = true;
            $classes[$name][] = $id;
        }
    }
    if ($classes === []) {
        throw new RuntimeException('PHPUnit discovered no tests.');
    }
    ksort($classes);

    return $classes;
}

function shardReportKey(string $id): string
{
    [$method, $dataset] = array_pad(explode('#', $id, 2), 2, null);
    if ($dataset === null) {
        return $method;
    }

    return $method.' with data set '.((string) (int) $dataset === $dataset ? '#'.$dataset : '"'.$dataset.'"');
}

/**
 * Estimated seconds per class: measured seconds per test times the number of
 * tests PHPUnit discovered today. A class with no recorded timing is assumed to
 * cost one second per test, so it is placed rather than ignored.
 *
 * @param  array<string, list<string>>  $classes
 * @return array<string, float>
 */
function shardEstimates(array $classes, array $weights): array
{
    if (($weights['version'] ?? null) !== 1 || ! is_array($weights['classes'] ?? null)) {
        throw new RuntimeException('Invalid timing weights.');
    }
    $estimates = [];
    foreach ($classes as $class => $ids) {
        $weight = $weights['classes'][$class] ?? ['seconds' => count($ids), 'tests' => count($ids)];
        if (! is_numeric($weight['seconds'] ?? null) || $weight['seconds'] <= 0 || ! is_int($weight['tests'] ?? null) || $weight['tests'] < 1) {
            throw new RuntimeException("Invalid timing weight for {$class}");
        }
        $estimates[$class] = $weight['seconds'] / $weight['tests'] * count($ids);
    }

    return $estimates;
}

/**
 * Longest class first, with explicit tie breaks so every runner independently
 * creates the same partition. All tests in a class stay together.
 *
 * @param  array<string, float>  $estimates
 * @return list<list<string>> class names per shard
 */
function shardPartition(array $estimates, int $count): array
{
    uksort($estimates, fn ($a, $b) => ($estimates[$b] <=> $estimates[$a]) ?: strcmp($a, $b));
    $loads = array_fill(0, $count, 0.0);
    $assignments = array_fill(0, $count, []);
    foreach ($estimates as $class => $seconds) {
        $shard = array_search(min($loads), $loads, true);
        $loads[$shard] += $seconds;
        $assignments[$shard][] = $class;
    }

    return $assignments;
}

/**
 * @param  list<string>  $classNames
 * @param  array<string, float>  $estimates
 */
function shardLoad(array $classNames, array $estimates): float
{
    return array_sum(array_map(fn ($class) => $estimates[$class] ?? 0.0, $classNames));
}

/**
 * A PHPUnit --filter pattern selecting exactly the given classes. ParaTest has
 * no --test-id-filter-file, but it does pass --filter through to every worker.
 * Every test in a selected class is selected, which is what the plan assigns, and
 * `verify` still proves the executed cases equal the assigned IDs.
 *
 * @param  list<string>  $classNames
 */
function shardFilterPattern(array $classNames): string
{
    sort($classNames);

    return '/^(?:'.implode('|', array_map(fn ($class) => preg_quote($class, '/'), $classNames)).')::/';
}

function shardPlan(string $inventory, string $weightsPath, int $count, int $index, string $output): void
{
    $classes = shardInventory($inventory);
    if ($count < 1 || $count > count($classes) || $index < 1 || $index > $count) {
        throw new RuntimeException('Invalid shard count or index.');
    }
    $estimates = shardEstimates($classes, shardJson($weightsPath));
    $assignments = shardPartition($estimates, $count);
    $all = array_merge(...array_values($classes));
    sort($all);
    $selected = array_merge(...array_map(fn ($class) => $classes[$class], $assignments[$index - 1]));
    sort($selected);
    if (! is_dir($output) && ! mkdir($output, 0777, true)) {
        throw new RuntimeException("Cannot create {$output}");
    }
    shardWriteJson($output.'/plan.json', [
        'version' => 1, 'shard' => $index, 'shards' => $count,
        'all' => $all, 'selected' => $selected,
    ]);
    if (file_put_contents($output.'/test-ids.txt', implode("\n", $selected)."\n") === false) {
        throw new RuntimeException('Cannot write test selection.');
    }
    if (file_put_contents($output.'/filter.txt', shardFilterPattern($assignments[$index - 1])) === false) {
        throw new RuntimeException('Cannot write test filter.');
    }
    printf("Shard %d/%d: %d of %d tests; estimated weight %.1fs\n", $index, $count, count($selected), count($all), shardLoad($assignments[$index - 1], $estimates));
}

/**
 * How much slower the slowest shard would be under the committed timings than
 * under timings measured now. Both partitions are scored with the new numbers,
 * which are the better estimate of what CI actually spends.
 */
function shardDrift(string $inventory, string $currentPath, string $freshPath, int $count, float $threshold): void
{
    $classes = shardInventory($inventory);
    if ($count < 1 || $count > count($classes)) {
        throw new RuntimeException('Invalid shard count.');
    }
    $fresh = shardEstimates($classes, shardJson($freshPath));
    $current = shardPartition(shardEstimates($classes, shardJson($currentPath)), $count);
    $best = shardPartition($fresh, $count);
    $slowestCurrent = max(array_map(fn ($shard) => shardLoad($shard, $fresh), $current));
    $slowestBest = max(array_map(fn ($shard) => shardLoad($shard, $fresh), $best));
    $ratio = $slowestCurrent / $slowestBest;
    printf("committed_slowest_shard_seconds=%.1f\n", $slowestCurrent);
    printf("refreshed_slowest_shard_seconds=%.1f\n", $slowestBest);
    printf("ratio=%.3f\n", $ratio);
    printf("drifted=%s\n", $ratio > $threshold ? 'true' : 'false');
}

function shardVerify(string $directory, int $count): void
{
    if ($count < 1) {
        throw new RuntimeException('Invalid shard count.');
    }
    $all = null;
    $seen = [];
    foreach (range(1, $count) as $index) {
        $path = $directory.'/phpunit-shard-'.$index;
        $plan = shardJson($path.'/plan.json');
        if (($plan['version'] ?? null) !== 1 || ($plan['shard'] ?? null) !== $index || ($plan['shards'] ?? null) !== $count || ! is_array($plan['all'] ?? null) || ! is_array($plan['selected'] ?? null) || $plan['selected'] === []) {
            throw new RuntimeException("Invalid plan for shard {$index}");
        }
        $all ??= $plan['all'];
        if ($all !== $plan['all'] || count($all) !== count(array_unique($all)) || $all === []) {
            throw new RuntimeException('Shard inventories disagree or are invalid.');
        }
        $expected = [];
        foreach ($plan['selected'] as $id) {
            if (isset($seen[$id]) || ! in_array($id, $all, true)) {
                throw new RuntimeException("Duplicate or unknown assigned test: {$id}");
            }
            $seen[$id] = true;
            $expected[] = shardReportKey($id);
        }
        $actual = [];
        foreach (shardXml($path.'/junit.xml')->xpath('//testcase') as $case) {
            $actual[] = (string) $case['class'].'::'.(string) $case['name'];
        }
        sort($expected);
        sort($actual);
        if ($expected !== $actual) {
            throw new RuntimeException("Shard {$index}: executed tests differ from assigned tests.");
        }
    }
    $executed = array_keys($seen);
    sort($executed);
    if ($executed !== $all) {
        throw new RuntimeException('The shards did not cover the complete PHPUnit inventory.');
    }
    printf("Verified all %d tests executed exactly once across %d shards.\n", count($all), $count);
}

function shardWeights(string $output, array $reports): void
{
    if ($reports === []) {
        throw new RuntimeException('Supply at least one JUnit report.');
    }
    $classes = [];
    foreach ($reports as $report) {
        foreach (shardXml($report)->xpath('//testcase') as $case) {
            $class = (string) $case['class'];
            $classes[$class] ??= ['seconds' => 0.0, 'tests' => 0];
            $classes[$class]['seconds'] += (float) $case['time'];
            $classes[$class]['tests']++;
        }
    }
    if ($classes === []) {
        throw new RuntimeException('No timings found.');
    }
    ksort($classes);
    foreach ($classes as &$weight) {
        $weight['seconds'] = max(0.001, round($weight['seconds'], 3));
    }
    shardWriteJson($output, ['version' => 1, 'classes' => $classes]);
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $command = $argv[1] ?? '';
        if ($command === 'plan' && $argc === 7) {
            shardPlan($argv[2], $argv[3], (int) $argv[4], (int) $argv[5], $argv[6]);
        } elseif ($command === 'verify' && $argc === 4) {
            shardVerify($argv[2], (int) $argv[3]);
        } elseif ($command === 'drift' && $argc === 7) {
            shardDrift($argv[2], $argv[3], $argv[4], (int) $argv[5], (float) $argv[6]);
        } elseif ($command === 'weights' && $argc >= 4) {
            shardWeights($argv[2], array_slice($argv, 3));
        } else {
            throw new RuntimeException('Usage: phpunit-shards.php plan inventory.xml weights.json count index output-dir | verify reports-dir count | drift inventory.xml committed.json fresh.json count threshold | weights output.json junit.xml ...');
        }
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage()."\n");
        exit(1);
    }
}
