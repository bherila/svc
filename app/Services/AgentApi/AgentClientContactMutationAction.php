<?php

namespace App\Services\AgentApi;

use App\Http\Requests\SaveClientContactRequest;
use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Authorization\AgentAccess;
use App\Services\Clients\ClientContactRepository;
use App\Services\Clients\ContactDetails;
use App\Services\Clients\ContactPatch;
use App\Support\AgentApi\ExplicitConfirmation;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AgentClientContactMutationAction
{
    public function __construct(private readonly AgentMutationExecutor $receipts, private readonly AgentAccess $access, private readonly ClientContactRepository $contacts) {}

    /** @param array<string, mixed> $payload */
    public function perform(User $actor, Workspace $workspace, string $companyId, string $operation, ?string $contactId, array $payload, string $clientId, string $key): string
    {
        abort_unless(in_array($operation, ['create', 'update', 'archive', 'restore'], true), 404);
        // Agent cutovers belong to the registry-backed REST/MCP route. The
        // local CLI uses this same role-checked operation without an OAuth gate.
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:255']])->validate();
        $authorize = function () use ($workspace, $companyId): ClientCompany {
            Workspace::query()->whereKey($workspace->id)->tap(Locks::forShare())->firstOrFail();

            return ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $companyId)->firstOrFail();
        };
        $currentManager = static function () use ($actor, $workspace): void {
            // A locking read sees a committed revocation even when the caller
            // entered this operation with an older repeatable-read snapshot.
            $membership = WorkspaceMembership::query()->where('workspace_id', $workspace->id)
                ->where('user_id', $actor->id)->tap(Locks::forShare())->first();
            abort_unless($membership !== null && in_array($membership->role, ['owner', 'admin'], true), 403);
        };

        return $this->receipts->run($actor, $workspace, $clientId, 'clients.contacts.'.$operation, $key,
            ['company_id' => $companyId, 'contact_id' => $contactId, ...$payload],
            function () use ($authorize, $currentManager, $workspace, $operation, $contactId, $payload): array {
                $company = $authorize();
                $rules = (new SaveClientContactRequest)->rules();
                if ($operation === 'create') {
                    $rules['is_archived'] = ['sometimes', 'boolean'];
                } elseif ($operation === 'update') {
                    unset($rules['is_archived']);
                    foreach (['first_name', 'last_name'] as $field) {
                        $rules[$field] = ['sometimes', 'nullable', 'string', 'max:100'];
                    }
                    $rules['expected_version'] = ['required', 'string', 'size:64'];
                } else {
                    $rules = ['expected_version' => ['required', 'string', 'size:64']];
                    if ($operation === 'archive') {
                        $rules['confirm'] = ['required', new ExplicitConfirmation];
                    }
                }
                $unknown = array_diff(array_keys($payload), array_keys($rules));
                if ($unknown !== []) {
                    throw ValidationException::withMessages(array_fill_keys($unknown, 'This field is not supported.'));
                }
                $data = Validator::make($payload, $rules)->validate();
                if ($operation === 'create') {
                    return [$this->contacts->create($workspace, $company, ContactDetails::fromValidated($data), $currentManager)->public_id];
                }
                $contact = ClientContact::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('public_id', $contactId)->firstOrFail();
                /** @var array<string, string|bool|null> $attributes */
                $attributes = $operation === 'update' ? Arr::except($data, ['expected_version']) : ['is_archived' => $operation === 'archive'];

                return [$this->contacts->patch($workspace, $company, $contact, new ContactPatch($attributes), $data['expected_version'], $currentManager)->public_id];
            },
            function (array $ids) use ($authorize, $currentManager, $workspace): void {
                $company = $authorize();
                $currentManager();
                ClientContact::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->whereIn('public_id', $ids)->sole();
            })[0];
    }
}
