<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $name
 * @property string $slug
 * @property string|null $billing_email
 * @property bool $is_active
 */
#[Fillable(['workspace_id', 'name', 'slug', 'billing_email', 'is_active'])]
#[Hidden(['id', 'workspace_id'])]
class ClientCompany extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsToMany<User, $this, ClientCompanyMembership, 'pivot'> */
    public function portalUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_company_memberships')
            ->using(ClientCompanyMembership::class)
            ->withPivot(['public_id', 'role'])
            ->withTimestamps();
    }

    /** @return HasMany<ClientProject, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(ClientProject::class);
    }

    /** @return HasMany<ClientAgreement, $this> */
    public function agreements(): HasMany
    {
        return $this->hasMany(ClientAgreement::class);
    }

    /** @return HasMany<ClientInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(ClientInvoice::class);
    }

    /** @return HasMany<ClientTimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(ClientTimeEntry::class);
    }

    /**
     * The agreement in force today, if any.
     *
     * "Active" means status plus dates: a row can be marked active and still be
     * outside its own term, and invoice generation must not bill against one
     * that has ended.
     */
    public function activeAgreement(): ?ClientAgreement
    {
        $today = CarbonImmutable::now()->startOfDay();

        return $this->agreements()
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('starts_on')->orWhere('starts_on', '<=', $today->toDateString()))
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today->toDateString()))
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The most recent agreement of any state, used as the fallback so trailing
     * post-termination work can still be invoiced after the term has ended.
     */
    public function mostRecentAgreement(): ?ClientAgreement
    {
        return $this->agreements()
            ->where('status', '!=', 'draft')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }
}
