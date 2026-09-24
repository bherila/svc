<?php

namespace App\Services\Mcp;

use App\Models\User;
use App\Services\AgentApi\AgentAgreementReadService;
use App\Services\AgentApi\AgentClientMutationAction;
use App\Services\AgentApi\AgentClientReadService;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpRequestContext;
use App\Support\AgentApi\ClientMutationRules;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use LogicException;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * Thin MCP adapter over the manager-only client and agreement writes.
 *
 * Fields a call may set are read by presence, never by value: an omitted field
 * is left alone and a field sent as null is an erasure. The lists come from the
 * validation rules, so a field the web form accepts is one this accepts.
 */
final class AgentMcpClientWriteTools
{
    private const string SCOPE = 'clients:write';

    public function __construct(
        private readonly AgentClientMutationAction $mutations,
        private readonly AgentClientReadService $clients,
        private readonly AgentAgreementReadService $agreements,
        private readonly McpAccountContextResolver $accounts,
        private readonly RequestArguments $arguments,
        private readonly ?McpRequestContext $requestContext = null,
    ) {}

    /** @return array<string, mixed> */
    public function clientsCreate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 160)] string $name,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema(maxLength: 255)] ?string $billing_email = null,
    ): array {
        return $this->clientResult($workspace_id, fn (McpRequestContext $context, User $actor): string => $this->mutations->createClient(
            $actor, $context->workspace, $context->principal->clientId, $idempotency_key, ['name' => $name, 'billing_email' => $billing_email],
        ));
    }

    /** @return array<string, mixed> */
    public function clientsUpdate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
    ): array {
        return $this->clientResult($workspace_id, fn (McpRequestContext $context, User $actor): string => $this->mutations->updateClient(
            $actor, $context->workspace, $context->principal->clientId, $idempotency_key, $client_id,
            $this->provided($request, array_keys(ClientMutationRules::clientUpdate())),
        ));
    }

    /** @return array<string, mixed> */
    public function clientsArchive(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->clientResult($workspace_id, fn (McpRequestContext $context, User $actor): string => $this->mutations->setClientActive(
            $actor, $context->workspace, $context->principal->clientId, $idempotency_key, $client_id, false,
        ));
    }

    /** @return array<string, mixed> */
    public function clientsRestore(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->clientResult($workspace_id, fn (McpRequestContext $context, User $actor): string => $this->mutations->setClientActive(
            $actor, $context->workspace, $context->principal->clientId, $idempotency_key, $client_id, true,
        ));
    }

    /** @return array<string, mixed> */
    public function agreementsCreate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
    ): array {
        return $this->agreementResult($workspace_id, fn (McpRequestContext $context, User $actor): string => $this->mutations->createAgreement(
            $actor, $context->workspace, $context->principal->clientId, $idempotency_key, $client_id,
            $this->provided($request, array_keys(ClientMutationRules::agreementCreate())),
        ));
    }

    /** @return array<string, mixed> */
    public function agreementsUpdate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $agreement_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
    ): array {
        return $this->agreementResult($workspace_id, fn (McpRequestContext $context, User $actor): string => $this->mutations->updateAgreement(
            $actor, $context->workspace, $context->principal->clientId, $idempotency_key, $agreement_id,
            $this->provided($request, array_keys(ClientMutationRules::agreementUpdate())),
        ));
    }

    /** @return array<string, mixed> */
    public function agreementsActivate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $agreement_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->agreementResult($workspace_id, fn (McpRequestContext $context, User $actor): string => $this->mutations->activateAgreement(
            $actor, $context->workspace, $context->principal->clientId, $idempotency_key, $agreement_id,
        ));
    }

    /** @return array<string, mixed> */
    public function agreementsTerminate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $agreement_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema(format: 'date')] ?string $ends_on = null,
    ): array {
        return $this->agreementResult($workspace_id, fn (McpRequestContext $context, User $actor): string => $this->mutations->terminateAgreement(
            $actor, $context->workspace, $context->principal->clientId, $idempotency_key, $agreement_id, $ends_on,
        ));
    }

    /**
     * @param  callable(McpRequestContext, User): string  $write  returns the public id written
     * @return array<string, mixed>
     */
    private function clientResult(string $workspaceId, callable $write): array
    {
        return AgentMcpFailures::translate(function () use ($workspaceId, $write): array {
            [$context, $actor] = $this->writer($workspaceId);

            return ['data' => $this->clients->get($actor, $context->workspace, $write($context, $actor))];
        });
    }

    /**
     * @param  callable(McpRequestContext, User): string  $write  returns the public id written
     * @return array<string, mixed>
     */
    private function agreementResult(string $workspaceId, callable $write): array
    {
        return AgentMcpFailures::translate(function () use ($workspaceId, $write): array {
            [$context, $actor] = $this->writer($workspaceId);

            return ['data' => $this->agreements->get($actor, $context->workspace, $write($context, $actor))];
        });
    }

    /** @return array{0: McpRequestContext, 1: User} */
    private function writer(string $workspaceId): array
    {
        $context = $this->requestContext ?? throw new LogicException('MCP client write tools require a request context.');
        if (! $context->principal->hasScope(self::SCOPE)) {
            throw new ToolCallException('This connection lacks the required permission.');
        }
        $context = $this->accounts->resolve($context, $workspaceId);

        return [$context, User::query()->findOrFail($context->principal->subject->id)];
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function provided(RequestContext $request, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            if ($this->arguments->has($request, $field)) {
                $values[$field] = $this->arguments->value($request, $field, null);
            }
        }

        return $values;
    }
}
