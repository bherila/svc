<?php

namespace App\Http\Controllers;

use App\Actions\CreateClientCompany;
use App\Http\Requests\StoreClientCompanyRequest;
use App\Models\Workspace;
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
}
