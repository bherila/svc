<?php

namespace App\Services\Authorization;

use App\Models\AgentPrincipal;
use App\Models\User;
use Illuminate\Http\Request;

final class AgentTokenScopes
{
    public function allows(Request $request, string $scope): bool
    {
        $user = $request->user();

        return ($user instanceof User || $user instanceof AgentPrincipal)
            && $user->tokenCan($scope);
    }

    /** @param list<string> $scopes */
    public function allowsAll(Request $request, array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (! $this->allows($request, $scope)) {
                return false;
            }
        }

        return true;
    }
}
