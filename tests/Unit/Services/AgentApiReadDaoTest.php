<?php

namespace Tests\Unit\Services;

use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\DataTransferObjects\AgentApi\DocumentUploadData;
use App\DataTransferObjects\AgentApi\ImportReviewData;
use App\Services\AgentApi\Client\AgentApiMultipart;
use App\Services\AgentApi\Client\AgentApiReadDao;
use App\Services\AgentApi\Client\AgentApiTransport;
use App\Services\AgentApi\Client\AgentApiTransportResponse;
use App\Services\AgentApi\Client\AgentApiWriteDao;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;

final class AgentApiReadDaoTest extends TestCase
{
    public function test_it_builds_a_bounded_query_without_null_placeholders(): void
    {
        $transport = new RecordingAgentApiTransport(new AgentApiTransportResponse(200, [
            'data' => [['id' => 7]],
            'pagination' => ['limit' => 10, 'has_more' => false, 'next_cursor' => null],
        ]));
        $dao = new AgentApiReadDao($transport);

        $payload = $dao->patients(limit: 10, updatedAfter: '2026-08-16T00:00:00Z')->toArray();

        $this->assertSame([['id' => 7]], $payload['data']);
        $this->assertSame('GET', $transport->method);
        $this->assertSame('patients', $transport->path);
        $this->assertSame([
            'limit' => 10,
            'updated_after' => '2026-08-16T00:00:00Z',
            'archived' => 'include',
        ], $transport->query);
    }

    public function test_it_rejects_a_malformed_page_at_the_dao_boundary(): void
    {
        $dao = new AgentApiReadDao(new RecordingAgentApiTransport(new AgentApiTransportResponse(200, [
            'data' => ['not-an-object'],
            'pagination' => ['limit' => 25, 'has_more' => false, 'next_cursor' => null],
        ])));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The PHR API returned an invalid response.');
        $dao->patients();
    }

    public function test_it_maps_rest_failures_without_copying_response_content(): void
    {
        $dao = new AgentApiReadDao(new RecordingAgentApiTransport(new AgentApiTransportResponse(403, [
            'message' => 'Synthetic value that must not escape',
        ])));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('This connection lacks the required permission.');
        $dao->patient(1);
    }

    public function test_write_dao_preserves_nullable_concurrency_fields_and_validates_its_response(): void
    {
        $transport = new RecordingAgentApiTransport(new AgentApiTransportResponse(201, [
            'resource_type' => 'procedures',
            'patient_id' => 7,
            'outcome' => 'created',
            'version' => str_repeat('a', 64),
            'data' => ['id' => 11],
        ]));
        $dao = new AgentApiWriteDao($transport);
        $command = ClinicalUpsertData::fromValidated('procedures', [
            'external_id' => 'synthetic-external-id',
            'source_document_id' => null,
            'review_status' => 'pending_review',
            'expected_version' => null,
            'data' => ['name' => 'Synthetic typed procedure'],
        ]);

        $payload = $dao->clinicalUpsert(7, $command)->toArray();

        $this->assertSame('created', $payload['outcome']);
        $this->assertSame('PUT', $transport->method);
        $this->assertSame('patients/7/procedures', $transport->path);
        $this->assertSame([
            'external_id' => 'synthetic-external-id',
            'source_document_id' => null,
            'review_status' => 'pending_review',
            'expected_version' => null,
            'data' => ['name' => 'Synthetic typed procedure'],
        ], $transport->json);
    }

    public function test_write_dao_builds_a_typed_multipart_document_upload(): void
    {
        $transport = new RecordingAgentApiTransport(new AgentApiTransportResponse(201, [
            'resource_type' => 'document',
            'patient_id' => 7,
            'outcome' => 'created',
            'data' => ['id' => 12],
        ]));
        $dao = new AgentApiWriteDao($transport);
        $command = DocumentUploadData::fromBase64([
            'external_id' => 'synthetic-document-id',
            'filename' => 'synthetic.pdf',
            'content_base64' => base64_encode('%PDF-1.4 synthetic'),
            'title' => null,
            'document_type' => 'lab_report',
            'observed_at' => null,
            'summary' => null,
            'tags' => ['synthetic'],
        ]);

        $payload = $dao->documentUpload(7, $command)->toArray();

        $this->assertSame('created', $payload['outcome']);
        $this->assertSame('POST', $transport->method);
        $this->assertSame('patients/7/documents', $transport->path);
        $this->assertSame('synthetic-document-id', $transport->multipart?->fields['external_id']);
        $this->assertSame('%PDF-1.4 synthetic', $transport->multipart?->files['file']->contents);
    }

    public function test_read_dao_builds_typed_import_queries(): void
    {
        $transport = new RecordingAgentApiTransport(new AgentApiTransportResponse(200, [
            'resource_type' => 'import_job',
            'patient_id' => 7,
            'data' => [],
            'pagination' => ['limit' => 10, 'has_more' => false, 'next_cursor' => null],
        ]));

        $payload = (new AgentApiReadDao($transport))->imports(7, 10, 'synthetic-cursor', 'failed')->toArray();

        $this->assertSame('import_job', $payload['resource_type']);
        $this->assertSame('GET', $transport->method);
        $this->assertSame('patients/7/imports', $transport->path);
        $this->assertSame([
            'limit' => 10,
            'cursor' => 'synthetic-cursor',
            'status' => 'failed',
        ], $transport->query);
    }

    public function test_write_dao_builds_typed_import_review(): void
    {
        $transport = new RecordingAgentApiTransport(new AgentApiTransportResponse(200, [
            'resource_type' => 'import_result',
            'patient_id' => 7,
            'job_id' => 12,
            'outcome' => 'accepted',
            'import' => ['created' => 1, 'updated' => 0, 'skipped' => 0, 'documents' => 0],
            'data' => ['id' => 15, 'status' => 'imported'],
        ]));
        $review = ImportReviewData::make('accept', ['analyte' => 'Synthetic corrected result']);

        $payload = (new AgentApiWriteDao($transport))->importReview(7, 12, 15, $review)->toArray();

        $this->assertSame('accepted', $payload['outcome']);
        $this->assertSame('POST', $transport->method);
        $this->assertSame('patients/7/imports/12/results/15/review', $transport->path);
        $this->assertSame([
            'action' => 'accept',
            'payload' => ['analyte' => 'Synthetic corrected result'],
        ], $transport->json);
    }
}

final class RecordingAgentApiTransport implements AgentApiTransport
{
    public ?string $method = null;

    public ?string $path = null;

    /** @var array<string, scalar|list<scalar>|null> */
    public array $query = [];

    /** @var array<string, mixed>|null */
    public ?array $json = null;

    public ?AgentApiMultipart $multipart = null;

    public function __construct(private readonly AgentApiTransportResponse $response) {}

    public function send(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        ?AgentApiMultipart $multipart = null,
    ): AgentApiTransportResponse {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->json = $json;
        $this->multipart = $multipart;

        return $this->response;
    }
}
