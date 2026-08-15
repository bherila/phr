<?php

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class BlobsScriptTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_scaffolding_files_do_not_bypass_empty_mirror_guard(): void
    {
        $xData = $this->temporaryDirectory();
        mkdir($xData.'/phr', 0700, true);
        file_put_contents($xData.'/phr/.gitignore', "*\n");
        file_put_contents($xData.'/phr/.DS_Store', 'scaffold');

        $process = $this->runScript(['push', '--apply', '--prune'], $xData);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('local mirror is empty', $process->getErrorOutput());
    }

    public function test_verify_counts_bytes_portably_on_linux(): void
    {
        $xData = $this->temporaryDirectory();
        mkdir($xData.'/phr', 0700, true);
        file_put_contents($xData.'/phr/payload.bin', 'data');
        file_put_contents($xData.'/phr/.gitignore', "*\n");

        $bin = $this->temporaryDirectory();
        $ssh = $bin.'/ssh';
        file_put_contents($ssh, "#!/bin/sh\nprintf '1\\n4\\n'\n");
        chmod($ssh, 0700);

        $process = $this->runScript(['verify'], $xData, $bin);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('x-data  1 files, 4 bytes', $process->getOutput());
        $this->assertStringContainsString('match', $process->getOutput());
    }

    public function test_pull_forces_private_modes_after_archive_sync(): void
    {
        $xData = $this->temporaryDirectory();
        $bin = $this->temporaryDirectory();
        $rsync = $bin.'/rsync';
        file_put_contents($rsync, <<<'SH'
#!/bin/sh
destination=''
private_modes=0
for argument do
    destination="$argument"
    [ "$argument" = '--chmod=Du=rwx,Dgo=,Fu=rw,Fgo=' ] && private_modes=1
done
[ "$private_modes" -eq 1 ] || exit 9

destination=${destination%/}
mkdir -p "$destination/nested"
touch "$destination/nested/payload.bin"
chmod 0755 "$destination" "$destination/nested"
chmod 0644 "$destination/nested/payload.bin"
SH);
        chmod($rsync, 0700);

        $process = $this->runScript(['pull', '--apply'], $xData, $bin);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        clearstatcache();
        $this->assertSame(0700, fileperms($xData.'/phr') & 0777);
        $this->assertSame(0700, fileperms($xData.'/phr/nested') & 0777);
        $this->assertSame(0600, fileperms($xData.'/phr/nested/payload.bin') & 0777);
    }

    /** @param list<string> $arguments */
    private function runScript(array $arguments, string $xData, ?string $bin = null): Process
    {
        $projectRoot = dirname(__DIR__, 3);
        $path = $bin === null ? (string) getenv('PATH') : $bin.':'.getenv('PATH');
        $process = new Process(
            ['bash', $projectRoot.'/scripts/blobs.sh', ...$arguments],
            $projectRoot,
            ['X_DATA_DIR' => $xData, 'PATH' => $path],
        );
        $process->run();

        return $process;
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/phr-blobs-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
