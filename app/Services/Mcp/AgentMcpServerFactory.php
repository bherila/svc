<?php

namespace App\Services\Mcp;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Log\NullLogger;

final class AgentMcpServerFactory
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly AgentMcpToolCatalog $catalog,
        private readonly AgentMcpReadTools $reads,
        private readonly AgentMcpWriteTools $writes,
        private readonly AgentMcpInputSchemaFactory $inputs,
        private readonly AgentMcpOutputSchemaFactory $outputs,
    ) {}

    public function make(Request $request): Server
    {
        $logger = new NullLogger;
        $registry = new Registry(logger: $logger);
        $builder = Server::builder()
            ->setServerInfo(
                name: 'SVC Agent API',
                version: 'v1',
                description: 'Read authorized SVC projects, tasks, time, and invoices through the versioned REST API.',
                websiteUrl: url('/'),
            )
            ->setInstructions('Authenticate using OAuth Authorization Code with S256 PKCE. First call context.get; select an ID returned there and never guess a workspace or resource ID. This release is read-only. Invoice responses provide a browser URL for any payment flow; SVC does not expose payments, card data, or file blobs through MCP.')
            ->setPaginationLimit(100)
            ->setSession(new Psr16SessionStore($this->cache, 'svc_mcp_'.hash('sha256', $this->tokenIdentity($request)).'_', (int) config('agent_api.mcp_session_ttl_seconds')))
            // The SDK debug logger may contain tool arguments/results, so never enable it for agent traffic.
            ->setLogger($logger)
            ->setContainer(app())
            ->setRegistry($registry)
            ->setReferenceHandler(new ReferenceHandler(app()))
            ->addRequestHandler(new AgentMcpValidatedCallToolHandler(
                new CallToolHandler($registry, new ReferenceHandler(app()), $logger, new SchemaValidator($logger)),
                $registry,
                new SchemaValidator($logger),
            ))
            ->setLazyLoading(false);

        foreach ($this->catalog->definitions($this->reads, $this->writes) as $definition) {
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

    private function tokenIdentity(Request $request): string
    {
        $token = $request->bearerToken();

        return is_string($token) && $token !== '' ? $token : 'preflight';
    }
}
