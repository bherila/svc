<?php

namespace App\Services\Mcp;

use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Throwable;

/**
 * Enforces the advertised output schemas; no MCP response is schema-by-claim.
 *
 * @implements RequestHandlerInterface<CallToolResult>
 */
final readonly class AgentMcpValidatedCallToolHandler implements RequestHandlerInterface
{
    public function __construct(
        private CallToolHandler $inner,
        private RegistryInterface $registry,
        private SchemaValidator $validator,
    ) {}

    public function supports(Request $request): bool
    {
        return $this->inner->supports($request);
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        $response = $this->inner->handle($request, $session);
        if (! $request instanceof CallToolRequest || ! $response instanceof Response || $response->result->isError || $response->result->structuredContent === null) {
            return $response;
        }
        try {
            $errors = $this->validator->validateAgainstJsonSchema(
                $response->result->structuredContent,
                $this->registry->getTool($request->name)->tool->outputSchema,
            );
        } catch (Throwable) {
            $errors = [['keyword' => 'validation-unavailable']];
        }

        return $errors === [] ? $response : Error::forInternalError('The SVC API returned a response that failed its output contract.', $request->getId());
    }
}
