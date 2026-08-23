<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * OAuth-only view of the users table.
 *
 * SVC keeps Sanctum on User for the existing finance integration. Passport uses
 * this separate model and provider so the two packages never share a token
 * relation, while both principals resolve to the same local user id.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 */
class AgentPrincipal extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    protected $hidden = ['id', 'password', 'remember_token', 'oauth_provider', 'oauth_subject'];

    /** @return BelongsToMany<Workspace, $this, WorkspaceMembership, 'pivot'> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memberships', 'user_id', 'workspace_id')
            ->using(WorkspaceMembership::class)
            ->withPivot(['public_id', 'role'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<ClientCompany, $this, ClientCompanyMembership, 'pivot'> */
    public function clientCompanies(): BelongsToMany
    {
        return $this->belongsToMany(ClientCompany::class, 'client_company_memberships', 'user_id', 'client_company_id')
            ->using(ClientCompanyMembership::class)
            ->withPivot(['public_id', 'role'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<ClientProject, $this, ClientProjectMembership, 'pivot'> */
    public function clientProjects(): BelongsToMany
    {
        return $this->belongsToMany(ClientProject::class, 'client_project_memberships', 'user_id', 'client_project_id')
            ->using(ClientProjectMembership::class)
            ->withPivot(['public_id', 'workspace_id', 'role'])
            ->withTimestamps();
    }
}
