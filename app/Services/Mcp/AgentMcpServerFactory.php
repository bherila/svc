<?php

namespace App\Services\Mcp;

use App\Services\Authorization\AgentTokenScopes;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Mcp\CredentialSessionNamespace;
use Bherila\McpLaravelBridge\Mcp\OriginalShapeSchemaValidator;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Bherila\McpLaravelBridge\Mcp\ValidatedCallToolHandler;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AgentMcpServerFactory
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly AgentMcpToolCatalog $catalog,
        private readonly AgentMcpReadTools $reads,
        private readonly AgentMcpWriteTools $writes,
        private readonly AgentMcpPrompts $prompts,
        private readonly AgentMcpInputSchemaFactory $inputs,
        private readonly AgentMcpOutputSchemaFactory $outputs,
        private readonly RequestArguments $requestArguments,
        private readonly AgentTokenScopes $scopes,
    ) {}

    public function make(Request $request): Server
    {
        $logger = new NullLogger;
        $driftLogger = app(LoggerInterface::class);
        $registry = new Registry(logger: $logger);
        $definitions = $this->catalog->definitions($this->reads, $this->writes);
        $schemaIds = [];
        foreach ($definitions as $definition) {
            $schemaIds[$definition->name] = AgentApiResponseSchemaCatalog::operationComponent($definition->operationId());
        }
        $builder = Server::builder()
            ->setServerInfo(
                name: 'SVC Agent API',
                version: 'v1',
                description: (bool) config('agent_api.writes_enabled')
                    ? 'Read and safely manage authorized SVC tasks, time, and invoices through the versioned REST API.'
                    : 'Read authorized SVC projects, tasks, time, and invoices through the versioned REST API.',
                websiteUrl: url('/'),
            )
            ->setInstructions($this->instructions())
            ->addPrompt(
                handler: [$this->prompts, 'logTimeAcrossProjects'],
                name: 'log-time-across-projects',
                title: 'Log time across projects',
                description: 'Safely discover SVC projects and log one or more completed time entries with retry-safe idempotency.',
            )
            ->addPrompt(
                handler: [$this->prompts, 'prepareInvoiceSafely'],
                name: 'prepare-invoice-safely',
                title: 'Prepare an invoice safely',
                description: 'Build and review an invoice draft while preserving explicit confirmation for consequential actions.',
            )
            ->setPaginationLimit(100)
            ->setSession(new Psr16SessionStore($this->cache, CredentialSessionNamespace::prefix($request, 'svc_mcp_'), (int) config('agent_api.mcp_session_ttl_seconds')))
            // The SDK debug logger may contain tool arguments/results, so never enable it for agent traffic.
            ->setLogger($logger)
            ->setContainer(app())
            ->setRegistry($registry)
            ->setReferenceHandler(new ReferenceHandler(app()))
            ->addRequestHandler(new ValidatedCallToolHandler(
                new CallToolHandler($registry, new ReferenceHandler(app()), $logger, new OriginalShapeSchemaValidator($logger, $this->requestArguments)),
                $registry,
                new SchemaValidator($logger),
                $schemaIds,
                $driftLogger,
                'The SVC API returned a response that failed its output contract.',
            ))
            ->setLazyLoading(false);

        foreach ($definitions as $definition) {
            if (! $this->scopes->allowsAll($request, AgentApiResponseSchemaCatalog::scopesForOperation($definition->operationId()))) {
                continue;
            }
            $builder->addTool(
                handler: $definition->handler,
                name: $definition->name,
                title: $definition->title,
                description: $definition->description,
                annotations: new ToolAnnotations(readOnlyHint: $definition->readOnly, destructiveHint: $definition->destructive, idempotentHint: $definition->idempotent, openWorldHint: false),
                inputSchema: $this->inputs->for($definition),
                outputSchema: $this->outputs->for($definition),
            );
        }

        return $builder->build();
    }

    private function instructions(): string
    {
        $base = 'First call context.get; select only workspace and resource IDs returned by SVC and never guess an ID. For time tracking, use projects.list to match projects, tasks.list only when needed, and time_entries.log for completed work with the exact date, whole minutes, description, and a stable idempotency key. Reuse a key only for an identical retry and never approve time unless the user asks. Read an existing record before updating or deleting it and supply its current opaque version.';
        $mode = (bool) config('agent_api.writes_enabled')
            ? 'Task, time, and invoice workflow writes are enabled. Read the current record before mutation, supply its opaque version when required, and obtain explicit user confirmation before issue, send, or void.'
            : 'This release is read-only; use the SVC website for changes.';

        return $base.' '.$mode.' Authenticate using OAuth Authorization Code with S256 PKCE. Invoice responses provide a browser URL for any payment flow; SVC does not expose payments, card data, project mutations, or file uploads through MCP. Use the log-time-across-projects and prepare-invoice-safely prompts for complete guided workflows when the client exposes MCP prompts.';
    }
}
