<?php

namespace Tests\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * The property PHR_STORAGE depends on: nothing assumes a stored object is a file on this
 * machine. An assumption that slips in passes every test on a local disk and on
 * Storage::fake, and on an object store FilesystemAdapter::path() does not even fail, it
 * returns a key that reads as a relative path that does not exist. So the sites are
 * enumerated here, and a new one fails until someone says what kind of path it is.
 */
class LocalDiskAssumptionsTest extends TestCase
{
    /**
     * Turning a disk key into a local path. Only the two classes that first check that the
     * disk is local may do it; everyone else goes through them.
     */
    private const string DISK_PATH = '/(?:->|::)path\(|(?<![>:\w$])(?<!function )link\(|StagedChunkAppender::append\(/';

    private const array DISK_PATH_SITES = [
        'app/Support/Storage/LocalObjectFile.php' => 4,
        'app/Support/Storage/StagedUpload.php' => 2,
    ];

    /** Calls that only work on a file on this machine, whatever it is. */
    private const string LOCAL_FILE = '/(?<![>:\w$])(?<!function )(?:realpath|file_exists|is_file|is_dir|is_readable|is_writable|fopen|readfile|file_get_contents|file_put_contents|file|hash_file|md5_file|sha1_file|filesize|filemtime|unlink|rename|copy|glob|scandir|mkdir|rmdir|chmod|mime_content_type|finfo_file|parse_ini_file)\(|new (?:RecursiveDirectoryIterator|DirectoryIterator|SplFileInfo|SplFileObject)\b|(?<![\w\\\\])File::\w+\(|response\(\)->(?:file|download)\(|->getRealPath\(|->getPathname\(|\$zip->open\(/';

    private const string OWN_TEMPORARY_FILE = 'its own temporary file, from tempnam or tmpfile';

    private const string HTTP_UPLOAD = 'the temporary file PHP made for this request\'s upload';

    private const string FROM_LOCAL_OBJECT_FILE = 'a path handed in, from LocalObjectFile when the bytes are stored';

    private const string OPERATOR_PATH = 'a path an operator gave a console command';

    private const string NOT_A_STORED_OBJECT = 'an application file, not a stored object';

    private const string SPOOL_OR_LOCAL_DISK = 'a path from StagedUpload, on the spool or a disk it found to be local';

    private const string STREAM_WRAPPER = 'its own stream wrapper, not a path';

    /** @var array<string, array{int, string}> file => how many sites, and what their paths are */
    private const array LOCAL_FILE_SITES = [
        'app/Console/Commands/Phr/AgentApiVerifyOAuthKeysCommand.php' => [4, self::NOT_A_STORED_OBJECT],
        'app/Console/Commands/Phr/BasePhrCommand.php' => [1, self::OPERATOR_PATH],
        'app/Console/Commands/Phr/PhrGenAiEnqueueCommand.php' => [1, self::OPERATOR_PATH],
        'app/Console/Commands/Phr/PhrImportPdfExtractionCommand.php' => [1, self::OPERATOR_PATH],
        'app/Console/Commands/Phr/PhrReconcileSourceEvidenceCommand.php' => [2, self::OPERATOR_PATH],
        'app/GenAiProcessor/External/HashVerifyingReadStream.php' => [1, self::STREAM_WRAPPER],
        'app/Http/Controllers/OhifViewerController.php' => [2, self::NOT_A_STORED_OBJECT],
        'app/Http/Controllers/PHR/PhrStructuredImportController.php' => [2, self::HTTP_UPLOAD],
        'app/Http/Requests/PHR/DICOM/StoreDicomVolumeCacheRequest.php' => [2, self::HTTP_UPLOAD],
        'app/Services/PHR/DICOM/DicomMetadataParser.php' => [1, self::HTTP_UPLOAD],
        'app/Services/PHR/DICOM/DicomUploadProcessor.php' => [3, self::HTTP_UPLOAD],
        'app/Services/PHR/DICOM/VolumeCacheService.php' => [3, self::HTTP_UPLOAD],
        'app/Services/PHR/Documents/PhrDocumentUploadService.php' => [3, self::HTTP_UPLOAD],
        'app/Services/PHR/Documents/SignedDocumentUploadService.php' => [1, self::OWN_TEMPORARY_FILE],
        'app/Services/PHR/Export/PhrExportArchiveBuilder.php' => [6, self::OWN_TEMPORARY_FILE],
        'app/Services/PHR/Export/PhrExportService.php' => [9, self::OWN_TEMPORARY_FILE],
        'app/Services/PHR/Import/DeltaDentalEobCsvParser.php' => [2, self::FROM_LOCAL_OBJECT_FILE],
        'app/Services/PHR/Import/DeltaDentalEobImporter.php' => [5, self::OPERATOR_PATH],
        'app/Services/PHR/Import/Ehi/EhiDirectorySource.php' => [4, self::OPERATOR_PATH],
        'app/Services/PHR/Import/Ehi/EhiIntakeService.php' => [1, self::OWN_TEMPORARY_FILE],
        'app/Services/PHR/Import/Ehi/EhiUploadService.php' => [1, self::HTTP_UPLOAD],
        'app/Services/PHR/Import/Ehi/EhiZipSource.php' => [3, self::FROM_LOCAL_OBJECT_FILE],
        'app/Services/PHR/Import/EobDocumentImportService.php' => [1, self::FROM_LOCAL_OBJECT_FILE],
        'app/Services/PHR/Import/MeritainEobImporter.php' => [4, self::OPERATOR_PATH],
        'app/Services/PHR/Import/PhrCcdaImporter.php' => [2, self::OPERATOR_PATH],
        'app/Services/PHR/Import/PhrDocumentImporter.php' => [6, self::OPERATOR_PATH],
        'app/Services/PHR/Import/PhrFhirImporter.php' => [2, self::OPERATOR_PATH],
        'app/Services/PHR/Import/PhrMyChartZipImporter.php' => [5, self::FROM_LOCAL_OBJECT_FILE],
        'app/Services/PHR/Import/PhrStructuredDocumentImporter.php' => [1, self::FROM_LOCAL_OBJECT_FILE],
        'app/Services/PHR/NativeBackup/PhrNativeArchiveBuilder.php' => [8, self::OWN_TEMPORARY_FILE],
        'app/Services/PHR/NativeBackup/PhrNativeBackupService.php' => [3, self::OWN_TEMPORARY_FILE],
        'app/Services/PHR/NativeBackup/PhrNativeRestoreArchive.php' => [2, self::OWN_TEMPORARY_FILE],
        'app/Services/PHR/NativeBackup/PhrNativeRestoreArchiveReader.php' => [12, self::OWN_TEMPORARY_FILE],
        'app/Services/PHR/NativeBackup/PhrNativeRestoreService.php' => [1, self::HTTP_UPLOAD],
        'app/Support/Storage/LocalObjectFile.php' => [3, self::OWN_TEMPORARY_FILE],
        'app/Support/Storage/PhrSourceReconciliationService.php' => [6, self::OPERATOR_PATH],
        'app/Support/Storage/StagedChunkAppender.php' => [5, self::SPOOL_OR_LOCAL_DISK],
    ];

