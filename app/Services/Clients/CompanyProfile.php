<?php

namespace App\Services\Clients;

use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Facades\DB;

final class CompanyProfile
{
    public const FIELDS = ['billing_address', 'phone', 'website_url', 'tax_id', 'currency', 'notes'];

    /** @return array<string, mixed> */
    public function read(ClientCompany $company): array
    {
        return array_intersect_key($company->getAttributes(), array_flip(self::FIELDS));
    }

    /** @param array<string, mixed> $attributes */
    public function update(Workspace $workspace, ClientCompany $company, array $attributes): void
    {
        DB::transaction(function () use ($workspace, $company, $attributes): void {
            $locked = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($company->id)
                ->tap(Locks::forUpdate())->firstOrFail();
            $locked->fill(array_intersect_key($attributes, array_flip(self::FIELDS)))->save();
        });
    }
}
