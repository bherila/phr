<?php

namespace Tests\Unit\Services;

use App\Services\AgentApi\Client\AgentApiReadDao;
use App\Services\AgentApi\Client\AgentApiTransport;
use App\Services\AgentApi\Client\AgentApiTransportResponse;
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
}

final class RecordingAgentApiTransport implements AgentApiTransport
{
    public ?string $method = null;

    public ?string $path = null;

    /** @var array<string, scalar|list<scalar>|null> */
    public array $query = [];

    public function __construct(private readonly AgentApiTransportResponse $response) {}

    public function send(string $method, string $path, array $query = [], ?array $json = null): AgentApiTransportResponse
    {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;

        return $this->response;
    }
}
