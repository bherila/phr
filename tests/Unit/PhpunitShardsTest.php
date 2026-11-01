<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class PhpunitShardsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        require_once __DIR__.'/../../scripts/phpunit-shards.php';
        $this->directory = sys_get_temp_dir().'/phr-shards-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    private function fixture(): array
    {
        $inventory = $this->directory.'/inventory.xml';
        file_put_contents($inventory, <<<'XML'
<testSuite xmlns="https://xml.phpunit.de/testSuite"><tests>
<testClass name="Heavy"><testMethod id="Heavy::one"/><testMethod id="Heavy::two#named &amp; quoted &quot;set&quot;"/></testClass>
<testClass name="Medium"><testMethod id="Medium::one#0"/><testMethod id="Medium::one#-1"/></testClass>
<testClass name="New"><testMethod id="New::one#01"/></testClass>
</tests></testSuite>
XML);
        $weights = $this->directory.'/weights.json';
        shardWriteJson($weights, ['version' => 1, 'classes' => [
            'Heavy' => ['seconds' => 20, 'tests' => 2],
            'Medium' => ['seconds' => 10, 'tests' => 2],
            'Removed' => ['seconds' => 100, 'tests' => 1],
        ]]);
        // JUnit's names differ from inventory IDs. Use independent fixtures so
        // a broken dataset-name conversion cannot validate its own output.
        $reportNames = [
            'Heavy::one' => 'one',
            'Heavy::two#named & quoted "set"' => 'two with data set "named & quoted "set""',
            'Medium::one#0' => 'one with data set #0',
            'Medium::one#-1' => 'one with data set #-1',
            'New::one#01' => 'one with data set "01"',
        ];
        ob_start();
        try {
            foreach ([1, 2] as $index) {
                $path = $this->directory.'/phpunit-shard-'.$index;
                shardPlan($inventory, $weights, 2, $index, $path);
                $report = new \SimpleXMLElement('<testsuites/>');
                foreach (shardJson($path.'/plan.json')['selected'] as $id) {
                    [$class] = explode('::', $id, 2);
                    $case = $report->addChild('testcase');
                    $case->addAttribute('class', $class);
                    $case->addAttribute('name', $reportNames[$id]);
                    $case->addAttribute('time', '0.25');
                }
                $report->asXML($path.'/junit.xml');
            }
        } finally {
            ob_end_clean();
        }

        return [$inventory, $weights];
    }

    public function test_every_discovered_case_is_assigned_once_and_classes_stay_together(): void
    {
        [$inventory, $weights] = $this->fixture();
        $first = shardJson($this->directory.'/phpunit-shard-1/plan.json');
        $second = shardJson($this->directory.'/phpunit-shard-2/plan.json');
        $this->assertSame(['Heavy::one', 'Heavy::two#named & quoted "set"'], $first['selected']);
        $this->assertSame(['Medium::one#-1', 'Medium::one#0', 'New::one#01'], $second['selected']);
        $this->expectOutputRegex('/Verified all 5 tests executed exactly once/');
        shardVerify($this->directory, 2);
        // A second independent planner must produce the same partition.
        ob_start();
        shardPlan($inventory, $weights, 2, 1, $this->directory.'/repeat');
        ob_end_clean();
        $this->assertSame($first, shardJson($this->directory.'/repeat/plan.json'));
    }

    public function test_missing_reported_case_fails_verification(): void
    {
        $this->fixture();
        file_put_contents($this->directory.'/phpunit-shard-2/junit.xml', '<testsuites/>');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('executed tests differ');
        shardVerify($this->directory, 2);
    }

    public function test_duplicate_assignment_fails_verification(): void
    {
        $this->fixture();
        $path = $this->directory.'/phpunit-shard-2/plan.json';
        $plan = shardJson($path);
        $plan['selected'][] = 'Heavy::one';
        shardWriteJson($path, $plan);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate or unknown');
        shardVerify($this->directory, 2);
    }

    public function test_different_inventory_fails_verification(): void
    {
        $this->fixture();
        $path = $this->directory.'/phpunit-shard-2/plan.json';
        $plan = shardJson($path);
        $plan['all'][] = 'Unexpected::test';
        shardWriteJson($path, $plan);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inventories disagree');
        shardVerify($this->directory, 2);
    }

    public function test_missing_shard_is_refused(): void
    {
        $this->fixture();
        unlink($this->directory.'/phpunit-shard-2/plan.json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing JSON report');
        shardVerify($this->directory, 2);
    }

    public function test_duplicate_discovery_is_refused(): void
    {
        $path = $this->directory.'/inventory.xml';
        file_put_contents($path, '<testSuite><tests><testClass name="A"><testMethod id="A::test"/><testMethod id="A::test"/></testClass></tests></testSuite>');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate PHPUnit');
        shardInventory($path);
    }

    public function test_empty_discovery_is_refused(): void
    {
        $path = $this->directory.'/empty.xml';
        file_put_contents($path, '<testSuite/>');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('discovered no tests');
        shardInventory($path);
    }

    public function test_unsupported_inventory_entries_cannot_be_silently_skipped(): void
    {
        $path = $this->directory.'/unsupported.xml';
        file_put_contents($path, '<testSuite><tests><testClass name="A"><testMethod id="A::test"/></testClass><phpt file="new.phpt"/></tests></testSuite>');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported PHPUnit inventory');
        shardInventory($path);
    }

    public function test_weights_can_be_refreshed_from_reports_without_changing_discovery(): void
    {
        $this->fixture();
        $output = $this->directory.'/updated.json';
        shardWeights($output, [
            $this->directory.'/phpunit-shard-1/junit.xml',
            $this->directory.'/phpunit-shard-2/junit.xml',
        ]);
        $this->assertSame([
            'Heavy' => ['seconds' => 0.5, 'tests' => 2],
            'Medium' => ['seconds' => 0.5, 'tests' => 2],
            'New' => ['seconds' => 0.25, 'tests' => 1],
        ], shardJson($output)['classes']);
    }

    public function test_filter_pattern_selects_exactly_the_planned_ids(): void
    {
        $inventory = $this->directory.'/namespaced.xml';
        file_put_contents($inventory, <<<'XML'
<testSuite xmlns="https://xml.phpunit.de/testSuite"><tests>
<testClass name="Tests\Feature\Foo"><testMethod id="Tests\Feature\Foo::a"/><testMethod id="Tests\Feature\Foo::b#0"/></testClass>
<testClass name="Tests\Feature\FooBar"><testMethod id="Tests\Feature\FooBar::a"/></testClass>
<testClass name="Tests\Unit\Baz.Qux"><testMethod id="Tests\Unit\Baz.Qux::a"/></testClass>
<testClass name="Tests\Unit\BazXQux"><testMethod id="Tests\Unit\BazXQux::a"/></testClass>
</tests></testSuite>
XML);
        $weights = $this->directory.'/namespaced.json';
        shardWriteJson($weights, ['version' => 1, 'classes' => []]);
        $all = [];
        $matched = [];
        ob_start();
        try {
            foreach ([1, 2] as $index) {
                $output = $this->directory.'/ns-'.$index;
                shardPlan($inventory, $weights, 2, $index, $output);
                $selected = shardJson($output.'/plan.json')['selected'];
                $all = shardJson($output.'/plan.json')['all'];
                $pattern = (string) file_get_contents($output.'/filter.txt');
                $matches = array_values(array_filter($all, fn ($id) => preg_match($pattern, $id) === 1));
                $this->assertSame($selected, $matches);
                $matched = array_merge($matched, $matches);
            }
        } finally {
            ob_end_clean();
        }
        sort($matched);
        $this->assertSame($all, $matched);
    }

    public function test_drift_reports_when_the_committed_timings_unbalance_the_shards(): void
    {
        [$inventory, $weights] = $this->fixture();
        $balanced = $this->directory.'/balanced.json';
        shardWriteJson($balanced, ['version' => 1, 'classes' => [
            'Heavy' => ['seconds' => 20, 'tests' => 2],
            'Medium' => ['seconds' => 10, 'tests' => 2],
            'New' => ['seconds' => 5, 'tests' => 1],
        ]]);
        $stale = $this->directory.'/stale.json';
        ob_start();
        shardDrift($inventory, $balanced, $balanced, 2, 1.1);
        $same = ob_get_clean();
        $this->assertStringContainsString('drifted=false', $same);
        $this->assertStringContainsString('ratio=1.000', $same);

        // Believing Heavy is cheap and the others costly puts Heavy beside Medium: 30s of real
        // work on one shard where the refreshed plan needs 20s.
        shardWriteJson($stale, ['version' => 1, 'classes' => [
            'Heavy' => ['seconds' => 1, 'tests' => 2],
            'Medium' => ['seconds' => 30, 'tests' => 2],
            'New' => ['seconds' => 30, 'tests' => 1],
        ]]);
        ob_start();
        shardDrift($inventory, $stale, $balanced, 2, 1.1);
        $drifted = ob_get_clean();
        $this->assertStringContainsString('drifted=true', $drifted);
    }
}
