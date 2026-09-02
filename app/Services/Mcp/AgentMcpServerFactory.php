<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\AgentAgreementReadService;
use App\Services\AgentApi\AgentBillingAuditReadService;
use App\Services\AgentApi\AgentBillingScheduleReadService;
use App\Services\AgentApi\AgentCapacityLedgerReadService;
use App\Services\AgentApi\AgentReadService;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpAuthorizer;
use App\Services\Mcp\Context\McpPrincipalResolver;
use App\Services\Mcp\Context\McpRequestContext;
use App\Services\Mcp\Registry\McpCapabilityDefinition;
use App\Services\Mcp\Registry\McpCapabilityKind;
use Bherila\McpLaravelBridge\Mcp\CredentialSessionNamespace;
use Bherila\McpLaravelBridge\Mcp\OriginalShapeSchemaValidator;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Bherila\McpLaravelBridge\Mcp\ValidatedCallToolHandler;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        private readonly AgentMcpCapabilityRegistryFactory $capabilities,
        private readonly McpFeatureFlags $featureFlags,
        private readonly AgentReadService $readService,
        private readonly AgentAgreementReadService $agreementReadService,
        private readonly AgentBillingScheduleReadService $billingScheduleReadService,
        private readonly AgentCapacityLedgerReadService $capacityLedgerReadService,
        private readonly AgentBillingAuditReadService $billingAuditReadService,
        private readonly McpAccountContextResolver $accounts,
        private readonly McpAuthorizer $authorizer,
        private readonly McpPrincipalResolver $principals,
        private readonly AgentMcpWriteTools $writes,
        private readonly AgentMcpPrompts $prompts,
        private readonly RequestArguments $requestArguments,
    ) {}

    public function make(Request $request): Server
    {
        $logger = new NullLogger;
        $driftLogger = app(LoggerInterface::class);
        $registry = new Registry(logger: $logger);
        $context = new McpRequestContext(
            $this->principals->resolve($request),
            $this->requestId($request),
        );
        $reads = new AgentMcpReadTools($this->readService, $this->accounts, $context);
        $contextResource = new AgentMcpContextResource($this->readService, $context);
        $agreements = new AgentMcpAgreementTools($this->agreementReadService, $this->accounts, $context);
        $schedules = new AgentMcpBillingScheduleTools($this->billingScheduleReadService, $this->accounts, $context);
        $capacityLedger = new AgentMcpCapacityLedgerTools($this->capacityLedgerReadService, $this->accounts, $context);
        $billingAudits = new AgentMcpBillingAuditTools($this->billingAuditReadService, $this->accounts, $context);
        $writes = $this->writes->forContext($context);
        $definitions = $this->capabilities->make($reads, $contextResource, $agreements, $schedules, $capacityLedger, $billingAudits, $this->prompts, $writes)->all();
        $availableCapabilities = array_values(array_filter(
            $definitions,
            fn (McpCapabilityDefinition $definition): bool => $this->featureFlags->enabled($definition)
                && $this->authorizer->allowsDiscovery($context, $definition),
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
                app(RateLimiter::class),
                $driftLogger,
                $context,
                collect($definitions)
                    ->filter(static fn (McpCapabilityDefinition $definition): bool => $definition->kind === McpCapabilityKind::Tool)
                    ->mapWithKeys(static fn (McpCapabilityDefinition $definition): array => [$definition->name => [
                        'rate_limit_bucket' => $definition->rateLimitBucket,
                        'audit_classification' => $definition->auditClassification,
                    ]])
                    ->all(),
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
