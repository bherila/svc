<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\DeferredBuyDownAction;
use App\Support\Billing\DeferredBuyDownRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DeferredBuyDownOperations
{
    private const array FIELDS = ['hours', 'amount', 'round_up', 'discount_amount', 'discount_reason'];

    public function __construct(private readonly AgentAccess $access, private readonly AgentMutationExecutor $mutations, private readonly DeferredBuyDownAction $billing) {}

    /** @param array<string, mixed> $body
     * @return array<string, mixed> */
    public function preview(User|AgentPrincipal $actor, Workspace $workspace, string $agreement, array $body): array
    {
        $this->authorize($actor, $workspace);
        $this->closed($body, self::FIELDS);

        return $this->billing->preview($workspace, $this->agreement($workspace, $agreement), DeferredBuyDownRequest::from($body))->toArray();
    }

    /** @param array<string, mixed> $body */
    public function create(User $actor, Workspace $workspace, string $client, string $key, string $agreement, array $body): ClientInvoice
    {
        $this->authorize($actor, $workspace);
        $ids = $this->mutations->run($actor, $workspace, $client, 'invoices.create_deferred_buydown', $key,
            ['agreement_id' => $agreement, 'body' => $body], function () use ($workspace, $agreement, $body): array {
                $this->closed($body, [...self::FIELDS, 'expected_backlog_version']);
                $version = Validator::make($body, ['expected_backlog_version' => ['required', 'string', 'size:64']])->validate();
                $invoice = $this->billing->create($workspace, $this->agreement($workspace, $agreement),
                    DeferredBuyDownRequest::from(Arr::only($body, self::FIELDS)), $version['expected_backlog_version']);

                return [$invoice->public_id];
            }, fn (array $ids) => $this->authorize($actor, $workspace));

        return ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $ids[0])
            ->with(['clientCompany' => fn ($companies) => $companies->where('workspace_id', $workspace->id)])->firstOrFail();
    }

    private function agreement(Workspace $workspace, string $id): ClientAgreement
    {
        return ClientAgreement::query()->where('workspace_id', $workspace->id)->where('public_id', $id)
            ->whereHas('clientCompany', fn ($companies) => $companies->where('workspace_id', $workspace->id))->firstOrFail();
    }

    private function authorize(User|AgentPrincipal $actor, Workspace $workspace): void
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403);
    }

    /** @param array<string, mixed> $body
     * @param list<string> $fields */
    private function closed(array $body, array $fields): void
    {
        $unknown = array_diff(array_keys($body), $fields);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'This field is not accepted.'));
        }
    }
}
