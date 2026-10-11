<?php

namespace App\Services\AgentApi;

use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\WorkspaceAccess;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Portal writes share the access boundary and ordinary agent receipts. */
final class AgentPortalAccessMutationAction
{
    public function __construct(
        private readonly WorkspaceAccess $access,
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentPortalContactReadService $contacts,
    ) {}

    /** @param array<string, mixed> $payload
     * @return list<string> */
    public function run(User $actor, Workspace $workspace, string $clientId, string $key, string $operation, string $companyId, ?string $userId, array $payload): array
    {
        $context = AccessMutationContext::user($actor, hash('sha256', implode('|', [$workspace->public_id, $actor->public_id, $clientId, $operation, $key])));

        return $this->mutations->run($actor, $workspace, $clientId, $operation, $key, ['client_id' => $companyId, 'user_id' => $userId, 'body' => $payload], function () use ($actor, $workspace, $companyId, $userId, $operation, $payload, $context): array {
            $company = $this->contacts->company($actor, $workspace, $companyId);
            $rules = ['confirm' => ['required', 'boolean', 'accepted']];
            if ($operation !== 'clients.portal_access.grant') {
                $rules['expected_version'] = ['required', 'string', 'size:64'];
            }
            if ($operation !== 'clients.portal_access.revoke') {
                $rules += [
                    'scope' => ['required', Rule::enum(PortalAccessScope::class)],
                    'projects' => ['array', 'max:500', Rule::requiredIf(($payload['scope'] ?? null) === 'projects'),
                        Rule::prohibitedIf(($payload['scope'] ?? null) === 'company' && ($payload['projects'] ?? []) !== [])],
                    'projects.*' => ['required', 'uuid', 'distinct'],
                ];
            }
            if ($operation === 'clients.portal_access.grant') {
                $rules['email'] = ['required', 'string', 'email', 'max:255'];
            }
            $fields = array_values(array_filter(array_keys($rules), static fn (string $field): bool => ! str_contains($field, '.')));
            Validator::make(['body' => $payload], ['body' => ['required', 'array:'.implode(',', $fields)]])->validate();
            $data = Validator::make($payload, $rules)->validate();
            abort_unless($data['confirm'] === true, 422, 'Explicit confirmation is required.');
            if ($operation === 'clients.portal_access.grant') {
                $provider = (string) config('bherila-auth.oauth_client.provider');
                $person = $provider === '' ? null : User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])
                    ->where('oauth_provider', $provider)->whereNotNull('oauth_subject')->where('oauth_subject', '<>', '')->first();
                if (! $person instanceof User) {
                    throw ValidationException::withMessages(['email' => 'Nobody signs in with that address yet. Ask them to sign in once, then add them.']);
                }
            } else {
                $person = User::query()->where('public_id', $userId)->whereIn('id', ClientCompanyMembership::query()->select('user_id')
                    ->where('workspace_id', $workspace->id)->where('client_company_id', $company->id))->firstOrFail();
            }
            $scope = isset($data['scope']) ? PortalAccessScope::from($data['scope']) : PortalAccessScope::Company;
            $projects = array_values(array_map(strval(...), $data['projects'] ?? []));
            match ($operation) {
                'clients.portal_access.grant' => $this->access->grantPortal($context, $company, $person, $scope, $projects),
                'clients.portal_access.update' => $this->access->changePortalScope($context, $company, $person, $scope, $projects, $data['expected_version']),
                'clients.portal_access.revoke' => $this->access->revokePortal($context, $company, $person, $data['expected_version']),
                default => throw new \InvalidArgumentException('Unsupported portal access mutation.'),
            };

            return [$company->public_id, $person->public_id];
        }, function (array $ids) use ($actor, $workspace, $context): void {
            $this->access->assertMayManage($context, $workspace);
            $this->contacts->company($actor, $workspace, $ids[0]);
        });
    }
}
