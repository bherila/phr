<?php

namespace App\Services\AgentApi\Client;

use Bherila\McpLaravelBridge\Http\AgentApiTransportResponse;
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
            throw new ToolCallException(self::failureMessage($response->status, $response->json));
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
        // resolved is a map keyed by external ID, and AgentApiJson deliberately
        // keeps an empty object and a numeric-key object as stdClass so `{}`
        // never becomes `[]` on the wire. Both are legitimate here -- an empty
        // map is what a client's first sync pass gets back -- so the entries
        // are read through a temporary view. The stored payload keeps its
        // original shape; normalizing it to an array would reintroduce exactly
        // the distinction the decoder exists to preserve.
        //
        // A JSON list is drift, empty or not: the controller emits an object,
        // and accepting `[]` here would discard the one shape distinction the
        // decoder exists to keep.
        $entries = match (true) {
            is_object($resolved) => get_object_vars($resolved),
            is_array($resolved) && ! array_is_list($resolved) => $resolved,
            default => null,
        };
        if ($entries === null
            || array_filter($entries, static fn (mixed $entry): bool => ! is_array($entry) || array_is_list($entry)) !== []
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

    /**
     * A conflict or validation refusal is written for the caller and says what
     * to change (re-read the record, pick another external ID, fix a field), so
     * its message and first field error reach the MCP client. Every other
     * failure keeps a fixed message: its body is not written for agents.
     *
     * @param  array<string, mixed>|null  $body
     */
    private static function failureMessage(int $status, ?array $body): string
    {
        if (! in_array($status, [409, 422], true)) {
            return self::safeFailureMessage($status);
        }
        $parts = [];
        foreach ([$body['message'] ?? null, self::firstFieldError($body['errors'] ?? null)] as $part) {
            $part = is_string($part) ? trim((string) preg_replace('/[[:cntrl:]]+/u', ' ', $part)) : '';
            if ($part !== '' && ! in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        return $parts === [] ? self::safeFailureMessage($status) : mb_strimwidth(implode(' ', $parts), 0, self::MAX_REFUSAL_LENGTH, '…');
    }

    private const int MAX_REFUSAL_LENGTH = 500;

    private static function firstFieldError(mixed $errors): ?string
    {
        foreach (is_array($errors) ? $errors : [] as $fieldErrors) {
            foreach (is_array($fieldErrors) ? $fieldErrors : [] as $error) {
                if (is_string($error) && trim($error) !== '') {
                    return $error;
                }
            }
        }

        return null;
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
