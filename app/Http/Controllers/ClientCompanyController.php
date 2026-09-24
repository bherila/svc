<?php

namespace App\Http\Controllers;

use App\Actions\CreateClientCompany;
use App\Actions\UpdateClientCompany;
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

        $company = $createClientCompany->handle(
            $workspace,
            $request->string('name')->toString(),
            is_string($billingEmail) ? $billingEmail : null,
        );

        // Straight into the client that was just made. It is created from the
        // switcher, which exists to put the operator inside a client - so
        // returning them to a list to pick the one they just named would be
        // the switcher failing at its only job.
        return redirect()->route('clients.show', [$workspace, $company])
            ->with('status', 'Client created.');
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
        UpdateClientCompany $updateClientCompany,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);

        $billingEmail = $request->validated('billing_email');
        $attributes = [
            'name' => $request->string('name')->toString(),
            'billing_email' => is_string($billingEmail) ? $billingEmail : null,
            'is_active' => $request->boolean('is_active'),
            'automatic_invoice_email_delay_days' => $request->validated('automatic_invoice_email_delay_days'),
        ];
        if ($request->has('automatic_invoice_email_enabled')) {
            $attributes['automatic_invoice_email_enabled'] = $request->boolean('automatic_invoice_email_enabled');
        }

        $updateClientCompany->handle($workspace, $clientCompany, $attributes);

        return redirect()->back()->with('status', 'Client updated.');
    }
}
