<?php

namespace App\DataTransferObjects\AgentApi;

final readonly class RespiratoryEventBatchData
{
    /** @param list<array<string, mixed>> $events */
    public function __construct(public array $events) {}

    /** @param array<mixed> $events */
    public static function from(array $events): self
    {
        if (! array_is_list($events)
            || array_filter($events, static fn (mixed $event): bool => ! is_array($event) || array_is_list($event)) !== []) {
            throw new \InvalidArgumentException('Respiratory events must be a list of objects.');
        }

        /** @var list<array<string, mixed>> $events */
        return new self($events);
    }

    /** @return array{events: list<array<string, mixed>>} */
    public function toRequestPayload(): array
    {
        return ['events' => $this->events];
    }
}
