<?php

namespace App\Http\Controllers;

use App\Actions\CreateClientCompany;
use App\Http\Requests\StoreClientCompanyRequest;
use App\Http\Requests\UpdateClientCompanyRequest;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ClientCompanyController extends Controller
{
    public function store(
        StoreClientCompanyRequest $request,
        Workspace $workspace,
        CreateClientCompany $createClientCompany,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        $billingEmail = $request->validated('billing_email');

        $createClientCompany->handle(
            $workspace,
            $request->string('name')->toString(),
            is_string($billingEmail) ? $billingEmail : null,
        );

        return redirect()->route('dashboard')->with('status', 'Client created.');
    }

    /**
     * Edit the client record itself, from the client's own Manage tab.
     *
     * Back to where the operator was, rather than to the dashboard the create
     * path returns to: they were working inside one client and are still
     * working inside it.
     */
    public function update(
        UpdateClientCompanyRequest $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        WorkspaceAuthorization $authorization,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);

        $billingEmail = $request->validated('billing_email');

        $clientCompany->update([
            'name' => $request->string('name')->toString(),
            'billing_email' => is_string($billingEmail) ? $billingEmail : null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('status', 'Client updated.');
    }
}
