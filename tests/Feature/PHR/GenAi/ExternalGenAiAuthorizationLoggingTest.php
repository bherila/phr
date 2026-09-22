<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\External\PhrMcpMailboxAccessResolver;
use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * PhrMcpMailboxAccessResolver::authorize() must fail closed on every
 * throwable from the patient-grant re-check, but a transient infrastructure
 * failure must not look identical to a genuine authorization denial: only the
 * former is logged, and never with anything that could identify a patient.
 */
final class ExternalGenAiAuthorizationLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Bus::fake();
    }

    public function test_unexpected_grant_lookup_failure_is_logged_and_still_denies_access(): void
    {
        [$user, , $document, $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $job->refresh();
        $requestId = (string) $job->mcp_request_id;
        $this->assertNotSame('', $requestId);
        [$context, $mailbox, $request] = $this->authorizationInputs($user, $requestId);

        // A database blip while re-checking the grant is not an authorization
        // loss.
        $this->app->instance(PhrPatientAccessService::class, new class extends PhrPatientAccessService
        {
            public function writablePatient(int $patientId, int $userId): PhrPatient
            {
                throw new QueryException(
                    'mysql',
                    'select * from phr_patients where id = ?',
                    [$patientId],
                    new RuntimeException('SQLSTATE[HY000] [2002] Connection refused'),
                );
            }
        });

        Log::spy();

        $result = app(PhrMcpMailboxAccessResolver::class)
            ->authorize($context, $mailbox, AgentApiScopes::GENAI_WORK, $request);

        $this->assertFalse($result, 'A transient grant-lookup failure must still fail closed.');

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $logContext) use ($job, $requestId): bool {
                $this->assertSame(
                    'External GenAI mailbox authorization check failed unexpectedly; denying access.',
                    $message,
                );
                $this->assertSame(
                    ['job_id', 'request_id_hash', 'exception'],
                    array_keys($logContext),
                    'The log context must carry only these keys - nothing else, and certainly no PHI.',
                );
                $this->assertSame($job->id, $logContext['job_id']);
                $this->assertSame(hash('sha256', $requestId), $logContext['request_id_hash']);
                $this->assertSame(QueryException::class, $logContext['exception']);

                return true;
            });
    }

    public function test_genuine_authorization_denial_still_denies_access_without_an_error_log(): void
    {
        [$user, $patient, , $job] = $this->externalJob(owner: false);
        (new ParseImportJob($job->id))->handle();
        $job->refresh();
        $requestId = (string) $job->mcp_request_id;
        $this->assertNotSame('', $requestId);
        [$context, $mailbox, $request] = $this->authorizationInputs($user, $requestId);

        // Revoking the grant makes writablePatient() throw a genuine
        // AuthorizationException - an expected outcome, not an incident.
        PhrPatientUserAccess::query()
            ->where('patient_id', $patient->id)
            ->where('user_id', $user->id)
            ->delete();

        Log::spy();

        $result = app(PhrMcpMailboxAccessResolver::class)
            ->authorize($context, $mailbox, AgentApiScopes::GENAI_WORK, $request);

        $this->assertFalse($result);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_writable_patient_actually_throws_authorization_exception_for_read_only_access(): void
    {
        // Verifies (rather than assumes) what PhrPatientAccessService::writablePatient()
        // raises for a genuine denial, which is what the resolver's catch
        // must name to distinguish a denial from an unexpected failure.
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Synthetic Authorization Patient',
            'relationship' => 'self',
        ]);
        $viewer = User::factory()->create(['user_role' => 'user']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $viewer->id,
            'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        $this->expectException(AuthorizationException::class);
        app(PhrPatientAccessService::class)->writablePatient($patient->id, $viewer->id);
    }

    public function test_writable_patient_actually_throws_model_not_found_when_inaccessible(): void
    {
        // The other genuine-denial shape the resolver's catch must name: a
        // user with no access at all to the patient never reaches the
        // write-access check, so accessiblePatient()'s findOrFail() throws
        // ModelNotFoundException instead of AuthorizationException.
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Synthetic Inaccessible Patient',
            'relationship' => 'self',
        ]);
        $stranger = User::factory()->create(['user_role' => 'user']);

        $this->expectException(ModelNotFoundException::class);
        app(PhrPatientAccessService::class)->writablePatient($patient->id, $stranger->id);
    }

    /**
     * @return array{ExecutionContext, McpMailbox, McpRequest}
     */
    private function authorizationInputs(User $user, string $requestId): array
    {
        $mailboxId = (string) GenAiImportJob::query()->getConnection()
            ->table('genai_mcp_requests')
            ->where('id', $requestId)
            ->value('mailbox_id');
        $this->assertNotSame('', $mailboxId);

        $context = new ExecutionContext(
            principalKey: sprintf('phr:user:%d:oauth:%s', $user->id, hash('sha256', 'synthetic-'.$user->id)),
            mailboxIds: [$mailboxId],
            scopes: [AgentApiScopes::GENAI_READ, AgentApiScopes::GENAI_WORK],
        );
        $mailbox = McpMailbox::query()->findOrFail($mailboxId);
        $request = McpRequest::query()->findOrFail($requestId);

        return [$context, $mailbox, $request];
    }

    /** @return array{User, PhrPatient, PhrDocument, GenAiImportJob} */
    private function externalJob(bool $owner = true): array
    {
        $user = User::factory()->create([
            'user_role' => 'user',
            'genai_execution_mode' => GenAiImportJob::EXECUTION_EXTERNAL,
        ]);
        $patientOwner = $owner ? $user : User::factory()->create(['user_role' => 'user']);
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $patientOwner->id,
            'display_name' => 'Synthetic Authorization Logging Patient',
            'relationship' => 'self',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'access_level' => $owner ? PhrPatientUserAccess::LEVEL_OWNER : PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $patientOwner->id,
            'granted_at' => now(),
        ]);
        $bytes = '%PDF-1.4 synthetic authorization logging';
        $path = 'genai-import/'.$user->id.'/authz-logging/source.pdf';
        Storage::disk('s3')->put($path, $bytes);
        $document = PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patientOwner->id,
            'uploaded_by_user_id' => $user->id,
            'title' => 'Synthetic authorization logging document',
            'document_type' => 'lab_report',
            'original_filename' => 'synthetic-private-name.pdf',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'patients/'.$patient->id.'/documents/authz-logging.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'file_hash' => hash('sha256', $bytes),
            'source' => 'manual_upload',
        ]);
        $job = GenAiImportJob::query()->create([
            'user_id' => $user->id,
            'job_type' => 'phr_document',
            'file_hash' => hash('sha256', $bytes),
            'original_filename' => 'synthetic-private-name.pdf',
            's3_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => strlen($bytes),
            'context_json' => json_encode(['patient_id' => $patient->id, 'document_id' => $document->id], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'execution_mode' => GenAiImportJob::EXECUTION_EXTERNAL,
        ]);
        $document->update(['genai_job_id' => $job->id]);

        return [$user, $patient, $document, $job];
    }
}
