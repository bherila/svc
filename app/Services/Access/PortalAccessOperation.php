<?php

namespace App\Services\Access;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Granting, changing and revoking a client's portal access: one set of fields,
 * one validation and one person lookup for every caller (#385).
 *
 * The settings page and agents reach it through `/api/v1` (REST and MCP share
 * that route); the `svc:portal:project-access` console command calls it
 * directly as the system actor. Each caller authorizes and resolves the client
 * first; the {@see WorkspaceAccess} boundary then re-checks authority under its
 * own lock and writes the audit trail.
 */
final class PortalAccessOperation
{
    public const string GRANT = 'clients.portal_access.grant';

    public const string UPDATE = 'clients.portal_access.update';

    public const string REVOKE = 'clients.portal_access.revoke';

    public const string NOBODY_SIGNS_IN = 'Nobody signs in with that address yet. Ask them to sign in once, then add them.';

    public const string NOBODY_SIGNS_IN_INVITE = 'Nobody signs in with that address yet. Send them an invitation, and they get this access when they first sign in.';

    public function __construct(private readonly WorkspaceAccess $access) {}

    /**
     * What a portal grant or invitation reaches: the whole client, or a
     * non-empty set of its projects by public id.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, list<mixed>>
     */
    public static function scopeRules(array $payload): array
    {
        return [
            'scope' => ['required', Rule::enum(PortalAccessScope::class)],
            // Limiting access to no project at all would reach nothing; remove it instead.
            'projects' => ['array', 'max:500', Rule::requiredIf(($payload['scope'] ?? null) === PortalAccessScope::Projects->value),
                Rule::prohibitedIf(($payload['scope'] ?? null) === PortalAccessScope::Company->value && ($payload['projects'] ?? []) !== [])],
            'projects.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public static function scopeMessages(): array
    {
        return [
            'projects.required' => 'Choose at least one project, or give access to the whole client.',
            'projects.prohibited' => 'Access to the whole client is not limited to projects.',
        ];
    }

    /**
     * The operation's body, validated: exactly its fields, explicit
     * confirmation, and the contact's current version for a change or removal.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<mixed>>  $extra  further fields this caller's operation declares
     * @return array<string, mixed>
     */
    public static function validate(string $operation, array $payload, array $extra = []): array
    {
        $rules = ['confirm' => ['required', 'boolean', 'accepted']];
        if ($operation !== self::GRANT) {
            $rules['expected_version'] = ['required', 'string', 'size:64'];
        }
        if ($operation !== self::REVOKE) {
            $rules += self::scopeRules($payload);
        }
        if ($operation === self::GRANT) {
            $rules['email'] = ['required', 'string', 'email', 'max:255'];
        }
        $rules += $extra;

        return self::validated($rules, $payload);
    }

    /**
     * @param  array<string, list<mixed>>  $rules
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function validated(array $rules, array $payload): array
    {
        $fields = array_values(array_filter(array_keys($rules), static fn (string $field): bool => ! str_contains($field, '.')));
        Validator::make(['body' => $payload], ['body' => ['required', 'array:'.implode(',', $fields)]])->validate();
        $data = Validator::make($payload, $rules, self::scopeMessages())->validate();
        abort_unless($data['confirm'] === true, 422, 'Explicit confirmation is required.');

        return $data;
    }

    /**
     * Validate, resolve the person and apply the change. Returns who it was.
     *
     * @param  array<string, mixed>  $payload
     */
    public function perform(AccessMutationContext $context, ClientCompany $company, string $operation, ?string $userPublicId, array $payload): User
    {
        $data = self::validate($operation, $payload);
        $person = $operation === self::GRANT ? $this->grantee((string) $data['email']) : $this->member($company, (string) $userPublicId);
        $scope = isset($data['scope']) ? PortalAccessScope::from((string) $data['scope']) : PortalAccessScope::Company;
        $projects = array_values(array_map(strval(...), $data['projects'] ?? []));

        match ($operation) {
            self::GRANT => $this->access->grantPortal($context, $company, $person, $scope, $projects),
            self::UPDATE => $this->access->changePortalScope($context, $company, $person, $scope, $projects, (string) $data['expected_version']),
            self::REVOKE => $this->access->revokePortal($context, $company, $person, (string) $data['expected_version']),
            default => throw new \InvalidArgumentException('Unsupported portal access mutation.'),
        };

        return $person;
    }

    /**
     * The account a grant names, by the exact address it signs in with
     * (case-insensitively), and only among accounts bound to the configured
     * identity provider: an account nobody can sign in to would hold access
     * nobody can use.
     *
     * With portal invitations on, the refusal is reported under `invite`: the
     * caller may invite the address instead.
     */
    public function grantee(string $email): User
    {
        $provider = (string) config('bherila-auth.oauth_client.provider');
        $person = $provider === '' ? null : User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->where('oauth_provider', $provider)->whereNotNull('oauth_subject')->where('oauth_subject', '<>', '')->first();

        return $person instanceof User ? $person : throw ValidationException::withMessages(PortalInvitations::enabled()
            ? ['invite' => self::NOBODY_SIGNS_IN_INVITE]
            : ['email' => self::NOBODY_SIGNS_IN]);
    }

    /** A person with portal access to this client, by public id; anyone else is not found. */
    public function member(ClientCompany $company, string $userPublicId): User
    {
        return User::query()->where('public_id', $userPublicId)->whereIn('id', ClientCompanyMembership::query()->select('user_id')
            ->where('workspace_id', $company->workspace_id)->where('client_company_id', $company->id))->firstOrFail();
    }

    /** A person with portal access to this client, by address (case-insensitively), or null. */
    public function memberByEmail(ClientCompany $company, string $email): ?User
    {
        return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->whereIn('id', ClientCompanyMembership::query()->select('user_id')
            ->where('workspace_id', $company->workspace_id)->where('client_company_id', $company->id))->first();
    }
}