    /** @return array<string, string> every PHP file in app/, relative to the base path, as code only */
    private function applicationCode(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen(base_path()) + 1)] = $this->codeOnly((string) file_get_contents($file->getPathname()));
            }
        }
        ksort($files);

        return $files;
    }

    /** Without comments or strings, where "file(s)" and "fopen(" are only words. */
    private function codeOnly(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $code .= $token;
            } elseif (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $code .= $token[1];
            }
        }

        return $code;
    }

    /**
     * @param  array<string, string>  $code
     * @return array<string, int>
     */
    private function sites(array $code, string $pattern): array
    {
        $found = [];
        foreach ($code as $file => $source) {
            // Parsing C-CDA and reading a request's path are not file access.
            $source = str_replace(['$this->xml->path(', '$request->path('], '', $source);
            $count = preg_match_all($pattern, $source);
            if ($count > 0) {
                $found[$file] = $count;
            }
        }

        return $found;
    }

    public function test_only_the_helpers_turn_a_disk_key_into_a_local_path(): void
    {
        $this->assertSame(self::DISK_PATH_SITES, $this->sites($this->applicationCode(), self::DISK_PATH),
            'A stored object may be on an object store. Stream it through Storage, or get a file from LocalObjectFile.');
    }

    public function test_every_call_that_needs_a_local_file_says_whose_file_it_is(): void
    {
        $expected = array_map(static fn (array $site): int => $site[0], self::LOCAL_FILE_SITES);

        $this->assertSame($expected, $this->sites($this->applicationCode(), self::LOCAL_FILE),
            'A local-file call appeared or went away. If its path can name a stored object, use Storage streams or LocalObjectFile. If not, record the site here and say what the path is.');
    }

    public function test_the_guards_find_each_kind_of_site_and_ignore_comments_and_lookalikes(): void
    {
        $code = fn (string $line): array => ['probe.php' => $this->codeOnly("<?php {$line}")];
        foreach (['Storage::disk($disk)->path($key);', '$disk->path($key);', '@link($a, $b);', 'StagedChunkAppender::append($a, $b, 0, 1);'] as $line) {
            $this->assertSame(['probe.php' => 1], $this->sites($code($line), self::DISK_PATH), $line);
        }
        foreach (['$this->link($relation);', '$this->xml->path($node, "code");', '$request->path();', '$x->pathname();'] as $line) {
            $this->assertSame([], $this->sites($code($line), self::DISK_PATH), $line);
        }
        foreach (['fopen($path, "rb");', '@unlink($path);', 'hash_file("sha256", $path);', 'File::get($path);', '$zip->open($path);', '$file->getRealPath();', 'new RecursiveDirectoryIterator($root);'] as $line) {
            $this->assertSame(['probe.php' => 1], $this->sites($code($line), self::LOCAL_FILE), $line);
        }
        foreach (['$now->copy();', '$this->unlink($relation);', '$request->file("chunk");', '$source->open($entry);', '$this->copy($a);', 'File::class;', 'function file(): void {}', 'function link(): void {}', 'sprintf("%d file(s)", 1);', '// fopen($path);', '/* unlink($path); */'] as $line) {
            $this->assertSame([], $this->sites($code($line), self::LOCAL_FILE), $line);
        }
    }
}
