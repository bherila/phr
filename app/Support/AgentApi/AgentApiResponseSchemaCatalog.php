<?php

namespace App\Support\AgentApi;

use Bherila\McpLaravelBridge\OpenApi\SchemaCatalog;

/** Application path facade over the shared OpenAPI contract resolver. */
final class AgentApiResponseSchemaCatalog
{
    private static ?SchemaCatalog $catalog = null;

    /** @var array<string, array{method: string, path: string, scopes: list<string>|null, summary: string, description: string}>|null */
    private static ?array $operations = null;

    /**
     * Every operation in the document by operationId: its HTTP binding, its
     * OAuth scopes (null for one declared with no security at all) and its
     * prose.
     *
     * @return array<string, array{method: string, path: string, scopes: list<string>|null, summary: string, description: string}>
     */
    public static function operations(): array
    {
        if (self::$operations !== null) {
            return self::$operations;
        }
        $document = json_decode((string) file_get_contents(public_path('openapi/phr-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $operations = [];
        foreach ($document['paths'] ?? [] as $path => $item) {
            foreach (is_array($item) ? $item : [] as $method => $operation) {
                if (! is_array($operation) || ! is_string($operation['operationId'] ?? null)) {
                    continue;
                }
                $security = $operation['security'] ?? null;
                $operations[$operation['operationId']] = [
                    'method' => strtoupper((string) $method),
                    'path' => (string) $path,
                    'scopes' => $security === [] ? null : array_values(array_filter($security[0]['oauth2'] ?? [], 'is_string')),
                    'summary' => (string) ($operation['summary'] ?? ''),
                    'description' => (string) ($operation['description'] ?? ''),
                ];
            }
        }

        return self::$operations = $operations;
    }

    /** @return array<string, mixed> */
    public static function schema(string $component): array
    {
        return self::catalog()->schema($component);
    }

    /** @return list<string> */
    public static function componentIds(): array
    {
        return self::catalog()->componentIds();
    }

    public static function has(string $component): bool
    {
        return in_array($component, self::componentIds(), true);
    }

    /** @return array<string, mixed> */
    public static function forOperation(string $operationId): array
    {
        return self::catalog()->forOperation($operationId);
    }

    public static function operationComponent(string $operationId): string
    {
        return self::catalog()->operationComponent($operationId);
    }

    /** @return list<string> */
    public static function scopesForOperation(string $operationId): array
    {
        return self::catalog()->scopesForOperation($operationId);
    }

    public static function flush(): void
    {
        self::$catalog?->flush();
        self::$catalog = null;
        self::$operations = null;
    }

    private static function catalog(): SchemaCatalog
    {
        return self::$catalog ??= new SchemaCatalog(public_path('openapi/phr-agent-v1.json'));
    }
}
