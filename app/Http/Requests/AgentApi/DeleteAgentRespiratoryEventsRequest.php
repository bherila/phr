<?php

namespace App\Http\Requests\AgentApi;

use App\Http\Requests\PHR\DeleteRespiratoryEventBatchRequest;

/** respiratory_events.delete: the browser's batch delete, by the device's event UUIDs. */
final class DeleteAgentRespiratoryEventsRequest extends DeleteRespiratoryEventBatchRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return list<string> */
    public function uuids(): array
    {
        /** @var list<string> $uuids */
        $uuids = $this->validated('uuids');

        return $uuids;
    }
}
