<?php

namespace App\Services\Clients;

use App\Http\Requests\SaveClientContactRequest;
use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ClientContactRepository
{
    /** @param (Closure(): void)|null $authorizeCurrent */
    public function create(Workspace $workspace, ClientCompany $company, ContactDetails $details, ?Closure $authorizeCurrent = null): ClientContact
    {
        return DB::transaction(function () use ($workspace, $company, $details, $authorizeCurrent): ClientContact {
            $parent = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($company->id)
                ->tap(Locks::forUpdate())->firstOrFail();
            $authorizeCurrent?->__invoke();

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

    /** @param (Closure(): void)|null $authorizeCurrent */
    public function patch(Workspace $workspace, ClientCompany $company, ClientContact $contact, ContactPatch $patch, string $expectedVersion, ?Closure $authorizeCurrent = null): ClientContact
    {
        return DB::transaction(function () use ($workspace, $company, $contact, $patch, $expectedVersion, $authorizeCurrent): ClientContact {
            $locked = ClientContact::query()->where('workspace_id', $workspace->id)
                ->where('client_company_id', $company->id)->whereKey($contact->id)
                ->tap(Locks::forUpdate())->firstOrFail();
            $authorizeCurrent?->__invoke();
            abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'This contact has changed. Read it before saving.');
            $rules = (new SaveClientContactRequest)->rules();
            $current = array_intersect_key($locked->getAttributes(), $rules);
            $attributes = Validator::make(array_replace($current, $patch->attributes), $rules)->validate();
            $locked->fill(ContactDetails::fromValidated($attributes)->attributes())->save();

            return $locked;
        });
    }
}
