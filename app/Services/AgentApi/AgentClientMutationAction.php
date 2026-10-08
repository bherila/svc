<?php

namespace App\Services\AgentApi;

use App\Actions\CreateClientCompany;
use App\Actions\UpdateClientCompany;
use App\Http\Requests\Engagement\UpdateAgreementRequest;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Engagement\EngagementException;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\AgentApi\ClientMutationRules;
use App\Support\Concurrency\Locks;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Authorization, idempotency and validation for client and agreement writes.
 *
 * An adapter and nothing more: what a client or an agreement is allowed to
 * become stays in `CreateClientCompany`, `UpdateClientCompany` and
 * `AgreementWorkflow`, the same code the web forms call. Every operation
 * resolves to the public id of the record it touched, so a retried call returns
 * the receipt of the first instead of writing twice.
 *
 * "Delete" is archive here and nowhere is a row removed: a company is
 * deactivated and an agreement terminated, so invoices, time and payments keep
 * the history they point at.
 */
final class AgentClientMutationAction
{
    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentAccess $access,
        private readonly CreateClientCompany $createClient,
        private readonly UpdateClientCompany $updateClient,
        private readonly AgreementWorkflow $agreements,
    ) {}

    /** @param array<string, mixed> $payload */
    public function createClient(User $user, Workspace $workspace, string $oauthClientId, string $key, array $payload): string
    {
        return $this->execute('clients.create', $user, $workspace, $oauthClientId, $key, $payload, function () use ($workspace, $payload): string {
            /** @var array{name: string, billing_email?: ?string} $data */
            $data = Validator::make($payload, ClientMutationRules::clientCreate())->validate();

            return $this->createClient->handle($workspace, $data['name'], $data['billing_email'] ?? null)->public_id;
        });
    }

    /** @param array<string, mixed> $payload */
    public function updateClient(User $user, Workspace $workspace, string $oauthClientId, string $key, string $clientId, array $payload): string
    {
        return $this->execute('clients.update', $user, $workspace, $oauthClientId, $key, ['client_id' => $clientId, ...$payload], function () use ($workspace, $clientId, $payload): string {
            $data = Validator::make($payload, ClientMutationRules::clientUpdate() + self::versionRules())->validate();

            return $this->updateClient->handle($workspace, $this->client($workspace, $clientId), $data, $data['expected_version'])->public_id;
        });
    }

    /** Archive or restore: the reversible half of "delete", through the same edit the Manage form makes. */
    public function setClientActive(User $user, Workspace $workspace, string $oauthClientId, string $key, string $clientId, bool $active, string $expectedVersion): string
    {
        return $this->execute($active ? 'clients.restore' : 'clients.archive', $user, $workspace, $oauthClientId, $key, ['client_id' => $clientId, 'expected_version' => $expectedVersion], fn (): string => $this->updateClient->handle($workspace, $this->client($workspace, $clientId), ['is_active' => $active], $expectedVersion)->public_id);
    }

    /** @param array<string, mixed> $payload */
    public function createAgreement(User $user, Workspace $workspace, string $oauthClientId, string $key, string $clientId, array $payload): string
    {
        return $this->execute('agreements.create', $user, $workspace, $oauthClientId, $key, ['client_id' => $clientId, ...$payload], function () use ($workspace, $clientId, $payload): string {
            $data = Validator::make($payload, ClientMutationRules::agreementCreate() + self::versionRules())->validate();

            $client = ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $clientId)->tap(Locks::forUpdate())->firstOrFail();
            abort_unless(AgentApiVersion::matches($client, $data['expected_version']), 409, 'The client has changed; read it and retry.');

            return $this->agreements->create($workspace, $client, null, null, $data)->public_id;
        });
    }

    /** @param array<string, mixed> $payload */
    public function updateAgreement(User $user, Workspace $workspace, string $oauthClientId, string $key, string $agreementId, array $payload): string
    {
        return $this->execute('agreements.update', $user, $workspace, $oauthClientId, $key, ['agreement_id' => $agreementId, ...$payload], function () use ($workspace, $agreementId, $payload): string {
            $data = Validator::make($payload, ClientMutationRules::agreementUpdate() + self::versionRules())->validate();
            $agreement = ClientAgreement::query()->where('workspace_id', $workspace->id)->where('public_id', $agreementId)->tap(Locks::forUpdate())->firstOrFail();
            abort_unless(AgentApiVersion::matches($agreement, $data['expected_version']), 409, 'The agreement has changed; read it and retry.');
            if (UpdateAgreementRequest::datesRunBackwards($data, $agreement)) {
                throw ValidationException::withMessages(['ends_on' => 'The agreement cannot end before it starts.']);
            }

            return $this->agreements->update($workspace, $agreement, $data, $data['expected_version'])->public_id;
        });
    }

    public function activateAgreement(User $user, Workspace $workspace, string $oauthClientId, string $key, string $agreementId, string $expectedVersion): string
    {
        return $this->execute('agreements.activate', $user, $workspace, $oauthClientId, $key, ['agreement_id' => $agreementId, 'expected_version' => $expectedVersion], fn (): string => $this->agreements->activate($this->agreement($workspace, $agreementId), $expectedVersion)->public_id);
    }

    public function terminateAgreement(User $user, Workspace $workspace, string $oauthClientId, string $key, string $agreementId, ?string $endsOn, string $expectedVersion): string
    {
        return $this->execute('agreements.terminate', $user, $workspace, $oauthClientId, $key, ['agreement_id' => $agreementId, 'ends_on' => $endsOn, 'expected_version' => $expectedVersion], function () use ($workspace, $agreementId, $endsOn, $expectedVersion): string {
            Validator::make(['ends_on' => $endsOn], ['ends_on' => ['nullable', 'date_format:Y-m-d']])->validate();

            return $this->agreements->terminate($this->agreement($workspace, $agreementId), $endsOn, $expectedVersion)->public_id;
        });
    }

    /**
     * The shared shape of every write: manager check, idempotency receipt, audit.
     *
     * Manager access is checked again on a replay, so a caller whose role was
     * withdrawn cannot read back the receipt of what they once did.
     *
     * @param  array<string, mixed>  $digested  what the idempotency key is bound to
     * @param  Closure(): string  $work  returns the public id of the record written
     */
    private function execute(string $operation, User $user, Workspace $workspace, string $oauthClientId, string $key, array $digested, Closure $work): string
    {
        $this->authorize($user, $workspace);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:255']])->validate();

        try {
            return $this->mutations->run(
                $user,
                $workspace,
                $oauthClientId,
                $operation,
                $key,
                $digested,
                fn (): array => [$work()],
                fn (array $ids) => $this->authorize($user, $workspace),
            )[0];
        } catch (EngagementException $exception) {
            throw ValidationException::withMessages(['agreement' => $exception->getMessage()]);
        }
    }

    /** @return array<string, list<string>> */
    private static function versionRules(): array
    {
        return ['expected_version' => ['required', 'string', 'size:64']];
    }

    private function authorize(User $user, Workspace $workspace): void
    {
        abort_unless(AgentWriteCutover::clients(), 404);
        abort_unless($this->access->isWorkspaceManager($user, $workspace), 403);
    }

    /** Scoped to the workspace by the query, so another tenant's id is simply not found. */
    private function client(Workspace $workspace, string $clientId): ClientCompany
    {
        return ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $clientId)->firstOrFail();
    }

    private function agreement(Workspace $workspace, string $agreementId): ClientAgreement
    {
        return ClientAgreement::query()->where('workspace_id', $workspace->id)->where('public_id', $agreementId)->firstOrFail();
    }
}
