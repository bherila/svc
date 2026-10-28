<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientCompany;

/** Pure resource fields; callers keep authorization and page-specific extras. */
final class AgentClientPresenter
{
    /** @return array{id: string, name: string} */
    public static function identity(ClientCompany $company): array
    {
        return ['id' => $company->public_id, 'name' => $company->name];
    }

    /** @return array{id: string, name: string, billing_email: ?string, is_active: bool} */
    public static function summary(ClientCompany $company): array
    {
        return self::identity($company) + ['billing_email' => $company->billing_email, 'is_active' => $company->is_active];
    }
}
