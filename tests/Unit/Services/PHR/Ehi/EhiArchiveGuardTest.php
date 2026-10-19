<?php

namespace Tests\Unit\Services\PHR\Ehi;

use App\Models\PhrEhiPackage;
use App\Services\PHR\Import\Ehi\EhiArchiveGuard;
use App\Services\PHR\Import\Ehi\EhiDirectorySource;
use App\Services\PHR\Import\Ehi\EhiEntry;
use App\Services\PHR\Import\Ehi\EhiLimits;
use App\Services\PHR\Import\Ehi\EhiPackageRejected;
use App\Services\PHR\Import\Ehi\EhiPackageSource;
use App\Services\PHR\Import\Ehi\EhiValidatedMember;
use App\Services\PHR\Import\Ehi\EhiZipSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class EhiArchiveGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ehi-guard-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_directory_and_repacked_zips_converge_on_the_same_members_and_digest(): void
    {
        $files = $this->syntheticPackage();
        $directory = $this->writeTree('extracted', $files);
        $deflated = $this->writeZip('deflated.zip', $files, ZipArchive::CM_DEFLATE);
        $stored = $this->writeZip('stored.zip', array_reverse($files, true), ZipArchive::CM_STORE, withDirectories: false);

        $guard = new EhiArchiveGuard;
        $results = [];
        foreach ([new EhiDirectorySource($directory), new EhiZipSource($deflated), new EhiZipSource($stored)] as $source) {
            try {
                $members = $guard->validate($source, $this->limits());
                $results[] = [
                    'paths' => array_map(static fn (EhiValidatedMember $member): array => [$member->path, $member->entry->declaredBytes], $members),
                    'digest' => $guard->manifestDigest($this->copyAll($guard, $source, $members)),
                    'original' => $source->originalArchive(),
                    'kind' => $source->kind(),
                ];
            } finally {
                $source->close();
            }
        }

        $expectedPaths = array_keys($files);
        sort($expectedPaths, SORT_STRING);
        self::assertSame($expectedPaths, array_column($results[0]['paths'], 0));
        self::assertSame($results[0]['paths'], $results[1]['paths']);
        self::assertSame($results[0]['paths'], $results[2]['paths']);
        self::assertSame($results[0]['digest'], $results[1]['digest']);
        self::assertSame($results[0]['digest'], $results[2]['digest']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $results[0]['digest']);

        self::assertSame(PhrEhiPackage::INTAKE_DIRECTORY, $results[0]['kind']);
        self::assertSame(PhrEhiPackage::INTAKE_ZIP, $results[1]['kind']);
        self::assertNull($results[0]['original']);
        self::assertSame(['sha256' => hash_file('sha256', $deflated), 'bytes' => filesize($deflated)], $results[1]['original']);
        self::assertNotSame($results[1]['original'], $results[2]['original']);
    }

    public function test_manifest_digest_is_order_independent_and_content_sensitive(): void
    {
        $guard = new EhiArchiveGuard;
        $a = ['path' => 'a.tsv', 'bytes' => 3, 'sha256' => hash('sha256', 'one')];
        $b = ['path' => "tables/\u{00E9}/b.tsv", 'bytes' => 3, 'sha256' => hash('sha256', 'two')];

        self::assertSame($guard->manifestDigest([$a, $b]), $guard->manifestDigest([$b, $a]));
        self::assertNotSame($guard->manifestDigest([$a, $b]), $guard->manifestDigest([$a, ['sha256' => hash('sha256', 'TWO')] + $b]));
        self::assertNotSame($guard->manifestDigest([$a]), $guard->manifestDigest([$a, $b]));
    }

    /** @return array<string, array{string}> */
    public static function unsafeZipNames(): array
    {
        return [
            'traversal' => ['../escape.tsv'],
            'nested traversal' => ['tables/../../escape.tsv'],
            'absolute' => ['/escape.tsv'],
            'backslash' => ['tables\\escape.tsv'],
            'empty segment' => ['tables//escape.tsv'],
            'dot segment' => ['./escape.tsv'],
            'drive letter' => ['C:/escape.tsv'],
            'directory entry with content' => ['tables/escape.tsv/'],
            'unsafe directory entry' => ['../'],
        ];
    }

    #[DataProvider('unsafeZipNames')]
    public function test_unsafe_zip_paths_are_refused(string $name): void
    {
        $zip = $this->writeZip('unsafe.zip', ['ok.tsv' => "A\n", $name => $name === '../' ? '' : "B\n"]);

        $this->assertRefused(new EhiZipSource($zip), EhiPackageRejected::PATH_UNSAFE, ['escape']);
    }

    /** @return array<string, array{string}> */
    public static function unsafeRawNames(): array
    {
        return [
            'nul' => ["escape\0.tsv"],
            'control character' => ["escape\n.tsv"],
            'delete character' => ["escape\x7F.tsv"],
            'invalid utf-8' => ["escape\xFF.tsv"],
            'empty' => [''],
        ];
    }

    #[DataProvider('unsafeRawNames')]
    public function test_unsafe_raw_paths_are_refused(string $name): void
    {
        $this->assertRefused($this->fakeSource([new EhiEntry('ok.tsv', 1), new EhiEntry($name, 1)]), EhiPackageRejected::PATH_UNSAFE, ['escape']);
    }

    public function test_directory_symlinks_are_reported_and_never_followed(): void
    {
        $outside = $this->writeTree('outside', ['secret.tsv' => "OUTSIDE\n"]);
        $directory = $this->writeTree('extracted', ['ok.tsv' => "A\n"]);
        symlink($outside.'/secret.tsv', $directory.'/linked-file.tsv');
        symlink($outside, $directory.'/linked-dir');

        $source = new EhiDirectorySource($directory);
        $paths = array_map(static fn (EhiEntry $entry): string => $entry->originalPath, $source->entries());
        self::assertSame(['linked-dir', 'linked-file.tsv', 'ok.tsv'], $paths);
        self::assertTrue($source->entries()[0]->isSymlink);
        self::assertFalse($source->entries()[0]->isDirectory);

        $this->assertRefused($source, EhiPackageRejected::SYMLINK, ['linked', 'secret', $outside]);
    }

    public function test_zip_symlinks_are_refused(): void
    {
        $zip = $this->writeZip('link.zip', ['ok.tsv' => "A\n", 'linked.tsv' => '/etc/passwd'], attributes: ['linked.tsv' => 0120777]);

        $this->assertRefused(new EhiZipSource($zip), EhiPackageRejected::SYMLINK, ['linked', 'passwd']);
    }

    public function test_special_files_are_refused(): void
    {
        $zip = $this->writeZip('fifo.zip', ['ok.tsv' => "A\n", 'pipe.tsv' => ''], attributes: ['pipe.tsv' => 0010644]);
        $this->assertRefused(new EhiZipSource($zip), EhiPackageRejected::SPECIAL_FILE, ['pipe']);

        if (! function_exists('posix_mkfifo')) {
            return;
        }
        $directory = $this->writeTree('extracted', ['ok.tsv' => "A\n"]);
        self::assertTrue(posix_mkfifo($directory.'/pipe.tsv', 0600));
        $this->assertRefused(new EhiDirectorySource($directory), EhiPackageRejected::SPECIAL_FILE, ['pipe']);
    }

    public function test_encrypted_zip_entries_are_refused(): void
    {
        $path = $this->root.'/encrypted.zip';
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('ok.tsv', "A\n");
        $zip->addFromString('locked.tsv', "B\n");
        self::assertTrue($zip->setEncryptionName('locked.tsv', ZipArchive::EM_AES_256, 'synthetic-password'));
        $zip->close();

        $this->assertRefused(new EhiZipSource($path), EhiPackageRejected::ENCRYPTED, ['locked']);
    }

    public function test_duplicate_zip_names_are_refused(): void
    {
        $path = $this->writeZip('dup.zip', ['first.tsv' => "A\n", 'other.tsv' => "B\n"]);
        // Same-length rename in both headers yields a genuine duplicate central directory name.
        file_put_contents($path, str_replace('other.tsv', 'first.tsv', (string) file_get_contents($path)));

        $source = new EhiZipSource($path);
        self::assertCount(2, $source->entries());
        $this->assertRefused($source, EhiPackageRejected::PATH_COLLISION, ['first']);
    }

    public function test_unicode_normalization_collisions_are_refused(): void
    {
        $nfc = "\u{00E9}.tsv";
        $nfd = "e\u{0301}.tsv";

        $zip = $this->writeZip('nfc.zip', [$nfc => "A\n", $nfd => "B\n"]);
        $this->assertRefused(new EhiZipSource($zip), EhiPackageRejected::PATH_COLLISION, [$nfc, $nfd]);

        $directory = $this->writeTree('extracted', [$nfc => "A\n", $nfd => "B\n"]);
        $this->assertRefused(new EhiDirectorySource($directory), EhiPackageRejected::PATH_COLLISION, [$nfc, $nfd]);
    }

    public function test_nfd_names_are_normalized_to_nfc(): void
    {
        $directory = $this->writeTree('extracted', ["caf\u{0065}\u{0301}.tsv" => "A\n"]);

        $members = (new EhiArchiveGuard)->validate(new EhiDirectorySource($directory), $this->limits());

        self::assertSame(["caf\u{00E9}.tsv"], array_map(static fn (EhiValidatedMember $member): string => $member->path, $members));
        self::assertSame("caf\u{0065}\u{0301}.tsv", $members[0]->entry->originalPath);
    }

    public function test_case_collisions_are_refused(): void
    {
        $directory = $this->writeTree('extracted', ['A.tsv' => "A\n", 'a.tsv' => "B\n"]);
        $this->assertRefused(new EhiDirectorySource($directory), EhiPackageRejected::PATH_COLLISION, ['A.tsv', 'a.tsv']);

        $zip = $this->writeZip('case.zip', ['Tables/x.tsv' => "A\n", 'tables/X.tsv' => "B\n"]);
        $this->assertRefused(new EhiZipSource($zip), EhiPackageRejected::PATH_COLLISION, ['tables', 'x.tsv']);
    }

    public function test_a_file_named_like_a_directory_is_refused(): void
    {
        $zip = $this->writeZip('shadow.zip', ['tables' => "A\n", 'Tables/x.tsv' => "B\n"], withDirectories: false);

        $this->assertRefused(new EhiZipSource($zip), EhiPackageRejected::PATH_COLLISION, ['tables']);
    }

    public function test_directory_entries_are_allowed_but_not_returned(): void
    {
        $zip = $this->writeZip('dirs.zip', ['tables/nested/x.tsv' => "A\n"]);
        $source = new EhiZipSource($zip);
        self::assertCount(3, $source->entries());

        $members = (new EhiArchiveGuard)->validate($source, $this->limits());

        self::assertSame(['tables/nested/x.tsv'], array_map(static fn (EhiValidatedMember $member): string => $member->path, $members));
        $this->assertRefused($this->fakeSource([new EhiEntry('stuffed/', 5, isDirectory: true)]), EhiPackageRejected::PATH_UNSAFE, ['stuffed']);
    }

    public function test_member_count_depth_and_path_length_limits(): void
    {
        $directory = $this->writeTree('extracted', ['one.tsv' => '1', 'two.tsv' => '2', 'three.tsv' => '3']);
        $this->assertRefused(new EhiDirectorySource($directory), EhiPackageRejected::TOO_MANY_MEMBERS, ['one', 'two', 'three'], $this->limits(maxMembers: 2));

        $deep = $this->writeTree('deep', ['a/b/c/d/leaf.tsv' => '1']);
        $this->assertRefused(new EhiDirectorySource($deep), EhiPackageRejected::PATH_TOO_DEEP, ['leaf'], $this->limits(maxPathDepth: 4));
        self::assertCount(1, (new EhiArchiveGuard)->validate(new EhiDirectorySource($deep), $this->limits(maxPathDepth: 5)));

        $long = str_repeat('n', 40).'.tsv';
        $this->assertRefused($this->fakeSource([new EhiEntry($long, 1)]), EhiPackageRejected::PATH_TOO_LONG, [$long], $this->limits(maxPathBytes: 32));
    }

    public function test_zip_bombs_are_refused_by_expansion_ratio(): void
    {
        $zip = $this->writeZip('bomb.zip', ['ok.tsv' => "A\n", 'bomb.tsv' => str_repeat("\t", 4 * 1024 * 1024)]);
        $source = new EhiZipSource($zip);
        $bomb = $source->entries()[1];
        self::assertGreaterThan(100 * $bomb->compressedBytes, $bomb->declaredBytes);

        $this->assertRefused($source, EhiPackageRejected::EXPANSION_RATIO, ['bomb'], $this->limits(maxExpansionRatio: 10));
    }

    public function test_package_wide_expansion_ratio_is_enforced(): void
    {
        // Each member is under the ratio on its own; together they are not.
        $entries = [];
        for ($i = 0; $i < 4; $i++) {
            $entries[] = new EhiEntry("part{$i}.tsv", 1024 * 1024, compressedBytes: 110 * 1024);
        }
        $entries[] = new EhiEntry('tiny.tsv', 512 * 1024, compressedBytes: 1);

        $this->assertRefused($this->fakeSource($entries), EhiPackageRejected::EXPANSION_RATIO, ['part', 'tiny'], $this->limits(maxExpansionRatio: 10));
    }

    public function test_member_and_package_size_limits(): void
    {
        $directory = $this->writeTree('extracted', ['big.tsv' => str_repeat('x', 100), 'small.tsv' => 'x']);
        $this->assertRefused(new EhiDirectorySource($directory), EhiPackageRejected::MEMBER_TOO_LARGE, ['big'], $this->limits(maxMemberBytes: 99));
        $this->assertRefused(new EhiDirectorySource($directory), EhiPackageRejected::PACKAGE_TOO_LARGE, ['big', 'small'], $this->limits(maxPackageBytes: 100));
        self::assertCount(2, (new EhiArchiveGuard)->validate(new EhiDirectorySource($directory), $this->limits(maxMemberBytes: 100, maxPackageBytes: 101)));
    }

    public function test_copy_bounded_hashes_and_rejects_size_lies(): void
    {
        $guard = new EhiArchiveGuard;
        $payload = str_repeat('0123456789', 300000);

        $destination = $this->memoryStream('');
        $copied = $guard->copyBounded($this->memoryStream($payload), $destination, strlen($payload), strlen($payload));
        self::assertSame(['sha256' => hash('sha256', $payload), 'bytes' => strlen($payload)], $copied);
        rewind($destination);
        self::assertSame($payload, stream_get_contents($destination));

        $this->assertCopyRefused(fn () => $guard->copyBounded($this->memoryStream($payload), $this->memoryStream(''), strlen($payload) + 1, PHP_INT_MAX), EhiPackageRejected::SIZE_MISMATCH);
        $this->assertCopyRefused(fn () => $guard->copyBounded($this->memoryStream($payload), $this->memoryStream(''), strlen($payload) - 1, PHP_INT_MAX), EhiPackageRejected::SIZE_MISMATCH);

        // An entry larger than the cap is never read past cap + 1.
        $source = $this->memoryStream($payload);
        $this->assertCopyRefused(fn () => $guard->copyBounded($source, $this->memoryStream(''), strlen($payload), 1000), EhiPackageRejected::MEMBER_TOO_LARGE);
        self::assertSame(1001, ftell($source));
    }

    public function test_directory_open_rechecks_the_entry_after_listing(): void
    {
        $outside = $this->writeTree('outside', ['secret.tsv' => "OUTSIDE\n"]);
        $directory = $this->writeTree('extracted', ['swapped.tsv' => "A\n", 'replaced.tsv' => "B\n", 'stable.tsv' => "C\n"]);
        $source = new EhiDirectorySource($directory);
        $members = [];
        foreach ((new EhiArchiveGuard)->validate($source, $this->limits()) as $member) {
            $members[$member->path] = $member->entry;
        }

        unlink($directory.'/swapped.tsv');
        symlink($outside.'/secret.tsv', $directory.'/swapped.tsv');
        // Written before the rename so the replacement cannot reuse the old inode.
        file_put_contents($directory.'/replacement.tmp', "B\n");
        rename($directory.'/replacement.tmp', $directory.'/replaced.tsv');

        $this->assertCopyRefused(fn () => $source->open($members['swapped.tsv']), EhiPackageRejected::SYMLINK);
        $this->assertCopyRefused(fn () => $source->open($members['replaced.tsv']), EhiPackageRejected::UNREADABLE);
        $this->assertCopyRefused(fn () => $source->open(new EhiEntry('../outside/secret.tsv', 8, handle: $outside.'/secret.tsv')), EhiPackageRejected::UNREADABLE);

        $stream = $source->open($members['stable.tsv']);
        self::assertSame("C\n", stream_get_contents($stream));
        fclose($stream);
    }

    public function test_zip_open_refuses_entries_it_did_not_list(): void
    {
        $source = new EhiZipSource($this->writeZip('one.zip', ['ok.tsv' => "A\n"], withDirectories: false));

        $this->assertCopyRefused(fn () => $source->open(new EhiEntry('other.tsv', 2, handle: 0)), EhiPackageRejected::UNREADABLE);
        $this->assertCopyRefused(fn () => $source->open(new EhiEntry('ok.tsv', 2, handle: 7)), EhiPackageRejected::UNREADABLE);
        $source->close();
    }

    /** @return array<string, string> */
    private function syntheticPackage(): array
    {
        return [
            'README.txt' => "Synthetic package for tests.\n",
            'dictionary/index.htm' => "<html><body><table><tr><td>ALPHA</td></tr></table></body></html>\n",
            'tables/ALPHA.tsv' => "ALPHA_ID\tVALUE\n0001\tsynthetic\n0002\t\n",
            'tables/BETA.tsv' => "BETA_ID\n",
            'tables/empty.tsv' => '',
            "tables/caf\u{00E9}.tsv" => "COL\n".str_repeat("row\n", 2000),
        ];
    }

    /** @param array<string, string> $files */
    private function writeTree(string $name, array $files): string
    {
        $base = $this->root.'/'.$name;
        mkdir($base, 0700, true);
        foreach ($files as $path => $contents) {
            $target = $base.'/'.$path;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            file_put_contents($target, $contents);
        }

        return $base;
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, int>  $attributes  unix mode per entry name
     */
    private function writeZip(string $name, array $files, int $compression = ZipArchive::CM_DEFLATE, bool $withDirectories = true, array $attributes = []): string
    {
        $path = $this->root.'/'.$name;
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $directories = [];
        foreach ($files as $entry => $contents) {
            $segments = explode('/', rtrim($entry, '/'));
            // Implied directory entries only for well-formed paths; hostile names stand alone.
            if ($withDirectories && array_intersect($segments, ['', '.', '..']) === []) {
                for ($depth = 1; $depth < count($segments); $depth++) {
                    $directory = implode('/', array_slice($segments, 0, $depth));
                    if (! isset($directories[$directory])) {
                        $directories[$directory] = true;
                        $zip->addEmptyDir($directory);
                    }
                }
            }
            if (str_ends_with($entry, '/') && $contents === '') {
                $zip->addEmptyDir(rtrim($entry, '/'));

                continue;
            }
            self::assertTrue($zip->addFromString($entry, $contents));
            self::assertTrue($zip->setCompressionName($entry, $compression));
            if (isset($attributes[$entry])) {
                self::assertTrue($zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, $attributes[$entry] << 16));
            }
        }
        self::assertTrue($zip->close());

        return $path;
    }

    /** @param list<EhiEntry> $entries */
    private function fakeSource(array $entries): EhiPackageSource
    {
        return new class($entries) implements EhiPackageSource
        {
            /** @param list<EhiEntry> $entries */
            public function __construct(private readonly array $entries) {}

            public function kind(): string
            {
                return PhrEhiPackage::INTAKE_ZIP;
            }

            public function entries(): array
            {
                return $this->entries;
            }

            public function open(EhiEntry $entry)
            {
                throw new EhiPackageRejected(EhiPackageRejected::UNREADABLE, 'not readable');
            }

            public function originalArchive(): ?array
            {
                return null;
            }

            public function close(): void {}
        };
    }

    /**
     * @param  list<EhiValidatedMember>  $members
     * @return list<array{path: string, bytes: int, sha256: string}>
     */
    private function copyAll(EhiArchiveGuard $guard, EhiPackageSource $source, array $members): array
    {
        $manifest = [];
        foreach ($members as $member) {
            $stream = $source->open($member->entry);
            $destination = $this->memoryStream('');
            try {
                $copied = $guard->copyBounded($stream, $destination, $member->entry->declaredBytes, PHP_INT_MAX);
            } finally {
                fclose($stream);
                fclose($destination);
            }
            $manifest[] = ['path' => $member->path] + $copied;
        }

        return $manifest;
    }

    /** @param list<string> $forbidden fragments that must never appear in the message */
    private function assertRefused(EhiPackageSource $source, string $reason, array $forbidden, ?EhiLimits $limits = null): void
    {
        try {
            (new EhiArchiveGuard)->validate($source, $limits ?? $this->limits());
            self::fail("Expected the package to be refused with {$reason}.");
        } catch (EhiPackageRejected $exception) {
            self::assertSame($reason, $exception->reason);
            $this->assertStructuralMessage($exception, $forbidden);
        } finally {
            $source->close();
        }
    }

    private function assertCopyRefused(callable $operation, string $reason): void
    {
        try {
            $operation();
            self::fail("Expected the operation to be refused with {$reason}.");
        } catch (EhiPackageRejected $exception) {
            self::assertSame($reason, $exception->reason);
            $this->assertStructuralMessage($exception, ['.tsv', $this->root]);
        }
    }

    /** @param list<string> $forbidden */
    private function assertStructuralMessage(EhiPackageRejected $exception, array $forbidden): void
    {
        $message = $exception->getMessage();
        self::assertStringNotContainsString($this->root, $message);
        self::assertStringNotContainsString('.tsv', $message);
        foreach ($forbidden as $fragment) {
            if ($fragment !== '') {
                self::assertStringNotContainsStringIgnoringCase($fragment, $message);
            }
        }
    }

    private function limits(
        int $maxPackageBytes = 1024 * 1024 * 1024,
        int $maxMemberBytes = 512 * 1024 * 1024,
        int $maxMembers = 1000,
        int $maxExpansionRatio = 200,
        int $maxPathDepth = 16,
        int $maxPathBytes = 512,
    ): EhiLimits {
        return new EhiLimits(
            maxPackageBytes: $maxPackageBytes,
            maxMemberBytes: $maxMemberBytes,
            maxMembers: $maxMembers,
            maxExpansionRatio: $maxExpansionRatio,
            maxPathDepth: $maxPathDepth,
            maxPathBytes: $maxPathBytes,
            maxRowBytes: 1024 * 1024,
            maxValueBytes: 1024 * 1024,
            maxDictionaryBytes: 1024 * 1024,
            maxFindingsPerMember: 100,
        );
    }

    /** @return resource */
    private function memoryStream(string $contents)
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path.'/'.$name);
            }
        }
        rmdir($path);
    }
}
