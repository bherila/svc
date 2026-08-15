<?php

namespace App\Policies;

use App\Models\ClientCompany;
use App\Models\User;

class ClientCompanyPolicy
{
    public function viewPortal(User $user, ClientCompany $clientCompany): bool
    {
        return $clientCompany->workspace->memberships()->where('user_id', $user->id)->exists()
            || $clientCompany->portalUsers()->whereKey($user->id)->exists();
    }
}
