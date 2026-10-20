<?php

/**
 * Synthetic, isolated resource runs. Prepare in one process, measure in another:
 * php -d memory_limit=1G scripts/measure-phr-resources.php --dir=/tmp/phr-resource-small --prepare --shape=small --records=20000
 * php -d memory_limit=1G scripts/measure-phr-resources.php --dir=/tmp/phr-resource-small --operation=backup
 * Operations: backup, validate, plan, apply, preview. Shape: small, wide, artifacts, ehi, fanout, dense.
 * All files stay under the explicitly selected private temporary directory.
 */

use App\Models\PhrEhiPackage;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiDirectorySource;
use App\Services\PHR\Import\Ehi\EhiIntakeService;
use App\Services\PHR\Import\Ehi\EhiProjectionRefused;
use App\Services\PHR\Import\Ehi\EhiProjectionService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\NativeBackup\NativeBackupException;
use App\Services\PHR\NativeBackup\NativeRestoreException;
use App\Services\PHR\NativeBackup\PhrNativeArchiveBuilder;
use App\Services\PHR\NativeBackup\PhrNativeRestoreArchiveReader;
use App\Services\PHR\NativeBackup\PhrNativeRestorePlanner;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiSyntheticPackage;

require dirname(__DIR__).'/vendor/autoload.php';
$options = getopt('', ['dir:', 'prepare', 'shape:', 'records:', 'bytes:', 'operation:', 'new-patient', 'extra-identities:']);
$directory = $options['dir'] ?? '';
$parent = is_string($directory) ? realpath(dirname($directory)) : false;
if ($parent !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($directory), 'phr-resource-') || is_link($directory)) {
    throw new RuntimeException('--dir must be a direct, nonsymlinked /tmp/phr-resource-* directory.');
}
$prepare = array_key_exists('prepare', $options);
if ($prepare) {
    if (file_exists($directory)) {
        throw new RuntimeException('Prepare requires a fresh directory; existing fixtures are never overwritten.');
    }
    mkdir($directory, 0700);
    file_put_contents($directory.'/.synthetic-phr-resource-fixture', 'synthetic only');
    touch($directory.'/database.sqlite');
} elseif (! is_file($directory.'/.synthetic-phr-resource-fixture')) {
    throw new RuntimeException('Prepare the synthetic fixture first.');
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'app.env' => 'testing', 'database.default' => 'sqlite',
    'database.connections.sqlite.url' => null,
    'database.connections.sqlite.database' => $directory.'/database.sqlite',
    'database.connections.sqlite.foreign_key_constraints' => true,
    'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
    'auth_provider.cache_user_on_validation' => false,
]);
foreach (['phr_documents', 'phr_exports', 'phr_dicom'] as $disk) {
    config(['filesystems.disks.'.$disk => ['driver' => 'local', 'root' => $directory.'/'.$disk, 'throw' => true]]);
    Storage::forgetDisk($disk);
}
DB::purge();
DB::disableQueryLog();
Queue::fake();

