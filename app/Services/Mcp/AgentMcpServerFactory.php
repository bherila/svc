<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\AgentAgreementReadService;
use App\Services\AgentApi\AgentBillingAuditReadService;
use App\Services\AgentApi\AgentBillingScheduleReadService;
use App\Services\AgentApi\AgentCapacityLedgerReadService;
use App\Services\AgentApi\AgentClientReadService;
use App\Services\AgentApi\AgentReadService;
use App\Services\AgentApi\Operations\AgentAvailability;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AgentOperationPrincipal;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpAuthorizer;
use App\Services\Mcp\Context\McpPrincipalResolverInterface;
use App\Services\Mcp\Context\McpRequestContext;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\McpKind;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
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
        private readonly AgentOperationCatalog $catalog,
        private readonly AgentAvailability $availability,
        private readonly AgentMcpToolSchemas $schemas,
        private readonly AgentReadService $readService,
        private readonly AgentAgreementReadService $agreementReadService,
        private readonly AgentBillingScheduleReadService $billingScheduleReadService,
        private readonly AgentCapacityLedgerReadService $capacityLedgerReadService,
        private readonly AgentBillingAuditReadService $billingAuditReadService,
        private readonly AgentClientReadService $clientReadService,
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
        $clients = new AgentMcpClientTools($this->clientReadService, $this->accounts, $context);
        $clientWrites = new AgentMcpClientWriteTools(app(InternalAgentApiTransport::class), $this->requestArguments);
        $writes = $this->writes->forContext($context);
        $instances = [
            AgentMcpReadTools::class => $reads,
            AgentMcpContextResource::class => $contextResource,
            AgentMcpAgreementTools::class => $agreements,
            AgentMcpAgreementResource::class => $agreementResource,
            AgentMcpBillingScheduleTools::class => $schedules,
            AgentMcpCapacityLedgerTools::class => $capacityLedger,
            AgentMcpBillingAuditTools::class => $billingAudits,
            AgentMcpClientTools::class => $clients,
            AgentMcpClientWriteTools::class => $clientWrites,
            AgentMcpWriteTools::class => $writes,
            AgentMcpPrompts::class => $this->prompts,
        ];
        $resultLimiter = new McpCapabilityResultLimiter;
        $cacheStore = $this->cache instanceof Repository ? $this->cache->getStore() : null;
        $concurrencyLimiter = new McpCapabilityConcurrencyLimiter($cacheStore instanceof LockProvider ? $cacheStore : null);
        $operations = array_values(array_filter(
            $this->catalog->registry()->all(),
            static fn (Operation $operation): bool => $operation->mcp !== null,
        ));
        // Split deliberately. A deployment switch is server-side, so a
        // switched-off group genuinely is not served here; authorization is
        // about this one caller and must not change what the server says it
        // implements. Dependencies are not followed for this: a prompt whose
        // tools are cut over is still a prompt this server implements.
        $flags = $this->availability->agentFlags();
        $implementedOperations = array_values(array_filter(
            $operations,
            static fn (Operation $operation): bool => array_filter($operation->requirement->flags, static fn (string $flag): bool => ! $flags->enabled($flag)) === [],
        ));
        $principal = new AgentOperationPrincipal(
            static fn (string $scope): bool => $context->principal->hasScope($scope),
            fn (): bool => $this->authorizer->hasManagedWorkspace($context),
        );
        $report = $this->availability->agents()->evaluate($principal);
        $availableOperations = array_values(array_filter(
            $operations,
            static fn (Operation $operation): bool => $report->isAvailable($operation->id),
        ));
        $exposedTools = array_values(array_filter(
            $availableOperations,
            static fn (Operation $operation): bool => self::kind($operation) === McpKind::Tool,
        ));
        $exposedToolNames = array_fill_keys(array_map(
            static fn (Operation $operation): string => (string) $operation->mcpName(),
            $exposedTools,
        ), true);
        $hasWriteTools = collect($exposedTools)->contains(
            static fn (Operation $operation): bool => ! $operation->effect->readOnly(),
        );
        // Capability negotiation is a protocol-feature exchange - which request
        // groups this server serves - and not an authorization decision, which is
        // enforced per call and already is. So these describe what the server
        // implements, not what this principal may invoke.
        //
        // Deriving them from the authorized set made a connection holding no
        // operation scope advertise no group at all, and `ServerCapabilities`
        // builds its result by adding a key per enabled group: with none enabled
        // that is an empty PHP array, which `json_encode` renders as `[]` where
        // the protocol requires an object. A conformant client then rejects
        // `initialize` outright, so a user granted `mcp:use` alone could not
        // connect (#197). Advertising a group the caller may invoke nothing in
        // costs an empty `tools/list` - a spec-legal answer it can read.
        $implementsTools = collect($implementedOperations)->contains(
            static fn (Operation $operation): bool => self::kind($operation) === McpKind::Tool,
        );
        $implementsResources = collect($implementedOperations)->contains(
            static fn (Operation $operation): bool => in_array(self::kind($operation), [McpKind::Resource, McpKind::ResourceTemplate], true),
        );
        $implementsPrompts = collect($implementedOperations)->contains(
            static fn (Operation $operation): bool => self::kind($operation) === McpKind::Prompt,
        );
        $schemaIds = [];
        foreach ($exposedTools as $operation) {
            $schemaIds[(string) $operation->mcpName()] = (string) $operation->mcpName();
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
                tools: $implementsTools,
                toolsListChanged: false,
                resources: $implementsResources,
                resourcesSubscribe: false,
                resourcesListChanged: false,
                prompts: $implementsPrompts,
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
                $this->capabilityMetadata($operations, McpKind::Tool),
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
                    ...$this->capabilityMetadata($operations, McpKind::Resource, static fn (Operation $operation): string => $operation->mcp->uri ?? (string) $operation->mcpName()),
                    ...$this->capabilityMetadata($operations, McpKind::ResourceTemplate, static fn (Operation $operation): string => $operation->mcp->uri ?? (string) $operation->mcpName()),
                ],
                function (JsonRpcRequest $request) use ($operations): string {
                    if (! $request instanceof ReadResourceRequest) {
                        throw new LogicException('MCP resource audit handler received an invalid request.');
                    }

                    return $this->resourceCapabilityKey($operations, $request->uri);
                },
            ))
            ->addRequestHandler(new McpAuditedCapabilityRequestHandler(
                new GetPromptHandler($registry, new ReferenceHandler(app()), $logger),
                new McpCapabilityRateLimiter(app(RateLimiter::class)),
                $resultLimiter,
                $concurrencyLimiter,
                $capabilityAuditor,
                $context,
                $this->capabilityMetadata($operations, McpKind::Prompt),
                function (JsonRpcRequest $request) use ($operations): string {
                    if (! $request instanceof GetPromptRequest) {
                        throw new LogicException('MCP prompt audit handler received an invalid request.');
                    }

                    return $this->promptCapabilityKey($operations, $request->name);
                },
            ))
            ->setLazyLoading(false);

        foreach ($exposedTools as $operation) {
            $builder->addTool(
                handler: $this->handler($operation, $instances),
                name: (string) $operation->mcpName(),
                title: $operation->title,
                description: $operation->description,
                annotations: new ToolAnnotations(
                    readOnlyHint: $operation->effect->readOnly(),
                    destructiveHint: $operation->effect === Effect::Destructive,
                    idempotentHint: $operation->isIdempotent(),
                    openWorldHint: false,
                ),
                inputSchema: $this->schemas->input($operation),
                outputSchema: $this->schemas->output($operation),
            );
        }
        foreach ($availableOperations as $operation) {
            $uri = $operation->mcp?->uri;
            if ($uri === null) {
                continue;
            }
            match (self::kind($operation)) {
                McpKind::Resource => $builder->addResource(
                    handler: $this->handler($operation, $instances),
                    uri: $uri,
                    name: (string) $operation->mcpName(),
                    title: $operation->title,
                    description: $operation->description,
                    mimeType: 'application/json',
                ),
                McpKind::ResourceTemplate => $builder->addResourceTemplate(
                    handler: $this->handler($operation, $instances),
                    uriTemplate: $uri,
                    name: (string) $operation->mcpName(),
                    title: $operation->title,
                    description: $operation->description,
                    mimeType: 'application/json',
                ),
                McpKind::Tool, McpKind::Prompt, null => null,
            };
        }
        foreach ($availableOperations as $operation) {
            if (self::kind($operation) !== McpKind::Prompt) {
                continue;
            }
            $builder->addPrompt(
                handler: $this->handler($operation, $instances),
                name: (string) $operation->mcpName(),
                title: $operation->title,
                description: $operation->description,
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

    private static function kind(Operation $operation): ?McpKind
    {
        return $operation->mcp?->kind;
    }

    /**
     * The operation's handler, bound to this request's instance of its class.
     *
     * @param  array<class-string, object>  $instances
     * @return array{0: object, 1: string}
     */
    private function handler(Operation $operation, array $instances): array
    {
        $handler = $operation->mcp?->handler;
        if (! is_array($handler) || ! is_string($handler[0]) || ! isset($instances[$handler[0]])) {
            throw new LogicException("MCP operation [{$operation->id}] has no request-bound handler.");
        }

        return [$instances[$handler[0]], $handler[1]];
    }

    /**
     * @param  list<Operation>  $operations
     * @param  (Closure(Operation): string)|null  $key
     * @return array<string, array{rate_limit_bucket: string, audit_classification: string}>
     */
    private function capabilityMetadata(array $operations, McpKind $kind, ?Closure $key = null): array
    {
        $metadata = [];
        foreach ($operations as $operation) {
            if (self::kind($operation) !== $kind) {
                continue;
            }
            $read = $operation->effect->readOnly();
            $metadata[($key ?? static fn (Operation $operation): string => (string) $operation->mcpName())($operation)] = [
                'rate_limit_bucket' => $read ? 'mcp-read' : 'mcp-write',
                'audit_classification' => match (true) {
                    $kind === McpKind::Prompt => 'agent_api.prompt',
                    $read => 'agent_api.read',
                    default => 'agent_api.write',
                },
            ];
        }

        return $metadata;
    }

    /** @param list<Operation> $operations */
    private function resourceCapabilityKey(array $operations, string $uri): string
    {
        foreach ($operations as $operation) {
            if (self::kind($operation) === McpKind::Resource && $operation->mcp?->uri === $uri) {
                return $uri;
            }
        }
        foreach ($operations as $operation) {
            $binding = $operation->mcp;
            if ($binding === null || $binding->kind !== McpKind::ResourceTemplate || $binding->uri === null || ! is_array($binding->handler)) {
                continue;
            }
            $template = $binding->uri;
            if ((new ResourceTemplateReference(new ResourceTemplate($template, (string) $operation->mcpName()), $binding->handler))->matches($uri)) {
                return $template;
            }
        }

        return 'mcp.unknown_resource';
    }

    /** @param list<Operation> $operations */
    private function promptCapabilityKey(array $operations, string $name): string
    {
        foreach ($operations as $operation) {
            if (self::kind($operation) === McpKind::Prompt && $operation->mcpName() === $name) {
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
        if ($this->hasTools($available, ['time_entries.log', 'time_entries.approve'])) {
            $mode .= ' When the user asks to approve time they are logging, pass approve: true to time_entries.log instead of a separate approve call; with time:read and while time_entries.list remains enabled, it returns the rows as time_entries.list does, otherwise it returns the plain write shape.';
        }
        if (array_intersect(['invoices.issue', 'invoices.send', 'invoices.void', 'invoices.correct', 'agreements.activate', 'agreements.terminate', 'expense_schedules.generate', 'invoices.release_delivery', 'invoices.generate_period'], array_keys($available)) !== []) {
            $mode .= ' Obtain explicit user confirmation before issue, send, void, correct, activating an agreement, terminating an agreement, expense_schedules.generate, releasing automatic delivery, or generating a cadence draft.';
        }
        if (isset($available['billing_schedules.generate'])) {
            $mode .= ' Obtain explicit user confirmation before billing_schedules.generate: it issues invoices and may schedule automatic client delivery.';
        }
        if (array_intersect(['projects.create', 'projects.update', 'projects.archive', 'projects.members.update'], array_keys($available)) !== []) {
            $mode .= ' Obtain explicit user confirmation before creating, updating, archiving projects or changing project member access; these decisions affect client visibility and access.';
        }
        if (array_intersect(['proposals.send', 'proposals.accept'], array_keys($available)) !== []) {
            $mode .= ' Obtain explicit user confirmation before sending or accepting a proposal. Sending publishes it in the client portal without emailing. Acceptance signs and activates an agreement; require an explicit signer name and never infer acceptance from a request to read or create a proposal.';
        }
        if (isset($available['attachments.delete'])) {
            $mode .= ' Obtain explicit user confirmation before deleting an attachment.';
        }
        if ($this->hasTools($available, ['invoices.issue', 'payments.record'])) {
            $mode .= ' When the user confirms issuing a draft for money already collected elsewhere, pass payment to invoices.issue so it is issued and paid in one step and the automatic client delivery is never sent; never infer a payment from an invoice balance.';
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

        $payments = isset($available['payments.record'])
            ? 'Invoice responses provide a browser URL for any payment flow; payments.record records money already received but never initiates a charge, and SVC does not expose card data or file uploads through MCP.'
            : 'Invoice responses provide a browser URL for any payment flow; SVC does not expose payments, card data or file uploads through MCP.';

        return $base.' '.$mode.' Authenticate using OAuth Authorization Code with S256 PKCE. '.$payments.$prompts;
    }
}
