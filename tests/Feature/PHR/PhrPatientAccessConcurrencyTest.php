<?php

namespace Tests\Feature\PHR;

use App\Http\Controllers\PHR\PatientAccessController;
use App\Http\Requests\PHR\StorePatientAccessRequest;
use App\Models\PhrEhiFinding;
use App\Models\PhrEhiPackage;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Actual overlapping SQL transactions; exercised by the required PostgreSQL CI job. */
class PhrPatientAccessConcurrencyTest extends TestCase
{
    private const string REVOKER = 'patient_access_revoker';

    private ?string $writer = null;

    /** @var list<int> */
    private array $patients = [];

    /** @var list<int> */
    private array $users = [];

    /**
     * These two-session tests need committed fixtures. Keep SafeTestCase's host/
     * database guards and migrations; only this class opts out of its outer PG
     * transaction and deletes its own synthetic fixture rows in tearDown.
     *
     * @return list<string|null>
     */
    protected function connectionsToTransact(): array
    {
        return DB::connection()->getDriverName() === 'pgsql' ? [] : parent::connectionsToTransact();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the disposable PostgreSQL test database and two SQL sessions.');
        }
        $this->writer = DB::getDefaultConnection();
        config(['database.connections.'.self::REVOKER => config('database.connections.'.$this->writer)]);
        DB::connection(self::REVOKER)->statement("SET lock_timeout = '300ms'");
        DB::connection($this->writer)->statement("SET lock_timeout = '300ms'");
        self::assertNotSame(DB::connection($this->writer)->getPdo(), DB::connection(self::REVOKER)->getPdo());
        self::assertSame(0, DB::connection($this->writer)->transactionLevel());
    }

    protected function tearDown(): void
    {
        if ($this->writer !== null) {
            DB::setDefaultConnection($this->writer);
            foreach ([$this->writer, self::REVOKER] as $name) {
                while (DB::connection($name)->transactionLevel() > 0) {
                    DB::connection($name)->rollBack();
                }
            }
            DB::table('phr_patients')->whereIn('id', $this->patients)->delete();
            DB::table('users')->whereIn('id', $this->users)->delete();
            DB::connection($this->writer)->statement('RESET lock_timeout');
            DB::purge(self::REVOKER);
        }
        parent::tearDown();
    }

    #[DataProvider('revocationPaths')]
    public function test_revocation_ordering_distinguishes_the_legacy_gap_from_the_locked_controller(bool $legacyAutocommit): void
    {
        [$owner, $manager, $patient, $grant, $package] = $this->fixture();
        $interleaved = false;
        $blocked = false;
        $pendingChunk = false;

        // Grant::delete touches the parent BEFORE deleting. Without the shared
        // transaction that touch autocommits: a writer can start in this exact
        // window, then commit AFTER delete acknowledges. Merely starting a
        // revocation while a writer holds the lock would miss the old-code bug.
        DB::listen(function (QueryExecuted $event) use ($manager, $patient, $package, &$interleaved, &$blocked, &$pendingChunk): void {
            if ($interleaved || $event->connectionName !== self::REVOKER
                || ! str_starts_with($event->sql, 'update "phr_patients"')) {
                return;
            }
            $interleaved = true;
            $this->onConnection($this->writer, function () use ($manager, $patient, $package, &$blocked, &$pendingChunk): void {
                DB::beginTransaction();
                try {
                    $this->chunk($patient, $manager, $package, 'late_chunk');
                    $pendingChunk = true;
                } catch (QueryException $exception) {
                    self::assertSame('55P03', $exception->getCode(), 'The patient lock must block the competing SQL session.');
                    $blocked = true;
                    DB::rollBack();
                }
            });
        });

        $response = $this->onConnection(self::REVOKER, function () use ($owner, $patient, $grant, $legacyAutocommit) {
            if ($legacyAutocommit) {
                // Exact pre-fix destroy algorithm (e3d8119), including the real
                // model's implicit touch. This live SQL negative control must
                // demonstrate the late commit; otherwise the harness proves nothing.
                $resolved = app(PhrPatientAccessService::class)->ownedPatient($patient->id, $owner->id);
                PhrPatientUserAccess::query()->where('patient_id', $resolved->id)
                    ->where('access_level', '!=', PhrPatientUserAccess::LEVEL_OWNER)
                    ->findOrFail($grant->id)->delete();

                return response()->json(['ok' => true]);
            }
            $request = Request::create('/synthetic/access', 'DELETE');
            $request->setUserResolver(static fn () => $owner);

            return app(PatientAccessController::class)->destroy($request, $patient->id, $grant->id);
        });
        self::assertSame(200, $response->getStatusCode(), 'Revocation has now acknowledged.');
        if ($pendingChunk) {
            // Negative control: old code reaches this commit after the response,
            // making its post-ack durable write observable before assertions fail.
            DB::commit();
        }
        self::assertTrue($interleaved, 'The race must enter the post-touch/pre-delete window.');
        self::assertSame(! $legacyAutocommit, $blocked, 'Only the locked controller prevents the writer entering the post-touch window.');
        self::assertSame($legacyAutocommit, $pendingChunk);
        self::assertSame($legacyAutocommit, PhrEhiFinding::query()->where('code', 'late_chunk')->exists(), 'The legacy control commits an authorized chunk after revoke acknowledgement; the controller must not.');
        $this->assertNextChunkDenied($patient, $manager, $package);
    }

    /** @return array<string, array{bool}> */
    public static function revocationPaths(): array
    {
        return ['legacy autocommit permits a post-ack commit' => [true], 'locked controller prevents a post-ack commit' => [false]];
    }

    public function test_downgrade_waits_for_an_authorized_chunk_and_later_chunks_are_denied(): void
    {
        [$owner, $manager, $patient, $grant, $package] = $this->fixture();
        DB::transaction(function () use ($owner, $manager, $patient, $grant, $package): void {
            $this->chunk($patient, $manager, $package, 'before_downgrade');
            try {
                $this->onConnection(self::REVOKER, fn () => $this->downgrade($owner, $manager, $patient));
                self::fail('A downgrade cannot acknowledge while the authorized chunk holds the patient lock.');
            } catch (QueryException $exception) {
                self::assertSame('55P03', $exception->getCode());
            }
            self::assertSame(PhrPatientUserAccess::LEVEL_MANAGER, $grant->fresh()->access_level, 'An unacknowledged downgrade cannot leak a partial grant write.');
        });
        self::assertTrue(PhrEhiFinding::query()->where('code', 'before_downgrade')->exists());
        $response = $this->onConnection(self::REVOKER, fn () => $this->downgrade($owner, $manager, $patient));
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(PhrPatientUserAccess::LEVEL_VIEWER, $grant->fresh()->access_level);
        $this->assertNextChunkDenied($patient, $manager, $package);
    }

    private function chunk(PhrPatient $patient, User $actor, PhrEhiPackage $package, string $code): void
    {
        // The production background-chunk boundary, with a small durable EHI
        // finding in place of a large mapping batch. Authorization uses the
        // newly locked patient, never the caller's possibly cached grants.
        app(PhrPatientArtifactWriteGuard::class)->run($patient->id, function (PhrPatient $locked) use ($actor, $package, $code): void {
            app(PhrPatientAccessService::class)->ensureCanWrite($locked, $actor->id);
            PhrEhiFinding::query()->create(['patient_id' => $locked->id, 'package_id' => $package->id, 'code' => $code, 'severity' => PhrEhiFinding::SEVERITY_INFO]);
        });
    }

    private function assertNextChunkDenied(PhrPatient $patient, User $manager, PhrEhiPackage $package): void
    {
        try {
            $this->chunk($patient, $manager, $package, 'after_ack');
            self::fail('A fresh chunk cannot use the revoked manager grant.');
        } catch (AuthorizationException) {
            self::assertFalse(PhrEhiFinding::query()->where('code', 'after_ack')->exists());
        }
    }

    private function downgrade(User $owner, User $manager, PhrPatient $patient): JsonResponse
    {
        $payload = ['email' => $manager->email, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER];
        $request = StorePatientAccessRequest::create('/synthetic/access', 'POST', $payload);
        $request->setUserResolver(static fn () => $owner);
        $request->setValidator(Validator::make($payload, $request->rules()));

        return app(PatientAccessController::class)->store($request, $patient->id);
    }

    /** @return array{User, User, PhrPatient, PhrPatientUserAccess, PhrEhiPackage} */
    private function fixture(): array
    {
        $owner = $this->createUser();
        $this->users[] = $owner->id;
        $manager = $this->createUser();
        $this->users[] = $manager->id;
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic concurrency patient']);
        $this->patients[] = $patient->id;
        $grant = PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $manager->id, 'access_level' => PhrPatientUserAccess::LEVEL_MANAGER]);
        $package = PhrEhiPackage::query()->create(['patient_id' => $patient->id, 'manifest_digest' => hash('sha256', 'synthetic race fixture'), 'intake_kind' => PhrEhiPackage::INTAKE_DIRECTORY]);
        $patient->load('accessGrants');

        return [$owner, $manager, $patient, $grant, $package];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function onConnection(?string $name, Closure $callback): mixed
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($name);
        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }
}
