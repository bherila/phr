<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentApiJson;
use Illuminate\Http\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;

/** Typed access to raw MCP argument shapes lost by associative SDK decoding. */
final readonly class AgentMcpRequestArguments
{
    /** @var array<string, array<string, mixed>> */
    private array $calls;

    public function __construct(Request $request)
    {
        $this->calls = $this->parse($request->getContent());
    }

    public function value(RequestContext $context, string $name, mixed $fallback): mixed
    {
        $request = $context->getRequest();
        if (! $request instanceof CallToolRequest) {
            return $fallback;
        }

        $arguments = $this->calls[$this->key($request->getId(), $request->name)] ?? null;

        return is_array($arguments) && array_key_exists($name, $arguments)
            ? $arguments[$name]
            : $fallback;
    }

    /** @return array<string, array<string, mixed>> */
    private function parse(string $content): array
    {
        $decoded = AgentApiJson::decodeRaw($content);
        $messages = is_array($decoded) ? $decoded : [$decoded];
        $calls = [];
        foreach ($messages as $message) {
            if (! is_object($message)
                || ($message->method ?? null) !== 'tools/call'
                || (! is_int($message->id ?? null) && ! is_string($message->id ?? null))
                || ! is_object($message->params ?? null)
                || ! is_string($message->params->name ?? null)
                || ! is_object($message->params->arguments ?? null)) {
                continue;
            }
            $calls[$this->key($message->id, $message->params->name)] =
                AgentApiJson::objectProperties($message->params->arguments);
        }

        return $calls;
    }

    private function key(string|int $id, string $tool): string
    {
        return get_debug_type($id).':'.$id."\0".$tool;
    }
}
