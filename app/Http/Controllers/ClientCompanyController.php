<?php

namespace App\Http\Controllers;

use App\Actions\CreateClientCompany;
use App\Http\Requests\StoreClientCompanyRequest;
use App\Http\Requests\UpdateClientCompanyRequest;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);

        $billingEmail = $request->validated('billing_email');

        // Re-read under a row lock inside the transaction that writes, scoped
        // to the workspace this request was authorized against.
        //
        // `assertOwnedBy` checks the instance the router bound, and the router
        // binds by key alone. The write that followed was also keyed by primary
        // key, so the authorization and the update were two statements about a
        // row that nothing held still in between: reparent the company after
        // the check and the request authorized against one tenant modifies a
        // row now owned by another. Taking the lock and re-asserting ownership
        // under it makes the check and the write one decision.
        DB::transaction(function () use ($workspace, $clientCompany, $request, $billingEmail): void {
            $locked = ClientCompany::query()
                ->whereKey($clientCompany->getKey())
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();

            // Gone, or no longer this workspace's. 404 rather than 403 for the
            // same reason every other miss here does: a tenant learns nothing
            // about a record it cannot reach, including that it exists.
            abort_if($locked === null, 404);

            $locked->update([
                'name' => $request->string('name')->toString(),
                'billing_email' => is_string($billingEmail) ? $billingEmail : null,
                'is_active' => $request->boolean('is_active'),
            ]);
        });

        return redirect()->back()->with('status', 'Client updated.');
    }
}
