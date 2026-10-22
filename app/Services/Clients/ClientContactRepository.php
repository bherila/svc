<?php

namespace App\Services\Clients;

use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\Workspace;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Facades\DB;

final class ClientContactRepository
{
    public function create(Workspace $workspace, ClientCompany $company, ContactDetails $details): ClientContact
    {
        return DB::transaction(function () use ($workspace, $company, $details): ClientContact {
            $parent = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($company->id)
                ->tap(Locks::forUpdate())->firstOrFail();

            return ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $parent->id] + $details->attributes());
        });
    }

    public function update(Workspace $workspace, ClientCompany $company, ClientContact $contact, ContactDetails $details, int $expectedVersion): ClientContact
    {
        return DB::transaction(function () use ($workspace, $company, $contact, $details, $expectedVersion): ClientContact {
            $locked = ClientContact::query()->where('workspace_id', $workspace->id)
                ->where('client_company_id', $company->id)->whereKey($contact->id)
                ->tap(Locks::forUpdate())->firstOrFail();
            abort_unless((int) $locked->lock_version === $expectedVersion, 409, 'This contact has changed. Reload before saving.');
            $locked->fill($details->attributes())->save();

            return $locked;
        });
    }
}
