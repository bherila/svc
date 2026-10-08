<?php

namespace App\Services\AgentApi;

use App\Http\Requests\Engagement\StoreProposalRequest;
use App\Models\ClientCompany;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\ProposalAccess;
use App\Services\Engagement\ProposalMutationAction;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Facades\Validator;

final class AgentProposalMutationAction
{
    public function __construct(private readonly AgentMutationExecutor $mutations, private readonly ProposalMutationAction $proposals, private readonly ProposalAccess $access) {}

    /** @param array<string,mixed> $payload */
    public function run(User $actor, Workspace $workspace, string $clientId, string $key, string $operation, ?string $proposalId, array $payload): ClientProposal
    {
        abort_unless(AgentWriteCutover::proposals(), 404);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:255']])->validate();
        $ids = $this->mutations->run($actor, $workspace, $clientId, $operation, $key, ['proposal_id' => $proposalId, 'body' => $payload], function () use ($actor, $workspace, $operation, $proposalId, $payload): array {
            $rules = ['expected_version' => ['required', 'string', 'size:64']];
            if ($operation === 'proposals.create') {
                $this->access->requireManager($actor, $workspace);
                $rules += (new StoreProposalRequest)->rules();
                $rules += ['company_id' => ['required', 'uuid'], 'project_id' => ['sometimes', 'nullable', 'uuid']];
                $rules['items.*'] = ['array:description,quantity,unit_amount,cadence,sort_order'];
            } else {
                $rules += ['confirm' => ['required', 'boolean', 'accepted']];
                if ($operation === 'proposals.accept') {
                    $rules += ['signer_name' => ['required', 'string', 'max:200'], 'signer_title' => ['nullable', 'string', 'max:200']];
                }
            }
            Validator::make(['body' => $payload], ['body' => ['required', 'array:'.implode(',', array_filter(array_keys($rules), fn (string $field): bool => ! str_contains($field, '.')))]])->validate();
            $data = Validator::make($payload, $rules)->validate();
            if ($operation !== 'proposals.create') {
                abort_unless(($payload['confirm'] ?? null) === true, 422, 'Confirm must be true.');
            }
            try {
                if ($operation === 'proposals.create') {
                    $company = ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $data['company_id'])->firstOrFail();
                    $proposal = $this->proposals->create($actor, $workspace, $company, $data, $data['expected_version'], $data['project_id'] ?? null);
                } else {
                    $record = ClientProposal::query()->where('workspace_id', $workspace->id)->where('public_id', $proposalId)->tap(Locks::forUpdate())->firstOrFail();
                    $proposal = match ($operation) {
                        'proposals.send' => $this->proposals->send($actor, $workspace, $record, $data['expected_version']),
                        'proposals.accept' => $this->proposals->accept($actor, $workspace, $record, $data['signer_name'], $data['signer_title'] ?? null, $data['expected_version']),
                        default => throw new \InvalidArgumentException('Unsupported proposal mutation.'),
                    };
                }
            } catch (EngagementException $exception) {
                abort(422, $exception->getMessage());
            }

            return [$proposal->public_id];
        }, function (array $ids) use ($actor, $workspace, $operation): void {
            if ($operation !== 'proposals.accept') {
                $this->access->requireManager($actor, $workspace);
            }
            $record = $this->access->visible($actor, $workspace)->where('public_id', $ids[0] ?? null)->firstOrFail();
            if ($operation === 'proposals.accept') {
                $this->access->requireAcceptance($actor, $workspace, $record);
            }
        });

        return $this->access->visible($actor, $workspace)->where('public_id', $ids[0] ?? null)->firstOrFail();
    }
}
