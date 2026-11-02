<?php

namespace Tests\Feature;

use App\Models\PhrPatient;
use App\Models\PhrSinusEnrollment;
use App\Services\PHR\NativeBackup\PhrNativeRecordCodec;
use App\Support\Database\BinaryColumn;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDOException;
use ReflectionProperty;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DatabaseDriverSupportTest extends TestCase
{
    private const string BYTES = "\x00\xFF\xFEraw\\x41'\xC3\x28";

    public function test_a_stream_reads_as_its_bytes_every_time(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, self::BYTES);

        $this->assertSame(self::BYTES, BinaryColumn::read($stream));
        $this->assertSame(self::BYTES, BinaryColumn::read($stream));
        $this->assertSame(self::BYTES, BinaryColumn::read(self::BYTES));
        $this->assertNull(BinaryColumn::read(null));
    }

    public function test_raw_bytes_survive_the_model_a_lookup_and_the_archive_encoding(): void
    {
        $enrollment = $this->enrollment();
        $found = PhrSinusEnrollment::query()->where('client_enrollment_uuid', BinaryColumn::bind(self::BYTES))->sole();

        $this->assertSame($enrollment->id, $found->id);
        $this->assertSame(self::BYTES, $found->client_enrollment_uuid);
        $this->assertSame(self::BYTES, $found->embedding);
        // Reading twice proves a stream is not left at its end.
        $this->assertSame(self::BYTES, $found->embedding);

        $row = DB::table('phr_sinus_enrollments')->where('id', $found->id)->sole();
        $encoded = (new PhrNativeRecordCodec)->encodeValue('phr_sinus_enrollments', 'embedding', $row->embedding);
        $this->assertSame(['encoding' => 'base64', 'data' => base64_encode(self::BYTES)], $encoded);

        DB::table('phr_sinus_enrollments')->where('id', $found->id)->update(['embedding' => PhrNativeRecordCodec::decodeValue($encoded)]);
        $this->assertSame(self::BYTES, $found->fresh()->embedding);
    }

    public function test_an_archive_writes_a_boolean_as_0_or_1_whatever_the_engine(): void
    {
        $row = DB::table('phr_sinus_enrollments')->where('id', $this->enrollment()->id)->sole();
        $codec = new PhrNativeRecordCodec;

        $this->assertSame(0, $codec->encodeValue('phr_sinus_enrollments', 'is_negative', $row->is_negative));
        $this->assertSame(1, $codec->encodeValue('phr_sinus_enrollments', 'negative_scoped', $row->negative_scoped));
    }

    public function test_a_value_postgresql_refuses_for_a_typed_column_is_not_found_rather_than_a_server_error(): void
    {
        $previous = new PDOException('invalid input syntax for type uuid');
        (new ReflectionProperty(Exception::class, 'code'))->setValue($previous, '22P02');
        Route::get('/synthetic-refused', fn () => throw new QueryException('pgsql', 'select 1', [], $previous));
        Route::get('/synthetic-other', fn () => throw new QueryException('pgsql', 'select 1', [], new PDOException('other')));

        $this->getJson('/synthetic-refused')->assertNotFound();
        $this->getJson('/synthetic-other')->assertServerError();
    }

    public function test_a_real_malformed_uuid_lookup_is_not_found_on_postgresql(): void
    {
        Route::get('/synthetic-lookup', fn () => response()->json(['client' => DB::table('oauth_clients')->where('id', 'not-a-uuid')->first()]));

        $response = $this->getJson('/synthetic-lookup');

        DB::connection()->getDriverName() === 'pgsql' ? $response->assertNotFound() : $response->assertOk();
    }

    public function test_postgresql_tests_refuse_a_host_that_is_not_local(): void
    {
        $process = new Process([PHP_BINARY, 'tests/bootstrap.php'], base_path(), [
            'PHR_TEST_DATABASE' => 'pgsql',
            'PHR_TEST_PGSQL_HOST' => 'db.example.test',
        ]);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('SAFETY ERROR', $process->getOutput().$process->getErrorOutput());
    }

    private function enrollment(): PhrSinusEnrollment
    {
        $owner = $this->createUser();
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic patient']);

        return PhrSinusEnrollment::query()->create([
            'phr_patient_id' => $patient->id,
            'client_enrollment_uuid' => self::BYTES,
            'class' => 'cough',
            'is_negative' => false,
            'negative_scoped' => true,
            'embedding' => self::BYTES,
            'embedding_dim' => 3,
            'captured_at' => now(),
        ]);
    }
}
