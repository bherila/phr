<?php

namespace App\DataTransferObjects\AgentApi;

use InvalidArgumentException;

final readonly class ImportReviewData
{
    /** @param array<string, mixed>|null $payload */
    private function __construct(
        public string $action,
        public ?array $payload,
    ) {}

    /** @param array<string, mixed>|null $payload */
    public static function make(string $action, ?array $payload): self
    {
        if (! in_array($action, ['accept', 'reject'], true) || ($action === 'reject' && $payload !== null)) {
            throw new InvalidArgumentException('The import review request is invalid.');
        }

        return new self($action, $payload);
    }

    /** @return array{action: string, payload?: array<string, mixed>} */
    public function toRequestPayload(): array
    {
        return $this->payload === null
            ? ['action' => $this->action]
            : ['action' => $this->action, 'payload' => $this->payload];
    }
}
