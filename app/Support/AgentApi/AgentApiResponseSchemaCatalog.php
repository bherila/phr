<?php

namespace App\Support\AgentApi;

use InvalidArgumentException;
use JsonException;

/**
 * Packages the versioned OpenAPI response components as standalone JSON Schemas.
 *
 * The OpenAPI document is already the release-gated description of every agent
 * response, and it is OpenAPI 3.1, whose component schemas are JSON Schema
 * 2020-12 verbatim. Deriving MCP output schemas from it keeps one contract
 * instead of two that drift apart; hand-authoring a parallel set is how the
 * wire shape and its published description stop agreeing.
 *
 * MCP schemas must stand alone, so the transitive `$ref` closure is copied into
 * local `$defs` and every pointer is rewritten from `#/components/schemas/...`
 * to `#/$defs/...`. Nothing resolves against the OpenAPI document at runtime.
 */
final class AgentApiResponseSchemaCatalog
{
    private const string DOCUMENT = 'openapi/phr-agent-v1.json';

    private const string REF_PREFIX = '#/components/schemas/';

    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    /** @var array<string, string>|null */
    private static ?array $operationComponents = null;

    /** @var array<string, array<string, mixed>> */
    private static array $packaged = [];

    /**
     * A standalone JSON Schema for one response component.
     *
     * @return array<string, mixed>
     */
    public static function schema(string $component): array
    {
        return self::$packaged[$component] ??= self::package($component);
    }

    public static function has(string $component): bool
    {
        return isset(self::components()[$component]);
    }

    /** @return list<string> */
    public static function componentIds(): array
    {
        return array_keys(self::components());
    }

    /**
     * A standalone JSON Schema for the success response of one REST operation.
     *
     * This is the seam that keeps MCP and REST on one contract: a tool declares
     * the operation it mirrors, not a schema of its own.
     *
     * @return array<string, mixed>
     */
    public static function forOperation(string $operationId): array
    {
        return self::schema(self::operationComponent($operationId));
    }

    /** The response component name for a REST operation, or a hard failure. */
    public static function operationComponent(string $operationId): string
    {
        $components = self::operationComponents();
        if (! isset($components[$operationId])) {
            throw new InvalidArgumentException(
                "No agent API response schema is declared for operation [{$operationId}]."
            );
        }

        return $components[$operationId];
    }

    /** Test seam: the document is read once per process and memoized. */
    public static function flush(): void
    {
        self::$document = null;
        self::$operationComponents = null;
        self::$packaged = [];
    }

    /**
     * Maps every operation that declares a single success response component.
     *
     * Operations whose success body is not one component -- a file download, a
     * bare 204 -- are simply absent, so asking for one fails loudly rather than
     * resolving to something permissive.
     *
     * @return array<string, string>
     */
    private static function operationComponents(): array
    {
        if (self::$operationComponents !== null) {
            return self::$operationComponents;
        }

        $map = [];
        $paths = self::document()['paths'] ?? [];
        foreach (is_array($paths) ? $paths : [] as $operations) {
            foreach (is_array($operations) ? $operations : [] as $operation) {
                if (! is_array($operation) || ! is_string($operation['operationId'] ?? null)) {
                    continue;
                }
                $component = self::successComponent($operation);
                if ($component !== null) {
                    $map[$operation['operationId']] = $component;
                }
            }
        }

        return self::$operationComponents = $map;
    }

    /** @param array<string, mixed> $operation */
    private static function successComponent(array $operation): ?string
    {
        $responses = $operation['responses'] ?? [];
        foreach (['200', '201', '202'] as $status) {
            $ref = is_array($responses)
                ? ($responses[$status]['content']['application/json']['schema']['$ref'] ?? null)
                : null;
            if (is_string($ref) && str_starts_with($ref, self::REF_PREFIX)) {
                return substr($ref, strlen(self::REF_PREFIX));
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function package(string $component): array
    {
        $components = self::components();
        if (! isset($components[$component])) {
            throw new InvalidArgumentException("Unknown agent API response schema [{$component}].");
        }

        $reachable = [];
        self::collect($component, $components, $reachable);

        $schema = self::rewrite($components[$component]);

        // The root is inlined so the schema still reads as `type: object` for
        // clients that check it, and is additionally kept in `$defs` only when
        // something reachable points back at it.
        $referenced = $reachable;
        unset($referenced[$component]);
        $defs = [];
        foreach ($referenced as $name => $_) {
            $defs[$name] = self::rewrite($components[$name]);
        }
        if (self::referencesRoot($component, $referenced, $components)) {
            $defs[$component] = self::rewrite($components[$component]);
        }

        if ($defs !== []) {
            ksort($defs);
            $schema['$defs'] = $defs;
        }

        return $schema;
    }

    /**
     * @param  array<string, array<string, mixed>>  $components
     * @param  array<string, true>  $seen
     */
    private static function collect(string $component, array $components, array &$seen): void
    {
        if (isset($seen[$component])) {
            return;
        }
        $seen[$component] = true;
        foreach (self::refsIn($components[$component] ?? []) as $ref) {
            if (! isset($components[$ref])) {
                throw new InvalidArgumentException("Dangling response schema reference [{$ref}].");
            }
            self::collect($ref, $components, $seen);
        }
    }

    /**
     * @param  array<string, true>  $referenced
     * @param  array<string, array<string, mixed>>  $components
     */
    private static function referencesRoot(string $root, array $referenced, array $components): bool
    {
        foreach ([...array_keys($referenced), $root] as $name) {
            if (in_array($root, self::refsIn($components[$name] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $node
     * @return list<string>
     */
    private static function refsIn(array $node): array
    {
        $refs = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, self::REF_PREFIX)) {
                $refs[] = substr($value, strlen(self::REF_PREFIX));

                continue;
            }
            if (is_array($value)) {
                $refs = [...$refs, ...self::refsIn($value)];
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $node
     * @return array<string, mixed>|list<mixed>
     */
    private static function rewrite(array $node): array
    {
        $rewritten = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, self::REF_PREFIX)) {
                $rewritten[$key] = '#/$defs/'.substr($value, strlen(self::REF_PREFIX));

                continue;
            }
            $rewritten[$key] = is_array($value) ? self::rewrite($value) : $value;
        }

        return $rewritten;
    }

    /** @return array<string, array<string, mixed>> */
    private static function components(): array
    {
        $schemas = self::document()['components']['schemas'] ?? null;
        if (! is_array($schemas) || $schemas === []) {
            throw new InvalidArgumentException('The agent API OpenAPI document declares no response schemas.');
        }

        return $schemas;
    }

    /** @return array<string, mixed> */
    private static function document(): array
    {
        if (self::$document !== null) {
            return self::$document;
        }

        $path = public_path(self::DOCUMENT);
        $contents = is_readable($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new InvalidArgumentException('The agent API OpenAPI document is missing or unreadable.');
        }

        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The agent API OpenAPI document is not valid JSON.');
        }

        if (! is_array($document)) {
            throw new InvalidArgumentException('The agent API OpenAPI document is not an object.');
        }

        return self::$document = $document;
    }
}
