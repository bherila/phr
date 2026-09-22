<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDicomFile;
use App\Models\PhrDicomUpload;
use App\Models\PhrPatient;
use App\Services\PHR\DICOM\DicomUploadProcessor;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

use function App\Support\Logging\safelog_test_error_log_should_throw;

require_once __DIR__.'/../../Unit/Support/Logging/SafeLogErrorLogStub.php';

/**
 * Pins the context `DicomUploadProcessor::failUpload()` logs when storage
 * cleanup fails. `r2_prefix` is built by `PhrStorageKey::dicomUpload()` and
 * embeds the owning patient id, and a cleanup exception's message is
 * uncontrolled text, so neither may reach any log sink. SafeLog only bounds
 * context to scalars - it does not redact - so this caller-level test is what
 * catches the prefix or the raw message coming back.
 *
 * Both values are synthetic canaries: a patient id no fixture would otherwise
 * produce, and exception text that stands in for clinical content.
 */
final class DicomUploadCleanupLoggingTest extends TestCase
{
    private const PATIENT_ID = 987654;

    private const CLINICAL_CANARY = 'SYNTHETIC-CANARY cardiac CT for Jane Roe DOB 1970-01-01';

    private ?string $errorLogFile = null;

    protected function tearDown(): void
    {
        safelog_test_error_log_should_throw(false);

        if (is_string($this->errorLogFile) && file_exists($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }

        parent::tearDown();
    }

    public function test_cleanup_failure_logs_only_the_upload_id_and_exception_class(): void
    {
        $this->redirectErrorLogTo();
        /** @var list<MessageLogged> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });
        [$processor, $upload] = $this->uploadWhoseCleanupThrows();

        $processor->failUpload($upload, 'Synthetic failure reason.');

        $cleanupEvents = array_values(array_filter(
            $logged,
            fn (MessageLogged $event): bool => $event->message === 'phr.dicom.cleanup_delete_prefix_failed',
        ));
        foreach ($logged as $event) {
            $this->assertCarriesNoPatientData($upload, $event->message.' '.json_encode($event->context, JSON_UNESCAPED_SLASHES));
        }
        $this->assertCount(1, $cleanupEvents, 'The cleanup failure must be logged exactly once.');
        $this->assertSame('warning', $cleanupEvents[0]->level);
        $this->assertSame(
            ['upload_id' => $upload->id, 'exception' => RuntimeException::class],
            $cleanupEvents[0]->context,
        );

        $this->assertSame('', trim($this->readFallbackSink()), 'A healthy logger must not fall through to the fallback sink.');
        $this->assertUploadFailedAndCleanedUp($upload);
    }

    public function test_cleanup_failure_with_a_throwing_logger_keeps_patient_data_out_of_the_fallback_sink(): void
    {
        $this->redirectErrorLogTo();
        Log::shouldReceive('warning')
            ->once()
            ->andThrow(new RuntimeException('log destination unavailable'));
        [$processor, $upload] = $this->uploadWhoseCleanupThrows();

        $processor->failUpload($upload, 'Synthetic failure reason.');

        $fallback = $this->readFallbackSink();
        $this->assertStringContainsString('phr.dicom.cleanup_delete_prefix_failed', $fallback);
        $this->assertStringContainsString('"upload_id":'.$upload->id, $fallback);
        $this->assertCarriesNoPatientData($upload, $fallback);
        $this->assertUploadFailedAndCleanedUp($upload);
    }

    public function test_cleanup_failure_completes_even_when_both_log_sinks_throw(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->andThrow(new RuntimeException('log destination unavailable'));
        safelog_test_error_log_should_throw(true);
        [$processor, $upload] = $this->uploadWhoseCleanupThrows();

        $processor->failUpload($upload, 'Synthetic failure reason.');

        $this->assertUploadFailedAndCleanedUp($upload);
    }

    /**
     * @return array{DicomUploadProcessor, PhrDicomUpload}
     */
    private function uploadWhoseCleanupThrows(): array
    {
        $owner = $this->createUser();
        $patient = PhrPatient::forceCreate([
            'id' => self::PATIENT_ID,
            'owner_user_id' => $owner->id,
            'display_name' => 'Synthetic',
            'relationship' => 'self',
        ]);

        $processor = app(DicomUploadProcessor::class);
        $upload = $processor->openUpload($patient, $owner->id, 'SYNTHETIC_ROOT');
        $this->assertStringContainsString('patients/'.self::PATIENT_ID.'/', $upload->r2_prefix, 'Fixture precondition: the prefix must embed the patient id.');

        PhrDicomFile::create([
            'patient_id' => $patient->id,
            'upload_id' => $upload->id,
            'file_kind' => 'dicom',
            'r2_key' => $upload->r2_prefix.'/files/synthetic.dcm',
            'original_relative_path' => 'synthetic.dcm',
            'original_path_hash' => hash('sha256', 'synthetic.dcm'),
            'original_filename' => 'synthetic.dcm',
            'mime_type' => 'application/dicom',
            'file_size_bytes' => 1,
            'sha256' => hash('sha256', 'x'),
        ]);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('deleteDirectory')
            ->with($upload->r2_prefix)
            ->andThrow(new RuntimeException('delete failed for '.$upload->r2_prefix.': '.self::CLINICAL_CANARY));
        Storage::set(DicomUploadProcessor::DISK, $disk);

        return [$processor, $upload];
    }

    private function assertCarriesNoPatientData(PhrDicomUpload $upload, string $written): void
    {
        $this->assertStringNotContainsString($upload->r2_prefix, $written);
        $this->assertStringNotContainsString('patients/', $written);
        $this->assertStringNotContainsString((string) self::PATIENT_ID, $written);
        $this->assertStringNotContainsString('SYNTHETIC-CANARY', $written);
        $this->assertStringNotContainsString('delete failed for', $written);
    }

    private function assertUploadFailedAndCleanedUp(PhrDicomUpload $upload): void
    {
        $upload->refresh();
        $this->assertSame(PhrDicomUpload::STATUS_FAILED, $upload->status);
        $this->assertSame('Synthetic failure reason.', $upload->error_message);
        $this->assertSame(0, PhrDicomFile::query()->where('upload_id', $upload->id)->count(), 'Child rows must still be cleaned up after the logging failure.');
    }

    /**
     * PHPUnit redirects the `error_log` ini directive for every test method,
     * so the redirect has to happen inside the test body (see SafeLogTest).
     */
    private function redirectErrorLogTo(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dicom-safelog-');
        $this->assertIsString($file);
        $this->errorLogFile = $file;
        ini_set('error_log', $file);
    }

    private function readFallbackSink(): string
    {
        return is_string($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
    }
}
