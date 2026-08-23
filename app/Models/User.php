<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['public_id', 'name', 'email', 'password', 'oauth_provider', 'oauth_subject'])]
#[Hidden(['id', 'password', 'remember_token', 'oauth_provider', 'oauth_subject'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsToMany<Workspace, $this, WorkspaceMembership, 'pivot'> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memberships')
            ->using(WorkspaceMembership::class)
            ->withPivot(['public_id', 'role'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<ClientCompany, $this, ClientCompanyMembership, 'pivot'> */
    public function clientCompanies(): BelongsToMany
    {
        return $this->belongsToMany(ClientCompany::class, 'client_company_memberships')
            ->using(ClientCompanyMembership::class)
            ->withPivot(['public_id', 'role'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<ClientProject, $this, ClientProjectMembership, 'pivot'> */
    public function clientProjects(): BelongsToMany
    {
        return $this->belongsToMany(ClientProject::class, 'client_project_memberships')
            ->using(ClientProjectMembership::class)
            ->withPivot(['public_id', 'workspace_id', 'role'])
            ->withTimestamps();
    }
}
