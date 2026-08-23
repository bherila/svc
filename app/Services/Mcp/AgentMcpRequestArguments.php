<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentApiJson;
use Illuminate\Http\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;

/** Access to raw MCP argument presence lost by typed handler binding. */
final class AgentMcpRequestArguments
{
    /** @var array<string, array<string, mixed>> */
    private array $calls;

    /** @var list<array<string, mixed>> */
    private array $validationCalls;

    private int $validationCursor = 0;

    public function __construct(Request $request)
    {
        [$this->calls, $this->validationCalls] = $this->parse($request->getContent());
    }

    public function has(RequestContext $context, string $name): bool
    {
        $request = $context->getRequest();
        if (! $request instanceof CallToolRequest) {
            return false;
        }

        $arguments = $this->calls[$this->key($request->getId(), $request->name)] ?? null;

        return is_array($arguments) && array_key_exists($name, $arguments);
    }

    /** @return array<string, mixed>|null */
    public function nextValidationArguments(mixed $sdkArguments): ?array
    {
        for ($index = $this->validationCursor; $index < count($this->validationCalls); $index++) {
            $arguments = $this->validationCalls[$index];
            if ($this->sdkShape($arguments) === $sdkArguments) {
                $this->validationCursor = $index + 1;

                return $arguments;
            }
        }

        return null;
    }

    /** @return array{array<string, array<string, mixed>>, list<array<string, mixed>>} */
    private function parse(string $content): array
    {
        $decoded = AgentApiJson::decodeRaw($content);
        $messages = is_array($decoded) ? $decoded : [$decoded];
        $calls = [];
        $validationCalls = [];
        foreach ($messages as $message) {
            if (! is_object($message)
                || ($message->method ?? null) !== 'tools/call'
                || (! is_int($message->id ?? null) && ! is_string($message->id ?? null))
                || ! is_object($message->params ?? null)
                || ! is_string($message->params->name ?? null)
                || ! is_object($message->params->arguments ?? null)) {
                continue;
            }
            $arguments = AgentApiJson::objectProperties($message->params->arguments);
            $calls[$this->key($message->id, $message->params->name)] = $arguments;
            $validationCalls[] = $arguments;
        }

        return [$calls, $validationCalls];
    }

    /** @param array<string, mixed> $arguments */
    private function sdkShape(array $arguments): mixed
    {
        return json_decode(json_encode($arguments, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function key(string|int $id, string $tool): string
    {
        return get_debug_type($id).':'.$id."\0".$tool;
    }
}
