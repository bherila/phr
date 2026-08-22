<?php

namespace App\Services\AgentApi\Client;

use Mcp\Exception\ToolCallException;

/**
 * Validated JSON-object boundary between the REST API and its adapters.
 * Keeping this wrapper immutable prevents MCP handlers from reaching into HTTP
 * response objects or accidentally depending on headers/cookies.
 */
final readonly class AgentApiPayload
{
    /** @param array<string, mixed> $value */
    private function __construct(private array $value) {}

    /** @param list<string> $requiredKeys */
    public static function from(AgentApiTransportResponse $response, array $requiredKeys): self
    {
        if ($response->status < 200 || $response->status >= 300) {
            throw new ToolCallException(self::safeFailureMessage($response->status));
        }
        if ($response->json === null) {
            throw new ToolCallException('The PHR API returned an invalid response.');
        }
        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $response->json)) {
                throw new ToolCallException('The PHR API returned an invalid response.');
            }
        }

        return new self($response->json);
    }

    public static function page(AgentApiTransportResponse $response): self
    {
        $payload = self::from($response, ['data', 'pagination']);
        $data = $payload->value['data'];
        $pagination = $payload->value['pagination'];
        if (! is_array($data) || ! array_is_list($data)
            || array_filter($data, static fn (mixed $item): bool => ! is_array($item) || array_is_list($item)) !== []
            || ! is_array($pagination)
            || ! is_int($pagination['limit'] ?? null)
            || ! is_bool($pagination['has_more'] ?? null)
            || ! array_key_exists('next_cursor', $pagination)
            || (! is_string($pagination['next_cursor'] ?? null) && ($pagination['next_cursor'] ?? null) !== null)) {
            throw new ToolCallException('The PHR API returned an invalid response.');
        }

        return $payload;
    }

    public static function resolution(AgentApiTransportResponse $response): self
    {
        $payload = self::from($response, ['resource_type', 'patient_id', 'resolved', 'unresolved']);
        $resolved = $payload->value['resolved'];
        $unresolved = $payload->value['unresolved'];
        // resolved is keyed by external ID. It is checked by its values rather
        // than array_is_list, because PHP turns numeric-string keys back into
        // integers on decode -- external IDs of "0", "1", ... would otherwise
        // decode as a list and be rejected as drift.
        if (! is_array($resolved)
            || array_filter($resolved, static fn (mixed $entry): bool => ! is_array($entry) || array_is_list($entry)) !== []
            || ! is_array($unresolved)
            || ! array_is_list($unresolved)
            || array_filter($unresolved, static fn (mixed $id): bool => ! is_string($id)) !== []) {
            throw new ToolCallException('The PHR API returned an invalid response.');
        }

        return $payload;
    }

    /** @param list<string> $requiredKeys */
    public static function item(AgentApiTransportResponse $response, array $requiredKeys = ['data']): self
    {
        $payload = self::from($response, $requiredKeys);
        $data = $payload->value['data'] ?? null;
        if (! is_array($data) || array_is_list($data)) {
            throw new ToolCallException('The PHR API returned an invalid response.');
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->value;
    }

    private static function safeFailureMessage(int $status): string
    {
        return match ($status) {
            401 => 'The PHR API authorization is no longer valid.',
            403 => 'This connection lacks the required permission.',
            404 => 'The requested PHR resource was not found.',
            409 => 'The PHR API rejected the request because its current state conflicts.',
            422 => 'The PHR API rejected one or more request values.',
            429 => 'The PHR API rate limit was reached. Retry later.',
            default => 'The PHR API request could not be completed.',
        };
    }
}
