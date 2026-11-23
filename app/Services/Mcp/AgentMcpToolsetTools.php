<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Mcp\ToolDefinition;
use Mcp\Exception\ClientException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Server\RequestContext;

/**
 * The toolsets.list, toolsets.enable and toolsets.disable handlers for one
 * MCP request. They change only what the session lists: the tools a toolset
 * holds are the connection's authorized tools, decided by its scopes before
 * this object exists.
 */
final readonly class AgentMcpToolsetTools
{
    /**
     * @param  array<string, list<ToolDefinition>>  $available  each toolset with an authorized tool, and those tools
     * @param  bool  $canNotify  whether this request's client accepts a server-sent event stream for its response
     */
    public function __construct(
        private array $available,
        private bool $canNotify,
    ) {}

    /** @return array{toolsets: list<array{name: string, description: string, tool_count: int, enabled: bool}>} */
    public function list(RequestContext $context): array
    {
        $enabled = AgentMcpToolsets::enabled($context->getSession());
        $toolsets = [];
        foreach ($this->available as $name => $tools) {
            $toolsets[] = [
                'name' => $name,
                'description' => AgentMcpToolsets::TOOLSETS[$name],
                'tool_count' => count($tools),
                'enabled' => in_array($name, $enabled, true),
            ];
        }

        return ['toolsets' => $toolsets];
    }

    /**
     * @param  list<string>  $toolsets
     * @return array{enabled_toolsets: list<string>, tools: list<array{name: string, toolset: string, description: string}>, list_changed: bool, notified: bool}
     */
    public function enable(array $toolsets, RequestContext $context): array
    {
        $requested = $this->requested($toolsets);
        $session = $context->getSession();
        $before = AgentMcpToolsets::enabled($session);
        AgentMcpToolsets::store($session, [...$before, ...$requested]);
        $after = AgentMcpToolsets::enabled($session);

        $tools = [];
        foreach ($requested as $toolset) {
            foreach ($this->available[$toolset] as $definition) {
                $tools[] = [
                    'name' => $definition->name,
                    'toolset' => $toolset,
                    'description' => self::shortDescription($definition->description),
                ];
            }
        }

        return [
            'enabled_toolsets' => $after,
            'tools' => $tools,
            ...$this->changed($before, $after, $context),
        ];
    }

    /**
     * @param  list<string>  $toolsets
     * @return array{enabled_toolsets: list<string>, list_changed: bool, notified: bool}
     */
    public function disable(array $toolsets, RequestContext $context): array
    {
        $requested = $this->requested($toolsets);
        $session = $context->getSession();
        $before = AgentMcpToolsets::enabled($session);
        AgentMcpToolsets::store($session, array_values(array_diff($before, $requested)));
        $after = AgentMcpToolsets::enabled($session);

        return [
            'enabled_toolsets' => $after,
            ...$this->changed($before, $after, $context),
        ];
    }

    /** @return array<string, mixed> */
    public static function inputSchema(string $tool): array
    {
        if ($tool === AgentMcpToolsets::LIST) {
            return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
        }

        return [
            'type' => 'object',
            'properties' => [
                'toolsets' => [
                    'type' => 'array',
                    'description' => 'Toolset names from toolsets.list.',
                    'items' => ['type' => 'string', 'enum' => array_keys(AgentMcpToolsets::TOOLSETS)],
                    'minItems' => 1,
                    'maxItems' => count(AgentMcpToolsets::TOOLSETS),
                    'uniqueItems' => true,
                ],
            ],
            'required' => ['toolsets'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function outputSchema(string $tool): array
    {
        $names = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(AgentMcpToolsets::TOOLSETS)]];
        if ($tool === AgentMcpToolsets::LIST) {
            return self::object([
                'toolsets' => ['type' => 'array', 'items' => self::object([
                    'name' => ['type' => 'string', 'enum' => array_keys(AgentMcpToolsets::TOOLSETS)],
                    'description' => ['type' => 'string'],
                    'tool_count' => ['type' => 'integer', 'minimum' => 1],
                    'enabled' => ['type' => 'boolean'],
                ])],
            ]);
        }
        $changed = ['list_changed' => ['type' => 'boolean'], 'notified' => ['type' => 'boolean']];
        if ($tool === AgentMcpToolsets::ENABLE) {
            return self::object([
                'enabled_toolsets' => $names,
                'tools' => ['type' => 'array', 'items' => self::object([
                    'name' => ['type' => 'string'],
                    'toolset' => ['type' => 'string', 'enum' => array_keys(AgentMcpToolsets::TOOLSETS)],
                    'description' => ['type' => 'string'],
                ])],
                ...$changed,
            ]);
        }

        return self::object(['enabled_toolsets' => $names, ...$changed]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /**
     * @param  list<string>  $toolsets
     * @return list<string>
     */
    private function requested(array $toolsets): array
    {
        foreach ($toolsets as $toolset) {
            if (! isset($this->available[$toolset])) {
                throw new ToolCallException("Toolset {$toolset} has no tool this connection may use. Call toolsets.list for the toolsets it can enable.");
            }
        }

        return array_values(array_unique($toolsets));
    }

    /**
     * Tells the client to list its tools again when the listing changed. A
     * notification can only travel on an event stream, so a client that
     * accepts JSON alone is not sent one and learns of the change from the
     * result instead.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return array{list_changed: bool, notified: bool}
     */
    private function changed(array $before, array $after, RequestContext $context): array
    {
        $changed = $before !== $after;
        $notified = false;
        if ($changed && $this->canNotify) {
            try {
                $context->getClientGateway()->notify(new ToolListChangedNotification);
                $notified = true;
            } catch (ClientException) {
                // The listing changed either way; the result says so.
            }
        }

        return ['list_changed' => $changed, 'notified' => $notified];
    }

    private static function shortDescription(string $description): string
    {
        $sentence = preg_split('/(?<=[.!?])\s+/', trim($description), 2)[0] ?? $description;

        return mb_strlen($sentence) > 200 ? rtrim(mb_substr($sentence, 0, 199)).'…' : $sentence;
    }
}
