<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveClientContactRequest;
use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\Workspace;
use App\Services\Clients\ClientContactRepository;
use App\Services\Clients\ContactDetails;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ClientContactController extends Controller
{
    public function store(SaveClientContactRequest $request, Workspace $workspace, ClientCompany $clientCompany, WorkspaceAuthorization $authorization, ClientContactRepository $contacts): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $contacts->create($workspace, $clientCompany, ContactDetails::fromValidated($request->validated()));

        return back()->with('status', 'Contact created.');
    }

    public function update(SaveClientContactRequest $request, Workspace $workspace, ClientCompany $clientCompany, ClientContact $clientContact, WorkspaceAuthorization $authorization, ClientContactRepository $contacts): RedirectResponse
    {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $contacts->update($workspace, $clientCompany, $clientContact,
            ContactDetails::fromValidated($request->validated()), (int) $request->validated('lock_version'));

        return back()->with('status', 'Contact saved.');
    }
}
