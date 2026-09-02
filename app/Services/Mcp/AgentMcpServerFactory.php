<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\AgentAgreementReadService;
use App\Services\AgentApi\AgentBillingAuditReadService;
use App\Services\AgentApi\AgentBillingScheduleReadService;
use App\Services\AgentApi\AgentCapacityLedgerReadService;
use App\Services\AgentApi\AgentReadService;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpAuthorizer;
use App\Services\Mcp\Context\McpPrincipalResolverInterface;
use App\Services\Mcp\Context\McpRequestContext;
use App\Services\Mcp\Registry\McpCapabilityDefinition;
use App\Services\Mcp\Registry\McpCapabilityKind;
use Bherila\McpLaravelBridge\Mcp\CredentialSessionNamespace;
use Bherila\McpLaravelBridge\Mcp\OriginalShapeSchemaValidator;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Bherila\McpLaravelBridge\Mcp\ValidatedCallToolHandler;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Schema\JsonRpc\Request as JsonRpcRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\GetPromptHandler;
use Mcp\Server\Handler\Request\ReadResourceHandler;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AgentMcpServerFactory
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly AgentMcpCapabilityRegistryFactory $capabilities,
        private readonly McpFeatureFlags $featureFlags,
        private readonly AgentReadService $readService,
        private readonly AgentAgreementReadService $agreementReadService,
        private readonly AgentBillingScheduleReadService $billingScheduleReadService,
        private readonly AgentCapacityLedgerReadService $capacityLedgerReadService,
        private readonly AgentBillingAuditReadService $billingAuditReadService,
        private readonly McpAccountContextResolver $accounts,
        private readonly McpAuthorizer $authorizer,
        private readonly McpPrincipalResolverInterface $principals,
        private readonly AgentMcpWriteTools $writes,
        private readonly AgentMcpPrompts $prompts,
        private readonly RequestArguments $requestArguments,
    ) {}

    public function make(Request $request): Server
    {
        $logger = new NullLogger;
        $driftLogger = app(LoggerInterface::class);
        $capabilityAuditor = new McpCapabilityAuditor($driftLogger, app(Dispatcher::class));
        $registry = new Registry(logger: $logger);
        $context = new McpRequestContext(
            $this->principals->resolve($request),
            $this->requestId($request),
        );
        $reads = new AgentMcpReadTools($this->readService, $this->accounts, $context);
        $contextResource = new AgentMcpContextResource($this->readService, $context);
        $agreements = new AgentMcpAgreementTools($this->agreementReadService, $this->accounts, $context);
        $agreementResource = new AgentMcpAgreementResource($this->agreementReadService, $this->accounts, $context);
        $schedules = new AgentMcpBillingScheduleTools($this->billingScheduleReadService, $this->accounts, $context);
        $capacityLedger = new AgentMcpCapacityLedgerTools($this->capacityLedgerReadService, $this->accounts, $context);
        $billingAudits = new AgentMcpBillingAuditTools($this->billingAuditReadService, $this->accounts, $context);
        $writes = $this->writes->forContext($context);
        $resultLimiter = new McpCapabilityResultLimiter;
        $cacheStore = $this->cache instanceof Repository ? $this->cache->getStore() : null;
        $concurrencyLimiter = new McpCapabilityConcurrencyLimiter($cacheStore instanceof LockProvider ? $cacheStore : null);
        $definitions = $this->capabilities->make($reads, $contextResource, $agreements, $agreementResource, $schedules, $capacityLedger, $billingAudits, $this->prompts, $writes)->all();
        $hasManagerCapabilities = false;
        foreach ($definitions as $definition) {
            if ($definition->policyAbility === 'AgentAccess::isWorkspaceManager') {
                $hasManagerCapabilities = true;
                break;
            }
        }
        $hasManagedWorkspace = $hasManagerCapabilities ? $this->authorizer->hasManagedWorkspace($context) : null;
        $availableCapabilities = array_values(array_filter(
            $definitions,
            fn (McpCapabilityDefinition $definition): bool => $this->featureFlags->enabled($definition)
                && $this->authorizer->allowsDiscovery($context, $definition, $hasManagedWorkspace),
        ));
        $availableNames = array_fill_keys(array_map(
            static fn (McpCapabilityDefinition $definition): string => $definition->name,
            $availableCapabilities,
        ), true);
        $availableCapabilities = array_values(array_filter(
            $availableCapabilities,
            static fn (McpCapabilityDefinition $definition): bool => array_diff($definition->requiredCapabilities, array_keys($availableNames)) === [],
        ));
        $exposedDefinitions = array_values(array_filter(
            $availableCapabilities,
            static fn (McpCapabilityDefinition $definition): bool => $definition->kind === McpCapabilityKind::Tool,
        ));
        $exposedToolNames = array_fill_keys(array_map(
            static fn (McpCapabilityDefinition $definition): string => $definition->name,
            $exposedDefinitions,
        ), true);
        $hasWriteTools = collect($exposedDefinitions)->contains(
            static fn (McpCapabilityDefinition $definition): bool => ! $definition->readOnly,
        );
        $hasResources = collect($availableCapabilities)->contains(
            static fn (McpCapabilityDefinition $definition): bool => in_array($definition->kind, [McpCapabilityKind::Resource, McpCapabilityKind::ResourceTemplate], true),
        );
        $hasPrompts = collect($availableCapabilities)->contains(
            static fn (McpCapabilityDefinition $definition): bool => $definition->kind === McpCapabilityKind::Prompt,
        );
        $schemaIds = [];
        foreach ($exposedDefinitions as $definition) {
            $schemaIds[$definition->name] = $definition->name;
        }
        $builder = Server::builder()
            ->setServerInfo(
                name: 'SVC Agent API',
                version: 'v1',
                description: $hasWriteTools
                    ? 'Read authorized SVC data and safely use the write operations authorized for this connection.'
                    : 'Read authorized SVC projects, tasks, time, and invoices through the versioned REST API.',
                websiteUrl: url('/'),
            )
            ->setInstructions($this->instructions($exposedToolNames, $hasWriteTools));

        $builder->setPaginationLimit(100)
            ->setCapabilities(new ServerCapabilities(
                tools: $exposedDefinitions !== [],
                toolsListChanged: false,
                resources: $hasResources,
                resourcesSubscribe: false,
                resourcesListChanged: false,
                prompts: $hasPrompts,
                promptsListChanged: false,
                logging: false,
                completions: false,
            ))
            ->setSession(new Psr16SessionStore($this->cache, CredentialSessionNamespace::prefix($request, 'svc_mcp_'), (int) config('agent_api.mcp_session_ttl_seconds')))
            // The SDK debug logger may contain tool arguments/results, so never enable it for agent traffic.
            ->setLogger($logger)
            ->setContainer(app())
            ->setRegistry($registry)
            ->setReferenceHandler(new ReferenceHandler(app()))
            ->addRequestHandler(new McpRateLimitedCallToolHandler(
                new ValidatedCallToolHandler(
                    new CallToolHandler($registry, new ReferenceHandler(app()), $logger, new OriginalShapeSchemaValidator($logger, $this->requestArguments)),
                    $registry,
                    new SchemaValidator($logger),
                    $schemaIds,
                    $driftLogger,
                    'The SVC API returned a response that failed its output contract.',
                ),
                new McpCapabilityRateLimiter(app(RateLimiter::class)),
                $resultLimiter,
                $concurrencyLimiter,
                $capabilityAuditor,
                $context,
                $this->capabilityMetadata($definitions, McpCapabilityKind::Tool),
            ))
            ->addRequestHandler(new McpUnsupportedOptionalProtocolHandler)
            ->addRequestHandler(new McpUnsupportedResourceSubscriptionHandler)
            ->addRequestHandler(new McpAuditedCapabilityRequestHandler(
                new ReadResourceHandler($registry, new ReferenceHandler(app()), $logger),
                new McpCapabilityRateLimiter(app(RateLimiter::class)),
                $resultLimiter,
                $concurrencyLimiter,
                $capabilityAuditor,
                $context,
                [
                    ...$this->capabilityMetadata($definitions, McpCapabilityKind::Resource, static fn (McpCapabilityDefinition $definition): string => $definition->uri ?? $definition->name),
                    ...$this->capabilityMetadata($definitions, McpCapabilityKind::ResourceTemplate, static fn (McpCapabilityDefinition $definition): string => $definition->uri ?? $definition->name),
                ],
                function (JsonRpcRequest $request) use ($definitions): string {
                    if (! $request instanceof ReadResourceRequest) {
                        throw new LogicException('MCP resource audit handler received an invalid request.');
                    }

                    return $this->resourceCapabilityKey($definitions, $request->uri);
                },
            ))
            ->addRequestHandler(new McpAuditedCapabilityRequestHandler(
                new GetPromptHandler($registry, new ReferenceHandler(app()), $logger),
                new McpCapabilityRateLimiter(app(RateLimiter::class)),
                $resultLimiter,
                $concurrencyLimiter,
                $capabilityAuditor,
                $context,
                $this->capabilityMetadata($definitions, McpCapabilityKind::Prompt),
                function (JsonRpcRequest $request) use ($definitions): string {
                    if (! $request instanceof GetPromptRequest) {
                        throw new LogicException('MCP prompt audit handler received an invalid request.');
                    }

                    return $this->promptCapabilityKey($definitions, $request->name);
                },
            ))
            ->setLazyLoading(false);

        foreach ($exposedDefinitions as $definition) {
            $builder->addTool(
                handler: $definition->handler,
                name: $definition->name,
                title: $definition->title,
                description: $definition->description,
                annotations: new ToolAnnotations(readOnlyHint: $definition->readOnly, destructiveHint: $definition->destructive, idempotentHint: $definition->idempotent, openWorldHint: false),
                inputSchema: $definition->inputSchema,
                outputSchema: $definition->outputSchema,
            );
        }
        foreach ($availableCapabilities as $definition) {
            if ($definition->kind !== McpCapabilityKind::Resource || $definition->uri === null) {
                continue;
            }
            $builder->addResource(
                handler: $definition->handler,
                uri: $definition->uri,
                name: $definition->name,
                title: $definition->title,
                description: $definition->description,
                mimeType: 'application/json',
            );
        }
        foreach ($availableCapabilities as $definition) {
            if ($definition->kind !== McpCapabilityKind::ResourceTemplate || $definition->uri === null) {
                continue;
            }
            $builder->addResourceTemplate(
                handler: $definition->handler,
                uriTemplate: $definition->uri,
                name: $definition->name,
                title: $definition->title,
                description: $definition->description,
                mimeType: 'application/json',
            );
        }
        foreach ($availableCapabilities as $definition) {
            if ($definition->kind !== McpCapabilityKind::Prompt) {
                continue;
            }
            $builder->addPrompt(
                handler: $definition->handler,
                name: $definition->name,
                title: $definition->title,
                description: $definition->description,
            );
        }

        return $builder->build();
    }

    /**
     * @param  array<string, true>  $available
     * @param  list<string>  $required
     */
    private function hasTools(array $available, array $required): bool
    {
        return array_diff($required, array_keys($available)) === [];
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->header('X-Request-Id');

        return is_string($requestId) && preg_match('/^[A-Za-z0-9_-]{8,128}$/', $requestId) === 1
            ? $requestId
            : (string) Str::uuid();
    }

    /**
     * @param  list<McpCapabilityDefinition>  $definitions
     * @param  (Closure(McpCapabilityDefinition): string)|null  $key
     * @return array<string, array{rate_limit_bucket: string, audit_classification: string}>
     */
    private function capabilityMetadata(array $definitions, McpCapabilityKind $kind, ?Closure $key = null): array
    {
        $metadata = [];
        foreach ($definitions as $definition) {
            if ($definition->kind !== $kind) {
                continue;
            }
            $metadata[($key ?? static fn (McpCapabilityDefinition $definition): string => $definition->name)($definition)] = [
                'rate_limit_bucket' => $definition->rateLimitBucket,
                'audit_classification' => $definition->auditClassification,
            ];
        }

        return $metadata;
    }

    /** @param list<McpCapabilityDefinition> $definitions */
    private function resourceCapabilityKey(array $definitions, string $uri): string
    {
        foreach ($definitions as $definition) {
            if ($definition->kind === McpCapabilityKind::Resource && $definition->uri === $uri) {
                return $uri;
            }
        }
        foreach ($definitions as $definition) {
            if ($definition->kind !== McpCapabilityKind::ResourceTemplate || $definition->uri === null) {
                continue;
            }
            $template = new ResourceTemplate($definition->uri, $definition->name);
            if ((new ResourceTemplateReference($template, $definition->handler))->matches($uri)) {
                return $definition->uri;
            }
        }

        return 'mcp.unknown_resource';
    }

    /** @param list<McpCapabilityDefinition> $definitions */
    private function promptCapabilityKey(array $definitions, string $name): string
    {
        foreach ($definitions as $definition) {
            if ($definition->kind === McpCapabilityKind::Prompt && $definition->name === $name) {
                return $name;
            }
        }

        return 'mcp.unknown_prompt';
    }

    /** @param array<string, true> $available */
    private function instructions(array $available, bool $hasWriteTools): string
    {
        if ($this->hasTools($available, ['context.get', 'projects.list', 'time_entries.log'])) {
            $base = 'First call context.get; select only workspace and resource IDs returned by SVC and never guess an ID. For time tracking, use projects.list to match projects, tasks.list only when available and needed, and time_entries.log for completed work with the exact date, whole minutes, description, and a stable idempotency key. Reuse a key only for an identical retry and never approve time unless the user asks. Read an existing record before updating or deleting it and supply its current opaque version.';
        } elseif (isset($available['context.get'])) {
            $base = 'First call context.get; select only workspace and resource IDs returned by SVC and never guess an ID. Use only operations currently exposed in tools/list; missing tools are not authorized for this connection.';
        } else {
            $base = 'Use only operations currently exposed in tools/list; missing tools are not authorized for this connection. Never guess a workspace or resource ID.';
        }
        $mode = $hasWriteTools
            ? 'Authorized write tools are enabled for this connection. Read the current record before mutation and supply its opaque version when required.'
            : 'This connection is read-only; use the SVC website for changes.';
        if (array_intersect(['invoices.issue', 'invoices.send', 'invoices.void'], array_keys($available)) !== []) {
            $mode .= ' Obtain explicit user confirmation before issue, send, or void.';
        }

        $promptGuidance = [];
        if ($this->hasTools($available, ['context.get', 'projects.list', 'time_entries.log'])) {
            $promptGuidance[] = 'log-time-across-projects';
        }
        if ($this->hasTools($available, [
            'context.get',
            'projects.get',
            'time_entries.list',
            'invoices.get',
            'invoices.create_draft',
            'invoices.update_draft',
        ])) {
            $promptGuidance[] = 'prepare-invoice-safely';
        }
        $prompts = $promptGuidance === []
            ? ''
            : ' Use the '.implode(' and ', $promptGuidance).' prompts for complete guided workflows when the client exposes MCP prompts.';

        return $base.' '.$mode.' Authenticate using OAuth Authorization Code with S256 PKCE. Invoice responses provide a browser URL for any payment flow; SVC does not expose payments, card data, project mutations, or file uploads through MCP.'.$prompts;
    }
}