if ($prepare) {
    Artisan::call('migrate', ['--force' => true]);
    $owner = User::factory()->create(['user_role' => 'user']);
    $patient = PhrPatient::create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic Resource Patient', 'relationship' => 'self']);
    PhrPatientUserAccess::create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => 'owner', 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
    $shape = $options['shape'] ?? 'small';
    $records = max(2, (int) ($options['records'] ?? 1000));
    $packageId = null;
    if (in_array($shape, ['ehi', 'fanout', 'dense'], true)) {
        $clinical = EhiClinicalPackage::standard();
        for ($i = 0; $i < $records; $i++) {
            if (in_array($shape, ['fanout', 'dense'], true)) {
                $clinical = $clinical->adding('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '9102', 'LINE' => (string) ($i + 100), 'NOTE_ID' => '88001', 'NOTE_TEXT' => str_repeat('Synthetic note text ', max(1, (int) ($options['bytes'] ?? 1)))]);
            } else {
                $clinical = $clinical->adding('IMMUNE', ['IMMUNE_ID' => (string) ($i + 10000), 'IMMUNZATN_ID_NAME' => 'SYNTHETIC VACCINE', 'IMMUNE_DATE' => '2024-03-05', 'IMMNZTN_STATUS_C_NAME' => 'Given', 'IMM_HISTORIC_ADM_YN' => 'N'])
                    ->adding('PAT_IMMUNIZATIONS', ['PAT_ID' => EhiClinicalPackage::PATIENT_KEY, 'LINE' => (string) ($i + 100), 'IMMUNE_ID' => (string) ($i + 10000)]);
            }
        }
        $synthetic = $clinical->build();
        if ($shape === 'dense') {
            // Many small, unknown source cells exercise JSON/object overhead.
            // Unknown columns remain preserved and explicitly accounted.
            $files = $synthetic->files;
            $lines = explode("\n", rtrim($files['HNO_PLAIN_TEXT.tsv'], "\n"));
            $columns = [];
            for ($i = 0; $i < 8000; $i++) {
                $columns[] = 'SYNTH_CELL_'.$i;
            }
            $lines[0] .= "\t".implode("\t", $columns);
            for ($i = 1; $i < count($lines); $i++) {
                $lines[$i] .= str_repeat("\tx", 8000);
            }
            $files['HNO_PLAIN_TEXT.tsv'] = implode("\n", $lines)."\n";
            $synthetic = EhiSyntheticPackage::fromFiles($files);
        }
        $source = new EhiDirectorySource($synthetic->writeDirectory($directory.'/source'));
        try {
            $package = app(EhiIntakeService::class)->intake($source, $patient, $owner->id)->package;
        } finally {
            $source->close();
        }
        $package = app(EhiBindingService::class)->bind($package, $patient, $owner->id, null, 'Synthetic Resource Source');
        app(EhiProjectionService::class)->project($package, $owner->id);
        $packageId = $package->id;
    } else {
        $text = $shape === 'wide' ? str_repeat('x', max(1, (int) ($options['bytes'] ?? 1500000))) : 'Synthetic resource text';
        for ($offset = 0; $offset < $records - 2; $offset += 100) {
            $rows = [];
            for ($i = $offset; $i < min($offset + 100, $records - 2); $i++) {
                $rows[] = ['patient_id' => $patient->id, 'user_id' => $owner->id, 'document_type' => 'other', 'title' => 'Synthetic Resource Document', 'extracted_text' => $text, 'byte_size' => 0, 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('phr_documents')->insert($rows);
        }
    }
    if ($shape === 'artifacts') {
        foreach (DB::table('phr_documents')->where('patient_id', $patient->id)->orderBy('id')->lazyById(100) as $document) {
            $key = 'synthetic-artifact-'.$document->id;
            Storage::disk('phr_documents')->put($key, 'synthetic');
            DB::table('phr_documents')->where('id', $document->id)->update(['storage_path' => $key, 'byte_size' => 9, 'file_hash' => hash('sha256', 'synthetic')]);
        }
    }
    $state = ['patient' => $patient->id, 'owner' => $owner->id, 'package' => $packageId, 'shape' => $shape, 'requested_records' => $records];
    try {
        $built = app(PhrNativeArchiveBuilder::class)->build($patient);
        rename($built->path, $directory.'/archive.zip');
        $state['archive_records'] = array_sum($built->counts);
        $state['archive_bytes'] = $built->fileSize;
    } catch (NativeBackupException $refused) {
        $state['backup_refused'] = $refused->failureCategory;
    }
    $extra = (int) ($options['extra-identities'] ?? 0);
    for ($offset = 0; $offset < $extra; $offset += 200) {
        $rows = [];
        for ($i = $offset; $i < min($offset + 200, $extra); $i++) {
            $rows[] = ['patient_id' => $patient->id, 'record_table' => 'phr_lab_results', 'record_id' => $i + 1, 'native_id' => (string) Str::uuid()];
        }
        DB::table('phr_native_record_identities')->insert($rows);
    }
    file_put_contents($directory.'/state.json', json_encode($state, JSON_THROW_ON_ERROR));
    echo json_encode($state, JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

$state = json_decode(file_get_contents($directory.'/state.json'), true, flags: JSON_THROW_ON_ERROR);
$operation = $options['operation'] ?? 'backup';
$patient = PhrPatient::findOrFail($state['patient']);
// New-patient planning/apply deliberately removes the fixture graph before measurement.
if (array_key_exists('new-patient', $options)) {
    if (! in_array($operation, ['plan', 'apply'], true)) {
        throw new InvalidArgumentException('--new-patient requires plan or apply.');
    }
    DB::table('phr_native_record_identities')->where('patient_id', $patient->id)->delete();
    DB::table('phr_patients')->where('id', $patient->id)->delete();
}
$archive = null;
$attempt = null;
if (in_array($operation, ['plan', 'apply'], true)) {
    if ($operation === 'plan') {
        $copy = $directory.'/measured-archive.zip';
        copy($directory.'/archive.zip', $copy);
        $archive = app(PhrNativeRestoreArchiveReader::class)->openLocal($copy);
    } else {
        $service = app(PhrNativeRestoreService::class);
        $upload = new UploadedFile($directory.'/archive.zip', 'synthetic.zip', 'application/zip', null, true);
        $attempt = $service->preview($service->createPreview($upload, $state['owner'], false));
    }
}
$queries = 0;
$bindings = 0;
DB::listen(static function (QueryExecuted $query) use (&$queries, &$bindings): void {
    $queries++;
    $bindings = max($bindings, count($query->bindings));
});
$baseline = memory_get_usage(true);
memory_reset_peak_usage();
$started = microtime(true);
$status = 'completed';
try {
    switch ($operation) {
        case 'backup':
            $built = app(PhrNativeArchiveBuilder::class)->build($patient);
            @unlink($built->path);
            break;
        case 'validate':
            $copy = $directory.'/measured-archive.zip';
            copy($directory.'/archive.zip', $copy);
            $archive = app(PhrNativeRestoreArchiveReader::class)->openLocal($copy);
            break;
        case 'plan':
            $plan = app(PhrNativeRestorePlanner::class)->plan($archive, $state['owner'], false);
            if ($plan->blockers !== []) {
                throw new RuntimeException('Unexpected plan blockers.');
            }
            break;
        case 'apply':
            app(PhrNativeRestoreService::class)->apply($attempt);
            break;
        case 'preview':
            app(EhiProposalService::class)->preview(PhrEhiPackage::findOrFail($state['package']), $patient, $state['owner']);
            break;
        default:
            throw new InvalidArgumentException('Unknown operation.');
    }
} catch (NativeBackupException|NativeRestoreException $refused) {
    $status = $refused->failureCategory;
} catch (EhiProjectionRefused $refused) {
    $status = $refused->reason;
}
$usage = getrusage();
$procStatus = is_file('/proc/self/status') ? file_get_contents('/proc/self/status') : '';
preg_match('/VmHWM:\s+(\d+)/', $procStatus, $hwm);
preg_match('/VmRSS:\s+(\d+)/', $procStatus, $rss);
echo json_encode([
    'shape' => $state['shape'], 'operation' => $operation, 'requested_records' => $state['requested_records'],
    'status' => $status, 'seconds' => round(microtime(true) - $started, 3),
    'baseline_php_bytes' => $baseline, 'peak_php_bytes' => memory_get_peak_usage(true),
    'max_rss_kib' => $hwm[1] ?? $usage['ru_maxrss'], 'current_rss_kib' => $rss[1] ?? null, 'queries' => $queries, 'max_bindings' => $bindings,
], JSON_THROW_ON_ERROR)."\n";
