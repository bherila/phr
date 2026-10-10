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
     * Every REST operation by operationId, as {@see AgentRestDocumentation}
     * declares it: its HTTP binding, its OAuth scopes (null for a public one)
     * and its prose.
     *
     * @return array<string, array{method: string, path: string, scopes: list<string>|null, summary: string, description: string}>
     */
    public static function operations(): array
    {
        if (self::$operations !== null) {
            return self::$operations;
        }
        $operations = [];
        foreach (AgentRestDocumentation::operations() as $id => $declared) {
            $operations[$id] = [
                'method' => $declared['method'],
                'path' => $declared['path'],
                'scopes' => $declared['scopes'],
                'summary' => $declared['summary'],
                'description' => is_string($declared['description']) ? $declared['description'] : '',
            ];
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
